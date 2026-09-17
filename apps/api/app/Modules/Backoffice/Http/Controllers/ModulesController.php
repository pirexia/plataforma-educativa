<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleSubscription;
use App\Modules\Backoffice\Application\ModuleSubscriptionsService;
use App\Modules\Backoffice\Application\PlatformReauthenticationCheck;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\StorePlatformModulePreviewRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformModuleRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformModuleRolloutPreviewRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformModuleRolloutRequest;
use App\Modules\Core\Domain\ModuleCatalog;
use App\Modules\Core\Domain\ModuleChange;
use App\Modules\Core\Domain\ModuleContractingOutcome;
use App\Support\Api\ApiException;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * `REQ-BO-002`, `ADR-045`, api.md §2.6, §2.7. Los cinco *endpoints* de
 * módulos del backoffice. La capacidad de cada ruta vive en `routes.php`;
 * este controlador comprueba únicamente lo que depende del cuerpo —la
 * reautenticación de `PUT .../modules/{code}` sólo con `enabled: false`
 * (`OPEN-BO-17`)— y delega toda la regla de negocio en
 * `ModuleSubscriptionsService`/`ModuleCatalog`.
 */
class ModulesController extends Controller
{
    public function __construct(
        private readonly ModuleSubscriptionsService $service,
        private readonly ModuleCatalog $catalog,
    ) {}

    /**
     * `GET /modules`, api.md §2.6.1. Sin paginar: son los módulos
     * declarados, leídos en proceso (`ADR-038 §4.2`).
     */
    public function index(): JsonResponse
    {
        $retiredAt = Module::query()->pluck('retired_at', 'code');

        $data = array_map(static fn ($descriptor): array => [
            'code' => $descriptor->code,
            'name_key' => $descriptor->nameKey,
            'phase' => $descriptor->phase,
            'essential' => $descriptor->essential,
            'depends_on' => $descriptor->dependsOn,
            'retired_at' => $retiredAt[$descriptor->code]?->toJSON(),
        ], $this->catalog->all());

        return response()->json(['data' => $data]);
    }

    /**
     * `GET /tenants/{public_id}/modules`, api.md §2.6.2. Los tres
     * estados de `ADR-045 §4.7`, con la incoherencia de dependencias
     * visible (`RN-BO-28`, `CA-BO-146`).
     */
    public function forTenant(string $publicId): JsonResponse
    {
        $tenant = $this->findTenant($publicId);

        // `RN-BO-22`: cierre de dependencias calculado una sola vez
        // (`ModuleSubscriptionsService::subscriptionsFor()`/
        // `missingDependenciesOf()`), reutilizado también por la ficha de
        // salud de `1.6d` (`api.md §2.10.1` punto 2).
        $subscriptions = $this->service->subscriptionsFor($tenant);

        $data = [];

        foreach ($this->catalog->all() as $descriptor) {
            /** @var ModuleSubscription|null $subscription */
            $subscription = $subscriptions->get($descriptor->code);
            $enabled = $subscription !== null && $subscription->enabled;

            $state = match (true) {
                $descriptor->essential => 'esencial',
                $enabled => 'contratado',
                default => 'no_contratado',
            };

            $missingDependencies = $this->service->missingDependenciesOf($descriptor, $subscriptions);

            $data[] = [
                'code' => $descriptor->code,
                'state' => $state,
                'enabled_at' => $subscription?->enabled_at?->toJSON(),
                'disabled_at' => $subscription?->disabled_at?->toJSON(),
                'reason' => $subscription?->reason,
                'depends_on' => $descriptor->dependsOn,
                'missing_dependencies' => $missingDependencies,
                'dependent_modules' => $this->catalog->dependentsOf($descriptor->code),
            ];
        }

        return response()->json(['data' => $data]);
    }

    /**
     * `POST /tenants/{public_id}/modules/preview`, api.md §2.7.
     */
    public function preview(StorePlatformModulePreviewRequest $request, string $publicId): JsonResponse
    {
        $tenant = $this->findTenant($publicId);
        $change = new ModuleChange(
            moduleCode: $request->string('module_code')->value(),
            enabled: $request->boolean('enabled'),
            reason: '',
            cascade: $request->boolean('cascade', false),
        );

        $preview = $this->service->preview($tenant, $change);

        return response()->json([
            'affected_tenants' => $preview->affectedTenants,
            'modules_to_contract' => $preview->modulesToContract,
            'modules_to_decontract' => $preview->modulesToDecontract,
            'cascaded_dependencies' => $preview->cascadedDependencies,
            'blocked' => $preview->blocked,
            'impact' => $preview->impact,
        ]);
    }

