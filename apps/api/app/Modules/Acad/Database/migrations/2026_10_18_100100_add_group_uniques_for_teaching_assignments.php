<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.3 (1.12-a, `REQ-ACAD-002`), fase *expand*. Dos únicos
 * nuevos en tablas que ya existen en `develop`, destino de las FK de
 * `teaching_assignments` que garantizan que la asignatura (`group_subject`) y el
 * subgrupo son del grupo de la asignación (`RN-ACAD-93`): `(tenant_id, group_id,
 * id)` en `group_subjects` y en `subgroups`. Aditivos y compatibles con la
 * versión anterior, que no los usa.
 *
 * `$withinTransaction = false`: el índice se crea con `CONCURRENTLY` (no bloquea
 * escrituras) y luego se promueve a restricción con `ADD CONSTRAINT … UNIQUE
 * USING INDEX` (bloqueo breve, sin reescaneo). Idempotente: un reintento tras un
 * fallo parcial no se atasca.
 *
 * Endurecida tras la revisión de 1.12-a (#446, a y b): (a) `lock_timeout` con
 * reintento acotado, para que la promoción a restricción —que pide un bloqueo
 * `ACCESS EXCLUSIVE` breve— no se quede en cola bloqueando a su vez las
 * escrituras de la tabla detrás de una transacción larga; (b) la idempotencia
 * busca la restricción por nombre **y** por tabla (`conrelid`). Solo afecta a
 * instalaciones nuevas: donde la migración ya se aplicó no se vuelve a ejecutar.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> tabla => nombre de la restricción */
    private const UNIQUES = [
        'group_subjects' => 'group_subjects_tenant_group_id_unique',
        'subgroups' => 'subgroups_tenant_group_id_unique',
    ];

    /** Intentos con `lock_timeout` antes de rendirse (propiedades, no constantes, para que los tests las acorten). */
    private int $maxAttempts = 5;

    private string $lockTimeout = '3s';

    private int $retryPauseMs = 2000;

    /** SQLSTATE `lock_not_available`: vence `lock_timeout`. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::UNIQUES as $table => $name) {
            if ($this->constraintExists($owner, $table, $name)) {
                continue;
            }

            $this->withLockTimeout($owner, function () use ($owner, $table, $name): void {
                // Un índice inválido de un intento anterior interrumpido se descarta antes de recrearlo.
                $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
                $owner->statement("CREATE UNIQUE INDEX CONCURRENTLY {$name} ON {$table} (tenant_id, group_id, id)");
                $owner->statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} UNIQUE USING INDEX {$name}");
            });
        }
    }

    /** Por nombre y por tabla: un homónimo en otra tabla no cuenta como ya aplicado. */
    private function constraintExists(Connection $owner, string $table, string $name): bool
    {
        return $owner->selectOne(
            'SELECT 1 AS ok FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [$name, $table],
        ) !== null;
    }

    /**
     * Ejecuta el paso con `lock_timeout` y lo repite, hasta `$maxAttempts` veces, si vence por un bloqueo ajeno.
     * Cualquier otro error, o el último intento fallido, se propaga. Cada intento reentra en un estado sano
     * porque los pasos son idempotentes (el `DROP … IF EXISTS` descarta el índice a medias). `lock_timeout` se
     * restablece siempre al salir: la sesión de migraciones no debe arrastrarlo a las migraciones siguientes.
     */
    private function withLockTimeout(Connection $owner, \Closure $step): void
    {
        for ($attempt = 1; ; $attempt++) {
            $owner->statement("SET lock_timeout = '{$this->lockTimeout}'");

            try {
                $step();

                return;
            } catch (QueryException $e) {
                if ($attempt >= $this->maxAttempts || ($e->errorInfo[0] ?? null) !== self::LOCK_NOT_AVAILABLE) {
                    throw $e;
                }

                usleep($this->retryPauseMs * 1000);
            } finally {
                $owner->statement('RESET lock_timeout');
            }
        }
    }

    public function down(): void
    {
        // Aditiva; revertirla no aporta nada y la versión anterior convive con ella (operacion.md §12.5).
        $owner = DB::connection('pgsql_owner');

        foreach (self::UNIQUES as $table => $name) {
            $owner->statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
