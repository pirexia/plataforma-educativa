<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.6 y §1.4 (`REQ-FAM-UNIT-004`, `ADR-066 §5.2`, `OPEN-066-04`, issue #483). Fase *expand*:
 * `image_consent_decisions.occurred_at` y `guardian_student_links.legal_guardian_verified_at` pasan de `timestamptz(0)` a
 * `timestamptz(6)`. El cálculo del estado cuenta solo las decisiones **posteriores** a la verificación (`occurred_at >
 * legal_guardian_verified_at`, estricto); con precisión de segundos una decisión del mismo segundo —pero posterior— se
 * descartaba en silencio. La semántica no cambia.
 *
 * Ampliar la precisión de `timestamp(n)` es una conversión binariamente compatible: PostgreSQL **no reescribe la tabla** ni
 * reconstruye sus índices (solo cambia el `typmod` en el catálogo), de modo que basta un `ACCESS EXCLUSIVE` instantáneo,
 * acotado con `lock_timeout`. Es compatible con la versión anterior del código, que lee y escribe los mismos valores. Los
 * valores existentes quedan como estaban (a segundo exacto). `image_consent_decisions` es de solo anexar (`REVOKE UPDATE`):
 * `ALTER COLUMN TYPE` lo ejecuta el propietario y no toca ninguna fila.
 *
 * `down()` vuelve a `timestamptz(0)` (redondea los microsegundos a segundo; no pierde filas ni la evidencia relevante, que es
 * el instante a segundo).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'image_consent_decisions' => 'occurred_at',
        'guardian_student_links' => 'legal_guardian_verified_at',
    ];

    public function up(): void
    {
        $this->alter(6);
    }

    public function down(): void
    {
        $this->alter(0);
    }

    private function alter(int $precision): void
    {
        $owner = DB::connection('pgsql_owner');
        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            foreach (self::COLUMNS as $table => $column) {
                $owner->statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE timestamp({$precision}) with time zone");
            }
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }
    }
};
