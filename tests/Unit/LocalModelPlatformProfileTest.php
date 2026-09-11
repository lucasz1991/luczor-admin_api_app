<?php

namespace Tests\Unit;

use App\Exceptions\LocalModelManifestConfigurationException;
use App\Services\LocalModelPlatformProfile;
use PHPUnit\Framework\TestCase;

class LocalModelPlatformProfileTest extends TestCase
{
    public function test_platform_selection_preserves_weights_and_identity(): void
    {
        $model = ['id' => 'light', 'enabled' => true, 'artifact' => ['sha256' => 'same-weights'], 'runtime' => ['version' => 'legacy'],
            'platform_profiles' => ['windows-x86_64' => ['runtime' => ['version' => 'windows']], 'linux-x86_64' => ['runtime' => ['version' => 'linux']]]];
        $selector = new LocalModelPlatformProfile;
        foreach (['windows', 'linux'] as $os) {
            $selected = $selector->select($model, $os.'-x86_64');
            $this->assertSame($os, $selected['runtime']['version']);
            $this->assertSame($model['artifact'], $selected['artifact']);
            $this->assertSame('light', $selected['id']);
            $this->assertArrayNotHasKey('platform_profiles', $selected);
        }
        $this->assertFalse($selector->select($model, 'linux-aarch64')['enabled']);
        $this->assertNull($selector->select($model, 'linux-aarch64')['runtime']);
        $this->assertSame('legacy', $selector->select($model, null)['runtime']['version']);
    }

    public function test_a_platform_cannot_replace_weights_or_enable_a_disabled_model(): void
    {
        $this->expectException(LocalModelManifestConfigurationException::class);
        (new LocalModelPlatformProfile)->select(['platform_profiles' => ['windows-x86_64' => ['runtime' => [], 'enabled' => true]]], 'windows-x86_64');
    }
}
