<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Application\MfaExemptionService;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Auth\Http\Requests\IndexMfaExemptionsRequest;
use App\Modules\Auth\Http\Requests\StoreMfaExemptionRequest;
use App\Modules\Auth\Http\Resources\MfaExemptionResource;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * api.md §D.4, funcional.md §D.4.6-§D.4.8. Los tres endpoints de
 * excepciones temporales nominales. Permisos `exencion_mfa.crear`,
 * `.leer`, `.eliminar` (`permisos.md §D.3`), declarados en
 * `AuthServiceProvider` y aplicados por el middleware `permission:` en
 * `routes.php` (`INV-002`, denegar por defecto).
 */
class MfaExemptionsController extends Controller
{
    public function __construct(
        private readonly MfaExemptionService $service,
    ) {}

    public function store(StoreMfaExemptionRequest $request): JsonResponse
    {
        $actor = $this->currentUser();

        // RN-AUTH-07: el sujeto se resuelve dentro del tenant activo —
        // TenantModel ya acota la consulta (RLS + predicado explícito).
        // Inexistente o de otro tenant ⇒ 404, nunca 403 (ADR-038 §6.4).
        $target = User::query()->where('public_id', $request->string('user')->value())->first();

        if ($target === null) {
            throw ApiException::notFound();
        }

        $exemption = $this->service->grant(
            $actor,
            $target,
            $request->string('reason')->value(),
            Carbon::parse($request->string('expires_at')->value()),
        );

        $exemption->load(['user.person', 'grantedByUser.person', 'revokedByUser.person']);

        return (new MfaExemptionResource($exemption))
            ->response()
            ->setStatusCode(201);
    }

    public function index(IndexMfaExemptionsRequest $request): JsonResponse
    {
        $query = UserMfaExemption::query()->with(['user.person', 'grantedByUser.person', 'revokedByUser.person']);

        if ($request->filled('user')) {
            $target = User::query()->where('public_id', $request->string('user')->value())->first();

            // api.md §D.4: filtrar por un usuario inexistente o de otro
            // tenant no es un 404 (no es EL recurso, es un filtro sobre
            // una colección) — simplemente no devuelve nada.
            $query->where('user_id', $target === null ? 0 : $target->id);
        }

        if ($request->filled('state')) {
            $this->applyStateFilter($query, explode(',', $request->string('state')->value()));
        }

        // api.md §D.4: las vivas primero, y dentro de cada grupo por
        // fecha de concesión descendente.
        $query->orderByRaw('(revoked_at IS NULL AND expires_at > ?) DESC', [now()->toDateTimeString()])
            ->orderByDesc('created_at');

        $paginator = $query->paginate($request->integer('per_page', 25))->withQueryString();

        return PagePaginatedResponse::make($paginator, MfaExemptionResource::class);
    }

    public function destroy(string $publicId): Response
    {
        $actor = $this->currentUser();

        $exemption = UserMfaExemption::query()
            ->where('public_id', $publicId)
            ->whereNull('revoked_at')
            ->first();

        if ($exemption === null) {
            throw ApiException::notFound();
        }

        $this->service->revoke($actor, $exemption);

        return response()->noContent();
    }

    private function currentUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw ApiException::unauthenticated();
        }

        return $user;
    }

    /**
     * @param  Builder<UserMfaExemption>  $query
     * @param  list<string>  $states
     */
    private function applyStateFilter($query, array $states): void
    {
        $query->where(function ($q) use ($states): void {
            if (in_array('live', $states, true)) {
                $q->orWhere(function ($q2): void {
                    $q2->whereNull('revoked_at')->where('expires_at', '>', now());
                });
            }

            if (in_array('expired', $states, true)) {
                $q->orWhere(function ($q2): void {
                    $q2->whereNull('revoked_at')->where('expires_at', '<=', now());
                });
            }

            if (in_array('revoked', $states, true)) {
                $q->orWhereNotNull('revoked_at');
            }
        });
    }
}
