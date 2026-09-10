<?php

namespace App\Modules\Backoffice\Http\Middleware;

use App\Modules\Backoffice\Application\AdminActionLogRecorder;
use App\Modules\Backoffice\Domain\AdminActionLogAction;
use App\Support\Api\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-BO-06, RN-BO-07. Sin dirección de origen contenida en ninguna
 * entrada activa de la lista blanca: 403, auditado, antes de comprobar
 * credenciales. Lista vacía = denegar a todos — nunca "vacía = permitir".
 *
 * La contención la verifica el motor con el operador `>>=` de PostgreSQL
 * sobre el tipo `cidr` (datos.md §2.5), no un análisis de máscara en PHP:
 * "se elige el tipo que hace imposible el dato inválido".
 *
 * Segunda barrera de red, después de `RequirePlatformHost` y antes de
 * cookies/sesión: Traefik aplica su propio `ipallowlist` sobre el
 * *ingress* (operacion.md §0.3); ninguna de las dos capas sustituye a la
 * otra.
 */
class EnforcePlatformIpAllowlist
{
    public function __construct(
        private readonly AdminActionLogRecorder $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $ip = (string) $request->ip();

        $allowed = DB::connection('pgsql_platform')->selectOne(
            'SELECT EXISTS (SELECT 1 FROM platform_ip_allowlist '.
            'WHERE enabled = true AND deleted_at IS NULL AND cidr >>= ?::inet) AS allowed',
            [$ip]
        )->allowed;

        if (! $allowed) {
            $this->recorder->record(
                action: AdminActionLogAction::AccesoRechazadoPorIp,
                context: ['ip' => $ip],
            );

            throw ApiException::ipNotAllowed();
        }

        return $next($request);
    }
}
