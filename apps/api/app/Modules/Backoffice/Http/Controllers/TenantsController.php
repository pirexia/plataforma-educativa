<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Models\ModuleSubscription;
use App\Modules\Backoffice\Application\PlatformReauthenticationCheck;
use App\Modules\Backoffice\Application\TenantLifecycleService;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Domain\Models\TenantLifecycleEvent;
use App\Modules\Backoffice\Http\Requests\StoreTenantCloneRequest;
use App\Modules\Backoffice\Http\Requests\StoreTenantRequest;
use App\Modules\Backoffice\Http\Requests\StoreTenantSlugRequest;
use App\Modules\Backoffice\Http\Requests\StoreTenantTransitionRequest;
use App\Modules\Backoffice\Http\Requests\UpdateTenantRequest;
use App\Modules\Backoffice\Http\Resources\DualAuthorizationResource;
use App\Modules\Backoffice\Http\Resources\TenantCloneResource;
use App\Modules\Backoffice\Http\Resources\TenantLifecycleEventResource;
use App\Modules\Backoffice\Http\Resources\TenantResource;
use App\Modules\Core\Domain\TenantAdministrator;
use App\Modules\Core\Domain\TenantInitialSettings;
use App\Support\Api\ApiException;
use App\Support\Api\PagePaginatedResponse;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * REQ-BO-001, api.md §2.4, §2.4.1, §2.4.2, §2.4.3, §2.5. Inventario y
 * ciclo de vida de tenants. `POST /tenants/{id}/transitions` es la única
 * ruta de este módulo cuya capacidad **depende del cuerpo** —según la
 * transición pedida (permisos.md §4.3)—, por lo que el middleware de ruta
 * solo exige `tenant.leer` como línea de base y el servicio comprueba la
 * capacidad exacta antes de ejecutar nada.
 */
