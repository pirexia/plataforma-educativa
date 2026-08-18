<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-034 §1, §2, §7, §8 (subpaso 0.8.4). Rehace `users`: el starter kit de
 * Laravel la deja sin tenant_id y con los datos de la persona mezclados con
 * la credencial. Aceptable rehacerla en la misma migración solo porque
 * estamos en fase 0, sin datos reales (ADR-030) — en cualquier otro momento
 * sería una migración con parada.
 *
 * De paso corrige el hallazgo del ADR §8: `password_reset_tokens` con
 * `email` como PK global es una vía de toma de control de cuenta entre
 * tenants en cuanto `users.email` es único por tenant (dos centros pueden
 * compartir legítimamente el mismo correo). Se recrea con clave primaria
 * compuesta (tenant_id, email).
 *
 * `sessions` NO se toca aquí: REQ-AUTH-005 (listar/revocar sesiones, 1.2)
 * necesitará tenant_id, pero añadirlo es expand puro y no hace falta
 * todavía (ADR-034 §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        // Ninguna de las dos tiene datos reales todavía (fase 0, sin
        // usuarios reales creados: REQ-AUTH no existe hasta 1.2) ni FK real
        // apuntándolas desde otra tabla (sessions.user_id y el password
        // resetter de Laravel resuelven por valor, no por constraint).
        Schema::connection('pgsql_owner')->dropIfExists('password_reset_tokens');
        Schema::connection('pgsql_owner')->dropIfExists('users');

        TenantMigration::tenantTable('users', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'person_id', 'people');
            $table->text('email');
            $table->timestampTz('email_verified_at')->nullable();
            $table->text('password');
            $table->text('remember_token')->nullable();
            $table->text('status')->default('pendiente');
        });

        $owner->statement(<<<'SQL'
            ALTER TABLE users ADD CONSTRAINT users_status_check
                CHECK (status IN ('activo', 'inactivo', 'pendiente'))
            SQL);

        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX users_tenant_email_unique
                ON users (tenant_id, email)
                WHERE deleted_at IS NULL
            SQL);

        // Como mucho una cuenta viva por persona y tenant (ADR-034 §1):
        // rompería la resolución multi-rol de RPERM-007 tener dos.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX users_tenant_person_unique
                ON users (tenant_id, person_id)
                WHERE deleted_at IS NULL
            SQL);

        // Cierra la FK de created_by/updated_by de 0.8.1 para las dos
        // tablas creadas antes de que `users` (con tenant_id) existiera.
        TenantMigration::addAuthorshipForeignKeys('academic_years');
        TenantMigration::addAuthorshipForeignKeys('people');

        // ADR-034 §8: password_reset_tokens con tenant, PK compuesta.
        $owner->statement(<<<'SQL'
            CREATE TABLE password_reset_tokens (
                tenant_id  bigint      NOT NULL DEFAULT app.current_tenant_id(),
                email      text        NOT NULL,
                token      text        NOT NULL,
                created_at timestamptz NULL,
                PRIMARY KEY (tenant_id, email)
            )
            SQL);

        $owner->statement('ALTER TABLE password_reset_tokens ENABLE ROW LEVEL SECURITY');
        $owner->statement('ALTER TABLE password_reset_tokens FORCE ROW LEVEL SECURITY');
        $owner->statement(<<<'SQL'
            CREATE POLICY tenant_isolation ON password_reset_tokens
                USING      (tenant_id = app.current_tenant_id())
                WITH CHECK (tenant_id = app.current_tenant_id())
            SQL);
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP TABLE IF EXISTS password_reset_tokens');
        $owner->statement('DROP TABLE IF EXISTS users');

        Schema::connection('pgsql_owner')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::connection('pgsql_owner')->create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }
};
