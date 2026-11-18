<?php

namespace App\Platform\Api\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Phase 8B — renders every API exception in the single error envelope
 * ({@see ApiResponse::error()}), so the mobile client never receives Laravel's
 * default HTML or a shape that differs from the documented contract.
 *
 * Only requests under `api/*` are handled; everything else is left to the
 * framework's normal handling.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($exception instanceof ValidationException) {
            return ApiResponse::error('validation_error', $exception->getMessage(), 422, $exception->errors());
        }

        if ($exception instanceof AuthenticationException) {
            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        if ($exception instanceof AuthorizationException) {
            return ApiResponse::error('forbidden', 'This action is unauthorized.', 403);
        }

        if ($exception instanceof ModelNotFoundException) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        }

        if ($exception instanceof NotFoundHttpException) {
            return ApiResponse::error('not_found', 'The requested resource was not found.', 404);
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return ApiResponse::error(
                $this->codeForStatus($status),
                $exception->getMessage() !== '' ? $exception->getMessage() : $this->messageForStatus($status),
                $status,
            );
        }

        return ApiResponse::error('server_error', 'An unexpected error occurred.', 500);
    }

    private function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'validation_error',
            429 => 'too_many_requests',
            default => 'http_error',
        };
    }

    private function messageForStatus(int $status): string
    {
        return match ($status) {
            401 => 'Authentication is required.',
            403 => 'This action is unauthorized.',
            404 => 'The requested resource was not found.',
            405 => 'The HTTP method is not allowed for this route.',
            409 => 'The request conflicts with the current state.',
            429 => 'Too many requests.',
            default => 'The request could not be completed.',
        };
    }
}
