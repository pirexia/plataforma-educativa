<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Support\Api\ApiException;
use App\Support\Api\ProblemResponseFactory;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-038 §8: primitiva transversal de idempotencia — `datos.md §A.5`
 * insiste en que `idempotency_keys` no es de `REQ-CORE`, así que este
 * middleware vive en `app/Http/Middleware`, no en el módulo, para que
 * cualquier módulo futuro con una escritura idempotente lo reutilice
 * declarando `->middleware('idempotent:mi-modulo.mi-accion')` sin
 * importar nada de Core (`INV-007`).
 *
 * `$endpoint` es el identificador estable que exige ADR-038 §8.3 (p. ej.
 * `user-imports.execute`), no la ruta con parámetros.
 */
class RequireIdempotencyKey
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

        $existing = IdempotencyKey::query()
            ->where('endpoint', $endpoint)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            return $this->replay($existing, $bodyHash);
        }

        return $this->processFirstAttempt($request, $next, $endpoint, $key, $bodyHash);
    }

    private function replay(IdempotencyKey $existing, string $bodyHash): Response
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
            $record = IdempotencyKey::create([
                'endpoint' => $endpoint,
                'idempotency_key' => $key,
                'request_body_hash' => $bodyHash,
                'status' => 'en_curso',
                'expires_at' => now()->addHours(24),
            ]);
        } catch (QueryException $e) {
            // Cerrojo del índice único (ADR-038 §8.2): dos peticiones
            // concurrentes con la misma clave. La que pierde la carrera de
            // inserción recibe el mismo 409 que "en_curso".
            throw ApiException::conflict('core.idempotency.in_progress');
        }

        try {
            $response = $next($request);
        } catch (ApiException $e) {
            $rendered = ProblemResponseFactory::render($e, $request);
            $this->store($record, $rendered);

            throw $e;
        } catch (Throwable $e) {
            // Un fallo no controlado no es un resultado determinista que
            // deba reproducirse en el reintento: se libera la clave para
            // que una nueva petición pueda volver a intentarlo.
            $record->forceDelete();

            throw $e;
        }

        $this->store($record, $response);

        return $response;
    }

    private function store(IdempotencyKey $record, Response $response): void
    {
        $content = $response->getContent();

        $record->update([
            'status' => 'completado',
            'response_status' => $response->getStatusCode(),
            'response_body' => $content !== false && $content !== '' ? json_decode($content, true) : null,
        ]);
    }
}
