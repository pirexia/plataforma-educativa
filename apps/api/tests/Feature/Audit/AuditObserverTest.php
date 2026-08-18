<?php

use App\Models\AuditLog;
use App\Models\Person;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * ADR-035 §11 (0.9.e): los siete casos de la tabla de tests que exige la
 * decisión, sobre el ciclo de vida real de Person a través de PostgreSQL
 * — no dobles, para probar el observer de verdad (0.9.c).
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->enter($this->tenant->id);
});

afterEach(function (): void {
    app(TenantContext::class)->leave();
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

// ADR-035 §11 test 1: el caso de uso.
test('un UPDATE sobre document_number se redacta y el valor no aparece en ningún punto de la fila', function (): void {
    $person = Person::factory()->create(['document_type' => 'dni', 'document_number' => '11111111H']);

    $person->update(['document_number' => '99999999R']);

    $log = AuditLog::where('auditable_id', $person->id)->where('event', 'updated')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->changes['document_number'])->toEqual([
            'redacted' => 'identifier', 'from_empty' => false, 'to_empty' => false,
        ]);

    $encoded = json_encode([$log->changes, $log->context]);
    expect($encoded)->not->toContain('11111111H')->not->toContain('99999999R');
});

// ADR-035 §11 test 2: el caso de volumen, no el updated.
test('un created de Person no escribe ningún valor identificativo', function (): void {
    $person = Person::factory()->create([
        'document_type' => 'dni',
        'document_number' => '22222222J',
        'contact_phone' => '600111222',
    ]);

    $log = AuditLog::where('auditable_id', $person->id)->where('event', 'created')->firstOrFail();

    foreach (['given_name', 'family_name_1', 'family_name_2', 'document_type', 'document_number', 'contact_email', 'contact_phone'] as $identifyingAttribute) {
        expect($log->changes)->toHaveKey($identifyingAttribute);
        expect($log->changes[$identifyingAttribute]['redacted'] ?? null)->toBe('identifier');
    }

    // locale sí está en la lista de inclusión de Person (ADR-035 §8): se
    // registra en claro, no es un identificador.
    expect($log->changes['locale'])->toEqual(['from' => null, 'to' => 'es']);

    $encoded = json_encode($log->changes);
    expect($encoded)->not->toContain('22222222J')->not->toContain('600111222');
});

test('un delete y un restore no duplican fila y registran deleted_at correctamente', function (): void {
    $person = Person::factory()->create(['document_type' => 'dni', 'document_number' => '33333333P']);

    $person->delete();
    $person->restore();

    $rows = AuditLog::where('auditable_id', $person->id)->orderBy('id')->pluck('event');

    // created, deleted, restored — nunca un "updated" espurio del ciclo
    // interno de SoftDeletes (bug propio corregido en 0.9, ver commit).
    expect($rows->all())->toBe(['created', 'deleted', 'restored']);

    $deletedLog = AuditLog::where('auditable_id', $person->id)->where('event', 'deleted')->firstOrFail();
    $restoredLog = AuditLog::where('auditable_id', $person->id)->where('event', 'restored')->firstOrFail();

    expect($deletedLog->changes['deleted_at']['from'])->toBeNull()
        ->and($deletedLog->changes['deleted_at']['to'])->not->toBeNull()
        ->and($restoredLog->changes['deleted_at']['to'])->toBeNull();
});

// Regla 1 de ADR-035 §4 sobre el modelo real, no un doble: password y
// remember_token nunca en claro, ni siquiera con el patrón global —
// User los redacta por estar fuera de auditRecordedAttributes()
// (Selective), y el patrón global los redactaría igualmente si no lo
// estuvieran.
test('un cambio de password/remember_token/email en User nunca se registra en claro', function (): void {
    $user = User::factory()->create(['email' => 'antes@example.com']);

    $user->forceFill([
        'password' => bcrypt('un-secreto-nuevo'),
        'remember_token' => 'un-token-nuevo',
        'email' => 'despues@example.com',
    ])->save();

    $log = AuditLog::where('auditable_id', $user->id)->where('event', 'updated')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->changes['password'])->toEqual(['redacted' => 'secret'])
        ->and($log->changes['remember_token'])->toEqual(['redacted' => 'secret'])
        ->and($log->changes['email'])->toEqual(['redacted' => 'identifier', 'from_empty' => false, 'to_empty' => false]);

    $encoded = json_encode($log->changes);
    expect($encoded)
        ->not->toContain('un-secreto-nuevo')
        ->not->toContain('un-token-nuevo')
        ->not->toContain('antes@example.com')
        ->not->toContain('despues@example.com');
});

// ADR-035 §11 test 7: la propiedad legal, no el mecanismo.
test('tras anonimizar una persona, ninguna fila de audit_logs referida a ella contiene una cadena que permita reidentificarla', function (): void {
    $originalDocument = '44444444C';
    $originalPhone = '600999888';

    $person = Person::factory()->create([
        'document_type' => 'dni',
        'document_number' => $originalDocument,
        'contact_phone' => $originalPhone,
    ]);

    $person->update(['document_number' => '55555555V']);
    $person->update(['contact_phone' => '600777666']);
    $person->delete();
    $person->restore();

    // Simulación mínima de la anonimización de ADR-004 nivel 2 (el
    // procedimiento real es trabajo de REQ-PRIV-006, ver ADR-035 §10):
    // sustituir los identificadores por valores irreversibles.
    $person->forceFill([
        'given_name' => 'ANONIMIZADO',
        'family_name_1' => 'ANONIMIZADO',
        'family_name_2' => null,
        'document_number' => null,
        'document_type' => null,
        'contact_email' => null,
        'contact_phone' => null,
    ])->save();

    $rows = AuditLog::where('auditable_id', $person->id)->get(['changes', 'context']);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        $encoded = json_encode([$row->changes, $row->context]);

        expect($encoded)
            ->not->toContain($originalDocument)
            ->not->toContain('55555555V')
            ->not->toContain($originalPhone)
            ->not->toContain('600777666');
    }
});

// Hallazgo Media de la revisión independiente de 0.9 (security-reviewer):
// AuditRecorder no se protegía si isPlatformMode() y hasTenant() coinciden
// (TenantContext::runAsPlatform() no limpia tenantId()). No ocurre hoy con
// ningún consumidor real, pero es el hueco que se activaría en cuanto
// exista el primero. Falla en cerrado: lanza en vez de escribir con
// tenant_id potencialmente equivocado.
test('el observer no escribe en audit_logs mientras TenantContext está en modo plataforma', function (): void {
    $person = Person::factory()->create();

    app(TenantContext::class)->runAsPlatform(function () use ($person): void {
        expect(fn () => $person->update(['contact_phone' => '600111222']))
            ->toThrow(RuntimeException::class, 'modo plataforma');
    });
});
