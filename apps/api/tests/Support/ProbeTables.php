<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Tablas de prueba creadas por un test mediante `TenantMigration` (conexión
 * del propietario: el `CREATE` y los `ALTER` posteriores NO son
 * transaccionales y no los revierte `DatabaseTransactions`). Un test que las
 * crea las borra siempre y comprueba que no deja ninguna (revisión de 1.11-a:
 * quedaban `translatable_probes`, `fk_index_*`… en las bases `plataforma_test*`).
 *
 * Por qué hay que cerrar la transacción del test antes del `DROP`: si el
 * test ha escrito en la tabla por la conexión de aplicación, esa transacción
 * sigue abierta cuando corre `afterEach` y retiene un bloqueo sobre ella; un
 * `DROP` desde la conexión del propietario se quedaría esperando (deadlock
 * real, ver la cabecera de `TenantModelTest`). Se revierte, y el
 * `rollBack()` final de `DatabaseTransactions` queda sin efecto.
 */
final class ProbeTables
{
    /** @return list<string> */
    public static function snapshot(): array
    {
        return array_map(
            static fn (object $row): string => $row->tablename,
            DB::connection('pgsql_owner')->select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"),
        );
    }

    /**
     * @param  list<string>  $tables  las que el test puede haber creado
     * @param  list<string>  $before  `snapshot()` tomado antes de crear nada
     * @return list<string> tablas que quedan de más (vacío si el test limpia)
     */
    public static function dropAndReportLeftovers(array $tables, array $before): array
    {
        // Solo en las bases de test (revisión de seguridad B4): un DROP ... CASCADE
        // por el propietario contra otra base sería destructivo.
        $database = DB::connection('pgsql_owner')->selectOne('SELECT current_database() AS d')->d;

        if (preg_match('/^plataforma_test(_[0-9]+)?$/', $database) !== 1) {
            throw new RuntimeException("ProbeTables solo opera en bases plataforma_test*, no en «{$database}».");
        }

        $connection = DB::connection();

        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($tables as $table) {
            // Identificador acotado y esquema calificado: nunca el nombre sin comprobar.
            if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
                throw new InvalidArgumentException("Nombre de tabla de prueba no válido: {$table}");
            }

            DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS public.'.$table.' CASCADE');
        }

        return array_values(array_diff(self::snapshot(), $before));
    }
}
