<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Domain\ClientDeviceType;
use App\Modules\Auth\Domain\Models\UserSession;
use App\Modules\Auth\Domain\SessionEndReason;
use App\Modules\Auth\Domain\SessionRevoker;
use App\Modules\Auth\Http\Requests\DestroyUserSessionsRequest;
use App\Modules\Auth\Http\Requests\IndexUserSessionsRequest;
use App\Modules\Auth\Http\Resources\UserSessionResource;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * api.md §B.2-§B.4 (REQ-AUTH-005 puntos 2-3, 1.2b). Los tres endpoints son
 * autoservicio puro: sin permiso, autorizados por identidad del portador
 * de la cookie (`permisos.md §B.1`). El `user_id` del solicitante va en
 * el `WHERE` de cada consulta, no en un `if` posterior (`RN-AUTH-41`).
 */
class UserSessionsController extends Controller
{
    public function __construct(
        private readonly SessionRevoker $sessionRevoker,
    ) {}

    /**
     * §B.2. La única escritura de un GET en todo el módulo: cierra
     * perezosamente las filas cuya sesión ya no existe en `sessions`
     * (funcional.md §B.4.2 punto 3, CA-AUTH-084).
     */
    public function index(IndexUserSessionsRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser();

        $liveSessions = UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->get();

        $sessionRows = DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->whereIn('id', $liveSessions->pluck('session_id'))
            ->get(['id', 'last_activity'])
            ->keyBy('id');

        $currentSessionId = $request->session()->getId();

        // api.md §B.2: "device_known dice si esa sesión venía de un
        // dispositivo YA reconocido" — no basta con que la fila tenga
        // known_device_id (eso es cierto para TODAS las sesiones con
        // cookie admitida, incluida la que acaba de crear el dispositivo).
        // La sesión que dio de alta el dispositivo es, por construcción,
        // la de `id` más bajo entre las que comparten ese
        // known_device_id (funcional.md §B.4.5): esa, y solo esa, es la
        // que corresponde al aviso de dispositivo nuevo.
        $knownDeviceIds = $liveSessions->pluck('known_device_id')->filter()->unique()->values();

        $firstSessionIdByDevice = $knownDeviceIds->isEmpty()
            ? collect()
            : UserSession::query()
                ->whereIn('known_device_id', $knownDeviceIds)
                ->selectRaw('known_device_id, MIN(id) as first_session_id')
                ->groupBy('known_device_id')
                ->pluck('first_session_id', 'known_device_id');

        $rows = [];

        foreach ($liveSessions as $session) {
            $frameworkRow = $sessionRows->get($session->session_id);

            if ($frameworkRow === null) {
                $session->close(SessionEndReason::Caducidad);

                continue;
            }

            $isFirstSessionOnDevice = $session->known_device_id !== null
                && (int) $firstSessionIdByDevice->get($session->known_device_id) === $session->id;

            $rows[] = [
                'public_id' => $session->public_id,
                'current' => $session->session_id === $currentSessionId,
                'started_at' => $session->started_at,
                'last_activity_at' => Carbon::createFromTimestamp($frameworkRow->last_activity),
                'ip_address' => $session->ip_address,
                'client' => [
                    'browser' => $session->client_browser ?? 'desconocido',
                    'platform' => $session->client_platform ?? 'desconocido',
                    'device_type' => ($session->client_device_type ?? ClientDeviceType::Desconocido)->value,
                ],
                // RN-AUTH-47, OPEN-AUTH-13: siempre null en 1.2b.
                'location' => $session->location_label,
                'device_known' => $session->known_device_id !== null && ! $isFirstSessionOnDevice,
            ];
        }

        // Orden manual, no delegado a la base de datos: `last_activity_at`
        // combina dos orígenes (user_sessions y sessions del framework) y
        // el conjunto es de unidades por usuario, no una tabla de alto
        // crecimiento (api.md §B.2, "paginación por página").
        $sort = (string) $request->string('sort', '-last_activity_at');
        $direction = str_starts_with($sort, '-') ? -1 : 1;
        $field = ltrim($sort, '-');

        usort($rows, function (array $a, array $b) use ($field, $direction): int {
            $valueA = $a[$field] instanceof Carbon ? $a[$field]->timestamp : $a[$field];
            $valueB = $b[$field] instanceof Carbon ? $b[$field]->timestamp : $b[$field];

            return ($valueA <=> $valueB) * $direction;
        });

        $perPage = (int) $request->integer('per_page', 25);
        $page = (int) $request->integer('page', 1);
        $total = count($rows);
        $items = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return response()->json([
            'data' => UserSessionResource::collection(collect($items))->resolve(),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) max(1, ceil($total / max(1, $perPage))),
            ],
        ]);
    }

    /**
     * §B.3. `404` — nunca `403` — para una sesión inexistente, de otro
     * usuario del mismo tenant o de otro tenant: cuerpo idéntico en los
     * tres casos (`RN-AUTH-41`, `ADR-038 §6.4`, `CA-AUTH-087`).
     */
    public function destroy(Request $request, string $publicId): Response
    {
        $user = $this->authenticatedUser();

        $session = UserSession::query()
            ->where('public_id', $publicId)
            ->where('user_id', $user->id)
            ->first();

        if ($session === null) {
            throw ApiException::notFound();
        }

        if (! $session->isLive()) {
            throw ApiException::conflict('auth.validation.session_already_closed');
        }

        $isCurrent = $session->session_id === $request->session()->getId();

        $this->sessionRevoker->revokeSession($session, SessionEndReason::RevocadaUsuario, $user);

        // §B.4.3 punto 7: revocar la sesión actual equivale a un logout.
        if ($isCurrent) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    /**
     * §B.4. `scope=others` (por defecto) cierra todas salvo la actual;
     * `scope=all` las cierra todas, incluida la actual (RN-AUTH-43).
     */
    public function destroyAll(DestroyUserSessionsRequest $request): Response
    {
        $user = $this->authenticatedUser();
        $scope = (string) $request->string('scope', 'others');
        $currentSessionId = $request->session()->getId();

        $this->sessionRevoker->revokeAllForUser(
            $user,
            SessionEndReason::RevocadaUsuario,
            $scope === 'others' ? $currentSessionId : null,
        );

        if ($scope === 'all') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }
}
