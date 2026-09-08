<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class StageLocalModelRuntime extends Command
{
    protected $signature = 'luczor:stage-model-runtime {binary} {--library=*}';

    protected $description = 'Stage verified Linux runtime files and print their catalog hashes without publishing a policy';

    public function handle(): int
    {
        $paths = array_merge([(string) $this->argument('binary')], $this->option('library'));
        $root = (string) config('local_models.asset_directory');
        $result = [];
        foreach ($paths as $index => $path) {
            if (! is_file($path) || ! is_readable($path) || is_link($path)
                || ($index > 0 && ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+\-]*\.so\z/', basename($path)))) {
                $this->error('Expected a Linux executable and optional unversioned .so libraries.');

                return self::FAILURE;
            }
            $header = file_get_contents($path, false, null, 0, 20);
            if (strlen($header) !== 20 || substr($header, 0, 6) !== "\x7fELF\x02\x01") {
                $this->error('Only 64-bit little-endian ELF files can be staged.');

                return self::FAILURE;
            }
            $result[] = ['name' => basename($path), 'sha256' => hash_file('sha256', $path)];
        }
        if (! is_dir($root) && ! mkdir($root, 0750, true)) {
            $this->error('Cannot create runtime asset directory.');

            return self::FAILURE;
        }
        foreach ($paths as $index => $path) {
            $target = $root.DIRECTORY_SEPARATOR.$result[$index]['sha256'];
            if (file_exists($target)) {
                if (is_link($target) || hash_file('sha256', $target) !== $result[$index]['sha256']) {
                    $this->error('Existing staged asset is invalid.');

                    return self::FAILURE;
                }

                continue;
            }
            $temporary = tempnam($root, '.runtime-');
            try {
                if (! copy($path, $temporary) || hash_file('sha256', $temporary) !== $result[$index]['sha256']
                    || ! chmod($temporary, 0640) || ! link($temporary, $target)) {
                    $this->error('Runtime staging failed.');

                    return self::FAILURE;
                }
            } finally {
                unlink($temporary);
            }
        }
        $this->line(json_encode(['sha256' => $result[0]['sha256'], 'files' => array_slice($result, 1)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
