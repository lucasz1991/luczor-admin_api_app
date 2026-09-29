<?php

namespace Tests\Unit;

use App\Services\DeviceDebugRedactor;
use PHPUnit\Framework\TestCase;

class DeviceDebugRedactorTest extends TestCase
{
    public function test_credentials_are_removed_from_alternate_header_and_serialized_shapes(): void
    {
        $redactor = new DeviceDebugRedactor;
        $source = [
            'headers' => ['Set_Cookie' => 'hidden-cookie', 'Proxy-Authorization' => 'hidden-proxy'],
            'rows' => [
                ['name' => 'Cookie', 'value' => 'hidden-row'],
                ['key' => 'X-Api-Key', 'value' => 'hidden-api'],
                ['name' => 'Content-Type', 'value' => 'application/json'],
            ],
            'arguments' => '{"sessionId":"hidden-session","path":"docs/example.md"}',
            'request_id' => 'public-request',
        ];

        $clean = $redactor->clean($source);
        $encoded = json_encode($clean, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('hidden-', $encoded);
        $this->assertSame('application/json', $clean['rows'][2]['value']);
        $this->assertSame('public-request', $clean['request_id']);
        $this->assertSame('docs/example.md', json_decode($clean['arguments'], true, flags: JSON_THROW_ON_ERROR)['path']);
        $this->assertSame($clean, $redactor->clean($clean));
        $this->assertSame('hidden-cookie', $source['headers']['Set_Cookie']);
    }

    public function test_all_cookie_values_and_folded_credentials_are_removed_from_header_text(): void
    {
        $source = "Cookie: first=hidden-first; second=hidden-second\r\n"
            ."Proxy-Authorization: Basic hidden-proxy\r\n\thidden-continuation\r\n"
            ."Content-Type: text/plain\r\nPublic status 200";
        $redactor = new DeviceDebugRedactor;
        $clean = $redactor->clean($source);

        $this->assertStringNotContainsString('hidden-', $clean);
        $this->assertStringContainsString('Content-Type: text/plain', $clean);
        $this->assertStringContainsString('Public status 200', $clean);
        $this->assertSame($clean, $redactor->clean($clean));
    }
}
