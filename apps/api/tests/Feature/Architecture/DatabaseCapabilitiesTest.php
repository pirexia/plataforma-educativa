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
// Excepción conocida y aceptada, fijada aquí para que no cambie sin querer:
// PUBLIC tiene `TEMP` por defecto en PostgreSQL, así que se pueden crear
// tablas temporales; son locales a la sesión y desaparecen con ella, no
// alteran el esquema compartido.

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

test('#414: la excepción conocida: sí puede crear tablas TEMP, locales a la sesión (no alteran el esquema compartido)', function (): void {
    expect(createAttemptSqlState('CREATE TEMP TABLE cap_probe_temp (id int)'))->toBeNull();

    $persisted = DB::connection('pgsql_owner')->selectOne("SELECT count(*) AS n FROM pg_tables WHERE tablename = 'cap_probe_temp' AND schemaname = 'public'")->n;

    expect((int) $persisted)->toBe(0);
});
