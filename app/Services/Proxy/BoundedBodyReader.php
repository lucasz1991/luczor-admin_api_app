<?php

namespace App\Services\Proxy;

use App\Data\Proxy\ProxyBodyReadResult;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class BoundedBodyReader
{
    public function read(StreamInterface $body, int $limitBytes): ProxyBodyReadResult
    {
        $limitBytes = max(1, $limitBytes);
        $contents = '';
        $hash = hash_init('sha256');

        while (true) {
            $remaining = $limitBytes - strlen($contents);
            try {
                $chunk = $body->read(min(8192, $remaining + 1));
            } catch (Throwable) {
                $body->close();

                return new ProxyBodyReadResult($contents, false, true, null);
            }
            if ($chunk === '') {
                $atEof = $body->eof();
                $body->close();

                return $atEof
                    ? new ProxyBodyReadResult($contents, false, false, hash_final($hash))
                    : new ProxyBodyReadResult($contents, false, true, null);
            }

            if (strlen($chunk) > $remaining) {
                if ($remaining > 0) {
                    $contents .= substr($chunk, 0, $remaining);
                }
                $body->close();

                return new ProxyBodyReadResult($contents, true, false, null);
            }

            $contents .= $chunk;
            hash_update($hash, $chunk);
            if ($body->eof()) {
                break;
            }
        }

        $body->close();

        return new ProxyBodyReadResult($contents, false, false, hash_final($hash));
    }
}
