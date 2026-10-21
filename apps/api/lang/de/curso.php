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
            'reapertura_curso_academico' => 'Wiedereröffnung von Schuljahren',
            'apertura_curso_academico' => 'Eröffnung des nächsten Schuljahres',
        ],
    ],

    'validation' => [
        'code_required' => 'Der Schuljahrescode ist erforderlich.',
        'code_taken' => 'In der Schule gibt es bereits ein Schuljahr mit diesem Code.',
        'ends_before_start' => 'Das Enddatum muss nach dem Startdatum liegen.',
        'dates_overlap' => 'Die Daten überschneiden sich mit denen des Schuljahres „:code“.',
        'status_not_editable' => 'Der Status eines Schuljahres kann hier nicht angegeben werden: Verwende die Aktionen Aktivieren und Abschließen.',
        'reason_required' => 'Der Grund für die Wiedereröffnung ist erforderlich.',
        'rollover_target_required' => 'Gib das Zielschuljahr an.',
        'rollover_target_not_found' => 'Das Zielschuljahr existiert nicht.',
        'rollover_source_required' => 'Gib das Ausgangsschuljahr an.',
        'rollover_source_not_found' => 'Das Ausgangsschuljahr existiert nicht.',
        'rollover_source_invalid' => 'Das Ausgangsschuljahr muss aktiv oder abgeschlossen sein und vor dem Zielschuljahr beginnen.',
        'rollover_elements_required' => 'Wähle mindestens ein Element zum Kopieren aus.',
        'rollover_element_unknown' => '„:element“ kann nicht kopiert werden.',
        'rollover_element_requires' => 'Um „:element“ zu kopieren, muss auch „:requires“ kopiert werden.',
        'rollover_element_not_selectable' => '„:element“ wird nicht einzeln ausgewählt: Es wird mit den Lehraufträgen kopiert.',
        'rollover_teaching_assignments_unavailable' => 'Die Lehraufträge können nicht kopiert werden: Kein gebuchtes Modul bietet sie an.',
    ],

    'conflict' => [
        'planning_exists' => 'Es gibt bereits ein Schuljahr in Planung („:code“). Bearbeite oder aktiviere es, bevor du ein weiteres anlegst.',
        'active_exists' => 'Es gibt bereits ein aktives Schuljahr („:code“). Schließe es ab, bevor du ein weiteres aktivierst.',
        'invalid_transition' => 'Ein Schuljahr kann nicht von „:from“ zu „:to“ wechseln.',
        'not_editable' => 'Nur ein Schuljahr in Planung kann bearbeitet werden.',
        'closure_checks_failed' => 'Das Schuljahr kann nicht abgeschlossen werden: Einige Abschlussprüfungen sind nicht erfüllt.',
        'date_change_checks_failed' => 'Die neuen Daten des Schuljahres schließen davon abhängige Daten aus.',
        'reopen_not_latest' => 'Nur das zuletzt abgeschlossene Schuljahr kann wiedereröffnet werden: das Schuljahr „:code“ liegt später.',
        'reopen_checks_failed' => 'Das Schuljahr kann nicht wiedereröffnet werden: Einige Prüfungen für die Wiedereröffnung sind nicht erfüllt.',
        'rollover_target_not_planning' => 'Nur in ein Schuljahr in Planung kann kopiert oder eine Kopie zurückgesetzt werden.',
        'rollover_exists' => 'Für dieses Schuljahr gibt es bereits eine laufende oder abgeschlossene Kopie. Setze sie zurück, bevor du sie wiederholst.',
        'rollover_blocked' => 'Die Kopie kann nicht ausgeführt werden: Prüfe die Gründe.',
        'rollover_in_progress' => 'Für dieses Schuljahr läuft eine Kopie oder eine Rücksetzung. Warte, bis sie abgeschlossen ist.',
        'rollover_not_revertible' => 'Diese Kopie kann in ihrem aktuellen Status nicht zurückgesetzt werden.',
        'rollover_revert_module_unavailable' => 'Rücksetzung nicht möglich: Das Modul „:module“ ist nicht gebucht.',
        'rollover_revert_blocked' => 'Rücksetzung nicht möglich: Später hinzugefügte Daten hängen vom Kopierten ab.',
        'rollover_not_cancellable' => 'Diese Kopie kann in ihrem aktuellen Status nicht abgebrochen werden.',
        'rollover_cancel_too_early' => 'Noch kein Abbruch möglich: Warte bis :available_at, falls die Kopie noch läuft.',
    ],

    // 1.12b (api.md §5.1): incidencias propias de `curso`, estados de la copia y motivos de fallo.
    'rollover' => [
        'empty' => 'Mit dieser Auswahl gibt es nichts zu kopieren.',
        'exists' => 'Für dieses Schuljahr gibt es bereits eine laufende oder abgeschlossene Kopie.',
        'element_forbidden' => 'Du hast keine Berechtigung, „:element“ anzulegen.',
        'element_unavailable' => 'Das Modul von „:element“ ist nicht mehr gebucht.',
        'status' => [
            'pendiente' => 'In der Warteschlange',
            'en_curso' => 'Läuft',
            'completado' => 'Abgeschlossen',
            'fallido' => 'Fehlgeschlagen',
            'revirtiendo' => 'Wird zurückgesetzt',
            'revertido' => 'Zurückgesetzt',
        ],
        'failure' => [
            'rollover_blocked' => 'Etwas hat das Kopieren verhindert',
            'target_not_planning' => 'Das Zielschuljahr ist nicht mehr in Planung',
            'target_changed' => 'Die Daten haben sich beim Kopieren geändert; versuche es erneut',
            'internal_error' => 'Interner Fehler; nichts wurde kopiert',
            'actor_unavailable' => 'Die anfordernde Person hat kein aktives Konto mehr; es wurde nichts ausgeführt',
            'cancelled' => 'Abgebrochen; nichts wurde kopiert',
        ],
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
