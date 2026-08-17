<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-033 §7: failed_jobs es de categoría "plataforma" (sin tenant_id, sin
 * RLS) — REVOKE completo para plataforma_app salvo lo imprescindible. Los
 * GRANT por defecto de 0.7.1 (ALTER DEFAULT PRIVILEGES) le habían dado
 * SELECT/INSERT/UPDATE/DELETE como a cualquier tabla de negocio: sin esta
 * migración, cualquier código que corra como plataforma_app podría leer o
 * borrar los fallos registrados de OTROS tenants (failed_jobs no tiene
 * tenant_id que RLS pueda filtrar). plataforma_app conserva solo INSERT,
 * que es lo que el propio worker necesita para registrar sus fallos; leer
 * y gestionar la cola de fallos es un asunto de plataforma (REQ-BO-004),
 * ya cubierto por plataforma_platform (BYPASSRLS).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->statement(
            'REVOKE SELECT, UPDATE, DELETE ON failed_jobs FROM plataforma_app'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'GRANT SELECT, UPDATE, DELETE ON failed_jobs TO plataforma_app'
        );
    }
};
