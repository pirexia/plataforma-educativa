<?php

use App\Modules\Core\Domain\TenantSettingsReader;
use App\Support\Api\ValidationErrorBag;
use App\Support\I18n\TranslationsValidator;

// REQ-ACAD-001, 1.11-a. CA-ACAD-009 y CA-ACAD-010 en lo que se prueba sin la
// API real (alta y PATCH por fusión, contra TenantSettingsReader);
// la parte HTTP de los dos criterios se cubre en 1.11-b. ADR-062 §5.2,
// OPEN-062-01, INV-010, INV-015.

function validatorFor(string $default, array $active): TranslationsValidator
{
    $settings = Mockery::mock(TenantSettingsReader::class);
    $settings->shouldReceive('defaultLocale')->andReturn($default);
    $settings->shouldReceive('activeLocales')->andReturn($active);

    return new TranslationsValidator($settings);
}

/**
 * @return array<string, list<string>> campo => códigos
 */
function codesOf(ValidationErrorBag $errors): array
{
    return array_map(
        static fn (array $list): array => array_column($list, 'code'),
        $errors->toArray(),
    );
}

test('CA-ACAD-009 ADR-062 §5.2: un alta sin la clave del idioma por defecto es translations.default_locale_required', function (): void {
    $errors = new ValidationErrorBag;

    $result = validatorFor('es-ES', ['es-ES', 'en'])->validateForCreate(['en' => 'Language'], 'name_translations', $errors);

    expect($result)->toBeNull()
        ->and(codesOf($errors))->toBe(['name_translations' => ['translations.default_locale_required']]);
});

test('CA-ACAD-009 ADR-062 §5.2: un alta sin objeto (null) es translations.default_locale_required', function (): void {
    $errors = new ValidationErrorBag;

    expect(validatorFor('es-ES', ['es-ES'])->validateForCreate(null, 'name_translations', $errors))->toBeNull()
        ->and(codesOf($errors))->toBe(['name_translations' => ['translations.default_locale_required']]);
});

test('CA-ACAD-009 ADR-062 §5.2: una clave de un idioma no activo es translations.locale_not_active, también si está fuera del vocabulario', function (): void {
    $errors = new ValidationErrorBag;

    $result = validatorFor('es-ES', ['es-ES', 'en'])->validateForCreate(
        ['es-ES' => 'Lengua', 'fr' => 'Langue', 'es' => 'Lengua', 'it' => 'Lingua'],
        'name_translations',
        $errors,
    );

    expect($result)->toBeNull()
        ->and(codesOf($errors))->toBe([
            'name_translations.fr' => ['translations.locale_not_active'],
            'name_translations.es' => ['translations.locale_not_active'],
            'name_translations.it' => ['translations.locale_not_active'],
        ]);
});

test('CA-ACAD-009 ADR-062 §5.2: un valor vacío tras recortar, o que no es cadena, es translations.empty_value', function (): void {
    $errors = new ValidationErrorBag;

    $result = validatorFor('es-ES', ['es-ES', 'en', 'de'])->validateForCreate(
        ['es-ES' => 'Lengua', 'en' => "  \t ", 'de' => 5],
        'name_translations',
        $errors,
    );

    expect($result)->toBeNull()
        ->and(codesOf($errors))->toBe([
            'name_translations.en' => ['translations.empty_value'],
            'name_translations.de' => ['translations.empty_value'],
        ]);
});

test('CA-ACAD-009 ADR-062 §5.2: el idioma por defecto presente pero vacío da solo empty_value, no además default_locale_required', function (): void {
    $errors = new ValidationErrorBag;

    validatorFor('es-ES', ['es-ES'])->validateForCreate(['es-ES' => ''], 'name_translations', $errors);

    expect(codesOf($errors))->toBe(['name_translations.es-ES' => ['translations.empty_value']]);
});

