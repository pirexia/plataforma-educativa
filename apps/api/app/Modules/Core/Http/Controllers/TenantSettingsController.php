<?php

namespace App\Modules\Core\Http\Controllers;

use App\Modules\Core\Application\ContrastRatioCalculator;
use App\Modules\Core\Domain\Events\TenantSettingsUpdated;
use App\Modules\Core\Domain\Models\TenantSetting;
use App\Modules\Core\Http\Requests\PatchTenantSettingsRequest;
use App\Modules\Core\Http\Resources\TenantSettingsResource;
use App\Modules\Core\Infrastructure\TenantSettingsCache;
use App\Support\Api\ValidationErrorBag;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Controller;

/**
 * api.md §2. `GET /tenant/settings`: `configuracion.leer`. La fila puede
 * no existir todavía si el tenant no se ha aprovisionado — en ese caso se
 * construye en memoria con los valores por defecto de datos.md §A.1, sin
 * crear la fila (crearla es cosa de `tenant:provision-defaults`, no de
 * una lectura).
 */
class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly ContrastRatioCalculator $contrast,
        private readonly TenantSettingsCache $cache,
    ) {}

    public function show(): TenantSettingsResource
    {
        return new TenantSettingsResource($this->currentOrDefault());
    }

    /**
     * funcional.md §4.1, RN-CORE-13/15/17. `$request->has()`, nunca
     * `filled()` (ADR-038 §9.2 regla 7): un grupo ausente no se toca; una
     * clave presente dentro de un grupo presente se aplica, el resto del
     * grupo se conserva (fusión en profundidad, regla 4).
     */
    public function update(PatchTenantSettingsRequest $request): TenantSettingsResource
    {
        $settings = $this->currentOrDefault();
        $errors = new ValidationErrorBag;

        $updates = [
            ...$this->regionalUpdates($request, $settings),
            ...$this->fiscalUpdates($request),
            ...$this->brandingUpdates($request, $settings, $errors),
        ];

        $this->validateLocaleConsistency($request, $settings, $updates, $errors);
        $errors->throwIfAny();

        $settings->fill($updates);
        $settings->save();

        $this->cache->forget();

        event(new TenantSettingsUpdated(app(TenantContext::class)->tenantId()));

        return new TenantSettingsResource($settings->refresh());
    }

    private function currentOrDefault(): TenantSetting
    {
        return TenantSetting::query()->first() ?? new TenantSetting([
            'default_locale' => 'es-ES',
            'active_locales' => ['es-ES'],
            'timezone' => 'Europe/Madrid',
            'currency' => 'EUR',
            'fiscal_country_code' => 'ES',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function regionalUpdates(PatchTenantSettingsRequest $request, TenantSetting $settings): array
    {
        $map = [
            'regional.default_locale' => 'default_locale',
            'regional.active_locales' => 'active_locales',
            'regional.timezone' => 'timezone',
            'regional.currency' => 'currency',
            'regional.autonomous_community' => 'autonomous_community',
        ];

        return $this->collectPresent($request, $map);
    }

    /**
     * @return array<string, mixed>
     */
    private function fiscalUpdates(PatchTenantSettingsRequest $request): array
    {
        $map = [
            'fiscal.legal_name' => 'legal_name',
            'fiscal.tax_id' => 'tax_id',
            'fiscal.address' => 'fiscal_address',
            'fiscal.postal_code' => 'fiscal_postal_code',
            'fiscal.city' => 'fiscal_city',
            'fiscal.province' => 'fiscal_province',
            'fiscal.country_code' => 'fiscal_country_code',
        ];

        return $this->collectPresent($request, $map);
    }

    /**
     * RN-CORE-15, CA-CORE-003: si cambia cualquiera de los dos colores,
     * se valida el contraste de la pareja resultante (la nueva si se
     * envía, la ya guardada si no).
     *
     * @return array<string, mixed>
     */
    private function brandingUpdates(PatchTenantSettingsRequest $request, TenantSetting $settings, ValidationErrorBag $errors): array
    {
        $map = [
            'branding.color_primary' => 'color_primary',
            'branding.color_secondary' => 'color_secondary',
        ];

        $updates = $this->collectPresent($request, $map);

        if ($updates === []) {
            return $updates;
        }

        $primary = $updates['color_primary'] ?? $settings->color_primary;
        $secondary = $updates['color_secondary'] ?? $settings->color_secondary;

        if ($primary !== null && $secondary !== null) {
            $ratio = $this->contrast->ratio($primary, $secondary);

            if ($ratio < ContrastRatioCalculator::REQUIRED_RATIO) {
                $errors->add('branding', 'core.validation.contrast_insufficient', 'core.validation.contrast_insufficient', [
                    'ratio' => round($ratio, 2),
                    'required' => ContrastRatioCalculator::REQUIRED_RATIO,
                ]);
            }
        }

        return $updates;
    }

    /**
     * RN-CORE-13: el idioma por defecto (nuevo o ya guardado) debe estar
     * entre los idiomas activos (nuevos o ya guardados) resultantes.
     *
     * @param  array<string, mixed>  $updates
     */
    private function validateLocaleConsistency(
        PatchTenantSettingsRequest $request,
        TenantSetting $settings,
        array $updates,
        ValidationErrorBag $errors,
    ): void {
        if (! $request->has('regional.default_locale') && ! $request->has('regional.active_locales')) {
            return;
        }

        $defaultLocale = $updates['default_locale'] ?? $settings->default_locale;
        $activeLocales = $updates['active_locales'] ?? $settings->active_locales;

        if (! in_array($defaultLocale, $activeLocales, true)) {
            $errors->add(
                'regional.default_locale',
                'core.validation.default_locale_not_active',
                'core.validation.default_locale_not_active',
            );
        }
    }

    /**
     * @param  array<string, string>  $dotToColumn
     * @return array<string, mixed>
     */
    private function collectPresent(PatchTenantSettingsRequest $request, array $dotToColumn): array
    {
        $updates = [];

        foreach ($dotToColumn as $dotKey => $column) {
            if ($request->has($dotKey)) {
                $updates[$column] = $request->input($dotKey);
            }
        }

        return $updates;
    }
}
