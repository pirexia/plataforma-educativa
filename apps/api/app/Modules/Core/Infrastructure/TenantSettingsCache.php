<?php

namespace App\Modules\Core\Infrastructure;

use App\Modules\Core\Domain\Models\TenantSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * operacion.md §6: `tenant:{id}:settings`, TTL 10 min, invalidada en la
 * escritura (RN-CORE-17, no solo por vencimiento — lección del issue #7).
 * Cachea un array plano, nunca el modelo Eloquent (mismo motivo que
 * ResolveTenant: deserializar un objeto completo desde Redis es frágil).
 *
 * Valores por defecto de datos.md §A.1 cuando el tenant todavía no tiene
 * fila (antes de `tenant:provision-defaults`): nunca se lanza, se
 * devuelve el mismo valor por defecto que traería la migración.
 */
final class TenantSettingsCache
{
    /** @var array{default_locale: string, active_locales: list<string>, timezone: string, currency: string, session_timeout_minutes: int, autonomous_community: ?string, legal_name: ?string, tax_id: ?string, fiscal_address: ?string, fiscal_postal_code: ?string, fiscal_city: ?string, fiscal_province: ?string, fiscal_country_code: ?string, color_primary: ?string, color_secondary: ?string, logo_object_key: ?string, favicon_object_key: ?string, login_background_object_key: ?string} */
    private const DEFAULTS = [
        'default_locale' => 'es-ES',
        'active_locales' => ['es-ES'],
        'timezone' => 'Europe/Madrid',
        'currency' => 'EUR',
        // REQ-AUTH/datos.md §A.4. No se lee de config('auth-local...')
        // aquí: este valor por defecto solo aplica ANTES de que
        // tenant:provision-defaults exista una fila, y debe coincidir con
        // el DEFAULT de la columna (30), no con una variable de entorno
        // que podría cambiar sin tocar el esquema.
        'session_timeout_minutes' => 30,
        'autonomous_community' => null,
        'legal_name' => null,
        'tax_id' => null,
        'fiscal_address' => null,
        'fiscal_postal_code' => null,
        'fiscal_city' => null,
        'fiscal_province' => null,
        'fiscal_country_code' => 'ES',
        'color_primary' => null,
        'color_secondary' => null,
        'logo_object_key' => null,
        'favicon_object_key' => null,
        'login_background_object_key' => null,
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        if (! $this->tenantContext->hasTenant()) {
            return self::DEFAULTS;
        }

        return Cache::remember(
            "tenant:{$this->tenantContext->tenantId()}:settings",
            600,
            function (): array {
                $settings = TenantSetting::query()->first();

                if ($settings === null) {
                    return self::DEFAULTS;
                }

                return [
                    ...self::DEFAULTS,
                    ...$settings->only(array_keys(self::DEFAULTS)),
                ];
            },
        );
    }

    public function forget(): void
    {
        if (! $this->tenantContext->hasTenant()) {
            return;
        }

        Cache::forget("tenant:{$this->tenantContext->tenantId()}:settings");
    }
}
