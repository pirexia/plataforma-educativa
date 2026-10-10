<?php

namespace App\Support\I18n;

/**
 * ADR-062 §5.1, ADR-021: vocabulario cerrado de claves del contenido del
 * centro traducible, con la grafía de `tenant_settings.default_locale`
 * (`es-ES`, no `es`). Fuente única: el `CHECK` que genera
 * `TenantMigration::translatableColumn()`, la validación y el resolutor
 * leen esta lista. El orden es el de la última caída de `ADR-062 §5.3`.
 * Añadir una lengua cooficial es ampliar esta lista y el `CHECK` de
 * `tenant_settings`; no hay migración de datos.
 */
final class Locales
{
    /** @var list<string> */
    public const VOCABULARY = ['es-ES', 'en', 'de', 'fr'];

    /** Caída del producto (ADR-062 §5.3), anterior a «primera clave presente». */
    public const PRODUCT_FALLBACK = 'es-ES';

    public static function isKnown(string $locale): bool
    {
        return in_array($locale, self::VOCABULARY, true);
    }
}
