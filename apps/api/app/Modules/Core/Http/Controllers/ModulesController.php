<?php

namespace App\Modules\Core\Http\Controllers;

use App\Models\Module;
use App\Models\ModuleSubscription;
use App\Support\Api\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * api.md §6 (`RMOD-008`). Solo lectura de `enabled` en 1.1 — la
 * contradicción `REQ-CORE-002`/`RMOD-002` (funcional.md §2, issue #44)
 * queda diferida a 1.6. Solo se puede editar `settings`.
 *
 * `RN-BO-82` (1.6c, `REQ-BO/datos.md §7.7`): las dos consultas de este
 * controlador proyectan explícitamente
 * `ModuleSubscription::TENANT_VISIBLE_COLUMNS` — un `SELECT *` implícito
 * ya no funciona bajo `plataforma_app` tras el `REVOKE SELECT` de esa
 * migración, y `reason` (motivo interno del proveedor) no debe llegar
 * aquí en ningún caso.
 */
class ModulesController extends Controller
{
    public function index(): JsonResponse
    {
        $modules = Module::query()->whereNull('retired_at')->orderBy('code')->get();
        $subscriptions = ModuleSubscription::query()
            ->select(ModuleSubscription::TENANT_VISIBLE_COLUMNS)
            ->get()
            ->keyBy('module_code');

        $data = $modules->map(function (Module $module) use ($subscriptions): array {
            /** @var ModuleSubscription|null $subscription */
            $subscription = $subscriptions->get($module->code);

            return [
                'public_id' => $subscription?->public_id,
                'module_code' => $module->code,
                'name' => __($module->name_key),
                'phase' => $module->phase,
                'enabled' => $subscription->enabled ?? false,
                'enabled_at' => $subscription?->enabled_at,
                'disabled_at' => $subscription?->disabled_at,
                'settings' => $subscription->settings ?? [],
            ];
        });

        return response()->json(['data' => $data->values()]);
    }

    public function updateSettings(Request $request, string $publicId): JsonResponse
    {
        if ($request->has('enabled')) {
            throw ApiException::validation([
                'enabled' => [[
                    'code' => 'core.validation.enabled_not_editable',
                    'message' => __('core.validation.enabled_not_editable'),
                    'params' => [],
                ]],
            ]);
        }

        $request->validate(['settings' => ['required', 'array']]);

        $subscription = ModuleSubscription::query()
            ->select(ModuleSubscription::TENANT_VISIBLE_COLUMNS)
            ->where('public_id', $publicId)
            ->firstOrFail();
        $subscription->update(['settings' => $request->input('settings')]);

        return response()->json([
            'public_id' => $subscription->public_id,
            'module_code' => $subscription->module_code,
            'settings' => $subscription->settings,
        ]);
    }
}
