<?php

use App\Support\Tenancy\TenantMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * ADR-034 §1 (subpaso 0.8.3): identidad de la persona, no su cuenta —
 * `users` (0.8.4) es una faceta 0..1 de `people`, igual que las facetas de
 * alumno/tutor/empleado que llegarán con sus propios módulos.
 *
 * Mínimo suficiente por minimización de datos (OPEN-13 deja la lista
 * definitiva y su base legal por campo a REQ-PRIV-006): fuera a propósito
 * fotografía, sexo, nacionalidad y dirección postal.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantMigration::tenantTable('people', function (Blueprint $table): void {
            $table->ulid('public_id')->unique();
            $table->text('given_name');
            $table->text('family_name_1');
            $table->text('family_name_2')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('document_type')->nullable();
            $table->text('document_number')->nullable();
            $table->text('contact_email')->nullable();
            $table->text('contact_phone')->nullable();
            $table->text('locale')->default('es');
        });

        // Documento único por tenant, solo si está informado (documento y
        // tipo son ambos nullable: un menor puede no tener DNI todavía).
        DB::connection('pgsql_owner')->statement(<<<'SQL'
            CREATE UNIQUE INDEX people_tenant_document_unique
                ON people (tenant_id, document_type, document_number)
                WHERE document_number IS NOT NULL AND deleted_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS people');
    }
};
