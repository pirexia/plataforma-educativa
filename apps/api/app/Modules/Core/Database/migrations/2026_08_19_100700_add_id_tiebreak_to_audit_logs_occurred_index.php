<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-038 §4.4/§17.8: la paginación por cursor de `GET /audit-logs`
 * exige un orden total estricto — `(occurred_at DESC, id DESC)`, no
 * solo `occurred_at DESC` — o dos filas con el mismo `occurred_at` en
 * el límite de página se pierden o se repiten. Cambio pendiente desde
 * la publicación del ADR, aplicado aquí al implementar el endpoint que
 * lo necesita.
 */
return new class extends Migration
{
    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP INDEX IF EXISTS audit_logs_tenant_occurred_idx');
        $owner->statement(
            'CREATE INDEX audit_logs_tenant_occurred_idx ON audit_logs (tenant_id, occurred_at DESC, id DESC)'
        );
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('DROP INDEX IF EXISTS audit_logs_tenant_occurred_idx');
        $owner->statement(
            'CREATE INDEX audit_logs_tenant_occurred_idx ON audit_logs (tenant_id, occurred_at DESC)'
        );
    }
};
