<?php

// REQ-CURSO (paso 1.10), api.md §4, §5, permisos.md §8. INV-009: ningún
// literal de error ni de estado vive fuera de aquí. Los `code` de error de
// api.md §5 son a la vez la clave de su mensaje (`curso.validation.code_taken`…).
// El código del curso es contenido del centro y no se traduce.

return [
    'status' => [
        'planificacion' => 'Planning',
        'activo' => 'Active',
        'cerrado' => 'Closed',
        'archivado' => 'Archived',
    ],

    'permissions' => [
        'resources' => [
            'curso_academico' => 'Academic years',
            'estado_curso_academico' => 'Academic year status',
            'curso_historico' => 'Closed academic year history',
            'reapertura_curso_academico' => 'Academic year reopening',
            'apertura_curso_academico' => 'Opening of the next academic year',
        ],
    ],

    'validation' => [
        'code_required' => 'The academic year code is required.',
        'code_taken' => 'An academic year with that code already exists in the school.',
        'ends_before_start' => 'The end date must be after the start date.',
        'dates_overlap' => 'The dates overlap with those of academic year ":code".',
        'status_not_editable' => 'The status of an academic year cannot be set here: use the activate and close actions.',
        'reason_required' => 'The reason for reopening is required.',
        'rollover_target_required' => 'Specify the target academic year.',
        'rollover_target_not_found' => 'The target academic year does not exist.',
        'rollover_source_required' => 'Specify the source academic year.',
        'rollover_source_not_found' => 'The source academic year does not exist.',
        'rollover_source_invalid' => 'The source year must be active or closed and start before the target year.',
        'rollover_elements_required' => 'Select at least one item to copy.',
        'rollover_element_unknown' => '“:element” cannot be copied.',
        'rollover_element_requires' => 'To copy “:element” you must also copy “:requires”.',
        'rollover_element_not_selectable' => '“:element” is not selected separately: it is copied with the teaching assignments.',
        'rollover_teaching_assignments_unavailable' => 'Teaching assignments cannot be copied: no subscribed module offers them.',
    ],

    'conflict' => [
        'planning_exists' => 'There is already an academic year in planning (":code"). Edit or activate it before creating another.',
        'active_exists' => 'There is already an active academic year (":code"). Close it before activating another.',
        'invalid_transition' => 'An academic year cannot go from ":from" to ":to".',
        'not_editable' => 'Only an academic year in planning can be edited.',
        'closure_checks_failed' => 'The academic year cannot be closed: some closing checks are not met.',
        'date_change_checks_failed' => 'The new dates of the academic year leave out data that depends on them.',
        'reopen_not_latest' => 'Only the most recent closed academic year can be reopened: academic year ":code" is later.',
        'reopen_checks_failed' => 'The academic year cannot be reopened: some reopening checks are not met.',
        'rollover_target_not_planning' => 'You can only copy into, or revert a copy of, an academic year in planning.',
        'rollover_exists' => 'This academic year already has a copy in progress or completed. Revert it before repeating it.',
        'rollover_blocked' => 'The copy cannot be run: check the reasons.',
        'rollover_in_progress' => 'A copy or a revert is in progress for this academic year. Wait until it finishes.',
        'rollover_not_revertible' => 'This copy cannot be reverted in its current status.',
        'rollover_revert_module_unavailable' => 'It cannot be reverted: the “:module” module is not subscribed.',
        'rollover_revert_blocked' => 'It cannot be reverted: data added afterwards depends on what was copied.',
        'rollover_not_cancellable' => 'This copy cannot be cancelled in its current status.',
        'rollover_cancel_too_early' => 'It cannot be cancelled yet: wait until :available_at in case the copy is still running.',
    ],

    // 1.12b (api.md §5.1): incidencias propias de `curso`, estados de la copia y motivos de fallo.
    'rollover' => [
        'empty' => 'There is nothing to copy with this selection.',
        'exists' => 'This academic year already has a copy in progress or completed.',
        'element_forbidden' => 'You do not have permission to create “:element”.',
        'element_unavailable' => 'The module of “:element” is no longer subscribed.',
        'status' => [
            'pendiente' => 'Queued',
            'en_curso' => 'In progress',
            'completado' => 'Completed',
            'fallido' => 'Failed',
            'revirtiendo' => 'Reverting',
            'revertido' => 'Reverted',
        ],
        'failure' => [
            'rollover_blocked' => 'Something prevented the copy',
            'target_not_planning' => 'The target year is no longer in planning',
            'target_changed' => 'Data changed during the copy; try again',
            'internal_error' => 'Internal error; nothing was copied',
            'actor_unavailable' => 'The person who requested it no longer has an active account; nothing was done',
            'cancelled' => 'Cancelled; nothing was copied',
        ],
    ],

    'no_active_year' => 'The school has no active academic year.',

    // ADR-057 §5.5, api.md §4: `urn:pge:error:academic-year-closed`.
    'academic_year_closed' => 'Academic year ":code" is :status_label and its data is read-only.',
    'academic_year_closed_anonymous' => 'The academic year is closed and its data is read-only.',

    'errors' => [
        'rollover_rate_limited' => 'This school has reached the limit of copy or revert requests. Try again in about :minutes minutes.',
        'academic_year_closed' => [
            'title' => 'The academic year is read-only',
            'detail' => 'Academic year ":code" is :status_label and its data is read-only. Data of a closed academic year cannot be created, changed or deleted.',
            'detail_anonymous' => 'The academic year is closed and its data is read-only. Data of a closed academic year cannot be created, changed or deleted.',
        ],
    ],
];
