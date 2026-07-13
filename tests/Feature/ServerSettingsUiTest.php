<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerSettingsUiTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['email_verified_at' => now(), 'role' => 'admin']);
    }

    private function seedSettings(): void
    {
        Setting::putValue('assistant_name', 'Luczor', ['group' => 'client', 'label' => 'Assistenten-Name', 'type' => 'string']);
        Setting::putValue('memory_inject', true, ['group' => 'client', 'label' => 'Memory einblenden', 'type' => 'bool']);
        Setting::putValue('memory_inject_count', 5, ['group' => 'client', 'label' => 'Anzahl', 'type' => 'number']);
    }

    public function test_settings_page_renders_typed_inputs_grouped(): void
    {
        $this->seedSettings();

        $this->actingAs($this->admin())
            ->get(route('admin.page', 'settings'))
            ->assertOk()
            ->assertSee('name="settings[assistant_name]"', false)
            ->assertSee('type="number"', false)
            ->assertSee('type="checkbox"', false)
            ->assertSee('Alle Einstellungen speichern');
    }

    public function test_admin_saves_settings_including_bool_unchecking(): void
    {
        $this->seedSettings();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('dashboard.settings.store'), ['settings' => ['assistant_name' => 'Neo', 'memory_inject' => '1', 'memory_inject_count' => '9']])
            ->assertRedirect();

        $this->assertSame('Neo', Setting::getValue('assistant_name'));
        $this->assertTrue(Setting::getValue('memory_inject'));
        $this->assertEqualsWithDelta(9, Setting::getValue('memory_inject_count'), 0.001);

        // Absent checkbox -> bool set to false.
        $this->actingAs($admin)
            ->post(route('dashboard.settings.store'), ['settings' => ['assistant_name' => 'Neo']])
            ->assertRedirect();

        $this->assertFalse(Setting::getValue('memory_inject'));
    }

    public function test_non_admin_cannot_store_settings(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($user)
            ->post(route('dashboard.settings.store'), ['settings' => ['assistant_name' => 'x']])
            ->assertForbidden();
    }
}