test('CA-ACAD-009 OPEN-062-01: con default_locale = en y active_locales = [en], un alta con solo en es válida; es-ES no se exige', function (): void {
    $errors = new ValidationErrorBag;

    $result = validatorFor('en', ['en'])->validateForCreate(['en' => '  Mathematics '], 'name_translations', $errors);

    expect($errors->isEmpty())->toBeTrue()
        ->and($result)->toBe(['en' => 'Mathematics']);
});

test('CA-ACAD-009 OPEN-062-01: con default_locale = en, un alta solo con es-ES falla (es-ES no está activo ni es el por defecto)', function (): void {
    $errors = new ValidationErrorBag;

    validatorFor('en', ['en'])->validateForCreate(['es-ES' => 'Matemáticas'], 'name_translations', $errors);

    expect(codesOf($errors))->toBe(['name_translations.es-ES' => ['translations.locale_not_active']]);
});

test('CA-ACAD-009 ADR-062 §5.2: el alta devuelve el objeto recortado y con las claves en el orden del vocabulario', function (): void {
    $errors = new ValidationErrorBag;

    $result = validatorFor('es-ES', ['es-ES', 'en', 'de', 'fr'])->validateForCreate(
        ['fr' => ' Langue ', 'es-ES' => ' Lengua', 'en' => 'Language '],
        'name_translations',
        $errors,
    );

    expect($result)->toBe(['es-ES' => 'Lengua', 'en' => 'Language', 'fr' => 'Langue'])
        ->and(array_keys($result))->toBe(['es-ES', 'en', 'fr']);
});

test('CA-ACAD-010 ADR-062 §5.2 punto 2: PATCH {fr} añade solo fr y deja es-ES y en intactos', function (): void {
    $errors = new ValidationErrorBag;

    $merged = validatorFor('es-ES', ['es-ES', 'en', 'de', 'fr'])->validateForPatch(
        ['fr' => ' Langue '],
        ['es-ES' => 'Lengua', 'en' => 'Language'],
        'name_translations',
        $errors,
    );

    expect($errors->isEmpty())->toBeTrue()
        ->and($merged)->toBe(['es-ES' => 'Lengua', 'en' => 'Language', 'fr' => 'Langue']);
});

test('CA-ACAD-010 ADR-062 §5.2 punto 2: PATCH {fr: null} quita solo fr', function (): void {
    $errors = new ValidationErrorBag;

    $merged = validatorFor('es-ES', ['es-ES', 'en', 'fr'])->validateForPatch(
        ['fr' => null],
        ['es-ES' => 'Lengua', 'en' => 'Language', 'fr' => 'Langue'],
        'name_translations',
        $errors,
    );

    expect($errors->isEmpty())->toBeTrue()
        ->and($merged)->toBe(['es-ES' => 'Lengua', 'en' => 'Language']);
});

test('CA-ACAD-010 ADR-062 §5.2: PATCH {es-ES: null} siendo es-ES el idioma por defecto es translations.default_locale_required', function (): void {
    $errors = new ValidationErrorBag;

    $merged = validatorFor('es-ES', ['es-ES', 'en'])->validateForPatch(
        ['es-ES' => null],
        ['es-ES' => 'Lengua', 'en' => 'Language'],
        'name_translations',
        $errors,
    );

    expect($merged)->toBeNull()
        ->and(codesOf($errors))->toBe(['name_translations' => ['translations.default_locale_required']]);
});

test('CA-ACAD-010 ADR-062 §5.2: PATCH con cadena vacía es translations.empty_value; con idioma no activo, locale_not_active', function (): void {
    $errors = new ValidationErrorBag;

    $merged = validatorFor('es-ES', ['es-ES', 'en'])->validateForPatch(
        ['en' => '', 'fr' => 'Langue'],
        ['es-ES' => 'Lengua'],
        'name_translations',
        $errors,
    );

    expect($merged)->toBeNull()
        ->and(codesOf($errors))->toBe([
            'name_translations.en' => ['translations.empty_value'],
            'name_translations.fr' => ['translations.locale_not_active'],
        ]);
});

