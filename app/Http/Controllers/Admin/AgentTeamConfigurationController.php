<?php

namespace App\Http\Controllers\Admin;

use App\Models\AgentProfile;
use App\Services\AgentTeamDefaultsService;
use App\Services\AgentTeamPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class AgentTeamConfigurationController extends AdminController
{
    public function prepare(Request $request, AgentTeamDefaultsService $defaults)
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['provider_credential_id' => ['required', 'integer'], 'fill_empty_routes' => ['sometimes', 'boolean']]);
        $counts = $defaults->prepare((int) $data['provider_credential_id'], (bool) ($data['fill_empty_routes'] ?? false));

        return Redirect::route('admin.page', 'agents')->with('status', sprintf('Agententeams ergänzt: %d Modelle, %d Rollen, %d Routingeinträge. Bestehende Konfiguration bleibt erhalten.', ...array_values($counts)));
    }

    public function research(Request $request, AgentTeamDefaultsService $defaults)
    {
        $this->ensureAdmin($request);
        $catalog = $defaults->refreshResearch();

        return Redirect::route('admin.page', 'agents')->with('status', count($catalog['models']).' Kandidaten im öffentlichen OpenRouter-Katalog geprüft. Routing erst über „Teams ergänzen“ einrichten.');
    }

    public function update(Request $request)
    {
        $this->ensureAdmin($request);
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'default_preset' => ['required', 'in:free,budget'], 'max_parallel' => ['required', 'integer', 'min:1', 'max:3'],
        ]);
        AgentProfile::updateOrCreate(['key' => AgentTeamPolicyService::POLICY_KEY], [
            'name' => 'Luczor: lokale Steuerung und externe Spezialisten', 'type' => 'team_policy',
            'status' => $data['enabled'] ? 'active' : 'disabled',
            'config' => ['default_preset' => $data['default_preset'], 'max_parallel' => (int) $data['max_parallel']],
        ]);

        return Redirect::route('admin.page', 'agents')->with('status', 'Agententeam-Konfiguration gespeichert.');
    }
}
