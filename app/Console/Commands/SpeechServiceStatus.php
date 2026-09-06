<?php

namespace App\Console\Commands;

use App\Services\Speech\SharedSpeechException;
use App\Services\Speech\SharedSpeechService;
use Illuminate\Console\Command;

class SpeechServiceStatus extends Command
{
    protected $signature = 'luczor:speech-service-status {--smoke : Synthesize and validate one fixed German test sentence without saving audio}';

    protected $description = 'Check the authenticated shared speech service without exposing credentials or text';

    public function handle(SharedSpeechService $speech): int
    {
        $status = $speech->status();
        $this->line('Shared TTS: '.$status['status']);
        if (! $status['ready']) {
            $this->components->error('Server-TTS ist nicht bereit. Einrichtung: docs/shared-speech.md');

            return self::FAILURE;
        }
        if ($this->option('smoke')) {
            try {
                $audio = $speech->synthesize('Dies ist der Luczor Sprachtest.');
                $this->line('WAV smoke passed: '.strlen($audio).' bytes; audio discarded.');
            } catch (SharedSpeechException $exception) {
                $this->components->error($exception->errorCode.': '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
