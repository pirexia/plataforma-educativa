<?php

use App\Models\AuditLog;
use App\Modules\Alum\Domain\Models\Student;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-001, RN-ALUM-01, RN-ALUM-02, datos.md §1.1 y §4, INV-003, INV-004, INV-005, ADR-029, ADR-034 §1, ADR-035:
// esquema de `students` (faceta de `people`), baja lógica y auditoría con el alias del morph map.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('datos.md §1.1 ADR-029: students guarda solo public_id ULID y person_id (sin nombre, documento, nacimiento ni contacto), con campos de auditoría y borrado lógico', function (): void {
    $columns = DB::connection('pgsql_owner')->table('information_schema.columns')
        ->where('table_name', 'students')->pluck('data_type', 'column_name')->all();

    expect(array_keys($columns))->toEqualCanonicalizing(['id', 'tenant_id', 'public_id', 'person_id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by'])
        ->and($columns['public_id'])->toBe('character')
        ->and($columns['deleted_at'])->toBe('timestamp with time zone')
        ->and($columns['created_at'])->toBe('timestamp with time zone');
});

test('RN-ALUM-01 ADR-034 §1: una persona tiene como mucho una faceta de alumno viva; tras la baja lógica puede volver a darse de alta', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $first = Student::factory()->create();

        expect(fn () => DB::transaction(fn () => Student::factory()->create(['person_id' => $first->person_id])))->toThrow(QueryException::class);

        $first->delete();

        expect(Student::factory()->create(['person_id' => $first->person_id])->exists)->toBeTrue()
            ->and(Student::withTrashed()->where('person_id', $first->person_id)->count())->toBe(2);
    });
});

test('datos.md §1.1: la FK a people es compuesta con el tenant; una persona de otro centro no se puede referenciar', function (): void {
    $other = Tenant::factory()->create();
    $foreignPersonId = app(TenantContext::class)->runFor($other->id, fn () => Student::factory()->create()->person_id);

    expect(fn () => app(TenantContext::class)->runFor($this->tenant->id, fn () => DB::transaction(fn () => Student::factory()->create(['person_id' => $foreignPersonId]))))->toThrow(QueryException::class);
});

test('INV-003 ADR-035 ADR-034 §3: crear y dar de baja un Student se audita con el alias alum_student', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $student = Student::factory()->create();
        $student->delete();

        $events = AuditLog::query()->where('auditable_type', 'alum_student')->where('auditable_id', $student->id)->pluck('event')->all();

        expect($events)->toContain('created')->and($events)->toContain('deleted');
    });
});
