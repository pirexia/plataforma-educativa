<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Issue #393 · INV-003 · ADR-035 §5 · ADR-057 §5.7 punto 3:
 * AuditRecorder::record() acepta y persiste `context` (identificadores y
 * códigos, nunca valores de atributos).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->enter($this->tenant->id);
    $this->person = Person::factory()->create();
});

afterEach(function (): void {
    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('INV-003 #393: record() persiste el context en audit_logs.context', function (): void {
    app(AuditRecorder::class)->record($this->person, 'updated', [], [
        'exception_code' => 'rectificacion_calificacion',
        'reason_code' => 'error_material',
        'academic_year_public_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
    ]);

    $log = AuditLog::where('auditable_id', $this->person->id)->latest('id')->first();

    expect($log->context)->toEqual([
        'exception_code' => 'rectificacion_calificacion',
        'reason_code' => 'error_material',
        'academic_year_public_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
    ]);
});

test('INV-003 #393: sin context la columna queda NULL (control negativo)', function (): void {
    app(AuditRecorder::class)->record($this->person, 'updated');

    $log = AuditLog::where('auditable_id', $this->person->id)->latest('id')->first();

    expect($log->context)->toBeNull();
    expect(DB::table('audit_logs')->where('id', $log->id)->value('context'))->toBeNull();
});

test('ADR-035 §5 #393: un context con objetos o floats se rechaza y no escribe fila', function (): void {
    $before = AuditLog::count();

    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['x' => new stdClass]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['x' => 1.5]))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe($before);
});

test('ADR-035 §5 #393: un context por encima de audit.max_value_length se rechaza, no se trunca', function (): void {
    config(['audit.max_value_length' => 32]);
    $before = AuditLog::count();

    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['note' => str_repeat('a', 64)]))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe($before);
});

test('INV-003 #393: un context anidado válido (hasta 3 niveles) se persiste', function (): void {
    $context = ['a' => ['b' => ['c' => 'x', 'n' => 1, 'ok' => true, 'nada' => null]], 'lista' => [1, 2, 3]];

    app(AuditRecorder::class)->record($this->person, 'updated', [], $context);

    $log = AuditLog::where('auditable_id', $this->person->id)->latest('id')->first();

    expect($log->context)->toEqual($context);
});

test('ADR-035 §5 #393: un context con más de 3 niveles de anidamiento se rechaza', function (): void {
    $before = AuditLog::count();

    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['a' => ['b' => ['c' => ['d' => 'x']]]]))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe($before);
});

test('ADR-035 §5 #393: NAN, INF, enum y Stringable se rechazan', function (mixed $value): void {
    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['x' => $value]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'NAN' => [NAN],
    'INF' => [INF],
    'enum' => [PlatformAccessPurpose::Mantenimiento],
    'Stringable' => [new class implements Stringable
    {
        public function __toString(): string
        {
            return 'x';
        }
    }],
]);

test('ADR-035 §5 #393: el tope es inclusivo (cuenta caracteres con mb_strlen)', function (): void {
    config(['audit.max_value_length' => 32]);
    // {"a":"..."} suma 8 caracteres de estructura: 24 caracteres de valor llegan justo a 32.
    app(AuditRecorder::class)->record($this->person, 'updated', [], ['a' => str_repeat('n', 24)]);

    $log = AuditLog::where('auditable_id', $this->person->id)->latest('id')->first();
    expect($log->context)->toEqual(['a' => str_repeat('n', 24)]);

    $before = AuditLog::count();
    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['a' => str_repeat('n', 25)]))
        ->toThrow(InvalidArgumentException::class);
    expect(AuditLog::count())->toBe($before);
});

test('ADR-035 §5 #393: un context con UTF-8 inválido se rechaza y no elude el tope', function (): void {
    $before = AuditLog::count();

    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['a' => "\xB1\x31"]))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe($before);
});

test('ADR-035 §5 #393: el context se valida también en modo plataforma', function (): void {
    $person = $this->person;
    app(TenantContext::class)->leave();

    expect(fn () => app(TenantContext::class)->runAsPlatform(
        PlatformAccessPurpose::Mantenimiento,
        fn () => app(AuditRecorder::class)->record($person, 'updated', [], ['x' => 1.5]),
    ))->toThrow(InvalidArgumentException::class);

    app(TenantContext::class)->enter($this->tenant->id);
});

test('ADR-035 §5 #393: el context se valida también sin tenant activo, antes del retorno silencioso', function (): void {
    $person = $this->person;
    app(TenantContext::class)->leave();

    expect(fn () => app(AuditRecorder::class)->record($person, 'updated', [], ['x' => new stdClass]))
        ->toThrow(InvalidArgumentException::class);

    app(TenantContext::class)->enter($this->tenant->id);
});

test('ADR-035 §5 #393: valores string que no son código ni ULID se rechazan y no escriben fila', function (mixed $value): void {
    $before = AuditLog::count();

    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['nota' => $value]))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe($before);
})->with([
    'texto libre' => ['hablé con María López'],
    'nombre propio' => ['María López'],
    'email' => ['maria.lopez@example.com'],
    'mayúsculas' => ['Error_Material'],
    'vacío' => [''],
]);

test('ADR-035 §5 #393: claves de mapa que no cumplen el patrón se rechazan', function (string $key): void {
    $before = AuditLog::count();

    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], [$key => 'ok']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(AuditRecorder::class)->record($this->person, 'updated', [], ['a' => [$key => 'ok']]))
        ->toThrow(InvalidArgumentException::class);

    expect(AuditLog::count())->toBe($before);
})->with([
    'nombre propio' => ['María López'],
    'email' => ['maria@example.com'],
    'mayúscula inicial' => ['Maria'],
    'empieza por dígito' => ['1a'],
    'guion' => ['a-b'],
    'vacía' => [''],
]);

test('INV-003 #393: códigos, ULID y listas de ULID se persisten', function (): void {
    $context = [
        'exception_code' => 'rectificacion.calificacion-2',
        'user_public_id' => '01JABCDEFGHJKMNPQRSTVWXYZ0',
        'group_public_ids' => ['01JABCDEFGHJKMNPQRSTVWXYZ0', '01JZZZZZZZZZZZZZZZZZZZZZZZ'],
        'count' => 3,
        'flag' => false,
        'nada' => null,
    ];

    app(AuditRecorder::class)->record($this->person, 'updated', [], $context);

    $log = AuditLog::where('auditable_id', $this->person->id)->latest('id')->first();
    expect($log->context)->toEqual($context);
});

test('ADR-035 §5 #393: el patrón se valida también en modo plataforma y sin tenant', function (): void {
    $person = $this->person;
    app(TenantContext::class)->leave();

    expect(fn () => app(AuditRecorder::class)->record($person, 'updated', [], ['x' => 'hablé con María López']))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => app(TenantContext::class)->runAsPlatform(
        PlatformAccessPurpose::Mantenimiento,
        fn () => app(AuditRecorder::class)->record($person, 'updated', [], ['María' => 'x']),
    ))->toThrow(InvalidArgumentException::class);

    app(TenantContext::class)->enter($this->tenant->id);
});
