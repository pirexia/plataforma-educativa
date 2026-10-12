<?php

use App\Support\Audit\Auditable;
use App\Support\Audit\AuditChangeBuilder;
use App\Support\Audit\AuditValuePolicy;

/**
 * ADR-035 §4/§11 (0.9.b): los cinco pasos de evaluación, sin base de
 * datos — un doble de Auditable basta, el builder no exige un modelo
 * Eloquent real.
 */
function fakeAuditable(
    AuditValuePolicy $policy,
    array $recorded = [],
    array $secrets = [],
): Auditable {
    return new class($policy, $recorded, $secrets) implements Auditable
    {
        public function __construct(
            private readonly AuditValuePolicy $policy,
            private readonly array $recorded,
            private readonly array $secrets,
        ) {}

        public function auditValuePolicy(): AuditValuePolicy
        {
            return $this->policy;
        }

        public function auditRecordedAttributes(): array
        {
            return $this->recorded;
        }

        public function auditSecretAttributes(): array
        {
            return $this->secrets;
        }

        // ADR-040 §4.1: cuarto método del contrato. Sin exclusiones en
        // este doble de pruebas — el builder no depende de él.
        public function auditExcludedEvents(): array
        {
            return [];
        }
    };
}

function builder(int $maxLength = 256): AuditChangeBuilder
{
    return new AuditChangeBuilder(
        secretPatterns: ['*password*', '*token*', '*secret*', '*_key', '*totp*', '*recovery_code*'],
        maxValueLength: $maxLength,
    );
}

// Paso 1: secreto, absoluto — gana incluso en política Full y sin banderas de vacío.
test('un atributo declarado secreto se redacta sin banderas de vacío', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full, secrets: ['api_token']);

    $changes = builder()->build($model, ['api_token' => ['old-value', 'new-value']]);

    expect($changes['api_token'])->toBe(['redacted' => 'secret']);
});

// ADR-035 test 4: patrón global, defensa en profundidad, aunque el modelo no lo declare.
test('el patrón global de secretos redacta aunque el modelo no lo declare', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);

    $changes = builder()->build($model, ['recovery_code_1' => [null, '123456']]);

    expect($changes['recovery_code_1'])->toBe(['redacted' => 'secret']);
});

// Paso 2: política Redacted (categoría especial) — nunca el valor, solo banderas de vacío.
test('la política Redacted nunca registra el valor', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Redacted);

    $changes = builder()->build($model, ['diagnosis' => [null, 'texto clínico']]);

    expect($changes['diagnosis'])->toBe(['redacted' => 'special', 'from_empty' => true, 'to_empty' => false]);
});

// ADR-035 test 3: Selective falla en cerrado — un atributo nuevo que nadie
// clasificó se redacta, no se muestra por defecto.
test('Selective redacta cualquier atributo fuera de la lista de inclusión', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Selective, recorded: ['locale']);

    $changes = builder()->build($model, [
        'locale' => ['es-ES', 'en'],
        'new_column_nobody_classified' => [null, 'algo'],
    ]);

    expect($changes['locale'])->toBe(['from' => 'es-ES', 'to' => 'en'])
        ->and($changes['new_column_nobody_classified'])
        ->toBe(['redacted' => 'identifier', 'from_empty' => true, 'to_empty' => false]);
});

// ADR-035 test 5: tope de tamaño, nunca se trunca.
test('un valor que supera el tope se redacta como oversized, sin truncar', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);
    $longValue = str_repeat('a', 300);

    $changes = builder(maxLength: 256)->build($model, ['observations' => [null, $longValue]]);

    expect($changes['observations'])->toBe(['redacted' => 'oversized', 'from_empty' => true, 'to_empty' => false])
        ->and(json_encode($changes))->not->toContain($longValue);
});

test('un valor dentro del tope se registra normalmente', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);

    $changes = builder()->build($model, ['name' => ['Antiguo', 'Nuevo']]);

    expect($changes['name'])->toBe(['from' => 'Antiguo', 'to' => 'Nuevo']);
});

test('el orden de evaluación es secreto antes que política, y política antes que tamaño', function (): void {
    // Selective + fuera de lista + nombre que además encaja con el patrón
    // de secretos: debe ganar "secret", no "identifier".
    $model = fakeAuditable(AuditValuePolicy::Selective, recorded: []);

    $changes = builder()->build($model, ['user_token' => [null, 'abc']]);

    expect($changes['user_token'])->toBe(['redacted' => 'secret']);
});

