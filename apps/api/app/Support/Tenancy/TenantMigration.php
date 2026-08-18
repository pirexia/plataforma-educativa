<?php

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * ADR-033 §5, §6, §9 y ADR-034 §6, §7: lo que toda tabla de negocio necesita,
 * en un solo sitio, para no depender de que cada migración se acuerde de las
 * cuatro cosas por separado.
 *
 * Va explícitamente por la conexión pgsql_owner: no asume que la conexión
 * ambiente ya sea el propietario (cierto dentro de una migración real
 * ejecutada con --database=pgsql_owner, falso en cualquier otro contexto
 * que reutilice este helper, como un fixture de test).
 */
final class TenantMigration
{
    /**
     * Tabla de tenant ordinaria: id, tenant_id (DEFAULT+RLS), columnas
     * propias, autoría, timestamps, borrado lógico y UNIQUE (tenant_id, id).
     *
     * created_by/updated_by (ADR-034 §6) se añaden siempre como columnas
     * nullable — nullable porque hay actores sin usuario: consola, jobs del
     * sistema, seeders, importaciones. Su clave foránea compuesta a `users`
     * solo se añade aquí si `users` ya existe en el momento de crear la
     * tabla (cierto desde 0.8.5 en adelante); las dos tablas creadas antes
     * de que `users` exista (`academic_years`, `people`, 0.8.2/0.8.3) la
     * reciben más tarde con addAuthorshipForeignKeys(), llamado desde la
     * migración de 0.8.4 justo después de crear `users`.
     */
    public static function tenantTable(string $table, Closure $columns): void
    {
        self::assertSafeIdentifier($table);

        Schema::connection('pgsql_owner')->create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->id();
            $blueprint->foreignId('tenant_id');

            $columns($blueprint);

            $blueprint->unsignedBigInteger('created_by')->nullable();
            $blueprint->unsignedBigInteger('updated_by')->nullable();

            $blueprint->timestampsTz();
            $blueprint->softDeletesTz();

            // Clave foránea compuesta (ADR-033 §6): RLS no protege la
            // integridad referencial, la comprobación de un FK la ejecuta
            // el sistema saltándose las políticas. Sin este índice único,
            // un hijo no puede declarar FOREIGN KEY (tenant_id, padre_id)
            // REFERENCES padre (tenant_id, id).
            $blueprint->unique(['tenant_id', 'id']);
        });

        self::applyTenantDefaultsAndRls($table);

        // hasColumn, no hasTable: hasta 0.8.4 existe un `users` heredado del
        // starter kit de Laravel sin tenant_id ni UNIQUE (tenant_id, id) —
        // un FK compuesto contra esa tabla fallaría igual que contra una
        // tabla inexistente. hasColumn distingue ese `users` provisional
        // del `users` de tenant que crea 0.8.4. Cuando $table es el propio
        // `users`, la comprobación ya ve la columna (la acabamos de crear
        // arriba) y añade el FK autorreferenciado sin caso especial.
        if (Schema::connection('pgsql_owner')->hasColumn('users', 'tenant_id')) {
            self::addAuthorshipForeignKeys($table);
        }
    }

    /**
     * Tabla de tenant append-only (ADR-034 §3, §6): sin deleted_at (borrado
     * lógico en una tabla append-only no significa nada), sin
     * created_by/updated_by (lleva su propio actor_user_id, más rico), y
     * con REVOKE UPDATE, DELETE para que la inmutabilidad la garantice el
     * motor y no una convención de aplicación (misma lección que el bug 6
     * de 0.7 con failed_jobs: un REVOKE que no se escribe explícitamente no
     * existe).
     */
    public static function tenantTableAppendOnly(string $table, Closure $columns): void
    {
        self::assertSafeIdentifier($table);

        Schema::connection('pgsql_owner')->create($table, function (Blueprint $blueprint) use ($columns): void {
            $blueprint->id();
            $blueprint->foreignId('tenant_id');

            $columns($blueprint);

            $blueprint->unique(['tenant_id', 'id']);
        });

        self::applyTenantDefaultsAndRls($table);

        DB::connection('pgsql_owner')->statement("REVOKE UPDATE, DELETE ON {$table} FROM plataforma_app");
    }

    /**
     * Columna + índice + clave foránea compuesta (tenant_id, $column)
     * REFERENCES $referencedTable (tenant_id, id) en una sola llamada
     * (ADR-033 §6, ADR-034 §4/§7). $column es NOT NULL siempre —
     * ADR-034 §4: "academic_year_id es NOT NULL o no existe la columna,
     * nunca nullable"; el mismo criterio aplica a cualquier referencia
     * obligatoria entre tablas de tenant. Una referencia opcional no usa
     * este helper: se declara a mano, como created_by/updated_by.
     */
    public static function tenantForeignId(Blueprint $blueprint, string $column, string $referencedTable): void
    {
        self::assertSafeIdentifier($referencedTable);

        $blueprint->foreignId($column);
        $blueprint->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on($referencedTable);
    }

    /**
     * Cierra la clave foránea compuesta de created_by/updated_by hacia
     * `users` sobre una tabla creada antes de que `users` existiera.
     * Solo la llama la migración de 0.8.4, para academic_years y people.
     */
    public static function addAuthorshipForeignKeys(string $table): void
    {
        self::assertSafeIdentifier($table);

        Schema::connection('pgsql_owner')->table($table, function (Blueprint $blueprint): void {
            $blueprint->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $blueprint->foreign(['tenant_id', 'updated_by'])->references(['tenant_id', 'id'])->on('users');
        });
    }

    private static function applyTenantDefaultsAndRls(string $table): void
    {
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
