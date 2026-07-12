<?php

namespace Tests\Feature;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\User;
use App\Services\ProviderPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelUseCaseEntryToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_toggles_only_the_selected_use_case_entry(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $profile = ModelProfile::create([
            'name' => 'Shared model',
            'slug' => 'shared-model',
            'provider' => 'openrouter',
            'model_id' => 'provider/shared-model',
            'active' => true,
        ]);
        $chat = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat', 'active' => true]);
        $planner = ModelUseCase::create(['name' => 'Planner', 'slug' => 'planner-toggle', 'active' => true]);
        $chatFallbackProfile = ModelProfile::create([
            'name' => 'Chat fallback',
            'slug' => 'chat-fallback-model',
            'provider' => 'openrouter',
            'model_id' => 'provider/chat-fallback',
            'active' => true,
        ]);
        $chatEntry = ModelUseCaseEntry::create([
            'model_use_case_id' => $chat->id,
            'model_profile_id' => $profile->id,
            'sort_order' => 1,
            'active' => true,
        ]);
        $chatFallbackEntry = ModelUseCaseEntry::create([
            'model_use_case_id' => $chat->id,
            'model_profile_id' => $chatFallbackProfile->id,
            'sort_order' => 2,
            'active' => true,
        ]);
        $plannerEntry = ModelUseCaseEntry::create([
            'model_use_case_id' => $planner->id,
            'model_profile_id' => $profile->id,
            'sort_order' => 1,
            'active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('dashboard.model-use-case-entries.toggle', $chatEntry))
            ->assertRedirect(route('admin.page', 'models'));

        $this->assertFalse($chatEntry->fresh()->active);
        $this->assertTrue($plannerEntry->fresh()->active);
        $this->assertTrue($profile->fresh()->active);
        $this->assertSame(1, $chatEntry->fresh()->sort_order);
        $this->assertTrue($chatFallbackEntry->fresh()->active);
        $this->assertSame(
            [$chatFallbackProfile->id],
            array_map(fn (ModelProfile $candidate) => $candidate->id, app(ProviderPolicyService::class)->candidates(null, 'chat')),
        );
    }

    public function test_non_admin_cannot_toggle_a_use_case_entry(): void
    {
        $user = User::factory()->create(['email_verified_at' => now(), 'role' => 'user']);
        $profile = ModelProfile::create([
            'name' => 'Model',
            'slug' => 'toggle-denied-model',
            'provider' => 'openrouter',
            'model_id' => 'provider/model',
        ]);
        $useCase = ModelUseCase::create(['name' => 'Chat', 'slug' => 'toggle-denied-chat', 'active' => true]);
        $entry = ModelUseCaseEntry::create([
            'model_use_case_id' => $useCase->id,
            'model_profile_id' => $profile->id,
            'sort_order' => 1,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('dashboard.model-use-case-entries.toggle', $entry))
            ->assertForbidden();

        $this->assertTrue($entry->fresh()->active);
    }
}
