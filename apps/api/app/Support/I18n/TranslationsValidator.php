<?php

namespace App\Support\I18n;

use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ValidationErrorBag;

/**
 * ADR-062 §5.2, INV-010: validación de servidor de una columna
 * `<campo>_translations`, compartida por todos los módulos. Lo que depende
 * del centro (idioma por defecto, idiomas activos) se lee de
 * `TenantSettingsReader` y no va al motor; lo que no depende (vocabulario
 * cerrado, objeto, valores cadena) lo repite el `CHECK` del ayudante de
 * `TenantMigration`.
 *
 * Usa `ValidationErrorBag` y no una `ValidationRule` de Laravel: el `code`
 * lo fija esta clase, completo (`translations.*`); `ValidationErrorFormatter`
 * antepondría `core.validation.` a cualquier regla (issue #60, abierto).
 *
 * La forma del cuerpo (que `<campo>_translations` sea un objeto y la longitud
 * máxima por valor, que fija la especificación de cada módulo) la declara el
 * `FormRequest` del módulo con las reglas ordinarias de Laravel
 * (`array`, `<campo>.*` => `max:N`). Aquí, un valor que no es array se
 * rechaza igualmente (`empty_value`) para no depender de ello.
 *
 * Códigos y mensajes (`lang/*\/translations.php`), citados por las
 * especificaciones de módulo y no redefinidos por ellas:
 *
 * - `translations.default_locale_required`: falta el idioma por defecto
 *   del centro (alta) o la edición lo quita o lo deja sin él (`PATCH`);
 * - `translations.locale_not_active`: clave que no es de un idioma activo
 *   del centro (o fuera del vocabulario cerrado);
 * - `translations.empty_value`: valor vacío tras recortar, o que no es cadena.
 */
final class TranslationsValidator
{
    public const DEFAULT_LOCALE_REQUIRED = 'translations.default_locale_required';

    public const LOCALE_NOT_ACTIVE = 'translations.locale_not_active';

    public const EMPTY_VALUE = 'translations.empty_value';

    public function __construct(
        private readonly TenantSettingsReader $settings,
    ) {}

    /**
     * Alta (`POST`): el objeto es obligatorio; contiene el idioma por defecto
     * del centro; toda clave es de un idioma activo; cada valor, recortado,
     * no queda vacío.
     *
     * @return array<string, string>|null el objeto normalizado (valores recortados, claves en el orden del vocabulario), o `null` si hay errores
     */
    public function validateForCreate(mixed $input, string $field, ValidationErrorBag $errors): ?array
    {
        $default = $this->settings->defaultLocale();

        if ($input === null) {
            $this->fail($errors, $field, self::DEFAULT_LOCALE_REQUIRED, $default);

            return null;
        }

        if (! is_array($input)) {
            $this->fail($errors, $field, self::EMPTY_VALUE, $default);

            return null;
        }

        $clean = [];
        $valid = true;

        foreach ($input as $locale => $value) {
            $locale = (string) $locale;

            if (! $this->isActive($locale)) {
                $this->fail($errors, "{$field}.{$locale}", self::LOCALE_NOT_ACTIVE, $locale);
                $valid = false;

                continue;
            }

            $text = $this->cleanText($value);

            if ($text === null) {
                $this->fail($errors, "{$field}.{$locale}", self::EMPTY_VALUE, $locale);
                $valid = false;

                continue;
            }

            $clean[$locale] = $text;
        }

        // Una clave por defecto presente pero vacía ya tiene su error propio.
        if ($valid && ! array_key_exists($default, $clean)) {
            $this->fail($errors, $field, self::DEFAULT_LOCALE_REQUIRED, $default);
            $valid = false;
        }

        return $valid ? self::ordered($clean) : null;
    }

    /**
     * Edición (`PATCH`) por fusión (`ADR-038 §9.2` regla 4, no la 6): una
     * clave con cadena cambia solo ese idioma; `null` lo quita; `""` es error;
     * una clave no nula debe ser de un idioma activo. Las claves almacenadas
     * que el cuerpo no menciona no se tocan, aunque su idioma ya no esté
     * activo. El resultado debe conservar el idioma por defecto **actual**.
     *
     * @param  array<string, mixed>  $stored  el objeto almacenado hoy
     * @return array<string, string>|null el objeto resultante de la fusión, o `null` si hay errores
     */
    public function validateForPatch(mixed $input, array $stored, string $field, ValidationErrorBag $errors): ?array
    {
        $default = $this->settings->defaultLocale();

        if (! is_array($input)) {
            $this->fail($errors, $field, self::EMPTY_VALUE, $default);

            return null;
        }

        $merged = [];

        foreach ($stored as $locale => $value) {
            if (is_string($value)) {
                $merged[(string) $locale] = $value;
            }
        }

        $valid = true;

        foreach ($input as $locale => $value) {
            $locale = (string) $locale;

            if ($value === null) {
                // Quitar un idioma retirado del centro es legítimo; una clave
                // fuera del vocabulario no existe en ningún objeto válido.
                if (! Locales::isKnown($locale)) {
                    $this->fail($errors, "{$field}.{$locale}", self::LOCALE_NOT_ACTIVE, $locale);
                    $valid = false;

                    continue;
                }

                unset($merged[$locale]);

                continue;
            }

            if (! $this->isActive($locale)) {
                $this->fail($errors, "{$field}.{$locale}", self::LOCALE_NOT_ACTIVE, $locale);
                $valid = false;

                continue;
            }

            $text = $this->cleanText($value);

            if ($text === null) {
                $this->fail($errors, "{$field}.{$locale}", self::EMPTY_VALUE, $locale);
                $valid = false;

                continue;
            }

            $merged[$locale] = $text;
        }

        if ($valid && ! array_key_exists($default, $merged)) {
            $this->fail($errors, $field, self::DEFAULT_LOCALE_REQUIRED, $default);
            $valid = false;
        }

        return $valid ? self::ordered($merged) : null;
    }

    private function isActive(string $locale): bool
    {
        return Locales::isKnown($locale) && in_array($locale, $this->settings->activeLocales(), true);
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }

    private function fail(ValidationErrorBag $errors, string $field, string $code, string $locale): void
    {
        $errors->add($field, $code, $code, ['locale' => $locale]);
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private static function ordered(array $map): array
    {
        $ordered = [];

        foreach (Locales::VOCABULARY as $locale) {
            if (array_key_exists($locale, $map)) {
                $ordered[$locale] = $map[$locale];
            }
        }

        return $ordered;
    }
}
