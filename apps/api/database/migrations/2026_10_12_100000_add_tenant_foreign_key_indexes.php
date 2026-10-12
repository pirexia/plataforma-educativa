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
 * parcial (`WHERE deleted_at IS NULL`…) NO la cubre: la comprobación de la FK
 * al borrar o actualizar el padre, y cualquier consulta sin ese predicado, no
 * lo pueden usar. Solo se crea donde ningún índice la cubre. No se retira
 * ningún índice existente (calendario *contract*: decisión aparte).
 *
 * `$withinTransaction = false`: `CREATE INDEX CONCURRENTLY` no se ejecuta
 * dentro de una transacción (`migracion-segura`); no bloquea escrituras.
 * Idempotente: un segundo `up()` no hace nada; un índice que una ejecución
 * anterior dejó INVÁLIDO (fallo de una creación concurrente) se elimina y se
 * recrea. En una instalación nueva no hace nada (los índices ya los creó el
 * ayudante al crear cada tabla).
 *
 * `down()`: `DROP INDEX CONCURRENTLY IF EXISTS` de los 22 nombres. En una
 * instalación nueva esos índices los creó el ayudante, no esta migración; se
 * retiran igualmente (el esquema anterior no los tenía y el código anterior no
 * los usa), y las migraciones antiguas que se reviertan después borran sus
 * tablas enteras.
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

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            $name = TenantMigration::indexName($table, $column);

            // Un índice homónimo inválido (creación concurrente fallida) no sirve: fuera.
            $invalid = $owner->selectOne(
                'SELECT 1 AS found FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ? AND NOT i.indisvalid',
                [$name],
            );

            if ($invalid !== null) {
                $owner->statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
            }

            if (self::isCovered($table, $column)) {
                continue;
            }

            $owner->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} (tenant_id, {$column})");
        }
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        foreach (self::FOREIGN_KEYS as [$table, $column]) {
            $owner->statement('DROP INDEX CONCURRENTLY IF EXISTS '.TenantMigration::indexName($table, $column));
        }
    }

    /**
     * ¿Hay un índice válido, btree, no parcial y sin expresiones que empiece
     * por `(tenant_id, $column)`?
     */
    private static function isCovered(string $table, string $column): bool
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
