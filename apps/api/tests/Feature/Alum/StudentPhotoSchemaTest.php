<?php

use App\Models\AuditLog;
use App\Modules\Alum\Domain\Models\Student;
use App\Modules\Alum\Domain\Models\StudentPhoto;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// REQ-ALUM-003 (1.15-c T1), datos.md §1.4, §3, §4, RN-ALUM-50 (una viva por alumno), RN-ALUM-51 (solo jpeg y png),
// CA-ALUM-061 (sin acciones referenciales; la tabla no lleva `academic_year_id`, no aplica AR-13), CA-ALUM-018b (RLS),
// INV-001, INV-003, INV-004, INV-005, ADR-029, ADR-035: esquema real de `student_photos` con base de datos real
// (ADR-033 §10). CA-ALUM-062 (AR-10): el modelo entra en el mapa de `ScopedQueryConfinementTest` con su servicio
// sancionado en T2 (`StudentPhotoService`); hasta entonces no hay fichero que nombrar.

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
});

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

/** SQLSTATE del error que lanza la escritura (en su propia transacción), o `null` si se acepta. */
function studentPhotoSqlState(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $e) {
        return $e->errorInfo[0] ?? null;
    }

    return null;
}

test('datos.md §1.4 ADR-029: student_photos tiene las columnas y tipos de la especificación (content_type, byte_size bigint), RLS forzada, campos de autoría y borrado lógico', function (): void {
    $columns = DB::connection('pgsql_owner')->table('information_schema.columns')
        ->where('table_name', 'student_photos')->get()->keyBy('column_name');

    expect($columns->keys()->all())->toEqualCanonicalizing([
        'id', 'tenant_id', 'public_id', 'student_id', 'object_key', 'content_type', 'byte_size', 'created_at', 'updated_at',
        'deleted_at', 'created_by', 'updated_by',
    ])
        ->and($columns['public_id']->data_type)->toBe('character')
        ->and($columns['object_key']->data_type)->toBe('text')
        ->and($columns['content_type']->data_type)->toBe('text')
        ->and($columns['byte_size']->data_type)->toBe('bigint')
        ->and(collect(['object_key', 'content_type', 'byte_size', 'student_id'])->map(fn (string $c): string => $columns[$c]->is_nullable)->unique()->all())->toBe(['NO'])
        ->and($columns['deleted_at']->data_type)->toBe('timestamp with time zone');

    $rls = DB::connection('pgsql_owner')->selectOne("SELECT relrowsecurity AS on, relforcerowsecurity AS forced FROM pg_class WHERE oid = 'student_photos'::regclass");

    expect($rls->on)->toBeTrue()->and($rls->forced)->toBeTrue();
});

test('CA-ALUM-061 datos.md §1.4: sin disparador de curso académico, ninguna FK usa acciones referenciales y la FK a students es compuesta y MATCH SIMPLE', function (): void {
    $trigger = DB::connection('pgsql_owner')->selectOne(
        "SELECT count(*) AS n FROM pg_trigger t WHERE t.tgrelid = 'student_photos'::regclass AND t.tgname = 'academic_year_write_guard' AND NOT t.tgisinternal"
    );
    $referential = DB::connection('pgsql_owner')->select(
        "SELECT conname FROM pg_constraint WHERE conrelid = 'student_photos'::regclass AND contype = 'f' AND (confdeltype <> 'a' OR confupdtype <> 'a')"
    );
    $matchTypes = DB::connection('pgsql_owner')->table('pg_constraint')
        ->whereRaw("conrelid = 'student_photos'::regclass")->where('contype', 'f')->pluck('confmatchtype', 'conname');

    expect((int) $trigger->n)->toBe(0)
        ->and($referential)->toBe([])
        ->and($matchTypes->all())->each->toBe('s');
});

