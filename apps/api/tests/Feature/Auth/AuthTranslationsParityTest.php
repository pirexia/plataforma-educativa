<?php

// CA-AUTH-233 (REQ-AUTH-002, 1.4): "los textos de las pantallas y de los
// tres correos nuevos [...] existen en los cuatro idiomas y ninguno está
// escrito en el código" (INV-009). El frontend tiene su propio guardián
// mecánico para "sin literales" (scripts/check-i18n-literals.mjs), pero
// nada comprobaba hasta ahora que las CUATRO traducciones de
// `lang/*/auth.php` —donde viven los dos correos de 1.4
// (`identity_linked`/`identity_unlinked`, funcional.md §E.4.7) y el
// resto del vocabulario del módulo— tengan exactamente las mismas
// claves. Sin este test, un correo nuevo añadido solo en `es` (el
// idioma en el que se escribe primero) pasaría el resto de la batería
// sin que nada lo detectara: `Lang::get()` con clave ausente en un
// idioma sin `fallback_locale` configurado a nivel de aplicación
// devuelve la propia clave cruda, visible al destinatario del correo.

/**
 * @return array<int, string> claves en notación de puntos, aplanadas.
 */
function flattenTranslationKeys(array $array, string $prefix = ''): array
{
    $keys = [];

    foreach ($array as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $keys = [...$keys, ...flattenTranslationKeys($value, $path)];
        } else {
            $keys[] = $path;
        }
    }

    return $keys;
}

test('CA-AUTH-233: lang/{es,en,de,fr}/auth.php tienen exactamente las mismas claves', function (): void {
    $locales = ['es', 'en', 'de', 'fr'];
    $keysByLocale = [];

    foreach ($locales as $locale) {
        $path = base_path("lang/{$locale}/auth.php");
        expect(is_file($path))->toBeTrue("No existe {$path}");

        $keysByLocale[$locale] = flattenTranslationKeys(require $path);
    }

    $reference = $keysByLocale['es'];

    foreach (['en', 'de', 'fr'] as $locale) {
        $missing = array_values(array_diff($reference, $keysByLocale[$locale]));
        $extra = array_values(array_diff($keysByLocale[$locale], $reference));

        expect($missing)->toBe([], "Faltan en {$locale}: ".implode(', ', $missing));
        expect($extra)->toBe([], "Sobran en {$locale} (no están en es): ".implode(', ', $extra));
    }
});

// CA-AUTH-233, funcional.md §E.4.7/§E.5: los dos correos de 1.4 cubren
// las tres notificaciones del requisito (fusión y vinculación comparten
// plantilla con `link_method` distinto). Verificado en los cuatro
// idiomas, no solo que existan las claves sino que el mensaje real que
// recibe el destinatario está traducido — no la clave cruda.
test('CA-AUTH-233: los avisos de vínculo de Google se resuelven en los cuatro idiomas, sin clave cruda', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        app()->setLocale($locale);

        expect(__('auth.mail.identity_linked.subject'))->not->toBe('auth.mail.identity_linked.subject')
            ->and(__('auth.mail.identity_unlinked.subject'))->not->toBe('auth.mail.identity_unlinked.subject');
    }

    app()->setLocale('es');
});
