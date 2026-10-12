<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Issue #412 (REQ-ACAD-001, 1.11-a), issue #407, ADR-034 §7, CLAUDE.md §9:
 * migración *expand* que converge el esquema de una base ya migrada con el de
 * una instalación nueva.
 *
 * Desde 1.11-a `TenantMigration::tenantForeignId()` crea el índice
 * `(tenant_id, columna)` con el nombre de `TenantMigration::indexName()`. Las
 * 22 llamadas que ya estaban mezcladas en `develop` se ejecutaron antes, así
 * que una base migrada con la versión anterior no lo tiene (una instalación
 * nueva sí). Esta migración lo crea donde falta, con **el mismo nombre** que
 * crearía una instalación nueva.
 *
 * Cuándo se crea. Un índice «cubre» la clave foránea si es válido, de
 * `btree`, **no parcial**, sin expresiones y empieza por `(tenant_id, columna)`
 * (basta ese prefijo: `(tenant_id, user_id, started_at)` sirve). Un índice
 * parcial (`WHERE deleted_at IS NULL`…) NO la cubre. Solo se crea donde ningún
 * índice la cubre: **18 de las 22** en una base migrada con la versión
 * anterior; las otras 4 (`user_known_devices.user_id`, `mfa_resets.user_id`,
 * `saml_consumed_assertions.identity_provider_id`, `data_exports.requested_by`)
 * ya tenían un índice completo a mano y sus migraciones piden desde 1.11-a
 * `withIndex: false`, de modo que una instalación nueva también tiene 18 y las
 * dos convergen. No se retira ningún índice existente (fase *contract*).
 *
 * Por qué un parcial no basta (`ON DELETE`/`ON UPDATE NO ACTION`, `AR-13`): el
 * disparador de integridad referencial ejecuta, sobre la tabla hija,
 * `SELECT 1 FROM ONLY hija WHERE tenant_id = $1 AND col = $2 FOR KEY SHARE`,
 * sin el predicado del índice parcial (`deleted_at IS NULL`, `revoked_at IS
 * NULL`…), así que el planificador no puede usarlo. Solo se dispara al borrar
 * físicamente (o cambiar la clave de) la fila padre: purga de tenant, derecho
 * al olvido físico. Sin índice completo, cada borrado físico recorre la tabla
 * hija entera.
 *
 * `$withinTransaction = false`: `CREATE INDEX CONCURRENTLY` no se ejecuta
 * dentro de una transacción (`migracion-segura`); no bloquea escrituras (toma
 * `SHARE UPDATE EXCLUSIVE`). Aun así espera a las transacciones largas que
 * tengan la tabla abierta: lánzala en una ventana sin transacciones largas y,
 * si hay riesgo de espera, con `SET lock_timeout` de sesión. **No se ejecutan
 * dos `migrate` a la vez**: usa `php artisan migrate --isolated` (bloqueo por
 * `cache`). Idempotente: un segundo `up()` no hace nada; un índice homónimo
 * que una ejecución anterior dejó INVÁLIDO (fallo de una creación concurrente)
 * se elimina y se recrea, y al final se **reverifica** que cada clave quede
 * cubierta (si un índice homónimo válido tiene otra definición, lanza). En una
 * instalación nueva no hace nada (los índices ya los creó el ayudante).
 *
 * `down()`: `DROP INDEX CONCURRENTLY IF EXISTS` de los 18 nombres que `up()`
 * puede crear (los de las 4 claves ya cubiertas nunca los crea esta migración
 * ni el ayudante; no se tocan). En una instalación nueva esos 18 los creó el
 * ayudante, no esta migración; se retiran igualmente (el esquema anterior no
 * los tenía y el código anterior no los usa), y las migraciones antiguas que se
 * reviertan después borran sus tablas enteras.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Las 22 llamadas a `tenantForeignId()` de migraciones ya mezcladas:
     * [tabla, columna]. Inventario verificado contra el código y la base real.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const FOREIGN_KEYS = [
        ['users', 'person_id'],
        ['role_user', 'user_id'],
        ['role_user', 'role_id'],
        ['permission_role', 'role_id'],
        ['user_invitations', 'user_id'],
        ['data_exports', 'requested_by'],
        ['user_known_devices', 'user_id'],
        ['user_sessions', 'user_id'],
        ['user_mfa_factors', 'user_id'],
        ['user_mfa_recovery_codes', 'user_id'],
        ['mfa_challenges', 'user_id'],
        ['user_mfa_obligations', 'user_id'],
        ['user_mfa_exemptions', 'user_id'],
        ['user_mfa_exemptions', 'granted_by'],
        ['mfa_resets', 'user_id'],
        ['mfa_resets', 'performed_by'],
        ['user_identities', 'user_id'],
        ['identity_provider_secrets', 'identity_provider_id'],
        ['saml_identity_provider_settings', 'identity_provider_id'],
        ['identity_provider_certificates', 'identity_provider_id'],
        ['saml_auth_requests', 'identity_provider_id'],
        ['saml_consumed_assertions', 'identity_provider_id'],
    ];

    /**
     * Las 4 claves con índice completo declarado a mano (sus migraciones piden
     * `withIndex: false`): ni el ayudante ni esta migración crean el suyo.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const COVERED_BY_MANUAL_INDEX = [
        ['user_known_devices', 'user_id'],
        ['mfa_resets', 'user_id'],
        ['saml_consumed_assertions', 'identity_provider_id'],
        ['data_exports', 'requested_by'],
    ];

    public function up(): void
    {
        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            $this->ensureIndex($table, $column);
        }
    }

    /**
     * Garantiza que `(tenant_id, $column)` queda cubierta en `$table`, con el
     * índice del ayudante si ningún otro la cubre. Lanza si, tras intentarlo,
     * sigue sin cobertura.
     *
     * @throws RuntimeException
     */
    public function ensureIndex(string $table, string $column): void
    {
        $owner = DB::connection('pgsql_owner');
        $name = TenantMigration::indexName($table, $column);

        // Un índice homónimo inválido (creación concurrente fallida) no sirve: fuera.
        $invalid = $owner->selectOne(
            'SELECT 1 AS found FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
            [$name],
        );

        if ($invalid !== null) {
            $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }

        if (! self::isCovered($table, $column)) {
            $owner->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} (tenant_id, {$column})");
        }

        // Reverificación: un homónimo VÁLIDO con otra definición haría que el
        // `IF NOT EXISTS` no creara nada y la clave siguiera sin cobertura.
        if (! self::isCovered($table, $column)) {
            throw new RuntimeException("{$table}.{$column}: sigue sin un índice completo que empiece por (tenant_id, {$column}) tras la migración (¿un índice homónimo {$name} con otra definición?).");
        }
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            if (in_array([$table, $column], self::COVERED_BY_MANUAL_INDEX, true)) {
                continue;
            }

            $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS '.TenantMigration::indexName($table, $column));
        }
    }

    /**
     * ¿Hay un índice válido, btree, no parcial y sin expresiones que empiece
     * por `(tenant_id, $column)`?
     */
    public static function isCovered(string $table, string $column): bool
    {
        $row = DB::connection('pgsql_owner')->selectOne(<<<'SQL'
            SELECT 1 AS found
            FROM pg_index i
            JOIN pg_class ic ON ic.oid = i.indexrelid
            JOIN pg_am am ON am.oid = ic.relam AND am.amname = 'btree'
            WHERE i.indrelid = ?::regclass
              AND i.indisvalid
              AND i.indpred IS NULL
              AND i.indexprs IS NULL
              AND i.indnkeyatts >= 2
              AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = i.indkey[0]) = 'tenant_id'
              AND (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = i.indrelid AND a.attnum = i.indkey[1]) = ?
            LIMIT 1
            SQL, [$table, $column]);

        return $row !== null;
    }
};