// ADR-062 §5.5 (REQ-ACAD-001, 1.11-a), CA-ACAD-013: las columnas
// `*_translations` se desglosan por idioma; solo los que cambian.
test('CA-ACAD-013 ADR-062 §5.5: cambiar es-ES y añadir fr produce exactamente dos entradas {from,to}', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);

    $changes = builder()->build($model, ['name_translations' => [
        ['es-ES' => 'Lengua', 'en' => 'Language'],
        ['es-ES' => 'Lengua Castellana', 'en' => 'Language', 'fr' => 'Langue castillane'],
    ]]);

    expect(array_keys($changes))->toBe(['name_translations.es-ES', 'name_translations.fr'])
        ->and($changes['name_translations.es-ES'])->toBe(['from' => 'Lengua', 'to' => 'Lengua Castellana'])
        ->and($changes['name_translations.fr'])->toBe(['from' => null, 'to' => 'Langue castillane'])
        ->and($changes)->not->toHaveKey('name_translations');
});

test('CA-ACAD-013 ADR-062 §5.5: acepta el valor serializado como JSON (lo que entrega getDirty()) y registra la baja de un idioma', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);

    $changes = builder()->build($model, ['name_translations' => [
        ['es-ES' => 'Lengua', 'fr' => 'Langue'],
        '{"es-ES":"Lengua"}',
    ]]);

    expect($changes)->toBe(['name_translations.fr' => ['from' => 'Langue', 'to' => null]]);
});

test('CA-ACAD-013 ADR-062 §5.5: el alta desglosa cada idioma con from null', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);

    $changes = builder()->build($model, ['name_translations' => [null, '{"es-ES":"Lengua","en":"Language"}']]);

    expect($changes)->toBe([
        'name_translations.es-ES' => ['from' => null, 'to' => 'Lengua'],
        'name_translations.en' => ['from' => null, 'to' => 'Language'],
    ]);
});

test('CA-ACAD-013 ADR-062 §5.5: el tope de ADR-035 §5 se aplica por entrada, y se redacta oversized solo en su idioma', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);
    $long = str_repeat('a', 300);

    $changes = builder(maxLength: 256)->build($model, ['name_translations' => [
        ['es-ES' => 'Lengua'],
        ['es-ES' => $long, 'en' => 'Language'],
    ]]);

    expect($changes['name_translations.es-ES'])->toBe(['redacted' => 'oversized', 'from_empty' => false, 'to_empty' => false])
        ->and($changes['name_translations.en'])->toBe(['from' => null, 'to' => 'Language'])
        ->and(json_encode($changes))->not->toContain($long);
});

test('CA-ACAD-013 ADR-062 §5.5: la política Redacted redacta cada entrada como special y Selective fuera de lista como identifier', function (): void {
    $changes = builder()->build(fakeAuditable(AuditValuePolicy::Redacted), ['name_translations' => [['es-ES' => 'A'], ['es-ES' => 'B', 'en' => 'C']]]);

    expect($changes['name_translations.es-ES'])->toBe(['redacted' => 'special', 'from_empty' => false, 'to_empty' => false])
        ->and($changes['name_translations.en'])->toBe(['redacted' => 'special', 'from_empty' => true, 'to_empty' => false]);

    $selective = builder()->build(fakeAuditable(AuditValuePolicy::Selective, recorded: []), ['name_translations' => [null, ['es-ES' => 'A']]]);

    expect($selective['name_translations.es-ES'])->toBe(['redacted' => 'identifier', 'from_empty' => true, 'to_empty' => false]);

    $included = builder()->build(fakeAuditable(AuditValuePolicy::Selective, recorded: ['name_translations']), ['name_translations' => [null, ['es-ES' => 'A']]]);

    expect($included['name_translations.es-ES'])->toBe(['from' => null, 'to' => 'A']);
});

test('CA-ACAD-013 ADR-062 §5.5: un atributo que no termina en _translations no se desglosa, aunque sea un array', function (): void {
    $model = fakeAuditable(AuditValuePolicy::Full);

    $changes = builder()->build($model, ['settings' => [['a' => 1], ['a' => 2]]]);

    expect(array_keys($changes))->toBe(['settings']);
});
