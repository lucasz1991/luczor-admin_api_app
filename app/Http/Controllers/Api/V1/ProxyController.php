<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProxyChatRequest;
use App\Services\Proxy\ProxyChatService;
use Symfony\Component\HttpFoundation\Response;

/** Provider-key isolation, admin-owned model routing and full attempt telemetry. */
class ProxyController extends Controller
{
    public function chat(ProxyChatRequest $request, ProxyChatService $proxy): Response
    {
        return $proxy->handle($request, $request->toData());
    }
}
