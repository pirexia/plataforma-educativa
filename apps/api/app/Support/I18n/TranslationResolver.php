<?php

namespace App\Support\I18n;

/**
 * ADR-062 §5.3, CA-ACAD-012: único resolutor del contenido del centro
 * traducible. Función pura, sin base de datos ni estado: ningún módulo
 * resuelve por su cuenta (`->{'en'} ?? …` en un controlador es un fallo de
 * revisión).
 *
 * Caída: idioma pedido → `default_locale` del centro → `es-ES` → primera
 * clave presente en el orden `es-ES`, `en`, `de`, `fr`. Una entrada solo
 * cuenta si es una cadena no vacía. Como la validación garantiza el idioma
 * por defecto vigente al escribir, los dos últimos escalones solo actúan
 * tras un cambio de `default_locale`.
 */
final class TranslationResolver
{
    /**
     * @param  array<string, mixed>  $translations  el objeto `<campo>_translations` almacenado
     * @param  string|null  $requested  idioma pedido: el de la petición (`ResolveApiLocale`) o el del destinatario de un documento
     */
    public static function resolve(array $translations, ?string $requested, string $defaultLocale): ?ResolvedTranslation
    {
        $candidates = [];

        if ($requested !== null) {
            $candidates[] = $requested;
        }

        $candidates[] = $defaultLocale;
        $candidates[] = Locales::PRODUCT_FALLBACK;

        foreach (Locales::VOCABULARY as $locale) {
            $candidates[] = $locale;
        }

        foreach ($candidates as $locale) {
            $text = $translations[$locale] ?? null;

            if (is_string($text) && trim($text) !== '') {
                return new ResolvedTranslation($text, $locale);
            }
        }

        // Una fila válida (el CHECK del motor exige al menos un idioma) nunca
        // llega aquí; devuelve null solo ante datos que el esquema no admite.
        return null;
    }

    /**
     * Atajo de solo texto para representaciones que no necesitan el idioma de
     * procedencia. Cadena vacía solo ante datos que el esquema no admite.
     *
     * @param  array<string, mixed>  $translations
     */
    public static function text(array $translations, ?string $requested, string $defaultLocale): string
    {
        return self::resolve($translations, $requested, $defaultLocale)->text ?? '';
    }
}
