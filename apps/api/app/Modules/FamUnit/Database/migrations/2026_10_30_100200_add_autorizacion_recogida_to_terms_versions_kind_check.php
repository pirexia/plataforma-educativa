<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * REQ-FAM-UNIT/datos.md §8.3 (1.14b-a, `ADR-068 §6` `L2`). Fase *expand*: el `CHECK` de `terms_versions.kind` pasa de tres a
 * cuatro valores con `autorizacion_recogida` (el texto que firma el tutor principal al autorizar a un tercero,
 * `RN-FAM-UNIT-85`). Solo se **amplía** el conjunto admitido: la versión anterior de la aplicación sigue escribiendo
 * los tres valores de siempre sin cambio.
 *
 * `terms_versions` ya puede tener filas en producción, así que el patrón es el de `migracion-segura` (`ADR-039 §4.6`):
 * `DROP CONSTRAINT` + `ADD CONSTRAINT … NOT VALID` en **una** transacción (así no hay ni un instante sin `CHECK`), y
 * `VALIDATE CONSTRAINT` aparte, que solo toma `SHARE UPDATE EXCLUSIVE` y no bloquea lecturas ni escrituras. Un `ADD
 * CONSTRAINT` a secas validaría todas las filas bajo `ACCESS EXCLUSIVE`. `lock_timeout` breve (y restablecido) para no
 * encolar las escrituras de la tabla detrás de un `ALTER` que espera un bloqueo.
 *
 * Reintentable: con la restricción ya ampliada y validada no hace nada; con una `NOT VALID` de un intento anterior
 * fallido solo reintenta `VALIDATE`. `$withinTransaction = false` porque `VALIDATE` no debe ir dentro de la transacción
 * de la migración.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CONSTRAINT = 'terms_versions_kind_check';

    public function up(): void
    {
        $owner = DB::connection('pgsql_owner');

        $state = $owner->selectOne(
            'SELECT convalidated, pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ? AND conrelid = ?::regclass',
            [self::CONSTRAINT, 'terms_versions'],
        );

        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            if ($state === null || ! str_contains((string) $state->def, 'autorizacion_recogida')) {
                $owner->transaction(function () use ($owner): void {
                    $owner->statement('ALTER TABLE terms_versions DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
                    $owner->statement(<<<'SQL'
                        ALTER TABLE terms_versions
                            ADD CONSTRAINT terms_versions_kind_check
                                CHECK (kind IN ('condiciones_uso', 'consentimiento_imagen_web', 'consentimiento_imagen_redes', 'autorizacion_recogida')) NOT VALID
                        SQL);
                });
            } elseif ($state->convalidated) {
                return;
            }

            $owner->statement('ALTER TABLE terms_versions VALIDATE CONSTRAINT '.self::CONSTRAINT);
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }
    }

    public function down(): void
    {
        // Una versión de `autorizacion_recogida` referenciada por una autorización es evidencia (`ADR-066 §5.8`, `datos.md §6`):
        // no se revierte con filas. Con otras filas del tipo nuevo (un borrador), tampoco: el `CHECK` antiguo no las admite.
        if (DB::connection('pgsql_platform')->table('terms_versions')->where('kind', 'autorizacion_recogida')->exists()) {
            throw new RuntimeException('terms_versions tiene versiones de autorizacion_recogida: son evidencia y no se revierte. Purga el centro por el procedimiento de retención (REQ-PRIV-006) antes.');
        }

        $owner = DB::connection('pgsql_owner');
        $previous = $owner->selectOne("SELECT current_setting('lock_timeout') AS v")->v;
        $owner->statement("SET lock_timeout = '5s'");

        try {
            $owner->transaction(function () use ($owner): void {
                $owner->statement('ALTER TABLE terms_versions DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);
                $owner->statement(<<<'SQL'
                    ALTER TABLE terms_versions
                        ADD CONSTRAINT terms_versions_kind_check
                            CHECK (kind IN ('condiciones_uso', 'consentimiento_imagen_web', 'consentimiento_imagen_redes')) NOT VALID
                    SQL);
            });
            $owner->statement('ALTER TABLE terms_versions VALIDATE CONSTRAINT '.self::CONSTRAINT);
        } finally {
            $owner->statement("SET lock_timeout = '{$previous}'");
        }
    }
};
