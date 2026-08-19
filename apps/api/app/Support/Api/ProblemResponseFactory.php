<?php

namespace App\Support\Api;

use App\Support\Http\RequestId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * ADR-038 §6: único punto que construye una respuesta `application/
 * problem+json`. Ningún controlador ni middleware construye el cuerpo de
 * error a mano — todos lanzan `ApiException` o dejan que una excepción de
 * Laravel/Symfony llegue aquí, y este factory la normaliza.
 */
final class ProblemResponseFactory
{
    /**
     * Mapa de código de estado HTTP genérico -> slug del catálogo de §6.2,
     * para excepciones que no son ApiException (abort($status), 404 de
     * enrutado, etc.).
     *
     * @var array<int, string>
     */
    private const STATUS_TO_SLUG = [
        400 => 'malformed',
        401 => 'unauthenticated',
        403 => 'forbidden',
        404 => 'not-found',
        405 => 'method-not-allowed',
        409 => 'conflict',
        410 => 'gone',
        413 => 'payload-too-large',
        415 => 'unsupported-media-type',
        422 => 'validation',
        429 => 'too-many-requests',
        503 => 'unavailable',
    ];

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $type, $detail, $errors, $headers] = self::classify($e);

        $requestId = app(RequestId::class)->current();

        $body = array_filter([
            'type' => "urn:pge:error:{$type}",
            'title' => __("errors.title.{$type}"),
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->path() === '/' ? '/' : '/'.ltrim($request->path(), '/'),
            'request_id' => $requestId,
            'errors' => $errors === [] ? null : $errors,
        ], static fn (mixed $value): bool => $value !== null);

        return response()->json($body, $status, array_merge([
            'Content-Type' => 'application/problem+json',
        ], $headers));
    }

    /**
     * @return array{0: int, 1: string, 2: ?string, 3: array<string, mixed>, 4: array<string, string>}
     */
    private static function classify(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            $detail = self::resolveDetail($e->type, $e->detailKey, $e->detailParams);

            return [$e->status, $e->type, $detail, $e->errors, $e->headers];
        }

        if ($e instanceof ValidationException) {
            $errors = ValidationErrorFormatter::fromValidator($e->validator);

            return [422, 'validation', self::resolveDetail('validation', null, []), $errors, []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', self::resolveDetail('unauthenticated', null, []), [], []];
        }

        if ($e instanceof AuthorizationException) {
            return [403, 'forbidden', self::resolveDetail('forbidden', null, []), [], []];
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return [404, 'not-found', self::resolveDetail('not-found', null, []), [], []];
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return [405, 'method-not-allowed', self::resolveDetail('method-not-allowed', null, []), [], []];
        }

        if ($e instanceof TooManyRequestsHttpException) {
            $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;

            return [429, 'too-many-requests', null, [], ['Retry-After' => (string) $retryAfter]];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $type = self::STATUS_TO_SLUG[$status] ?? 'internal';
            // abort($status, __('...')) ya trae el mensaje traducido del
            // llamador (p. ej. ResolveTenant con 'tenancy.suspended'); un
            // 5xx nunca expone su mensaje (§6.5).
            $detail = ($status < 500 && $e->getMessage() !== '') ? $e->getMessage() : self::resolveDetail($type, null, []);

            return [$status, $type, $detail, [], $e->getHeaders()];
        }

        // §6.5: un 5xx nunca lleva detail con información interna. La causa
        // real vive en el log, correlacionada por request_id (INV-013).
        Log::error('Excepción no controlada en la API', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'request_id' => app(RequestId::class)->current(),
        ]);

        return [500, 'internal', self::resolveDetail('internal', null, []), [], []];
    }

    private static function resolveDetail(string $type, ?string $detailKey, array $detailParams): ?string
    {
        if ($detailKey !== null) {
            return __($detailKey, $detailParams);
        }

        $fallbackKey = "errors.detail.{$type}";

        return __($fallbackKey) === $fallbackKey ? null : __($fallbackKey);
    }
}
