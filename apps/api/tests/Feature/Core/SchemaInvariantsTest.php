<?php

use Illuminate\Support\Facades\DB;

/**
 * ADR-034 §6/§7 (subpaso 0.8.10): amplía la batería de tests de esquema
 * de 0.7.11 (tests/Feature/Tenancy/IsolationBatteryTest.php #8/#9) con las
 * cuatro comprobaciones que impiden que el patrón del núcleo se erosione
 * módulo a módulo. Generales sobre information_schema/pg_catalog, no
 * hardcodeadas por tabla: tienen que seguir fallando cuando un módulo
 * futuro se salte la regla, no solo hoy.
 */

// (a) Toda restricción única sobre una tabla con deleted_at es parcial,
// salvo UNIQUE (tenant_id, id) y UNIQUE (public_id) — las dos únicas no
// parciales que ADR-034 §6 acepta explícitamente, más las excepciones
// nombradas de datos.md §A.8 (paso 1.1), REQ-AUTH/datos.md §A.2 (paso
// 1.2) y REQ-AUTH/datos.md §C.3 (paso 1.3): un token de invitación no se
// reutiliza jamás (igual que public_id), una clave de idempotencia no
// tiene borrado lógico (se purga físicamente), un token de desbloqueo de
// cuenta tampoco se reutiliza jamás, y un código de respaldo MFA
// tampoco — ni siquiera tras su propio borrado lógico (§C.4.3 punto 3:
// "un código no se reutiliza jamás") — las cuatro deliberadamente
// totales, documentadas en su propio datos.md, no un descuido. Y
// REQ-AUTH/datos.md §G.4.1/§G.4.2 (paso 1.4c), dos más: un `request_id`
// de `saml_auth_requests` no se repite dentro de un centro, y la
// unicidad tiene que valer también sobre filas ya consumidas — parcial
// sobre `deleted_at` reabriría la repetición de un `request_id` por la
// puerta del borrado lógico, exactamente lo que la correlación de un
// solo uso (`RN-AUTH-121`) existe para impedir. Y el `assertion_id` de
// `saml_consumed_assertions` (por proveedor): el rechazo de una aserción
// reenviada contra otra petición viva lo produce la violación de este
// índice, no una lectura previa (`RN-AUTH-122`, `CA-AUTH-344`) — parcial
// dejaría reutilizar un `assertion_id` ya visto en cuanto su fila se
// borrara lógicamente. Y, desde 1.11-b (REQ-ACAD/datos.md §1.1-§1.5), los
// cinco destinos de las claves foráneas de tres columnas de `acad`
// (`(tenant_id, academic_year_id, id)` y `(tenant_id, course_id, id)`):
// no parciales a propósito (`ADR-034 §6`, «las de destino de FK no son
// parciales»), porque una clave foránea solo puede apuntar a una restricción
// única completa y la FK debe seguir resolviendo un padre ya borrado
// lógicamente en filas históricas. Y, desde 1.11d-a (REQ-ACAD/datos.md §1.7),
// el destino `(tenant_id, academic_year_id, id)` de las futuras claves
// foráneas de `CALIF` a `academic_periods`, por el mismo motivo. Y, desde 1.11d-b
// (REQ-ACAD/datos.md §1.8-§1.9), el destino de cuatro columnas
// `(tenant_id, academic_year_id, id, scale_type)` de la FK de
// `grading_scale_values` a `grading_scales`, y el `(tenant_id, academic_year_id,
// id)` de las futuras FK de `CALIF` a `grading_scale_values`.
test('toda restricción única sobre tabla con borrado lógico es parcial, salvo las excepciones nominales (ADR-034 §6 y los destinos de FK)', function (): void {
    $namedExceptions = [
        'user_invitations_tenant_token_unique',
        'idempotency_keys_tenant_endpoint_key_unique',
        'account_lockouts_tenant_unlock_token_hash_unique',
        'user_mfa_recovery_codes_tenant_hash_unique',
        'saml_auth_requests_tenant_request_id_unique',
        'saml_consumed_assertions_tenant_provider_assertion_unique',
        'education_levels_tenant_year_id_unique',
        'courses_tenant_year_id_unique',
        'groups_tenant_year_id_unique',
        'groups_tenant_course_id_unique',
        'subjects_tenant_course_id_unique',
        'academic_periods_tenant_year_id_unique',
        'grading_scales_tenant_year_id_type_unique',
        'grading_scale_values_tenant_year_id_unique',
        // 1.12-a (REQ-ACAD/datos.md §9.1): destino de la FK con discriminador de las sesiones
        // (tenant_id, facility_id, facility_type); los espacios borrados lógicamente conservan
        // su id y su tipo, que no se reutilizan.
        'facilities_tenant_id_type_unique',
        // 1.12-a (datos.md §9.3): destinos de las FK de teaching_assignments hacia group_subjects y subgroups.
        'group_subjects_tenant_group_id_unique',
        'subgroups_tenant_group_id_unique',
        // 1.12-a (datos.md §9.1): destinos de las FK por curso de las sesiones y las franjas.
        'teaching_assignments_tenant_year_id_unique',
        'timetable_frames_tenant_year_id_unique',
        'time_slots_tenant_year_id_unique',
        // 1.12-a (datos.md §9.1): el ancla de serialización no se borra nunca (RN-ACAD-115), único no parcial a propósito.
        'timetables_tenant_year_unique',
        // 1.12b (REQ-CURSO/datos.md §1.6): destino de la FK de tres columnas de `year_rollover_items`
        // (`(tenant_id, academic_year_id, id)`, RN-CURSO-27); no parcial a propósito, como los demás destinos de FK.
        'year_rollovers_tenant_year_id_unique',
        // 1.14-a, issue #469 (REQ-FAM-UNIT/datos.md §1.1): destino de la FK `(tenant_id, id, principal_guardian_id)` de
        // `family_units` (RN-FAM-UNIT-02); no parcial a propósito, como los demás destinos de FK (`id` ya es único).
        'guardians_tenant_unit_id_unique',
        // 1.14-b (REQ-FAM-UNIT/datos.md §1.8, ADR-066 §5.5): la numeración de versiones de un texto legal es correlativa por
        // centro y tipo y no se reutiliza nunca, ni tras borrar un borrador: único no parcial a propósito.
        'terms_versions_tenant_kind_number_unique',
    ];

    $tablesWithDeletedAt = collect(DB::select(<<<'SQL'
        SELECT DISTINCT table_name FROM information_schema.columns
        WHERE table_schema = 'public' AND column_name = 'deleted_at'
        SQL))->pluck('table_name');

    foreach ($tablesWithDeletedAt as $table) {
        $uniqueIndexes = DB::select(
            "SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public' AND tablename = ? AND indexdef ILIKE '%UNIQUE%'",
            [$table]
        );

        foreach ($uniqueIndexes as $index) {
            $def = $index->indexdef;
            $isPartial = str_contains($def, ' WHERE ');
            $isTenantIdException = str_contains($def, '(tenant_id, id)');
            $isPublicIdException = str_contains($def, '(public_id)');
            $isNamedException = in_array($index->indexname, $namedExceptions, true);
            // La propia PK bigint (id): tenantTable()/tenantTableAppendOnly()
            // la crean siempre, nunca se reutiliza aunque haya borrado
            // lógico — es el destino de la FK compuesta, no algo que ADR-034
            // §6 pida hacer parcial.
            $isPrimaryKeyException = str_contains($def, '_pkey ') && str_ends_with($def, '(id)');

            expect($isPartial || $isTenantIdException || $isPublicIdException || $isPrimaryKeyException || $isNamedException)
                ->toBeTrue("tabla `{$table}`: índice único no parcial fuera de las excepciones — {$def}");
        }
    }
});

