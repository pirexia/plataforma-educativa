<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\FamUnitTestHelpers as Fam;

/**
 * Recuperación de la migración `2026_10_27_100100_add_principal_guardian_same_unit_foreign_key` (issue #469, hallazgos de
 * `db-reviewer`). Va en `tests/Concurrency/` porque necesita datos CONFIRMADOS: el DDL con `CONCURRENTLY` espera a las
 * transacciones abiertas sobre las tablas y no puede correr dentro de la transacción envolvente de `tests/Feature/`.
 * Cada test restablece el esquema con `up()` en un `finally`.
 */
afterEach(function (): void {
    DB::connection('pgsql_platform')->table('tenants')->delete();
});

function famRecoveryMigration(): object
{
    return require app_path('Modules/FamUnit/Database/migrations/2026_10_27_100100_add_principal_guardian_same_unit_foreign_key.php');
}

test('RN-FAM-UNIT-02 #469: si VALIDATE falla por una fila infractora preexistente, la FK queda NOT VALID —protege lo nuevo— y, corregido el dato, relanzar up() la valida', function (): void {
    [$tenant, $admin] = Fam::setup('fam-rec-validate');
    $unitA = Fam::unitId($tenant, $admin, 'A');
    $unitB = Fam::unitId($tenant, $admin, 'B');
    [, $guardianB] = Fam::familyUser($tenant, $admin, 'b', $unitB);
    $migration = famRecoveryMigration();
    $owner = DB::connection('pgsql_owner');
    $fk = fn () => $owner->selectOne("SELECT convalidated AS v FROM pg_constraint WHERE conname = 'family_units_principal_same_unit_foreign'");

    $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);

    try {
        // Estado «anterior a la migración» con una fila infractora: el principal de A es un tutor de B (la FK simple antigua lo admitía).
        $migration->down();
        $owner->statement('UPDATE family_units SET principal_guardian_id = (SELECT id FROM guardians WHERE public_id = ?) WHERE public_id = ?', [$guardianB, $unitA]);

        $failed = null;

        try {
            $migration->up();
        } catch (QueryException $e) {
            $failed = $e->errorInfo[0] ?? null;
        }

        // 23503: VALIDATE encuentra la fila infractora; la FK queda creada pero sin validar.
        expect($failed)->toBe('23503')
            ->and($fk()->v)->toBeFalse();

        // Y NOT VALID ya protege lo nuevo: se corrige la fila infractora y, al intentar de nuevo un principal ajeno, el motor lo rechaza.
        $owner->statement('UPDATE family_units SET principal_guardian_id = NULL WHERE public_id = ?', [$unitA]);
        $owner->statement('UPDATE family_units SET principal_guardian_id = (SELECT id FROM guardians WHERE public_id = ?) WHERE public_id = ?', [$guardianB, $unitB]);

        $nuevo = null;

        try {
            $owner->statement('UPDATE family_units SET principal_guardian_id = (SELECT id FROM guardians WHERE public_id = ?) WHERE public_id = ?', [$guardianB, $unitA]);
        } catch (QueryException $e) {
            $nuevo = $e->errorInfo[0] ?? null;
        }

        expect($nuevo)->toBe('23503');

        // Corregido el dato, relanzar up() valida sin tocar nada más.
        $migration->up();
        expect($fk()->v)->toBeTrue();
    } finally {
        $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);
        $owner->statement('UPDATE family_units SET principal_guardian_id = NULL WHERE tenant_id = ?', [$tenant->id]);
        $migration->up();
        $owner->statement("select set_config('app.tenant_id', '', false)");
    }

    expect($fk()->v)->toBeTrue();
});

test('RN-FAM-UNIT-02 #469: un índice INVALID residual de un CREATE INDEX CONCURRENTLY interrumpido se descarta y se recrea bien al relanzar up()', function (): void {
    [$tenant, $admin] = Fam::setup('fam-rec-index');
    $unit = Fam::unitId($tenant, $admin);
    Fam::familyUser($tenant, $admin, 'tut', $unit);
    $migration = famRecoveryMigration();
    $owner = DB::connection('pgsql_owner');
    $index = fn () => $owner->selectOne("SELECT i.indisvalid AS valid, pg_get_indexdef(i.indexrelid) AS def FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = 'guardians_tenant_unit_id_unique'");

    $owner->statement("select set_config('app.tenant_id', ?, false)", [(string) $tenant->id]);

    try {
        $migration->down();

        // Un CONCURRENTLY que falla a mitad (división por cero en una fila) deja el índice INVALID con el mismo nombre.
        try {
            $owner->statement('CREATE INDEX CONCURRENTLY guardians_tenant_unit_id_unique ON guardians (tenant_id, family_unit_id, (1 / (id - id)))');
        } catch (QueryException) {
            // Esperado.
        }

        expect($index()->valid)->toBeFalse();

        // `IF NOT EXISTS` lo daría por bueno: la migración lo detecta, lo descarta y lo recrea.
        $migration->up();

        expect($index()->valid)->toBeTrue()
            ->and($index()->def)->toContain('UNIQUE')->toContain('(tenant_id, family_unit_id, id)');
    } finally {
        $owner->statement("select set_config('app.tenant_id', '', false)");
        $migration->up();
    }
});
