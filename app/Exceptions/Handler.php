<?php

namespace App\Exceptions;

use App\Http\Middleware\AttachCorrelationId;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Include the correlation identifier in exception logs without recording
     * request bodies or other user data.
     *
     * @return array<string, mixed>
     */
    protected function context(): array
    {
        $context = parent::context();
        $correlationId = request()->attributes->get(AttachCorrelationId::ATTRIBUTE);

        if (is_string($correlationId) && $correlationId !== '') {
            $context['correlation_id'] = $correlationId;
        }

        return $context;
    }

    /**
     * Normal middleware responses receive this header in the middleware. An
     * exception is rendered outside the pipeline, so attach it here as well.
     */
    public function render($request, Throwable $e): Response
    {
        $response = parent::render($request, $e);

        $correlationId = $request->attributes->get(AttachCorrelationId::ATTRIBUTE);
        if (is_string($correlationId) && $correlationId !== '') {
            $response->headers->set(AttachCorrelationId::HEADER, $correlationId);
        }

        return $response;
    }
}
