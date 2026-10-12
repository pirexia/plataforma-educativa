<?php

use App\Support\I18n\Locales;
use App\Support\I18n\TranslationResolver;

// REQ-ACAD-001, 1.11-a. CA-ACAD-012 (ADR-062 §5.3, test unitario del
// resolutor): idioma pedido → default_locale → es-ES → primera clave en el
// orden es-ES, en, de, fr. Función pura, sin base de datos. INV-015.

test('CA-ACAD-012 ADR-062 §5.3: el idioma pedido gana si está en la fila', function (): void {
    $row = ['es-ES' => 'Lengua', 'en' => 'Language', 'de' => 'Sprache'];

    $resolved = TranslationResolver::resolve($row, 'de', 'es-ES');

    expect($resolved->text)->toBe('Sprache')
        ->and($resolved->locale)->toBe('de');
});

test('CA-ACAD-012 ADR-062 §5.3: fila con es-ES y en y petición en de: cae al idioma por defecto del centro', function (): void {
    $row = ['es-ES' => 'Lengua', 'en' => 'Language'];

    $resolved = TranslationResolver::resolve($row, 'de', 'en');

    expect($resolved->text)->toBe('Language')
        ->and($resolved->locale)->toBe('en')
        ->and(TranslationResolver::text($row, 'de', 'es-ES'))->toBe('Lengua');
});

test('CA-ACAD-012 ADR-062 §5.3: tras cambiar default_locale a fr (sin fr en la fila) el texto es el de es-ES', function (): void {
    $row = ['es-ES' => 'Lengua', 'en' => 'Language'];

    $resolved = TranslationResolver::resolve($row, null, 'fr');

    expect($resolved->text)->toBe('Lengua')
        ->and($resolved->locale)->toBe('es-ES');
});

test('CA-ACAD-012 ADR-062 §5.3: fila solo con de y default_locale fr: la primera clave presente, de', function (): void {
    $resolved = TranslationResolver::resolve(['de' => 'Sprache'], 'en', 'fr');

    expect($resolved->text)->toBe('Sprache')
        ->and($resolved->locale)->toBe('de');
});

test('CA-ACAD-012 ADR-062 §5.3: la primera clave sigue el orden es-ES, en, de, fr, no el de inserción', function (): void {
    $row = ['fr' => 'Langue', 'de' => 'Sprache', 'en' => 'Language'];

    expect(TranslationResolver::resolve($row, null, 'es-ES')->locale)->toBe('en')
        ->and(Locales::VOCABULARY)->toBe(['es-ES', 'en', 'de', 'fr']);
});

test('CA-ACAD-012 ADR-062 §5.3: una entrada vacía o que no es cadena no cuenta; sin ninguna válida devuelve null', function (): void {
    expect(TranslationResolver::resolve(['en' => '', 'es-ES' => 'Lengua'], 'en', 'en')->locale)->toBe('es-ES')
        ->and(TranslationResolver::resolve(['en' => '  ', 'de' => 'Sprache'], 'en', 'en')->locale)->toBe('de')
        ->and(TranslationResolver::resolve([], 'en', 'es-ES'))->toBeNull()
        ->and(TranslationResolver::resolve(['en' => 1], 'en', 'es-ES'))->toBeNull()
        ->and(TranslationResolver::text([], 'en', 'es-ES'))->toBe('');
});

test('CA-ACAD-012 ADR-062 §5.3: el idioma pedido ausente (documento sin destinatario resuelto) usa el del centro', function (): void {
    expect(TranslationResolver::resolve(['es-ES' => 'Lengua', 'fr' => 'Langue'], null, 'fr')->text)->toBe('Langue');
});