// (b) academic_year_id es NOT NULL o no existe la columna — nunca
// nullable (ADR-034 §4, regla aplicable a los 53 módulos futuros).
test('ninguna columna academic_year_id es nullable', function (): void {
    $nullable = DB::select(<<<'SQL'
        SELECT table_name FROM information_schema.columns
        WHERE table_schema = 'public' AND column_name = 'academic_year_id' AND is_nullable = 'YES'
        SQL);

    expect($nullable)->toBeEmpty();
});

// (c) Toda columna *_id que referencia una tabla de tenant lleva clave
// foránea COMPUESTA (tenant_id, columna) — nunca una FK simple, que RLS
// no protege desde el otro lado (ADR-033 §6).
// Desde 1.11-b también vale una FK de tres o más columnas que incluya
// `tenant_id` y la columna (REQ-ACAD/datos.md §1.2: `(tenant_id,
// academic_year_id, education_level_id)` hacia el padre del mismo curso
// académico, RN-CURSO-27): sigue siendo composite con tenant_id, que es lo
// que esta regla protege.
test('toda columna *_id de una tabla de tenant tiene FK compuesta con tenant_id', function (): void {
    // Solo bigint: una FK interna real siempre es bigint (ADR-029), que es
    // como se filtran solas las columnas *_id que no son referencias —
    // public_id/auditable_public_id (ulid/text) y request_id (text, INV-013)
    // no son candidatas y no hace falta excluirlas a mano.
    // Excepción documentada que sí es bigint y aun así no lleva FK:
    // audit_logs.auditable_id, polimórfica a propósito (ADR-034 §3, "No
    // hay FK: es polimórfica" — no puede apuntar a una sola tabla).
    $excluded = ['id', 'tenant_id'];
    // 1.12b (REQ-CURSO/datos.md §1.7): `year_rollover_items` es polimórfica sin FK a propósito
    // (`entity_type` + `target_id`, patrón de `audit_logs`; `curso` nunca lee ni escribe las tablas de otros
    // módulos, INV-007): `source_id` y `target_id` apuntan a filas de cualquier tabla de un módulo participante.
    $polymorphicExceptions = ['audit_logs.auditable_id', 'year_rollover_items.source_id', 'year_rollover_items.target_id'];

    $candidates = collect(DB::select(<<<'SQL'
        SELECT c.table_name, c.column_name
        FROM information_schema.columns c
        WHERE c.table_schema = 'public'
          AND c.column_name LIKE '%\_id' ESCAPE '\'
          AND c.data_type = 'bigint'
          AND EXISTS (
              SELECT 1 FROM information_schema.columns t
              WHERE t.table_schema = 'public' AND t.table_name = c.table_name AND t.column_name = 'tenant_id'
          )
        SQL))
        ->reject(fn ($row) => in_array($row->column_name, $excluded, true))
        ->reject(fn ($row) => in_array("{$row->table_name}.{$row->column_name}", $polymorphicExceptions, true));

    $compositeForeignKeys = collect(DB::select(<<<'SQL'
        SELECT conrelid::regclass::text AS table_name, a.attname AS column_name
        FROM pg_constraint con
        JOIN pg_attribute a ON a.attnum = ANY (con.conkey) AND a.attrelid = con.conrelid
        WHERE con.contype = 'f' AND array_length(con.conkey, 1) >= 2 AND a.attname <> 'tenant_id'
          AND EXISTS (
              SELECT 1 FROM pg_attribute t
              WHERE t.attrelid = con.conrelid AND t.attname = 'tenant_id' AND t.attnum = ANY (con.conkey)
          )
        SQL))->map(fn ($row) => "{$row->table_name}.{$row->column_name}");

    foreach ($candidates as $candidate) {
        $key = "{$candidate->table_name}.{$candidate->column_name}";

        expect($compositeForeignKeys->contains($key))
            ->toBeTrue("{$key}: columna *_id de tabla de tenant sin FK compuesta (tenant_id, {$candidate->column_name})");
    }
});

// (d) Las tablas de referencia (config('tenancy.shared_tables.reference'))
// no tienen privilegios de escritura para ningún rol de aplicación.
// Issue #19: la versión original solo comprobaba plataforma_app, el mismo
// punto ciego que dejó pasar el hallazgo Alta de audit_logs (issue #17) —
// plataforma_platform (BYPASSRLS) es justo la conexión que más falta hace
// comprobar, no la que se puede dar por descontada.
test('las tablas de referencia no tienen privilegios de escritura para ningún rol de aplicación', function (): void {
    $referenceTables = config('tenancy.shared_tables.reference');

    expect($referenceTables)->not->toBeEmpty();

    foreach ($referenceTables as $table) {
        foreach (['plataforma_app', 'plataforma_platform'] as $role) {
            $grants = DB::select(<<<'SQL'
                SELECT privilege_type FROM information_schema.role_table_grants
                WHERE table_schema = 'public' AND table_name = ? AND grantee = ?
                  AND privilege_type IN ('INSERT', 'UPDATE', 'DELETE')
                SQL, [$table, $role]);

            expect($grants)->toBeEmpty("tabla de referencia `{$table}`: {$role} tiene privilegios de escritura");
        }
    }
});
