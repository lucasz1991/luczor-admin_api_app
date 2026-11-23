<?php

namespace App\Console\Commands;

use App\Services\ClaudeProfileInstaller;
use Illuminate\Console\Command;

class InstallClaudeProfiles extends Command
{
    protected $signature = 'luczor:claude:install-profiles';

    protected $description = 'Legt Claude-Modellprofile, den Use-Case main-claude und Preisstände idempotent an. Vorhandene Einträge bleiben unverändert.';

    public function handle(ClaudeProfileInstaller $installer): int
    {
        $created = $installer->install();
        $this->info(sprintf(
            '%d Modellprofil(e), %d Use-Case-Eintrag/Einträge, %d Preisstand/Preisstände neu angelegt.',
            $created['profiles'],
            $created['entries'],
            $created['prices'],
        ));

        return self::SUCCESS;
    }
}
