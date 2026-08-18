<?php

use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\ModuleSubscription;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
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
