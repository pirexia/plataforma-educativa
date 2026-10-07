<?php

// REQ-CURSO (paso 1.10), api.md §4, §5, permisos.md §8. INV-009: ningún
// literal de error ni de estado vive fuera de aquí. Los `code` de error de
// api.md §5 son a la vez la clave de su mensaje (`curso.validation.code_taken`…).
// El código del curso es contenido del centro y no se traduce.

return [
    'status' => [
        'planificacion' => 'In Planung',
        'activo' => 'Aktiv',
        'cerrado' => 'Abgeschlossen',
        'archivado' => 'Archiviert',
    ],

    'permissions' => [
        'resources' => [
            'curso_academico' => 'Schuljahre',
            'estado_curso_academico' => 'Status der Schuljahre',
            'curso_historico' => 'Verlauf abgeschlossener Schuljahre',
        ],
    ],

    'validation' => [
        'code_required' => 'Der Schuljahrescode ist erforderlich.',
        'code_taken' => 'In der Schule gibt es bereits ein Schuljahr mit diesem Code.',
        'ends_before_start' => 'Das Enddatum muss nach dem Startdatum liegen.',
        'dates_overlap' => 'Die Daten überschneiden sich mit denen des Schuljahres „:code“.',
        'status_not_editable' => 'Der Status eines Schuljahres kann hier nicht angegeben werden: Verwende die Aktionen Aktivieren und Abschließen.',
    ],

    'conflict' => [
        'planning_exists' => 'Es gibt bereits ein Schuljahr in Planung („:code“). Bearbeite oder aktiviere es, bevor du ein weiteres anlegst.',
        'active_exists' => 'Es gibt bereits ein aktives Schuljahr („:code“). Schließe es ab, bevor du ein weiteres aktivierst.',
        'invalid_transition' => 'Ein Schuljahr kann nicht von „:from“ zu „:to“ wechseln.',
        'not_editable' => 'Nur ein Schuljahr in Planung kann bearbeitet werden.',
        'closure_checks_failed' => 'Das Schuljahr kann nicht abgeschlossen werden: Einige Abschlussprüfungen sind nicht erfüllt.',
    ],

    'no_active_year' => 'Die Schule hat kein aktives Schuljahr.',

    // ADR-057 §5.5, api.md §4: `urn:pge:error:academic-year-closed`.
    'academic_year_closed' => 'Das Schuljahr „:code“ ist :status_label und seine Daten sind schreibgeschützt.',
    'academic_year_closed_anonymous' => 'Das Schuljahr ist abgeschlossen und seine Daten sind schreibgeschützt.',

    'errors' => [
        'academic_year_closed' => [
            'title' => 'Das Schuljahr ist schreibgeschützt',
            'detail' => 'Das Schuljahr „:code“ ist :status_label und seine Daten sind schreibgeschützt. Daten eines abgeschlossenen Schuljahres können nicht angelegt, geändert oder gelöscht werden.',
            'detail_anonymous' => 'Das Schuljahr ist abgeschlossen und seine Daten sind schreibgeschützt. Daten eines abgeschlossenen Schuljahres können nicht angelegt, geändert oder gelöscht werden.',
        ],
    ],
];
