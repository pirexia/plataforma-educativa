<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ADR-034 §3 (subpaso 0.8.8): tabla única polimórfica, append-only,
 * escrita desde el ciclo de vida del ORM (0.9). Inmutabilidad forzada en
 * el motor (REVOKE), no por convención — misma lección que el bug 6 de
 * 0.7 con failed_jobs (un REVOKE que no se escribe explícitamente no
 * existe).
 *
 * `changes` solo guarda atributos modificados, nunca la fila entera, y
 * pasa por una lista de redacción por modelo que escribe 0.9 junto con el
 * propio registro automático — aquí solo se fija el esquema.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTableAppendOnly('audit_logs', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->text('actor_type');
            $table->text('auditable_type');
            $table->unsignedBigInteger('auditable_id');
            $table->ulid('auditable_public_id')->nullable();
            $table->text('event');
            $table->jsonb('changes')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('request_id')->nullable();
            $table->jsonb('context')->nullable();
        });

        $owner = DB::connection('pgsql_owner');

        // FK compuesta hacia users, pero nullable: hay actores sin usuario
        // (consola, jobs del sistema, seeders, importaciones).
        $owner->statement(
            'ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_fk '.
            'FOREIGN KEY (tenant_id, actor_user_id) REFERENCES users (tenant_id, id)'
        );

        $owner->statement(<<<'SQL'
            ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_actor_type_check
                CHECK (actor_type IN ('user', 'system', 'console', 'import', 'platform'))
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_event_check
                CHECK (event IN ('created', 'updated', 'deleted', 'restored', 'read', 'exported'))
            SQL);

        $owner->statement(
            'CREATE INDEX audit_logs_tenant_occurred_idx ON audit_logs (tenant_id, occurred_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX audit_logs_tenant_auditable_idx ON audit_logs (tenant_id, auditable_type, auditable_id, occurred_at DESC)'
        );
        $owner->statement(
            'CREATE INDEX audit_logs_tenant_actor_idx ON audit_logs (tenant_id, actor_user_id, occurred_at DESC)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS audit_logs');
    }
};
