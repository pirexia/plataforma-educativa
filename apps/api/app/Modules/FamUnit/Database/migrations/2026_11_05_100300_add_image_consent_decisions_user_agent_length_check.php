<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §1.6 (`REQ-FAM-UNIT-004`, `ADR-066 §5.8`, issue #480 punto 1). Fase *expand*: el truncado de
 * `user_agent` a 512 caracteres (`ImageConsentService`, `mb_substr`) pasa a imponerlo también el motor con
 * `CHECK (char_length(user_agent) <= 512)` (`NULL` pasa: la comprobación es `UNKNOWN`). `NOT VALID` + `VALIDATE CONSTRAINT`
 * (`migracion-segura`): la tabla puede tener filas; el servicio ya trunca, así que `VALIDATE` debe pasar y, si fallara, la
 * migración aborta y **no** se corrigen filas (son evidencia). `down()` solo quita la restricción.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CHECK = 'image_consent_decisions_user_agent_length_check';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        if ($owner->selectOne('SELECT 1 AS found FROM pg_constraint WHERE conname = ?', [self::CHECK]) === null) {
            $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
            $owner->statement("SET lock_timeout = '5s'");

            try {
                $owner->statement('ALTER TABLE image_consent_decisions ADD CONSTRAINT '.self::CHECK.' CHECK (char_length(user_agent) <= 512) NOT VALID');
            } finally {
                $owner->statement("SET lock_timeout = '{$previous}'");
            }
        }

        $state = $owner->selectOne('SELECT convalidated FROM pg_constraint WHERE conname = ?', [self::CHECK]);

        if ($state !== null && ! $state->convalidated) {
            $owner->statement('ALTER TABLE image_consent_decisions VALIDATE CONSTRAINT '.self::CHECK);
        }
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('ALTER TABLE image_consent_decisions DROP CONSTRAINT IF EXISTS '.self::CHECK);
    }
};
