<?php

namespace App\Console\Commands;

use App\Services\AssistantDefaultsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PrepareAssistantDefaults extends Command
{
    protected $signature = 'luczor:assistant-defaults';

    protected $description = 'Ergänzt den editierbaren Luczor-Grundentwurf ausschließlich in einer lokalen SQLite-Datenbank.';

    public function handle(AssistantDefaultsService $defaults): int
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'sqlite') {
            $this->error('Nur APP_ENV=local|testing und SQLite sind für diesen lokalen Grundentwurf erlaubt.');

            return self::FAILURE;
        }

        $result = $defaults->prepare();
        $this->info(sprintf('%d Persönlichkeit(en), %d Skill(s) ergänzt. Vorhandene Inhalte und Auswahl bleiben erhalten.', $result['personas_created'], $result['skills_created']));

        return self::SUCCESS;
    }
}
