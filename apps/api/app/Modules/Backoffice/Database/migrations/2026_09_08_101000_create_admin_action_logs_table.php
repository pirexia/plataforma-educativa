<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REQ-BO-007, ADR-033 §7, ADR-036, ADR-047 §4.1/§4.3/§4.4. Auditoría de
 * plataforma: "plataforma con visibilidad por tenant afectado" —
 * `affected_tenant_id` es referencia, no propiedad (nunca `tenant_id`,
 * reservado a la columna que la tabla es SUYA). Solo-anexión permanente:
 * lo que cierra la escritura es `FORCE ROW LEVEL SECURITY` sin política
 * permisiva, no el `REVOKE` (que un propietario puede revertir).
 *
 * No usa `TenantMigration::tenantTable()`/`tenantTableAppendOnly()`: no
 * encajan (no es tabla de tenant), se crea a mano siguiendo la forma
 * canónica de ADR-047 §4.3/§4.4.
 *
 * Diseño del índice `admin_action_logs_affected_tenant_occurred_public_idx`,
 * no especificado por `datos.md §4.5` a propósito (reservado a
 * db-reviewer): sirve a `GET /api/v1/platform-actions` (REQ-CORE, api.md
 * §2.9), que corre como `plataforma_app` — un rol que NO tiene concedida
 * la columna `id` (§4.3 abajo) y por tanto no puede ordenar ni desempatar
 * por ella; un `ORDER BY id` con esa concesión de columna falla con error
 * de privilegios, no con un resultado de más. El desempate usa
 * `public_id` en su lugar: es ULID (monótono por su prefijo de tiempo,
 * aunque no idéntico al segundo exacto de `occurred_at` — dos filas de
 * la misma escritura por lotes comparten `occurred_at` y sus ULID no
 * garantizan el mismo orden relativo entre sí, pero sí son un valor
 * único y estable), y **es la única columna, aparte de `occurred_at`, que
 * identifica sin ambigüedad la posición de una fila dentro del `GRANT`
 * de seis columnas** — cualquier otra desviaría hacia ampliar ese
 * `GRANT` con `id`, que es justo lo que esta migración evita. Es un
 * índice DISTINTO del que sirve al listado del backoffice
 * (`admin_action_logs_affected_tenant_occurred_id_idx`, que sí puede
 * usar `id` porque corre como `plataforma_platform`, BYPASSRLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->create('admin_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->timestampTz('occurred_at');
            $table->text('actor_type');
            $table->unsignedBigInteger('actor_platform_admin_id')->nullable();
            $table->unsignedBigInteger('affected_tenant_id')->nullable();
            $table->text('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('subject_public_id')->nullable();
            $table->text('action');
            $table->text('reason')->nullable();
            $table->jsonb('changes')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('request_id')->nullable();
            $table->jsonb('context')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(
            'ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_actor_platform_admin_fk '.
            'FOREIGN KEY (actor_platform_admin_id) REFERENCES platform_admins (id)'
        );
        $owner->statement(
            'ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_affected_tenant_fk '.
            'FOREIGN KEY (affected_tenant_id) REFERENCES tenants (id)'
        );

        $owner->statement(<<<'SQL'
            ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_actor_type_check
                CHECK (actor_type IN ('platform_admin', 'console', 'system'))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_actor_matches_type_check
                CHECK ((actor_type = 'platform_admin') = (actor_platform_admin_id IS NOT NULL))
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE admin_action_logs ADD CONSTRAINT admin_action_logs_action_check
                CHECK (action IN (
                    'acceso.concedido', 'acceso.rechazado', 'acceso.rechazado_por_ip',
                    'sesion.cerrada', 'reautenticacion.superada',
                    'admin.creado', 'admin.actualizado', 'admin.suspendido', 'admin.reactivado',
                    'admin.eliminado', 'admin.rol_concedido', 'admin.rol_retirado', 'admin.mfa_restablecido',
                    'ip.permitida_anadida', 'ip.permitida_retirada',
                    'autorizacion.solicitada', 'autorizacion.aprobada', 'autorizacion.rechazada',
                    'autorizacion.caducada', 'autorizacion.ejecutada', 'autorizacion.fallida',
                    'tenant.creado', 'tenant.actualizado', 'tenant.suspendido', 'tenant.reactivado',
                    'tenant.baja_iniciada', 'tenant.rescatado', 'tenant.eliminado', 'tenant.clonado',
                    'modulo.contratado', 'modulo.descontratado', 'modulo.masivo_ejecutado',
                    'job.reintentado'
                ))
            SQL);

        // ADR-047 §4.3: solo-anexión. FORCE sin política permisiva de
        // escritura es el cierre de verdad; el REVOKE de más abajo es la
        // capa de encima.
        $owner->statement('ALTER TABLE admin_action_logs ENABLE ROW LEVEL SECURITY');
        $owner->statement('ALTER TABLE admin_action_logs FORCE ROW LEVEL SECURITY');

        $owner->statement(<<<'SQL'
            CREATE POLICY tenant_visibility ON admin_action_logs
                FOR SELECT
                USING (affected_tenant_id = app.current_tenant_id())
            SQL);

        // 1. Punto de partida limpio. El REVOKE de tabla NO cubre la
        //    secuencia: 01-tenancy.sql.tpl concede USAGE, SELECT sobre
        //    las secuencias por defecto.
        $owner->statement('REVOKE ALL ON admin_action_logs FROM plataforma_app');
        $owner->statement('REVOKE ALL ON SEQUENCE admin_action_logs_id_seq FROM plataforma_app');

        // 2. Lo imprescindible, y solo eso: columnas enumeradas, nunca la
        //    tabla. `reason` no cruza el GRANT (datos.md §4.3.1): ningún
        //    texto libre escrito por un operador del proveedor cruza la
        //    frontera.
        $owner->statement(<<<'SQL'
            GRANT SELECT (
                public_id,
                occurred_at,
                action,
                affected_tenant_id,
                subject_type,
                subject_public_id
            ) ON admin_action_logs TO plataforma_app
            SQL);

        // 3. Inmutabilidad también para el rol que escribe (precedente
        //    literal: harden_audit_logs_platform_grants).
        $owner->statement('REVOKE UPDATE, DELETE ON admin_action_logs FROM plataforma_platform');

        // Índices de consulta (datos.md §4.5).
        $owner->statement(
            'CREATE INDEX admin_action_logs_occurred_id_idx ON admin_action_logs (occurred_at DESC, id DESC)'
        );
        $owner->statement(
            'CREATE INDEX admin_action_logs_affected_tenant_occurred_id_idx ON admin_action_logs (affected_tenant_id, occurred_at DESC, id DESC)'
        );
        $owner->statement(
            'CREATE INDEX admin_action_logs_actor_occurred_id_idx ON admin_action_logs (actor_platform_admin_id, occurred_at DESC, id DESC)'
        );
        $owner->statement(
            'CREATE INDEX admin_action_logs_action_occurred_idx ON admin_action_logs (action, occurred_at DESC)'
        );

        // Índice propio de la consulta del propio centro (`plataforma_app`,
        // sin `id` concedido): ver docblock de esta migración.
        $owner->statement(
            'CREATE INDEX admin_action_logs_affected_tenant_occurred_public_idx ON admin_action_logs (affected_tenant_id, occurred_at DESC, public_id DESC)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS admin_action_logs');
    }
};
