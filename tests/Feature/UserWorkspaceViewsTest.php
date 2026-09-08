<?php

namespace Tests\Feature;

use App\Livewire\Admin\UserProfile;
use App\Livewire\Admin\Users;
use App\Livewire\Profile\ProfileIdentityCard;
use App\Models\Device;
use App\Models\LlmRun;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class UserWorkspaceViewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_list_has_bounded_filters_sorting_and_pagination(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->count(17)->create(['name' => 'Active User']);
        $inactive = User::factory()->create(['name' => 'Paused Unique', 'status' => false]);
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSeeLivewire(Users::class);
        Livewire::actingAs($admin)->test(Users::class)
            ->assertViewHas('users', fn ($users) => $users->count() === 15 && $users->total() === 19)
            ->call('gotoPage', 2)
            ->set('accountStatus', 'inactive')
            ->assertSet('paginators.page', 1)
            ->assertSee($inactive->name)
            ->assertDontSee('Active User')
            ->call('resetFilters')
            ->set('search', 'Paused')
            ->assertViewHas('users', fn ($users) => $users->total() === 1)
            ->set('search', '%')
            ->assertSee('Keine Benutzer gefunden')
            ->set('search', '')
            ->set('perPage', 1000000)
            ->set('sortBy', 'password')
            ->set('sortDir', 'anything')
            ->assertViewHas('users', fn ($users) => $users->count() === 15)
            ->set('role', 'admin')
            ->assertViewHas('users', fn ($users) => $users->total() === 1);
    }

    public function test_normal_and_suspended_users_cannot_rehydrate_admin_components(): void
    {
        $normal = User::factory()->create();
        $this->actingAs($normal)->get('/admin/users')->assertForbidden();
        Livewire::actingAs($normal)->test(Users::class)->assertForbidden();
        Livewire::actingAs($normal)->test(UserProfile::class, ['userId' => $normal->id])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $component = Livewire::actingAs($admin)->test(Users::class);
        $admin->update(['status' => false]);
        $component->set('search', 'query')->assertForbidden();
    }

    public function test_profile_reads_only_the_selected_users_usage_and_requires_admin_for_changes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $other = User::factory()->create();
        Device::create(['user_id' => $user->id, 'device_id' => 'test-device', 'name' => 'Desktop', 'status' => 'online', 'last_seen_at' => now()]);
        LlmRun::create(['user_id' => $user->id, 'task_type' => 'chat', 'model_id' => 'local-model-visible', 'estimated_cost_usd' => 0.25]);
        LlmRun::create(['user_id' => $user->id, 'task_type' => 'chat', 'model_id' => 'cost-unknown']);
        LlmRun::create(['user_id' => $other->id, 'task_type' => 'chat', 'model_id' => 'other-user-secret-model', 'estimated_cost_usd' => 900]);
        $this->actingAs($admin)->get('/admin/users/'.$user->id)->assertOk()->assertSeeLivewire(UserProfile::class);
        Livewire::actingAs($admin)->test(UserProfile::class, ['userId' => $user->id])
            ->call('selectTab', 'usage')
            ->assertSee('local-model-visible')->assertDontSee('other-user-secret-model')
            ->assertViewHas('usage', fn ($usage) => (float) $usage->estimated_usd === 0.25 && (int) $usage->unknown_costs === 1)
            ->call('selectTab', 'account')
            ->set('name', 'Updated Account')
            ->set('active', false)
            ->call('save')->assertHasNoErrors();
        $this->assertSame('Updated Account', $user->fresh()->name);
        $this->assertFalse($user->fresh()->status);
        $this->assertDatabaseHas('audit_events', ['actor_user_id' => $admin->id, 'event_type' => 'user.updated']);
    }

    public function test_profile_subject_cannot_be_changed_in_the_livewire_payload(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::actingAs($admin)->test(UserProfile::class, ['userId' => $user->id])->set('userId', $admin->id);
    }

    public function test_admin_accounts_stay_protected_and_new_users_cannot_gain_admin_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(UserProfile::class, ['userId' => $admin->id])
            ->set('name', 'Changed')->set('active', false)->call('save')->assertForbidden();
        $this->assertTrue($admin->fresh()->status);
        Livewire::actingAs($admin)->test(Users::class)
            ->call('openCreate')
            ->set('newUser', ['name' => 'Created User', 'email' => 'new@example.test', 'password' => 'safe-password-123', 'password_confirmation' => 'safe-password-123', 'role' => 'admin', 'status' => false])
            ->call('createUser')->assertHasNoErrors()
            ->assertSet('newUser.password', '');
        $created = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('user', $created->role);
        $this->assertTrue($created->isActive());
        $this->assertNull($created->email_verified_at);
    }

    public function test_own_profile_uses_fortify_validation_and_keeps_email_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->get('/user/profile')->assertOk()->assertSeeLivewire(ProfileIdentityCard::class)->assertSee('current_password');
        Livewire::actingAs($user)->test(ProfileIdentityCard::class)
            ->set('email', $other->email)->call('saveIdentity')->assertHasErrors('email');
        Livewire::actingAs($user)->test(ProfileIdentityCard::class)
            ->set('name', 'My own name')
            ->set('email', 'changed@example.test')
            ->call('saveIdentity')->assertHasNoErrors()->assertRedirect(route('verification.notice'));
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('My own name', $user->fresh()->name);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertNotSame('My own name', $other->fresh()->name);
    }

    public function test_failed_creation_clears_passwords_and_validates_duplicate_email(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Livewire::actingAs($admin)->test(Users::class)
            ->call('openCreate')
            ->set('newUser', ['name' => 'Duplicate User', 'email' => $admin->email, 'password' => 'short', 'password_confirmation' => 'different'])
            ->call('createUser')
            ->assertHasErrors(['email', 'password'])
            ->assertSet('newUser.password', '')
            ->assertSet('newUser.password_confirmation', '');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_password_changes_still_require_the_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from('/user/profile')->put('/user/password', [
            'current_password' => 'wrong', 'password' => 'new-password-123', 'password_confirmation' => 'new-password-123',
        ])->assertSessionHasErrorsIn('updatePassword', ['current_password']);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