test('CA-ACAD-010 ADR-062 §5.2 punto 3: un idioma retirado de active_locales con traducción guardada no se toca si el PATCH no lo menciona', function (): void {
    $errors = new ValidationErrorBag;

    // de ya no está activo; la fila conserva su traducción.
    $merged = validatorFor('es-ES', ['es-ES', 'en'])->validateForPatch(
        ['en' => 'Language v2'],
        ['es-ES' => 'Lengua', 'de' => 'Sprache'],
        'name_translations',
        $errors,
    );

    expect($errors->isEmpty())->toBeTrue()
        ->and($merged)->toBe(['es-ES' => 'Lengua', 'en' => 'Language v2', 'de' => 'Sprache']);
});

test('CA-ACAD-010 ADR-062 §5.2: un idioma retirado se puede quitar con null aunque ya no esté activo', function (): void {
    $errors = new ValidationErrorBag;

    $merged = validatorFor('es-ES', ['es-ES', 'en'])->validateForPatch(
        ['de' => null],
        ['es-ES' => 'Lengua', 'de' => 'Sprache'],
        'name_translations',
        $errors,
    );

    expect($errors->isEmpty())->toBeTrue()
        ->and($merged)->toBe(['es-ES' => 'Lengua']);
});

test('CA-ACAD-010 ADR-062 §5.2 punto 3: tras cambiar default_locale, la siguiente edición del campo exige añadir el nuevo idioma por defecto', function (): void {
    $errors = new ValidationErrorBag;

    // La fila tiene es-ES y en; el centro ahora tiene fr como idioma por defecto.
    $merged = validatorFor('fr', ['es-ES', 'en', 'fr'])->validateForPatch(
        ['en' => 'Language v2'],
        ['es-ES' => 'Lengua', 'en' => 'Language'],
        'name_translations',
        $errors,
    );

    expect($merged)->toBeNull()
        ->and(codesOf($errors))->toBe(['name_translations' => ['translations.default_locale_required']]);

    $errors = new ValidationErrorBag;
    $merged = validatorFor('fr', ['es-ES', 'en', 'fr'])->validateForPatch(
        ['fr' => 'Langue'],
        ['es-ES' => 'Lengua', 'en' => 'Language'],
        'name_translations',
        $errors,
    );

    expect($errors->isEmpty())->toBeTrue()
        ->and($merged)->toHaveKey('fr');
});

test('CA-ACAD-010 ADR-062 §5.2: PATCH con una clave null fuera del vocabulario es locale_not_active', function (): void {
    $errors = new ValidationErrorBag;

    validatorFor('es-ES', ['es-ES'])->validateForPatch(['es' => null], ['es-ES' => 'Lengua'], 'name_translations', $errors);

    expect(codesOf($errors))->toBe(['name_translations.es' => ['translations.locale_not_active']]);
});

test('CA-ACAD-009 INV-009: los tres mensajes comunes existen y se interpolan en los cuatro idiomas', function (): void {
    foreach (['es', 'en', 'de', 'fr'] as $locale) {
        foreach (['default_locale_required', 'locale_not_active', 'empty_value'] as $key) {
            $message = __("translations.{$key}", ['locale' => 'xx'], $locale);

            expect($message)->not->toBe("translations.{$key}")
                ->and($message)->toContain('xx');
        }
    }
});

test('CA-ACAD-009 ADR-062 §5.2: el error lleva el code translations.* completo, el mensaje traducido y el idioma en params', function (): void {
    $errors = new ValidationErrorBag;

    validatorFor('es-ES', ['es-ES'])->validateForCreate(['es-ES' => ''], 'name_translations', $errors);

    $entry = $errors->toArray()['name_translations.es-ES'][0];

    expect($entry['code'])->toBe('translations.empty_value')
        ->and($entry['params'])->toBe(['locale' => 'es-ES'])
        ->and($entry['message'])->not->toBe('translations.empty_value');
});
