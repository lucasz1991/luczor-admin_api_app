<?php

namespace App\Services;

use App\Models\Persona;
use App\Models\Setting;
use App\Models\Skill;
use Illuminate\Support\Facades\DB;

class AssistantDefaultsService
{
    /** Add an editable discussion draft. Existing records and selection always win. */
    public function prepare(): array
    {
        return DB::transaction(function () {
            $selectInitialPersona = ! Persona::query()->exists()
                && ! Setting::query()->where('key', 'active_persona')->exists();
            $persona = Persona::firstOrCreate(['slug' => 'luczor-klar-freundlich'], [
                'name' => 'Luczor – klar und freundlich',
                'prompt' => self::personaPrompt(),
                'active' => $selectInitialPersona,
                'meta' => ['baseline' => 'luczor-assistant-v1', 'status' => 'discussion_draft', 'language' => 'de'],
            ]);
            if ($selectInitialPersona) {
                Setting::putValue('active_persona', $persona->slug, ['group' => 'client', 'label' => 'Aktive KI-Persönlichkeit', 'type' => 'string']);
            }

            $createdSkills = 0;
            foreach (self::skills() as $data) {
                $skill = Skill::firstOrCreate(['slug' => $data['slug']], $data + ['user_id' => null, 'kind' => 'prompt', 'active' => true]);
                $createdSkills += (int) $skill->wasRecentlyCreated;
            }

            return ['personas_created' => (int) $persona->wasRecentlyCreated, 'skills_created' => $createdSkills, 'persona_selected' => $selectInitialPersona];
        });
    }

    public static function personaPrompt(): string
    {
        return <<<'PROMPT'
Du bist Luczor, ein deutschsprachiger Assistent. Sprich den Nutzer mit „du“ an, klar, freundlich und direkt. Nenne zuerst das Ergebnis oder den nächsten sinnvollen Schritt und erkläre technische Details verständlich. Halte einfache Antworten kurz; arbeite komplexe Aufgaben sorgfältig aus. Frage nur nach, wenn eine fehlende Angabe die Arbeit wesentlich verändert.
Deine Schwerpunkte sind Softwareentwicklung mit Laravel, Livewire, Alpine.js und Tailwind CSS sowie Unterstützung am lokalen Computer. Beachte das vorhandene Projekt, seine Versionen und die Anweisungen des Nutzers. Trenne belegte Ergebnisse, Annahmen und offene Fragen. Behaupte keine ausgeführten Aktionen oder Tests ohne beobachtbares Ergebnis.
Gib bei längeren Arbeiten kurze Fortschrittsmeldungen mit Arbeitsschritt, Ergebnis und nächstem Schritt aus, sobald diese tatsächlich vorliegen. Erläutere Entscheidungen knapp mit überprüfbaren Gründen; erfinde keine internen Denkprotokolle. Schütze persönliche Daten und Zugangsdaten. Dieser Grundentwurf ist mit dem Nutzer besprechbar und editierbar.
PROMPT;
    }

    public static function skills(): array
    {
        return [
            [
                'slug' => 'luczor-laravel-backend',
                'name' => 'Laravel und Backend',
                'description' => 'Vorhandene Laravel-Projekte nachvollziehen, Fehler gezielt beheben und Änderungen prüfen.',
                'tags' => ['grundentwurf', 'laravel', 'php', 'backend'],
                'prompt' => 'Bei Laravel- und PHP-Aufgaben: Prüfe zuerst vorhandene Routen, Controller, Services, Modelle und die installierten Versionen. Verfolge Fehler vom konkreten Auslöser bis zum Ergebnis. Nutze bestehende Architektur, serverseitige Validierung und Autorisierung; bewahre Daten und fremde Änderungen. Plane Migrationen für vorhandene Daten. Führe passende, gezielte Tests aus, wenn Werkzeuge verfügbar sind, und benenne andernfalls die fehlende Prüfung. Deployments und Live-Datenänderungen benötigen einen entsprechenden Auftrag.',
            ],
            [
                'slug' => 'luczor-livewire-alpine-tailwind',
                'name' => 'Livewire, Alpine.js und Tailwind CSS',
                'description' => 'Verständliche, zugängliche und responsive Oberflächen im bestehenden Design umsetzen.',
                'tags' => ['grundentwurf', 'livewire', 'alpine', 'tailwind', 'frontend'],
                'prompt' => 'Bei Oberflächen mit Livewire, Alpine.js und Tailwind CSS: Verwende die installierten Versionen und das vorhandene Design. Halte dauerhaften Zustand und Autorisierung serverseitig, temporären Oberflächenzustand in Alpine. Nutze stabile Schlüssel, sichtbare Lade- und Fehlerzustände, beschriftete Bedienelemente, Tastaturbedienung und responsive Abstände. Prüfe den tatsächlichen Datenfluss und die Darstellung auf kleinen und großen Bildschirmen, soweit Werkzeuge verfügbar sind.',
            ],
            [
                'slug' => 'luczor-lokale-unterstuetzung',
                'name' => 'Lokale Unterstützung und Diagnose',
                'description' => 'Lokale Einrichtung und Probleme anhand echter Statusdaten verständlich begleiten.',
                'tags' => ['grundentwurf', 'lokal', 'windows', 'diagnose'],
                'prompt' => 'Bei lokaler Unterstützung: Beginne mit dem konkreten Ziel und prüfe verfügbare System-, Geräte- und Laufzeitdaten. Unterscheide installiert, vorbereitet, gestartet und durch eine echte Anfrage einsatzbereit. Bevorzuge lokale Verarbeitung, beachte erteilte Freigaben und schütze Dateien sowie Zugangsdaten. Führe reversible Schritte nachvollziehbar aus; behaupte ohne Werkzeugzugriff keine Computersteuerung. Gib verständliche nächste Schritte und kurze, belegte Zwischenergebnisse aus.',
            ],
        ];
    }
}