class TenantsController extends Controller
{
    public function __construct(
        private readonly TenantLifecycleService $lifecycle,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // withTrashed(): el inventario del backoffice ve el centro
        // entero, incluidos los `eliminado` (borrado lógico, RN-BO-18) —
        // filtrar por `status=eliminado` sobre la consulta con el scope
        // por defecto (que los excluye) devolvería siempre cero filas.
        $query = Tenant::withTrashed();

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->string('status')));
        }

        if ($request->filled('q')) {
            $q = '%'.$request->string('q')->value().'%';
            $query->where(fn ($w) => $w->where('name', 'ilike', $q)->orWhere('slug', 'ilike', $q));
        }

        if ($request->boolean('grace_expired', false) || $request->input('grace_expired') === 'true') {
            $query->whereNotNull('grace_period_expired_at');
        } elseif ($request->input('grace_expired') === 'false') {
            $query->whereNull('grace_period_expired_at');
        }

        if ($request->filled('provisioning')) {
            $query->where('status', TenantStatus::EnAlta);
        }

        if ($request->filled('module_code')) {
            $tenantIds = $this->tenantContext->runAsPlatform(
                PlatformAccessPurpose::BackofficeLectura,
                fn () => ModuleSubscription::query()
                    ->where('module_code', $request->string('module_code')->value())
                    ->where('enabled', true)
                    ->pluck('tenant_id'),
            );

            $query->whereIn('id', $tenantIds);
        }

        // `autonomous_community` (api.md §3.3) NO se implementa en
        // 1.6b, a propósito, y se deja dicho en vez de callarlo: la
        // comunidad autónoma vive en `tenant_settings`
        // (`App\Modules\Core\Domain\Models\TenantSetting`), tabla
        // interna de `REQ-CORE`, y la única interfaz pública que ese
        // módulo expone (`TenantSettingsReader`) está pensada para leer
        // el tenant **del contexto actual**, no para filtrar entre
        // todos los tenants — no hay ningún camino que respete `INV-007`
        // para esta consulta concreta hoy. Añadir uno es ampliar la
        // superficie pública de `REQ-CORE` fuera de lo que `ADR-048 §10`
        // autorizó para este sub-paso, y por tanto no se decide aquí
        // (`CLAUDE.md §11`). El parámetro, si llega, se ignora sin
        // error (`ADR-038 §5.2`: "parámetro desconocido ignorado").

        $sort = (string) $request->input('sort', '-created_at');
        $column = ltrim($sort, '-');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';

        if (! in_array($column, ['name', 'created_at', 'status'], true)) {
            $column = 'created_at';
            $direction = 'desc';
        }

        $paginator = $query->orderBy($column, $direction)->paginate($request->integer('per_page', 20));

        return PagePaginatedResponse::make($paginator, TenantResource::class);
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $settings = new TenantInitialSettings(
            defaultLocale: $request->string('settings.default_locale')->value(),
            activeLocales: $request->input('settings.active_locales'),
            timezone: $request->string('settings.timezone')->value(),
            currency: $request->string('settings.currency')->value(),
            autonomousCommunity: $request->string('settings.autonomous_community')->value(),
        );

        $administrator = new TenantAdministrator(
            $request->string('administrator.email')->value(),
            $request->string('administrator.given_name')->value(),
            $request->string('administrator.family_name')->value(),
        );

        $tenant = $this->lifecycle->create(
            $request->string('name')->value(),
            $request->string('slug')->value(),
            $request->string('reason')->value(),
            $settings,
            $administrator,
            $this->actor(),
        );

        return response()->json(new TenantResource($tenant), 201);
    }

    public function show(string $publicId): JsonResponse
    {
        return response()->json(new TenantResource($this->find($publicId)));
    }

    public function update(UpdateTenantRequest $request, string $publicId): JsonResponse
    {
        $attributes = $request->only(['name', 'suspension_message']);

        $tenant = $this->lifecycle->update($this->find($publicId), $attributes);

        return response()->json(new TenantResource($tenant));
    }

    public function transitions(StoreTenantTransitionRequest $request, string $publicId): JsonResponse
    {
        $to = TenantStatus::from($request->string('to_status')->value());

        if (in_array($to, [TenantStatus::EnBaja, TenantStatus::Eliminado], true)) {
            PlatformReauthenticationCheck::ensureFresh($request);
        }

        $result = $this->lifecycle->transition(
            $this->find($publicId),
            $to,
            $request->string('reason')->value(),
            $request->input('suspension_message'),
            $request->input('confirmation_name'),
            $this->actor(),
        );

        if ($result->dualAuthorization !== null) {
            return response()->json([
                'data' => (new DualAuthorizationResource($result->dualAuthorization))->resolve(),
            ], 202);
        }

        return response()->json(new TenantResource($result->tenant));
    }

    public function lifecycleEvents(Request $request, string $publicId): JsonResponse
    {
        $tenant = $this->find($publicId);

        $paginator = TenantLifecycleEvent::query()
            ->where('affected_tenant_id', $tenant->id)
            ->with(['performer', 'dualAuthorization'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return PagePaginatedResponse::make($paginator, TenantLifecycleEventResource::class);
    }

    public function clone(StoreTenantCloneRequest $request, string $publicId): JsonResponse
    {
        $source = $this->find($publicId);

        $administrator = new TenantAdministrator(
            $request->string('administrator.email')->value(),
            $request->string('administrator.given_name')->value(),
            $request->string('administrator.family_name')->value(),
        );

        $target = $this->lifecycle->clone(
            $source,
            $request->string('name')->value(),
            $request->string('slug')->value(),
            $request->string('reason')->value(),
            $administrator,
            $this->actor(),
        );

        return response()->json(new TenantCloneResource($target, $source->public_id), 201);
    }

    public function updateSlug(StoreTenantSlugRequest $request, string $publicId): JsonResponse
    {
        $tenant = $this->lifecycle->changeSlug(
            $this->find($publicId),
            $request->string('slug')->value(),
            $request->string('reason')->value(),
        );

        return response()->json(new TenantResource($tenant));
    }

    private function find(string $publicId): Tenant
    {
        $tenant = Tenant::query()->withTrashed()->where('public_id', $publicId)->first();

        if ($tenant === null) {
            throw ApiException::notFound();
        }

        return $tenant;
    }

    private function actor(): PlatformAdmin
    {
        $admin = Auth::guard('platform')->user();

        if (! $admin instanceof PlatformAdmin) {
            throw ApiException::unauthenticated();
        }

        return $admin;
    }
}
