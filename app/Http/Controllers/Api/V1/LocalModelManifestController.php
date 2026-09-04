<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\LocalModelManifestConfigurationException;
use App\Http\Controllers\Controller;
use App\Services\LocalModelManifestService;
use Illuminate\Http\JsonResponse;

final class LocalModelManifestController extends Controller
{
    public function __invoke(LocalModelManifestService $manifest): JsonResponse
    {
        try {
            return response()->json(
                $manifest->envelope(),
                200,
                [],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            )
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Content-Type-Options', 'nosniff');
        } catch (LocalModelManifestConfigurationException $exception) {
            return response()->json([
                'message' => 'Local-model policy is currently unavailable.',
                'code' => $exception->reasonCode,
            ], 503)->header('Cache-Control', 'no-store');
        }
    }
}
