<?php

namespace Tests\Feature;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reorders_use_case_chain_gaplessly(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $case = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat-x', 'active' => true]);
        $a = ModelProfile::create(['name' => 'A', 'slug' => 'prof-a', 'provider' => 'openrouter', 'model_id' => 'm/a']);
        $b = ModelProfile::create(['name' => 'B', 'slug' => 'prof-b', 'provider' => 'openrouter', 'model_id' => 'm/b']);
        $c = ModelProfile::create(['name' => 'C', 'slug' => 'prof-c', 'provider' => 'openrouter', 'model_id' => 'm/c']);
        $ea = ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $a->id, 'sort_order' => 1]);
        $eb = ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $b->id, 'sort_order' => 2]);
        $ec = ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $c->id, 'sort_order' => 3]);

        $this->actingAs($admin)
            ->post(route('dashboard.model-use-case-entries.reorder'), [
                'model_use_case_id' => $case->id,
                'entry_ids' => [$ec->id, $ea->id, $eb->id],
            ])
            ->assertRedirect();

        $this->assertSame(1, $ec->fresh()->sort_order);
        $this->assertSame(2, $ea->fresh()->sort_order);
        $this->assertSame(3, $eb->fresh()->sort_order);
    }

    public function test_deleting_entry_closes_the_gap(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $case = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat-y', 'active' => true]);
        $a = ModelProfile::create(['name' => 'A', 'slug' => 'py-a', 'provider' => 'openrouter', 'model_id' => 'm/a']);
        $b = ModelProfile::create(['name' => 'B', 'slug' => 'py-b', 'provider' => 'openrouter', 'model_id' => 'm/b']);
        $ea = ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $a->id, 'sort_order' => 1]);
        $eb = ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $b->id, 'sort_order' => 2]);

        $this->actingAs($admin)
            ->delete(route('dashboard.model-use-case-entries.destroy', $ea))
            ->assertRedirect();

        $this->assertNull($ea->fresh());
        $this->assertSame(1, $eb->fresh()->sort_order);
    }

    public function test_non_admin_cannot_reorder(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $case = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat-z', 'active' => true]);

        $this->actingAs($user)
            ->post(route('dashboard.model-use-case-entries.reorder'), [
                'model_use_case_id' => $case->id,
                'entry_ids' => [1],
            ])
            ->assertForbidden();
    }

    public function test_admin_models_page_renders_a_csrf_token_in_each_reorder_form(): void
    {
        $admin = User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
        $case = ModelUseCase::create(['name' => 'Chat', 'slug' => 'chat-csrf', 'active' => true]);
        $profile = ModelProfile::create(['name' => 'CSRF model', 'slug' => 'csrf-model', 'provider' => 'openrouter', 'model_id' => 'm/csrf']);
        $entry = ModelUseCaseEntry::create([
            'model_use_case_id' => $case->id,
            'model_profile_id' => $profile->id,
            'sort_order' => 1,
            'active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->get(route('admin.page', 'models'))
            ->assertOk()
            ->assertSee('data-entry-actions', false)
            ->assertSee(route('dashboard.model-use-case-entries.toggle', $entry), false)
            ->assertSee('Aus Anwendungsfall entfernen');

        $document = new \DOMDocument;
        $previousErrors = libxml_use_internal_errors(true);
        try {
            $this->assertTrue($document->loadHTML($response->getContent()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }

        $xpath = new \DOMXPath($document);
        $reorderForms = $xpath->query(sprintf(
            '//form[@data-chain-form and @action="%s"]',
            route('dashboard.model-use-case-entries.reorder')
        ));

        $this->assertNotFalse($reorderForms);
        $this->assertSame(1, $reorderForms->length);
        $csrfTokens = $xpath->query('.//input[@type="hidden" and @name="_token" and string-length(@value) > 0]', $reorderForms->item(0));
        $this->assertNotFalse($csrfTokens);
        $this->assertSame(1, $csrfTokens->length);
    }
}
