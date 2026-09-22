<?php

namespace App\Modules\Backoffice\Http\Controllers;

use App\Modules\Backoffice\Application\FeatureFlagsService;
use App\Modules\Backoffice\Application\PlatformReauthenticationCheck;
use App\Modules\Backoffice\Domain\Models\PlatformAdmin;
use App\Modules\Backoffice\Http\Requests\StorePlatformFeatureFlagRulesPreviewRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformFeatureFlagRulesRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformFeatureFlagStateRequest;
use App\Modules\Backoffice\Http\Requests\StorePlatformTenantEarlyAdopterRequest;
use App\Support\Api\ApiException;
use App\Support\FeatureFlags\FeatureFlagRolloutUnit;
use App\Support\FeatureFlags\FeatureFlagRuleInput;
use App\Support\FeatureFlags\FeatureFlagRuleSet;
use App\Support\FeatureFlags\FeatureFlagScopeType;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

/**
 * `REQ-BO-005` puntos 1-2, sub-paso `1.6e`, api.md §2.11-§2.14. Los siete
 * *endpoints* de *flags* más la designación de *early adopter*. Nunca
 * lee ni escribe `feature_flags`/`feature_flag_rules` directamente
 * (`RN-BO-99`, `CA-BO-169`): todo pasa por `FeatureFlagsService`, que
 * delega en `App\Modules\Core\Domain\FeatureFlagAdministration`.
 */
class FeatureFlagsController extends Controller
{
    public function __construct(
        private readonly FeatureFlagsService $service,
    ) {}

    /**
     * `GET /feature-flags`, api.md §2.11.
     */
    public function index(Request $request): JsonResponse
    {
        $retired = match ($request->input('retired')) {
            'true' => true,
            'false' => false,
            default => null,
        };

        $paginator = $this->service->paginate(
            moduleCode: $request->filled('module_code') ? $request->string('module_code')->value() : null,
            status: $request->filled('status') ? $request->string('status')->value() : null,
            retired: $retired,
            q: $request->filled('q') ? $request->string('q')->value() : null,
            perPage: $request->integer('per_page', 20),
            page: $request->integer('page', 1),
        );

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * `GET /feature-flags/{key}`.
     */
    public function show(string $key): JsonResponse
    {
        return response()->json(['data' => $this->service->find($key)]);
    }

    /**
     * `POST /feature-flags/{key}/rules/preview`, `RN-BO-68`.
     */
    public function rulesPreview(StorePlatformFeatureFlagRulesPreviewRequest $request, string $key): JsonResponse
    {
        $ruleSet = $this->buildRuleSet($key, (array) $request->input('rules', []));

        return response()->json($this->service->previewRules($key, $ruleSet));
    }

    /**
     * `PUT /feature-flags/{key}/state`, api.md §2.12: sensible sólo
     * cuando el destino es `activo` — apagar es el freno de emergencia y
     * va sin fricción.
     */
    public function updateState(StorePlatformFeatureFlagStateRequest $request, string $key): JsonResponse
    {
        $status = $request->string('status')->value();

        if ($status === 'activo') {
            PlatformReauthenticationCheck::ensureFresh($request);
        }

        $result = $this->service->setState($key, $status, (string) $request->input('reason'), $this->actor());

        return response()->json(['data' => $result]);
    }

    /**
     * `PUT /feature-flags/{key}/rules`, `RN-BO-104`: siempre sensible
     * (`api.md §2.12`), comprobado ya por `routes.php`.
     */
    public function updateRules(StorePlatformFeatureFlagRulesRequest $request, string $key): JsonResponse
    {
        $ruleSet = $this->buildRuleSet($key, (array) $request->input('rules', []));

        $result = $this->service->replaceRules($key, $ruleSet, (string) $request->input('reason'), $this->actor());

        return response()->json(['data' => $result]);
    }

    /**
     * `GET /tenants/{public_id}/feature-flags`, api.md §2.13.
     */
    public function forTenant(string $publicId): JsonResponse
    {
        return response()->json(['data' => $this->service->tenantFlags($this->findTenant($publicId))]);
    }

    /**
     * `PUT /tenants/{public_id}/early-adopter`, `RN-BO-46`. No sensible
     * (api.md §4): reversible con otra llamada y no expone nada por sí
     * misma (`RN-BO-109`).
     */
    public function updateEarlyAdopter(StorePlatformTenantEarlyAdopterRequest $request, string $publicId): JsonResponse
    {
        $tenant = $this->findTenant($publicId);

        $tenant = $this->service->setEarlyAdopter(
            $tenant,
            $request->boolean('early_adopter'),
            (string) $request->input('reason'),
            $this->actor(),
        );

        return response()->json(['data' => [
            'tenant_public_id' => $tenant->public_id,
            'early_adopter_since' => $tenant->early_adopter_since?->toJSON(),
        ]]);
    }

    /**
     * `api.md §2.11.1`: resuelve `tenant_public_id` a la clave interna
     * (`Tenant` es modelo compartido, `App\Support\Tenancy`, no interno
     * de ningún módulo) y compone el conjunto propuesto con la unidad de
     * reparto y el módulo dueño ya materializados — nunca los inventa el
     * cliente (`RN-BO-36`).
     *
     * @param  list<array<string, mixed>>  $rawRules
     */
    private function buildRuleSet(string $key, array $rawRules): FeatureFlagRuleSet
    {
        $flag = $this->service->find($key);

        $tenantPublicIds = array_values(array_unique(array_filter(array_map(
            static fn (array $rule): ?string => $rule['tenant_public_id'] ?? null,
            $rawRules,
        ))));

        $tenantIds = $tenantPublicIds === []
            ? []
            : Tenant::withTrashed()->whereIn('public_id', $tenantPublicIds)->pluck('id', 'public_id')->all();

        $rules = array_map(
            static function (array $raw) use ($tenantIds): FeatureFlagRuleInput {
                $tenantPublicId = $raw['tenant_public_id'] ?? null;

                return new FeatureFlagRuleInput(
                    scopeType: FeatureFlagScopeType::from($raw['scope_type']),
                    enabled: $raw['enabled'] ?? true,
                    tenantId: $tenantPublicId !== null ? ($tenantIds[$tenantPublicId] ?? null) : null,
                    tenantPublicId: $tenantPublicId,
                    roleCode: $raw['role_code'] ?? null,
                    percentage: isset($raw['percentage']) ? (int) $raw['percentage'] : null,
                );
            },
            $rawRules,
        );

        return new FeatureFlagRuleSet(
            flagKey: $key,
            rolloutUnit: FeatureFlagRolloutUnit::from($flag['rollout_unit']),
            rules: $rules,
            moduleCode: $flag['module_code'],
        );
    }

    private function findTenant(string $publicId): Tenant
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
