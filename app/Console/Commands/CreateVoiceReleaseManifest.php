<?php

namespace App\Console\Commands;

use App\Services\DeviceJobSigner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Creates the immutable, signed release payload consumed by the desktop voice bootstrapper. */
class CreateVoiceReleaseManifest extends Command
{
    protected $signature = 'luczor:voice-release
        {--release-version= : Release version, for example 2026.07.09}
        {--base-url= : HTTPS directory containing the four published assets}
        {--platform=windows-x86_64 : Target platform identifier}
        {--stt-binary= : whisper.cpp executable}
        {--stt-model= : Whisper GGML model}
        {--tts-binary= : Piper executable}
        {--tts-model= : Piper ONNX model}
        {--tts-config= : Optional Piper ONNX JSON config, stored next to the model}
        {--stt-binary-runtime-path= : Executable path inside a ZIP runtime archive}
        {--tts-binary-runtime-path= : Executable path inside a ZIP runtime archive}
        {--manifest-output= : JSON envelope output path, relative to the Laravel base path}';

    protected $description = 'Create a SHA-256 verified Whisper.cpp/Piper release manifest signed with the server job key.';

    public function handle(DeviceJobSigner $signer): int
    {
        $version = trim((string) $this->option('release-version'));
        $baseUrl = rtrim(trim((string) $this->option('base-url')), '/');
        if ($version === '' || ! str_starts_with($baseUrl, 'https://')) {
            $this->error('--release-version and an HTTPS --base-url are required.');
            return self::INVALID;
        }

        $assets = [];
        foreach ([
            ['id' => 'whisper-cli', 'kind' => 'stt_binary', 'option' => 'stt-binary', 'runtime_option' => 'stt-binary-runtime-path', 'executable' => true],
            ['id' => 'whisper-model', 'kind' => 'stt_model', 'option' => 'stt-model', 'executable' => false],
            ['id' => 'piper', 'kind' => 'tts_binary', 'option' => 'tts-binary', 'runtime_option' => 'tts-binary-runtime-path', 'executable' => true],
            ['id' => 'piper-model', 'kind' => 'tts_model', 'option' => 'tts-model', 'executable' => false],
        ] as $asset) {
            $source = $this->file((string) $this->option($asset['option']));
            if (! $source) {
                return self::INVALID;
            }
            $fileName = basename($source);
            $archive = str_ends_with(strtolower($fileName), '.zip');
            $runtimePath = isset($asset['runtime_option']) ? trim((string) $this->option($asset['runtime_option'])) : '';
            if ($archive && $runtimePath === '') {
                $this->error('ZIP runtime assets require --'.$asset['runtime_option'].'.');
                return self::INVALID;
            }
            $entry = [
                'id' => $asset['id'],
                'kind' => $asset['kind'],
                'platform' => (string) $this->option('platform'),
                'url' => $baseUrl.'/'.rawurlencode($fileName),
                'sha256' => hash_file('sha256', $source),
                'file_name' => $fileName,
                'executable' => $asset['executable'],
            ];
            if ($archive) {
                $entry['archive'] = true;
                $entry['runtime_path'] = str_replace('\\', '/', $runtimePath);
            }
            $assets[] = $entry;
        }
        $config = trim((string) $this->option('tts-config'));
        if ($config !== '') {
            $source = $this->file($config);
            if (! $source) return self::INVALID;
            $fileName = basename($source);
            $assets[] = [
                'id' => 'piper-config', 'kind' => 'tts_config', 'platform' => (string) $this->option('platform'),
                'url' => $baseUrl.'/'.rawurlencode($fileName), 'sha256' => hash_file('sha256', $source),
                'file_name' => $fileName, 'executable' => false,
            ];
        }

        $payload = json_encode(['version' => $version, 'assets' => $assets], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $publicKey = $signer->publicKey();
        if (! $publicKey) {
            $this->error('The device-job signing key is not configured.');
            return self::FAILURE;
        }
        $envelope = [
            'algorithm' => 'RSA-SHA256',
            'payload_json' => $payload,
            'signature' => $signer->signMessage($payload),
            'public_key_b64' => base64_encode($publicKey),
        ];
        $encoded = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $output = trim((string) $this->option('manifest-output'));
        if ($output !== '') {
            $path = $this->absolutePath($output);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $encoded);
            $this->info('Voice release envelope written to '.$path);
        } else {
            $this->line($encoded);
        }
        $this->newLine();
        $this->line('Build the Tauri release with LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64='.$envelope['public_key_b64']);
        $this->line('Publish payload_json as LUCZOR_VOICE_MANIFEST_JSON on the Laravel deployment.');
        return self::SUCCESS;
    }

    private function file(string $source): ?string
    {
        if ($source === '') {
            $this->error('All four asset options are required.');
            return null;
        }
        $path = $this->absolutePath($source);
        if (! is_file($path)) {
            $this->error('Asset not found: '.$source);
            return null;
        }
        return $path;
    }

    private function absolutePath(string $path): string
    {
        $windowsAbsolute = strlen($path) >= 3
            && ctype_alpha($path[0])
            && $path[1] === ':'
            && ($path[2] === '\\' || $path[2] === '/');
        return is_file($path) || str_starts_with($path, DIRECTORY_SEPARATOR) || $windowsAbsolute ? $path : base_path($path);
    }
}
