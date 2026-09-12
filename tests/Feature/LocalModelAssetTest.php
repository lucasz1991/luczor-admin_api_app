<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalModelAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_windows_staging_checks_pe_architecture_and_never_accepts_it_as_linux(): void
    {
        $root = sys_get_temp_dir().'/luczor-pe-test-'.bin2hex(random_bytes(10));
        mkdir($root);
        try {
            config(['local_models.asset_directory' => $root.'/assets']);
            $path = $root.'/llama-server.exe';
            $dos = 'MZ'.str_repeat("\0", 58).pack('V', 64);
            $pe = "PE\0\0".pack('v', 0x8664).str_repeat("\0", 18).pack('v', 0x20B);
            file_put_contents($path, $dos.$pe);
            $this->artisan('luczor:stage-model-runtime', ['binary' => $path, '--platform' => 'windows-x86_64'])->assertSuccessful();
            $this->assertSame($dos.$pe, file_get_contents($root.'/assets/'.hash('sha256', $dos.$pe)));
            $this->artisan('luczor:stage-model-runtime', ['binary' => $path])->assertFailed();
            $pe[4] = chr(0x4C);
            $pe[5] = chr(1);
            file_put_contents($path, $dos.$pe);
            $this->artisan('luczor:stage-model-runtime', ['binary' => $path, '--platform' => 'windows-x86_64'])->assertFailed();
            file_put_contents($path, 'MZ');
            $this->artisan('luczor:stage-model-runtime', ['binary' => $path, '--platform' => 'windows-x86_64'])->assertFailed();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_staging_is_repeatable_and_rejects_windows_executables(): void
    {
        $root = sys_get_temp_dir().'/luczor-stage-test-'.bin2hex(random_bytes(10));
        mkdir($root);
        try {
            config(['local_models.asset_directory' => $root.'/assets']);
            $binary = $root.'/llama-server';
            $bytes = "\x7fELF\x02\x01".str_repeat("\0", 14);
            $bytes[18] = chr(62);
            file_put_contents($binary, $bytes);
            $this->artisan('luczor:stage-model-runtime', ['binary' => $binary])->assertSuccessful();
            $this->artisan('luczor:stage-model-runtime', ['binary' => $binary])->assertSuccessful();
            $this->assertSame($bytes, file_get_contents($root.'/assets/'.hash('sha256', $bytes)));
            file_put_contents($binary, 'MZ'.str_repeat("\0", 18));
            $this->artisan('luczor:stage-model-runtime', ['binary' => $binary])->assertFailed();
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_only_catalog_listed_runtime_bytes_are_served(): void
    {
        $root = sys_get_temp_dir().'/luczor-assets-test-'.bin2hex(random_bytes(10));
        mkdir($root);
        try {
            $hash = hash('sha256', 'runtime');
            file_put_contents($root.'/'.$hash, 'runtime');
            config(['local_models.asset_directory' => $root]);
            $catalog = json_decode(file_get_contents(base_path('tests/Fixtures/local-model-manifest-v1.json')), true)['cases']['explicit_experiment'];
            $catalog['models'][0]['runtime']['sha256'] = $hash;
            config([
                'local_models.schema_version' => 1,
                'local_models.models' => $catalog['models'],
                'local_models.routing' => $catalog['routing'],
            ]);
            $this->get('/api/v1/local-model/assets/'.$hash)->assertOk()
                ->assertHeader('Content-Type', 'application/octet-stream');
            $this->get('/api/v1/local-model/assets/'.str_repeat('0', 64))->assertNotFound();
            unlink($root.'/'.$hash);
            $target = $root.'/unlisted-runtime';
            file_put_contents($target, 'not a published artifact');
            if (! @symlink($target, $root.'/'.$hash)) {
                $this->markTestSkipped('Symlink rejection needs filesystem symlink permission on this host; ordinary asset assertions passed.');
            }
            $this->get('/api/v1/local-model/assets/'.$hash)->assertNotFound();
        } finally {
            File::deleteDirectory($root);
        }
    }
}
