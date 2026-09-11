<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class StageLocalModelRuntime extends Command
{
    protected $signature = 'luczor:stage-model-runtime {binary} {--library=*} {--platform=linux-x86_64}';

    protected $description = 'Stage matching Windows/Linux runtime files and print catalog hashes without executing or publishing them';

    public function handle(): int
    {
        $platform = (string) $this->option('platform');
        if (! in_array($platform, ['windows-x86_64', 'linux-x86_64', 'linux-aarch64'], true)) {
            $this->error('Unsupported runtime target.');

            return self::FAILURE;
        }
        $windows = $platform === 'windows-x86_64';
        $paths = array_merge([(string) $this->argument('binary')], $this->option('library'));
        $root = (string) config('local_models.asset_directory');
        $result = [];
        foreach ($paths as $index => $path) {
            if (! is_file($path) || ! is_readable($path) || is_link($path)
                || ($index > 0 && ! preg_match($windows ? '/\A[A-Za-z0-9][A-Za-z0-9._+\-]*\.dll\z/i' : '/\A[A-Za-z0-9][A-Za-z0-9._+\-]*\.so\z/', basename($path)))) {
                $this->error('Expected a native executable and matching .dll or unversioned .so libraries.');

                return self::FAILURE;
            }
            if (! $this->matchesTarget($path, $platform)) {
                $this->error('Runtime binary format or architecture does not match the selected platform.');

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

    private function matchesTarget(string $path, string $platform): bool
    {
        $header = file_get_contents($path, false, null, 0, 64);
        if ($platform !== 'windows-x86_64') {
            return strlen($header) >= 20 && substr($header, 0, 6) === "\x7fELF\x02\x01"
                && unpack('v', substr($header, 18, 2))[1] === ($platform === 'linux-aarch64' ? 183 : 62);
        }
        if (strlen($header) < 64 || substr($header, 0, 2) !== 'MZ') {
            return false;
        }
        $offset = unpack('V', substr($header, 60, 4))[1];
        if ($offset < 64 || $offset > filesize($path) - 26) {
            return false;
        }
        $pe = file_get_contents($path, false, null, $offset, 26);

        return strlen($pe) === 26 && substr($pe, 0, 4) === "PE\0\0"
            && unpack('v', substr($pe, 4, 2))[1] === 0x8664
            && unpack('v', substr($pe, 24, 2))[1] === 0x20B;
    }
}
