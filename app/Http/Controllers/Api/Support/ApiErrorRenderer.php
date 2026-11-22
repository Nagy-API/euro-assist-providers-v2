<?php

namespace App\Http\Controllers\Api\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every exception raised under /api/* as the V2 error envelope:
 *
 *   { "error": { "code": "...", "message": "...", "details": { ... }? } }
 *
 * Messages are fixed strings: exception messages, class names, file paths and stack traces never
 * reach a client, whatever APP_DEBUG says. The original exception is still reported (logged)
 * by the framework.
 */
class ApiErrorRenderer
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        $headers = [];
        $details = null;

        switch (true) {
            case $e instanceof ValidationException:
                $status = 422;
                $code = 'validation_failed';
                $message = 'The given data was invalid.';
                $details = $e->errors();
                break;

            case $e instanceof AuthenticationException:
                $status = 401;
                $code = 'unauthenticated';
                $message = 'Authentication is required. Send a valid bearer token.';
                $headers['WWW-Authenticate'] = 'Bearer';
                break;

            case $e instanceof AuthorizationException:
            case $e instanceof AccessDeniedHttpException:
                $status = 403;
                $code = 'forbidden';
                $message = 'You are not allowed to perform this request.';
                break;

            case $e instanceof ModelNotFoundException:
            case $e instanceof NotFoundHttpException:
                $status = 404;
                $code = 'not_found';
                $message = 'The requested resource was not found.';
                break;

            case $e instanceof MethodNotAllowedHttpException:
                $status = 405;
                $code = 'method_not_allowed';
                $message = 'This endpoint is read-only; only GET is supported.';
                $headers = $this->passThrough($e, ['Allow']);
                break;

            case $e instanceof TooManyRequestsHttpException:
                $status = 429;
                $code = 'rate_limited';
                $message = 'Too many requests. Retry after the number of seconds in the Retry-After header.';
                $headers = $this->passThrough($e, ['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'X-RateLimit-Reset']);
                break;

            case $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500:
                $status = $e->getStatusCode();
                $code = 'bad_request';
                $message = 'The request could not be processed.';
                break;

            default:
                $status = 500;
                $code = 'server_error';
                $message = 'An unexpected error occurred.';
        }

        $error = ['code' => $code, 'message' => $message];
        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status, $headers);
    }

    /**
     * @param  list<string>  $allowed
     * @return array<string, string>
     */
    private function passThrough(HttpExceptionInterface $e, array $allowed): array
    {
        $out = [];
        foreach ($e->getHeaders() as $name => $value) {
            foreach ($allowed as $candidate) {
                if (strcasecmp($name, $candidate) === 0) {
                    $out[$candidate] = (string) (is_array($value) ? ($value[0] ?? '') : $value);
                }
            }
        }

        return $out;
    }
}
