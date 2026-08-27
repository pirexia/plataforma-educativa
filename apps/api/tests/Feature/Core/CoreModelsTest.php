<?php

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ModuleSubscription;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Modules\Auth\Domain\Models\MfaReset;
use App\Modules\Auth\Domain\Models\UserMfaExemption;
use App\Modules\Auth\Domain\Models\UserMfaObligation;
use App\Modules\Core\Domain\Models\DataExport;
use App\Support\Audit\Auditable;
use App\Support\Audit\AuditValuePolicy;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

// ADR-034 §3/§6 (0.8.9): modelos y traits sobre las tablas de 0.8.2-0.8.8.

afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

test('todo modelo de tenant núcleo está en el morph map', function (): void {
    $map = Relation::morphMap();

    foreach ([Person::class, User::class, Role::class, AcademicYear::class, ModuleSubscription::class] as $class) {
        expect(in_array($class, $map, true))->toBeTrue("{$class} no está en el morph map (ADR-034 §3)");
    }
});

// ADR-035 §11 test 6: impide la erosión modelo a modelo. Todo modelo del
// morph map implementa Auditable, y el conjunto Full es exactamente el
// vigente tras ADR-036 (que sustituye la fila `Tenant` de ADR-035 §8:
// Tenant no entra en este morph map, vive en la conexión de plataforma) —
// añadir un modelo a Full exige tocar este test. DataExport (datos.md
// §A.4, paso 1.1) se suma en Full: no contiene datos personales.
// MfaReset/UserMfaObligation/UserMfaExemption (REQ-AUTH-003, 1.3,
// datos.md §C.5/§C.6/§C.6.1) se suman en Full: sin datos personales más
// allá de la relación con el usuario (MfaReset.reason es la única
// excepción y es contenido, no dato personal, mismo criterio que
// roles.name — ADR-035 §8).
test('todo modelo del morph map es Auditable y el conjunto Full coincide con ADR-035 §8', function (): void {
    $map = Relation::morphMap();

    $fullPolicyModels = [];

    foreach ($map as $class) {
        expect(is_a($class, Auditable::class, true))
            ->toBeTrue("{$class} está en el morph map pero no implementa Auditable (ADR-035 §2)");

        if ((new $class)->auditValuePolicy() === AuditValuePolicy::Full) {
            $fullPolicyModels[] = $class;
        }
    }

    expect($fullPolicyModels)->toEqualCanonicalizing([
        AcademicYear::class, Role::class, ModuleSubscription::class, DataExport::class,
        MfaReset::class, UserMfaObligation::class, UserMfaExemption::class,
    ]);
});

test('Person y User se relacionan y HasPublicId rellena public_id', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->enter($tenant->id);
    $person = Person::factory()->create();
    $user = User::factory()->for($person)->create();

    expect($person->public_id)->not->toBeEmpty()
        ->and($user->public_id)->not->toBeEmpty()
        ->and($user->person->is($person))->toBeTrue()
        ->and($person->user->is($user))->toBeTrue();

    $context->leave();
});

test('delete() en un TenantModel es lógico, no físico', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $year = AcademicYear::create([
        'code' => '2026-2027', 'status' => 'planificacion',
        'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
    ]);

    $year->delete();

    $stillInDb = DB::table('academic_years')->where('id', $year->id)->whereNotNull('deleted_at')->exists();
    $goneFromEloquent = AcademicYear::query()->whereKey($year->id)->exists();

    $context->leave();

    expect($stillInDb)->toBeTrue()
        ->and($goneFromEloquent)->toBeFalse();
});

test('AuditLog es append-only también a través de Eloquent', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $log = AuditLog::create([
        'occurred_at' => now(),
        'actor_type' => 'system',
        'auditable_type' => 'academic_year',
        'auditable_id' => 1,
        'event' => 'created',
    ]);

    expect(fn () => $log->update(['event' => 'updated']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);

    $context->leave();
});

test('created_by/updated_by quedan en null sin usuario autenticado', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->enter($tenant->id);

    $year = AcademicYear::create([
        'code' => '2027-2028', 'status' => 'planificacion',
        'starts_on' => '2027-09-01', 'ends_on' => '2028-06-30',
    ]);

    $context->leave();

    expect($year->created_by)->toBeNull()
        ->and($year->updated_by)->toBeNull();
});
