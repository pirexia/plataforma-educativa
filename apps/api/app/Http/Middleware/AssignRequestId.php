<?php

namespace App\Http\Middleware;

use App\Support\Http\RequestId;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * INV-013. Primero de los dos grupos globales (junto a ResolveTenant en
 * `web`), para que toda petición —incluida /api/health, que no resuelve
 * tenant— tenga request_id desde el primer log. ULID por consistencia con
 * ADR-029, no por necesidad de orden temporal: aquí solo hace falta que
 * sea único y corto.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) Str::ulid();

        app(RequestId::class)->set($id);
        Log::withContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
