<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hallazgo Media de la revisión independiente de `1.6e` (`db-reviewer` +
 * `doc-reviewer`, coincidentes): `datos.md §9.3` exige
 * `CHECK (length(btrim(reason)) > 0)` en `feature_flag_rules.reason` —
 * "en el motor, no en el FormRequest" (`RN-BO-43`), como las demás
 * restricciones de la tabla — y la migración original
 * (`2026_09_22_100100_create_feature_flags_tables.php`) no la incluyó.
 * Aditiva y segura: la tabla no tiene tráfico en ningún entorno conocido.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql_owner')->statement(
            'ALTER TABLE feature_flag_rules ADD CONSTRAINT feature_flag_rules_reason_not_blank_check CHECK (length(btrim(reason)) > 0)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement(
            'ALTER TABLE feature_flag_rules DROP CONSTRAINT feature_flag_rules_reason_not_blank_check'
        );
    }
};
