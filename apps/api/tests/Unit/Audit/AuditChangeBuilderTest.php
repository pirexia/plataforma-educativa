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
