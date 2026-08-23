<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AttachCorrelationId
{
    public const ATTRIBUTE = 'luczor_correlation_id';

    public const HEADER = 'X-Luczor-Correlation-Id';

    /**
     * Attach one safe identifier to the complete HTTP request lifecycle.
     *
     * Client-provided values are accepted only when they are UUIDs. This
     * prevents arbitrary data from being reflected into response headers and
     * log contexts.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $candidate = trim((string) $request->headers->get(self::HEADER, ''));
        $correlationId = Str::isUuid($candidate) ? strtolower($candidate) : (string) Str::uuid();

        $request->attributes->set(self::ATTRIBUTE, $correlationId);
        // Laravel's Context repository enriches logs and is dehydrated into
        // queued job payloads. This keeps one trace identifier across HTTP,
        // workflow jobs, queued broadcasts, and their worker logs.
        Context::add('correlation_id', $correlationId);

        try {
            $response = $next($request);
            $response->headers->set(self::HEADER, $correlationId);

            return $response;
        } finally {
            // Required for long-running PHP workers and for isolated tests.
            Context::forget('correlation_id');
        }
    }
}
