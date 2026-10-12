<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

pest()->group('arch');

// Issue #414 (1.11-a), INV-001, ADR-033 §5, ADR-057 §5.2, INV-015. Lo que un
// escáner estático no puede ver (`$clase::método()`, `config($variable)`,
// nombres armados en ejecución) lo cubre el MOTOR: el rol de la aplicación
// (`plataforma_app`, la conexión `pgsql`) no puede crear objetos de esquema,
// así que ni un `TenantMigration` colado en tiempo de ejecución ni DDL a mano
// funcionan con él. Solo el propietario (`pgsql_owner`, por CLI) migra.
//
// TEMP (decisión del usuario sobre la revisión de 1.11-a): PostgreSQL lo
// concede a PUBLIC por defecto, y las tablas temporales se buscan antes que
// `public` salvo que `pg_temp` esté al final del `search_path`. Se revoca a
// PUBLIC en el aprovisionamiento (01-tenancy.sql.tpl, bases-test-paralelo.sh;
// SYSADMIN.md §2b para bases ya existentes) y config/database.php pone
// `pg_temp` al final. Ningún código de la aplicación usa tablas temporales.

function createAttemptSqlState(string $sql): ?string
{
    try {
        DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->statement($sql));
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }

    return null;
}

test('#414 INV-001: la conexión de aplicación no puede crear tablas, esquemas ni funciones (42501 insufficient_privilege)', function (): void {
    expect(DB::connection('pgsql')->selectOne('SELECT current_user AS u')->u)->toBe('plataforma_app')
        ->and(createAttemptSqlState('CREATE TABLE public.cap_probe_never (id int)'))->toBe('42501')
        ->and(createAttemptSqlState('CREATE TABLE app.cap_probe_never (id int)'))->toBe('42501')
        ->and(createAttemptSqlState('CREATE SCHEMA cap_probe_never'))->toBe('42501')
        ->and(createAttemptSqlState('CREATE FUNCTION public.cap_probe_never() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$'))->toBe('42501')
        ->and(createAttemptSqlState('CREATE VIEW public.cap_probe_never AS SELECT 1'))->toBe('42501');
});

test('#414: los privilegios del catálogo coinciden: sin CREATE en public ni app ni en la base, y sin ser propietaria de ninguna tabla de negocio', function (): void {
    $row = DB::connection('pgsql')->selectOne(<<<'SQL'
        SELECT has_schema_privilege(current_user, 'public', 'CREATE') AS public_create,
               has_schema_privilege(current_user, 'app', 'CREATE') AS app_create,
               has_database_privilege(current_user, current_database(), 'CREATE') AS db_create,
               (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tableowner = current_user) AS owned_tables
        SQL);

    expect($row->public_create)->toBeFalse()
        ->and($row->app_create)->toBeFalse()
        ->and($row->db_create)->toBeFalse()
        ->and((int) $row->owned_tables)->toBe(0);
});

test('#414: no puede alterar ni borrar tablas del esquema (ALTER/DROP exigen ser propietario)', function (): void {
    expect(createAttemptSqlState('ALTER TABLE tenants ADD COLUMN cap_probe_never int'))->toBe('42501')
        ->and(createAttemptSqlState('DROP TABLE tenant_settings'))->toBe('42501')
        ->and(createAttemptSqlState('CREATE INDEX cap_probe_never ON tenant_settings (tenant_id)'))->toBe('42501');
});

test('#414: sin TEMP: plataforma_app no puede crear tablas temporales (42501); sondas con ON COMMIT DROP por si algún día pudiera', function (): void {
    $privileges = DB::connection('pgsql')->selectOne("SELECT has_database_privilege(current_user, current_database(), 'TEMP') AS temp_privilege");

    expect($privileges->temp_privilege)->toBeFalse()
        ->and(createAttemptSqlState('CREATE TEMP TABLE cap_probe_temp (id int) ON COMMIT DROP'))->toBe('42501');
});

test('#414: pg_temp va al final del search_path de las tres conexiones, no implícito al principio', function (): void {
    foreach (['pgsql', 'pgsql_owner', 'pgsql_platform'] as $connection) {
        expect(config("database.connections.{$connection}.search_path"))->toBe('public,pg_temp');

        $path = DB::connection($connection)->selectOne("SELECT current_setting('search_path') AS p")->p;

        expect(str_replace(' ', '', $path))->toBe('public,pg_temp', "{$connection}: search_path real");
    }
});
