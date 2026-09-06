<?php

namespace Tests\Unit;

use App\Services\Speech\SharedSpeechException;
use App\Services\Speech\SharedSpeechTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SharedSpeechTransportTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        if (! extension_loaded('curl') || ! function_exists('proc_open')) {
            $this->markTestSkipped('The shared speech transport requires cURL and the test fixture requires proc_open.');
        }
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $this->url = 'http://'.$address;
        $this->server = proc_open([PHP_BINARY, '-S', $address, __DIR__.'/../Fixtures/shared-speech-router.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $this->pipes, null, null,
            ['bypass_shell' => true, 'create_process_group' => true]);
        $this->assertIsResource($this->server);
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = curl_init($this->url);
            curl_setopt_array($probe, [CURLOPT_CONNECT_ONLY => true, CURLOPT_TIMEOUT_MS => 50, CURLOPT_PROXY => '']);
            $connected = curl_exec($probe);
            curl_close($probe);
            if ($connected !== false) {

                return;
            }
            usleep(20000);
        }
        $this->fail('Synthetic loopback fixture did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($this->server);
        }
        parent::tearDown();
    }

    public function test_real_wire_contract_contains_exact_body_and_credentials(): void
    {
        $response = $this->send('luczor-test', ['text' => 'Guten Morgen, Grüße!', 'speed' => 1.2]);
        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['content_type']);
        $this->assertSame(30, $response['retry_after']);
        $wire = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('POST', $wire['method']);
        $this->assertSame('/v1/speech', $wire['path']);
        $this->assertSame('luczor-test', $wire['client_id']);
        $this->assertTrue($wire['token_ok']);
        $this->assertSame('test-request-id', $wire['request_id']);
        $this->assertSame(['text' => 'Guten Morgen, Grüße!', 'speed' => 1.2], $wire['payload']);
    }

    public function test_status_uses_authenticated_get_without_body(): void
    {
        $response = $this->send('luczor-test');
        $wire = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('GET', $wire['method']);
        $this->assertSame('/v1/status', $wire['path']);
        $this->assertTrue($wire['token_ok']);
        $this->assertNull($wire['payload']);
    }

    public function test_redirect_is_not_followed(): void
    {
        $this->assertSame(302, $this->send('redirect')['status']);
    }

    #[DataProvider('oversizedResponses')]
    public function test_bounds_apply_with_or_without_content_length(string $scenario): void
    {
        try {
            $this->send($scenario, maxBytes: 1024);
            $this->fail('Oversized upstream response must be interrupted.');
        } catch (SharedSpeechException $exception) {
            $this->assertSame('tts_invalid_audio', $exception->errorCode);
            $this->assertSame(502, $exception->httpStatus);
        }
    }

    public static function oversizedResponses(): array
    {
        return [['oversized-length'], ['oversized-body']];
    }

    public function test_total_timeout_covers_a_body_that_stalls_after_headers(): void
    {
        $start = microtime(true);
        try {
            $this->send('slow-body', timeout: 1);
            $this->fail('Body read must remain inside the request deadline.');
        } catch (SharedSpeechException $exception) {
            $this->assertSame('tts_timeout', $exception->errorCode);
            $this->assertSame(504, $exception->httpStatus);
            $this->assertLessThan(1.7, microtime(true) - $start);
        }
    }

    private function send(string $clientId, ?array $payload = null, int $timeout = 3, int $maxBytes = 65536): array
    {
        return (new SharedSpeechTransport)->send($this->url, 'synthetic-wire-token', $clientId,
            'test-request-id', $payload, 1, $timeout, $maxBytes);
    }
}
