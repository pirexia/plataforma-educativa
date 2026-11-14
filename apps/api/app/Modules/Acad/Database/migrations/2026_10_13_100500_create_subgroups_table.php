<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * REQ-ACAD/datos.md §1.4 (1.11-b, `REQ-ACAD-001`). Subgrupo de un grupo
 * (`RN-ACAD-16`: un solo grupo, sin relación con asignaturas en 1.11).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('subgroups', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            TenantMigration::tenantForeignId($table, 'academic_year_id', 'academic_years');
            $table->foreignId('group_id');
            $table->text('code');
            $table->text('name');
            $table->integer('position');

            $table->foreign(['tenant_id', 'academic_year_id', 'group_id'], 'subgroups_tenant_year_group_foreign')
                ->references(['tenant_id', 'academic_year_id', 'id'])
                ->on('groups');
        });

        $owner = DB::connection('pgsql_owner');

        // RN-ACAD-01; listado anidado; hijos vivos de un grupo.
        $owner->statement(<<<'SQL'
            CREATE UNIQUE INDEX subgroups_tenant_group_code_unique
                ON subgroups (tenant_id, group_id, code)
                WHERE deleted_at IS NULL
            SQL);

        $owner->statement(<<<'SQL'
            ALTER TABLE subgroups ADD CONSTRAINT subgroups_position_check CHECK (position >= 1)
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS subgroups');
    }
};