    /**
     * `PUT /tenants/{public_id}/modules/{module_code}`, api.md §2.6.3.
     * `OPEN-BO-17`: sensible sólo con `enabled: false`.
     */
    public function update(StorePlatformModuleRequest $request, string $publicId, string $moduleCode): JsonResponse
    {
        $enabled = $request->boolean('enabled');

        if (! $enabled) {
            PlatformReauthenticationCheck::ensureFresh($request);
        }

        $tenant = $this->findTenant($publicId);
        $change = new ModuleChange(
            moduleCode: $moduleCode,
            enabled: $enabled,
            reason: (string) $request->input('reason'),
            cascade: $request->boolean('cascade', false),
        );

        $result = $this->service->putModule($tenant, $change, $this->actor());

        return response()->json(['data' => $this->putResponse($tenant, $change, $result)]);
    }

    /**
     * `POST /module-rollouts/preview`, api.md §2.7.
     */
    public function rolloutsPreview(StorePlatformModuleRolloutPreviewRequest $request): JsonResponse
    {
        $tenants = $this->findTenants($request->input('tenant_public_ids'));

        $change = new ModuleChange(
            moduleCode: $request->string('module_code')->value(),
            enabled: $request->boolean('enabled'),
            reason: '',
            cascade: $request->boolean('cascade', false),
        );

        return response()->json($this->service->previewBulk($tenants, $change));
    }

    /**
     * `POST /module-rollouts`, api.md §2.6.4. `Idempotency-Key`
     * obligatoria y reautenticación las comprueba `routes.php`.
     */
    public function rollouts(StorePlatformModuleRolloutRequest $request): JsonResponse
    {
        $tenantPublicIds = $request->input('tenant_public_ids');
        $tenants = $this->findTenants($tenantPublicIds);

        $result = $this->service->requestBulkRollout(
            moduleCode: $request->string('module_code')->value(),
            enabled: $request->boolean('enabled'),
            reason: (string) $request->input('reason'),
            cascade: $request->boolean('cascade', false),
            tenantPublicIds: $tenantPublicIds,
            actor: $this->actor(),
        );

        if ($result['type'] === 'module_rollout') {
            return response()->json([
                'data' => ['type' => 'module_rollout', 'state' => 'en_cola', 'module_code' => $request->string('module_code')->value(), 'tenants' => $result['tenants']],
            ], 202);
        }

        $authorization = $result['authorization'];

        return response()->json([
            'data' => [
                'type' => 'dual_authorization',
                'public_id' => $authorization->public_id,
                'status' => $authorization->status->value,
                'action' => $authorization->action->value,
            ],
        ], 202);
    }

    /**
     * @param  array{outcome: ModuleContractingOutcome, cache_invalidated: bool}  $result
     * @return array<string, mixed>
     */
    private function putResponse(Tenant $tenant, ModuleChange $change, array $result): array
    {
        /** @var ModuleContractingOutcome $outcome */
        $outcome = $result['outcome'];

        $applied = array_map(static fn (array $item): array => [
            'code' => $item['module_code'],
            'enabled' => $item['enabled'],
            'cascaded' => $item['cascaded'],
            'enabled_at' => $item['enabled'] ? $item['occurred_at']->toJSON() : null,
            'disabled_at' => $item['enabled'] ? null : $item['occurred_at']->toJSON(),
        ], $outcome->applied);

        return [
            'tenant_public_id' => $tenant->public_id,
            'applied' => $applied,
            'unchanged' => $outcome->unchanged,
            'cache_invalidated' => $result['cache_invalidated'],
        ];
    }

    private function findTenant(string $publicId): Tenant
    {
        $tenant = Tenant::query()->withTrashed()->where('public_id', $publicId)->first();

        if ($tenant === null) {
            throw ApiException::notFound();
        }

        return $tenant;
    }

    /**
     * `RN-BO-81`: lista explícita — un `public_id` inexistente es `422`,
     * no un lote que sigue adelante con menos centros de los pedidos.
     *
     * @param  list<string>  $publicIds
     * @return list<Tenant>
     */
    private function findTenants(array $publicIds): array
    {
        $tenants = Tenant::withTrashed()->whereIn('public_id', $publicIds)->get();

        if ($tenants->count() !== count($publicIds)) {
            throw ApiException::validation([
                'tenant_public_ids' => [[
                    'code' => 'validation.exists',
                    'message' => __('validation.exists', ['attribute' => 'tenant_public_ids']),
                    'params' => [],
                ]],
            ]);
        }

        return $tenants->values()->all();
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
