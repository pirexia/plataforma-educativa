<?php

namespace App\Modules\Core\Domain;

use DateTimeZone;
use InvalidArgumentException;

/**
 * ADR-048 §4.2. Objeto de valor de la superficie pública de
 * aprovisionamiento: los ajustes iniciales de `tenant_settings` que hoy
 * `ProvisionTenantDefaults` no recibe (funcional.md §5.3.1). Un objeto de
 * valor y no cinco parámetros sueltos porque la firma va a crecer
 * (dominio propio, plan, etapas, régimen — funcional.md §5.3.1) y con un
 * objeto cada llegada es una propiedad nueva y un cambio compatible
 * (ADR-038 §7.2 aplicado a un contrato de código).
 *
 * Valida **coherencia**, no la obligatoriedad de cada campo: eso es del
 * `FormRequest` de `REQ-BO` (ADR-048 §4.7). Llegar aquí con un valor
 * incoherente es un defecto de programación del llamador, no un error de
 * operador, y por eso lanza `InvalidArgumentException` sin clave de
 * traducción.
 */
final readonly class TenantInitialSettings
{
    /**
     * @param  list<string>  $activeLocales
     */
    public function __construct(
        public string $defaultLocale,
        public array $activeLocales,
        public string $timezone,
        public string $currency,
        public ?string $autonomousCommunity = null,
    ) {
        if ($this->activeLocales === []) {
            throw new InvalidArgumentException('active_locales no puede estar vacío.');
        }

        foreach ($this->activeLocales as $locale) {
            if (! in_array($locale, self::SUPPORTED_LOCALES, true)) {
                throw new InvalidArgumentException("Idioma no soportado: {$locale}.");
            }
        }

        if (! in_array($this->defaultLocale, $this->activeLocales, true)) {
            throw new InvalidArgumentException('default_locale debe estar contenido en active_locales.');
        }

        if (! in_array($this->timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException("Zona horaria IANA no válida: {$this->timezone}.");
        }

        if (preg_match('/^[A-Z]{3}$/', $this->currency) !== 1) {
            throw new InvalidArgumentException("Moneda ISO 4217 no válida: {$this->currency}.");
        }

        if ($this->autonomousCommunity !== null && ! in_array($this->autonomousCommunity, AutonomousCommunity::CODES, true)) {
            throw new InvalidArgumentException("Comunidad autónoma no reconocida: {$this->autonomousCommunity}.");
        }
    }

    /** ADR-021: los cuatro idiomas obligatorios de la plataforma. */
    private const SUPPORTED_LOCALES = ['es-ES', 'en', 'de', 'fr'];

    /**
     * Los valores por defecto que hoy ya tiene la columna
     * (`tenant_settings`, migración de 1.1): arrancar un centro por
     * consola sigue sin exigir teclear nada nuevo (ADR-048 §4.8).
     */
    public static function defaults(): self
    {
        return new self(
            defaultLocale: 'es-ES',
            activeLocales: ['es-ES'],
            timezone: 'Europe/Madrid',
            currency: 'EUR',
            autonomousCommunity: null,
        );
    }
}
