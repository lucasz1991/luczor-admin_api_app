<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiCompositionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tab_ids_are_bound_to_their_component_and_form_attributes_survive(): void
    {
        $html = Blade::render('<x-ui.tabs id="settings" :tabs="[\'a\' => \'Allgemein\', \'b\' => \'Details\']"><x-ui.tab-panel name="a"><x-ui.input name="model" required wire:model="model" /><x-ui.button type="submit" name="publish" value="1">Speichern</x-ui.button></x-ui.tab-panel><x-ui.tab-panel name="b">Details</x-ui.tab-panel></x-ui.tabs>');
        $this->assertStringContainsString('id="settings-panel-b"', $html);
        $this->assertStringContainsString('aria-controls="settings-panel-b"', $html);
        $this->assertStringContainsString('aria-labelledby="settings-tab-b"', $html);
        $this->assertStringContainsString('wire:model="model"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('name="publish"', $html);
        $this->assertStringContainsString('required', $html);
    }

    public function test_every_administrator_page_renders_inside_the_shared_shell(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
        $this->actingAs($admin);
        foreach (['overview', 'providers', 'models', 'telemetry', 'optimizer', 'experiments', 'workflows', 'agents', 'users', 'costs', 'devices', 'api-keys', 'archives', 'settings'] as $page) {
            $this->get('/admin/'.$page)->assertOk()->assertSee('data-ui-page', false)->assertSee('data-luczor-topbar', false);
        }
        foreach (['/dashboard', '/admin/local-model-tiers', '/account/devices', '/account/workspace', '/user/profile', '/admin/users/'.$admin->id] as $path) {
            $this->get($path)->assertOk()->assertSee('data-ui-page', false);
        }
    }
}