test('datos.md §3 AR-16: la FK a students conserva su índice completo (tenant_id, student_id) y existe el único parcial de RN-ALUM-50', function (): void {
    $uncovered = DB::connection('pgsql_owner')->select(<<<'SQL'
        SELECT c.conname
        FROM pg_constraint c
        WHERE c.contype = 'f' AND c.conrelid = 'student_photos'::regclass
          AND c.conname NOT LIKE '%\_created\_by\_foreign' AND c.conname NOT LIKE '%\_updated\_by\_foreign'
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i
              WHERE i.indrelid = c.conrelid AND i.indpred IS NULL
                AND (i.indkey::int2[])[0:cardinality(c.conkey) - 1] = c.conkey
          )
        SQL);
    $indexes = DB::connection('pgsql_owner')->table('pg_indexes')->where('tablename', 'student_photos')->pluck('indexdef', 'indexname');

    expect($uncovered)->toBe([])
        ->and($indexes['student_photos_tenant_student_live_unique'])->toContain('UNIQUE', '(tenant_id, student_id)', 'deleted_at IS NULL');
});

test('RN-ALUM-51 datos.md §1.4: los CHECK del motor aceptan solo image/jpeg e image/png (no WebP) y un tamaño positivo', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $student = Student::factory()->create();

        foreach (['image/webp', 'image/gif', 'application/pdf', ''] as $type) {
            expect(studentPhotoSqlState(fn () => StudentPhoto::factory()->create(['student_id' => $student->id, 'content_type' => $type])))->toBe('23514', $type);
        }

        foreach ([0, -1] as $size) {
            expect(studentPhotoSqlState(fn () => StudentPhoto::factory()->create(['student_id' => $student->id, 'byte_size' => $size])))->toBe('23514', (string) $size);
        }

        // Un tamaño por encima de 2^31 cabe en `bigint`.
        expect(studentPhotoSqlState(fn () => StudentPhoto::factory()->create(['student_id' => $student->id, 'content_type' => 'image/png', 'byte_size' => 3_000_000_000])))->toBeNull();
    });
});

test('RN-ALUM-50: una sola fotografía viva por alumno; tras la baja lógica puede subirse otra y la anterior se conserva', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $first = StudentPhoto::factory()->create();
        $same = ['student_id' => $first->student_id];

        expect(studentPhotoSqlState(fn () => StudentPhoto::factory()->create($same)))->toBe('23505');

        $first->delete();
        $second = StudentPhoto::factory()->create($same);

        expect($second->exists)->toBeTrue()
            ->and(StudentPhoto::withTrashed()->where('student_id', $first->student_id)->count())->toBe(2)
            ->and(studentPhotoSqlState(fn () => StudentPhoto::factory()->create($same)))->toBe('23505')
            ->and(StudentPhoto::factory()->create()->exists)->toBeTrue();   // otro alumno
    });
});

test('CA-ALUM-018b INV-001: la FK a students es compuesta con el tenant y la RLS oculta las fotografías de otro centro', function (): void {
    $other = Tenant::factory()->create();
    $foreignStudentId = app(TenantContext::class)->runFor($other->id, fn () => StudentPhoto::factory()->create()->student_id);

    app(TenantContext::class)->runFor($this->tenant->id, function () use ($foreignStudentId): void {
        expect(studentPhotoSqlState(fn () => StudentPhoto::factory()->create(['student_id' => $foreignStudentId])))->toBe('23503')
            ->and(StudentPhoto::query()->count())->toBe(0)
            ->and(DB::table('student_photos')->count())->toBe(0);
    });
});

test('INV-003 INV-004 ADR-035 ADR-034 §3: crear y anular una fotografía se audita con el alias alum_student_photo sin registrar la clave del objeto; el borrado es lógico', function (): void {
    app(TenantContext::class)->runFor($this->tenant->id, function (): void {
        $photo = StudentPhoto::factory()->create(['object_key' => 'tenants/ficticio/alum/student-photos/clave-reservada.jpg']);
        $photo->delete();

        $logs = AuditLog::query()->where('auditable_type', 'alum_student_photo')->where('auditable_id', $photo->id)->get();
        $payload = $logs->map(fn (AuditLog $log): string => json_encode($log->changes))->implode('|');

        expect($logs->pluck('event')->all())->toContain('created')->and($logs->pluck('event')->all())->toContain('deleted')
            ->and($payload)->not->toContain('clave-reservada')->and($logs->firstWhere('event', 'created')->changes['content_type']['to'])->toBe('image/jpeg')
            ->and(StudentPhoto::withTrashed()->whereKey($photo->id)->exists())->toBeTrue()
            ->and(StudentPhoto::query()->whereKey($photo->id)->exists())->toBeFalse();
    });
});
