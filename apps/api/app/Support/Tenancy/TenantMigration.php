<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * ADR-033 §5, §6, §9: lo que toda tabla de negocio necesita, en un solo
 * sitio, para no depender de que cada migración se acuerde de las cuatro
 * cosas por separado.
 *
 * Va explícitamente por la conexión pgsql_owner: no asume que la conexión
 * ambiente ya sea el propietario (cierto dentro de una migración real
 * ejecutada con --database=pgsql_owner, falso en cualquier otro contexto
 * que reutilice este helper, como un fixture de test).
 */
final class TenantMigration
{
    public static function tenantTable(string $table, Closure $columns): void
    {
        self::assertSafeIdentifier($table);

        Schema::connection('pgsql_owner')->create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->id();
            $blueprint->foreignId('tenant_id');

            $columns($blueprint);

            // Clave foránea compuesta (ADR-033 §6): RLS no protege la
            // integridad referencial, la comprobación de un FK la ejecuta
            // el sistema saltándose las políticas. Sin este índice único,
            // un hijo no puede declarar FOREIGN KEY (tenant_id, padre_id)
            // REFERENCES padre (tenant_id, id).
            $blueprint->unique(['tenant_id', 'id']);
        });

        $connection = DB::connection('pgsql_owner');

        $connection->statement("ALTER TABLE {$table} ALTER COLUMN tenant_id SET DEFAULT app.current_tenant_id()");
        $connection->statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        $connection->statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        $connection->statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
                USING      (tenant_id = app.current_tenant_id())
                WITH CHECK (tenant_id = app.current_tenant_id())
            SQL);
    }

    private static function assertSafeIdentifier(string $name): void
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Nombre de tabla no válido para tenantTable(): {$name}");
        }
    }
}
