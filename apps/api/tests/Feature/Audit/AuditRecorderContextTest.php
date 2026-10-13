<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Support\Audit\AuditRecorder;
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
        ->toThrow(InvalidArgumentException::class, 'tope');

    expect(AuditLog::count())->toBe($before);
});
