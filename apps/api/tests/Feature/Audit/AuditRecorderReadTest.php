<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\PlatformAccessPurpose;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

// ADR-068 §5.4, CA-068-03, RN-FAM-UNIT-92, INV-003: AuditRecorder::recordRead() escribe una lectura (`read`, sin valores)
// sobre un sujeto identificado por alias, sin tener el modelo.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->enter($this->tenant->id);
    $this->person = Person::factory()->create();
});

afterEach(function (): void {
    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('CA-068-03: recordRead escribe event read con changes nulo, el sujeto por alias y el context', function (): void {
    $before = AuditLog::count();

    app(AuditRecorder::class)->recordRead('person', (int) $this->person->id, (string) $this->person->public_id, ['purpose' => 'puerta', 'entries' => 3, 'photos' => 2]);

    $log = AuditLog::query()->where('event', 'read')->latest('id')->firstOrFail();

    expect(AuditLog::count())->toBe($before + 1)
        ->and($log->auditable_type)->toBe('person')
        ->and($log->auditable_id)->toBe((int) $this->person->id)
        ->and($log->auditable_public_id)->toBe((string) $this->person->public_id)
        ->and($log->changes)->toBeNull()
        ->and($log->context)->toEqual(['purpose' => 'puerta', 'entries' => 3, 'photos' => 2])
        ->and((int) $log->tenant_id)->toBe((int) $this->tenant->id);
});

test('CA-068-03: un alias que no está en el morph map, o un context con texto libre, lanza y no escribe nada', function (): void {
    $before = AuditLog::count();
    $recorder = app(AuditRecorder::class);

    expect(fn () => $recorder->recordRead('no_existe', 1, null, []))->toThrow(RuntimeException::class)
        ->and(fn () => $recorder->recordRead('person', (int) $this->person->id, null, ['nombre' => 'Ana Pérez García']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $recorder->recordRead('person', (int) $this->person->id, null, ['Mala Clave' => 'x']))->toThrow(InvalidArgumentException::class)
        ->and(AuditLog::count())->toBe($before);
});

test('ADR-068 §5.4: sin centro en curso lanza (sin rastro no hay datos) y en modo plataforma de mantenimiento también', function (): void {
    $id = (int) $this->person->id;
    $before = DB::connection('pgsql_platform')->table('audit_logs')->count();
    app(TenantContext::class)->leave();

    expect(fn () => app(AuditRecorder::class)->recordRead('person', $id, null, []))->toThrow(RuntimeException::class);

    expect(fn () => app(TenantContext::class)->runAsPlatform(PlatformAccessPurpose::Mantenimiento, fn () => app(AuditRecorder::class)->recordRead('person', $id, null, [])))
        ->toThrow(RuntimeException::class)
        ->and(DB::connection('pgsql_platform')->table('audit_logs')->count())->toBe($before);
});
