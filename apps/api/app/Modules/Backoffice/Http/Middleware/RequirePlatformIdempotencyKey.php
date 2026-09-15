<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Domain\Models\PlatformIdempotencyKey;
use App\Support\Api\ApiException;
use App\Support\Api\ProblemResponseFactory;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `ADR-038 §8` (1.6c). Misma forma exacta que `App\Http\Middleware\
 * RequireIdempotencyKey` — mismo algoritmo, mismo índice único como
 * cerrojo de concurrencia, misma reproducción de la respuesta anterior—,
 * pero sobre `platform_idempotency_keys` (`App\Modules\Backoffice\
 * Domain\Models\PlatformIdempotencyKey`) en vez de la tabla de tenant:
 * `POST /module-rollouts` no tiene tenant activo (`RN-BO-01`), y
 * `IdempotencyKey`/`idempotency_keys` lo exigen (ver el docblock de la
 * migración de `platform_idempotency_keys` para el porqué exacto). No
 * vive en `App\Http\Middleware` porque, a diferencia de la primitiva de
 * tenant, ésta sólo tiene un consumidor posible: rutas de plataforma sin
 * contexto de tenant.
 */
class RequirePlatformIdempotencyKey
{
    public function handle(Request $request, Closure $next, string $endpoint): Response
    {
        $key = $request->header('Idempotency-Key');

        if ($key === null || $key === '') {
            throw ApiException::malformed('core.idempotency.missing');
        }

        if (! Str::isUlid($key)) {
            throw ApiException::malformed('core.idempotency.malformed');
        }

        $bodyHash = hash('sha256', $request->getContent());

        $existing = PlatformIdempotencyKey::query()
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $bodyHash);
        }

        return $this->processFirstAttempt($request, $next, $endpoint, $key, $bodyHash);
    }

    private function replay(PlatformIdempotencyKey $existing, string $bodyHash): Response
    {
        if ($existing->request_body_hash !== $bodyHash) {
            throw ApiException::conflict('core.idempotency.body_mismatch');
        }

        if ($existing->status === 'en_curso') {
            throw ApiException::conflict('core.idempotency.in_progress');
        }

        return response()->json($existing->response_body, (int) $existing->response_status, [
            'Idempotency-Replayed' => 'true',
        ]);
    }

    private function processFirstAttempt(Request $request, Closure $next, string $endpoint, string $key, string $bodyHash): Response
    {
        try {
            $record = PlatformIdempotencyKey::create([
                'endpoint' => $endpoint,
                'idempotency_key' => $key,
                'request_body_hash' => $bodyHash,
                'status' => 'en_curso',
                'expires_at' => now()->addHours(24),
            ]);
        } catch (QueryException) {
            throw ApiException::conflict('core.idempotency.in_progress');
        }

        try {
            $response = $next($request);
        } catch (ApiException $e) {
            $rendered = ProblemResponseFactory::render($e, $request);
            $this->store($record, $rendered);

            throw $e;
        } catch (Throwable $e) {
            $record->forceDelete();

            throw $e;
        }

        $this->store($record, $response);

        return $response;
    }

    private function store(PlatformIdempotencyKey $record, Response $response): void
    {
        $content = $response->getContent();

        $record->update([
            'status' => 'completado',
            'response_status' => $response->getStatusCode(),
            'response_body' => $content !== false && $content !== '' ? json_decode($content, true) : null,
        ]);
    }
}
