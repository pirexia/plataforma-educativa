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
 * - `translations.empty_value`: valor vacío tras recortar, o que no es cadena;
 * - `translations.too_long`: el valor recortado supera `$maxLength` caracteres
 *   (obligatorio en cada llamada, #413: un módulo no puede olvidarlo);
 * - `translations.invalid_characters`: el valor contiene NUL o un carácter de
 *   control C0 (salvo tabulación y saltos de línea, que admite un párrafo,
 *   ADR-062 §2), DEL, o no es UTF-8 válido. PostgreSQL rechaza `\u0000` en un
 *   `jsonb` con `22P05` (un `500`): el servidor lo ataja antes, `INV-010`.
 */
final class TranslationsValidator
{
    public const DEFAULT_LOCALE_REQUIRED = 'translations.default_locale_required';

    public const LOCALE_NOT_ACTIVE = 'translations.locale_not_active';

    public const EMPTY_VALUE = 'translations.empty_value';

    public const INVALID_CHARACTERS = 'translations.invalid_characters';

    public const TOO_LONG = 'translations.too_long';

    /**
     * Tope de errores `locale_not_active` por llamada (issue #413): una petición
     * con miles de claves desconocidas no amplifica la respuesta de error. Los
     * demás errores solo pueden darse en las cuatro claves del vocabulario.
     */
    public const MAX_UNKNOWN_KEY_ERRORS = 5;

    /** Longitud máxima (caracteres) de una clave ecoada en el campo o en `params`. */
    private const MAX_ECHOED_KEY_LENGTH = 16;

    public function __construct(
        private readonly TenantSettingsReader $settings,
    ) {}

    /**
     * Alta (`POST`): el objeto es obligatorio; contiene el idioma por defecto
     * del centro; toda clave es de un idioma activo; cada valor, recortado,
     * no queda vacío ni supera `$maxLength` caracteres (la fija la especificación
     * del módulo; obligatoria).
     *
     * @return array<string, string>|null el objeto normalizado (valores recortados, claves en el orden del vocabulario), o `null` si hay errores
     */
    public function validateForCreate(mixed $input, string $field, ValidationErrorBag $errors, int $maxLength): ?array
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
        $unknownReported = 0;

        foreach ($input as $locale => $value) {
            $locale = (string) $locale;

            if (! $this->isActive($locale)) {
                $this->failUnknownKey($errors, $field, $locale, $unknownReported);
                $valid = false;

                continue;
            }

            $text = $this->cleanText($value, $errors, "{$field}.{$locale}", $locale, $maxLength);

            if ($text === null) {
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
    public function validateForPatch(mixed $input, array $stored, string $field, ValidationErrorBag $errors, int $maxLength): ?array
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
        $unknownReported = 0;

        foreach ($input as $locale => $value) {
            $locale = (string) $locale;

            if ($value === null) {
                // Quitar un idioma retirado del centro es legítimo; una clave
                // fuera del vocabulario no existe en ningún objeto válido.
                if (! Locales::isKnown($locale)) {
                    $this->failUnknownKey($errors, $field, $locale, $unknownReported);
                    $valid = false;

                    continue;
                }

                unset($merged[$locale]);

                continue;
            }

            if (! $this->isActive($locale)) {
                $this->failUnknownKey($errors, $field, $locale, $unknownReported);
                $valid = false;

                continue;
            }

            $text = $this->cleanText($value, $errors, "{$field}.{$locale}", $locale, $maxLength);

            if ($text === null) {
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

    /**
     * El valor recortado, o `null` tras añadir el error que corresponde
     * (`empty_value` o `invalid_characters`).
     */
    private function cleanText(mixed $value, ValidationErrorBag $errors, string $path, string $locale, int $maxLength): ?string
    {
        if (! is_string($value)) {
            $this->fail($errors, $path, self::EMPTY_VALUE, $locale);

            return null;
        }

        // Antes de recortar y de decidir si queda vacío: un NUL no es espacio.
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            $this->fail($errors, $path, self::INVALID_CHARACTERS, $locale);

            return null;
        }

        $text = trim($value);

        if ($text === '') {
            $this->fail($errors, $path, self::EMPTY_VALUE, $locale);

            return null;
        }

        if (mb_strlen($text) > $maxLength) {
            $errors->add($path, self::TOO_LONG, self::TOO_LONG, ['locale' => $locale, 'max' => $maxLength]);

            return null;
        }

        return $text;
    }

    /**
     * Una clave que no es de un idioma activo. Como mucho
     * `MAX_UNKNOWN_KEY_ERRORS` por llamada, y la clave que se ecoa (en el
     * campo y en `params.locale`) se acota en longitud: es entrada arbitraria.
     */
    private function failUnknownKey(ValidationErrorBag $errors, string $field, string $key, int &$reported): void
    {
        if ($reported >= self::MAX_UNKNOWN_KEY_ERRORS) {
            return;
        }

        $reported++;
        $echo = mb_substr($key, 0, self::MAX_ECHOED_KEY_LENGTH);

        $this->fail($errors, "{$field}.{$echo}", self::LOCALE_NOT_ACTIVE, $echo);
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
