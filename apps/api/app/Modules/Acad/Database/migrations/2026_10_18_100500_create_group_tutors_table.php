<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §9.1 (1.12-a, `REQ-ACAD-002`). Tutoría de grupo: un usuario
 * es tutor de un grupo, por curso académico (`RN-ACAD-97`). FK de tres columnas
 * `(tenant_id, academic_year_id, group_id)` → `groups` a mano, sin acción
 * referencial (`AR-13`). Varios tutores por grupo y un usuario tutor de varios
 * grupos.
 *
 * Tabla nueva y vacía: índices sin `CONCURRENTLY` son inocuos.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('group_tutors', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('group_id');
            TenantMigration::tenantForeignId($table, 'user_id', 'users');

            $table->foreign(['tenant_id', 'academic_year_id', 'group_id'], 'group_tutors_tenant_year_group_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('groups');
        });

        $owner = DB::connection('pgsql_owner');

        // Cubre la FK de tres columnas; tutores de un grupo y has_dependents del grupo (RN-ACAD-96).
        $owner->statement('CREATE INDEX group_tutors_tenant_year_group_idx ON group_tutors (tenant_id, academic_year_id, group_id)');

        // RN-ACAD-97: un usuario una vez por grupo.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX group_tutors_tenant_group_user_unique
                ON group_tutors (tenant_id, group_id, user_id)
                WHERE deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS group_tutors');
    }
};
