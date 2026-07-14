<?php

namespace Tests\Feature;

use App\Models\ModelProfile;
use App\Models\ModelUseCase;
use App\Models\ModelUseCaseEntry;
use App\Services\ProviderPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapabilityRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_capability_filter_drops_profiles_lacking_a_required_capability(): void
    {
        $case = ModelUseCase::create(['name' => 'Vision', 'slug' => 'vision', 'active' => true]);
        $vision = ModelProfile::create(['name' => 'V', 'slug' => 'v', 'provider' => 'openrouter', 'model_id' => 'm/v', 'capabilities' => ['vision', 'tools']]);
        $textOnly = ModelProfile::create(['name' => 'T', 'slug' => 't', 'provider' => 'openrouter', 'model_id' => 'm/t', 'capabilities' => ['tools']]);
        $unknown = ModelProfile::create(['name' => 'U', 'slug' => 'u', 'provider' => 'openrouter', 'model_id' => 'm/u']); // no capabilities
        foreach ([$vision, $textOnly, $unknown] as $i => $p) {
            ModelUseCaseEntry::create(['model_use_case_id' => $case->id, 'model_profile_id' => $p->id, 'sort_order' => $i + 1, 'active' => true]);
        }

        $svc = new ProviderPolicyService;
        $ids = collect($svc->candidates(null, 'vision.describe', ['vision']))->pluck('slug')->all();

        $this->assertContains('v', $ids);     // has vision
        $this->assertContains('u', $ids);     // unknown caps kept
        $this->assertNotContains('t', $ids);  // declares caps but lacks vision
    }

    public function test_version_endpoint_is_public(): void
    {
        $this->getJson('/api/v1/version')
            ->assertOk()
            ->assertJsonStructure(['api', 'laravel', 'app', 'server_time']);
    }
}
