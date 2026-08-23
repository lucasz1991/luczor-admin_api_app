<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class CreateDeviceSigningKey extends Command
{
    protected $signature = 'luczor:device-signing-key
        {--path=storage/app/keys/luczor_device_job_private.pem : Private PEM output path}
        {--print-public-key : Print the public build variable for an existing key}
        {--force : Replace an existing key}';

    protected $description = 'Generate the RSA key used to sign device jobs and local voice releases.';

    public function handle(): int
    {
        $path = (string) $this->option('path');
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }
        if (File::exists($path) && ! $this->option('force')) {
            if (! $this->option('print-public-key')) {
                $this->error('Key already exists: '.$path.' (use --print-public-key or --force)');

                return self::FAILURE;
            }
            $key = openssl_pkey_get_private(File::get($path));
            if (! $key || ! ($details = openssl_pkey_get_details($key))) {
                $this->error('Existing key is invalid.');

                return self::FAILURE;
            }
            $this->line('LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64='.base64_encode($details['key']));

            return self::SUCCESS;
        }
        $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if (! $key || ! openssl_pkey_export($key, $private)) {
            $this->error('OpenSSL could not generate a signing key.');

            return self::FAILURE;
        }
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $private);
        @chmod($path, 0600);
        $details = openssl_pkey_get_details($key);
        $this->info('Private key written to '.$path);
        $this->line('LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64='.base64_encode($details['key']));

        return self::SUCCESS;
    }
}
