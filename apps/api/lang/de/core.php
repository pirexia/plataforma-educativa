<?php

// Siehe lang/es/core.php für den maßgeblichen Kommentar.

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Aktiviere dein Konto bei :tenant',
            'greeting' => 'Hallo, :name.',
            'body' => 'Du wurdest eingeladen, :tenant auf der Bildungsverwaltungsplattform beizutreten.',
            'cta' => 'Mein Konto aktivieren',
            'expires' => 'Dieser Link läuft in :days Tagen ab. Falls du dies nicht erwartet hast, kannst du die Nachricht ignorieren.',
        ],
    ],

    'validation' => [
        'locale_not_active' => 'Die Sprache „:locale" ist für diese Schule nicht aktiv.',
        'default_locale_not_active' => 'Die Standardsprache muss unter den aktiven Sprachen sein.',
        'active_locales_empty' => 'Es muss mindestens eine aktive Sprache geben.',
        'mfa_allowed_methods_requires_totp' => 'Die TOTP-Methode kann nicht deaktiviert werden: Sie ist die einzige, die nicht von einem externen Anbieter abhängt.',
        'mfa_allowed_methods_sms_unavailable' => 'Die SMS-Methode ist noch nicht verfügbar: Es ist kein Anbieter konfiguriert.',
        'role_patch_field_not_allowed' => 'Dieser Endpunkt akzeptiert nur das Feld „mfa_required".',
        'contrast_insufficient' => 'Der Kontrast der Farbpalette (:ratio:1) erreicht nicht das erforderliche Minimum (:required:1, WCAG 2.2 AA).',
        'document_number_invalid' => 'Die Dokumentnummer ist für den angegebenen Typ ungültig.',
        'document_duplicate' => 'In dieser Schule existiert bereits eine lebende Person mit demselben Dokumenttyp und derselben Nummer.',
        'email_duplicate' => 'In dieser Schule existiert bereits ein aktiver Benutzer mit dieser Zugangs-E-Mail.',
        'role_not_found' => 'Eine der angegebenen Rollen existiert in dieser Schule nicht.',
        'role_permission_exceeds_own' => 'Du kannst keine Rolle zuweisen, die Berechtigungen gewährt, die du selbst nicht besitzt.',
        'file_type_mismatch' => 'Der tatsächliche Dateityp stimmt nicht mit dem angegebenen überein.',
        'svg_unrepairable' => 'Das SVG kann nicht sicher bereinigt werden.',
        'empty_string_not_allowed' => 'Um dieses Feld zu leeren, sende null anstelle einer leeren Zeichenkette.',
        'query_boolean_invalid' => 'Der Wert muss „true" oder „false" sein.',
        'cannot_modify_self' => 'Du kannst diese Aktion nicht auf dein eigenes Konto anwenden.',
        'last_school_administrator' => 'Es muss jederzeit mindestens eine aktive Schulverwaltung geben.',
        'invitation_requires_pending_user' => 'Nur ein Benutzer im Status „ausstehend" kann eingeladen werden.',
        'invitation_already_accepted' => 'Diese Einladung wurde bereits angenommen und kann nicht widerrufen werden.',
        'enabled_not_editable' => 'Der Aktivierungsstatus des Moduls kann über diesen Weg nicht geändert werden.',
        'import_unknown_header' => 'Die Dateikopfzeile entspricht nicht dem erwarteten Format.',
        'cursor_invalid' => 'Der Paginierungs-Cursor ist für diese Abfrage nicht gültig.',
        'export_range_too_large' => 'Der angeforderte Bereich überschreitet das zulässige Zeilenlimit; grenze ihn ein und versuche es erneut.',
        'pdf_export_not_available' => 'Der PDF-Export ist noch nicht verfügbar; verwende CSV.',
        'export_not_ready' => 'Der Export wird noch erstellt; versuche es in ein paar Minuten erneut.',
        'export_failed' => 'Die Erstellung dieses Exports ist fehlgeschlagen.',
        'import_not_validated' => 'Der Stapel muss validiert sein, bevor er ausgeführt werden kann.',
        'import_already_executed' => 'Dieser Stapel wurde bereits ausgeführt oder wird gerade ausgeführt und kann nicht verworfen werden.',

        'role_code_taken' => 'Es gibt in dieser Schule bereits eine aktive Rolle mit diesem Code.',
        'role_code_immutable' => 'Der Code einer Rolle kann nicht geändert werden: Er ist ihre stabile Referenz.',
        'role_name_system' => 'Eine vordefinierte Rolle akzeptiert keinen literalen Namen: Verwende ihre Übersetzung.',
        'clone_source_not_found' => 'Die angegebene Ursprungsrolle existiert in dieser Schule nicht.',
        'clone_requires_special_data_access' => 'Du kannst diese Rolle nicht klonen: Sie gewährt Zugriff auf besonders geschützte Daten, den du nicht aktivieren kannst.',
        'clone_and_permissions_exclusive' => 'Du kannst nicht gleichzeitig „clone_from" und „permissions" angeben.',
        'scope_not_applicable' => 'Der Geltungsbereich „:scope" kann für diese Berechtigung nicht vergeben werden.',
        'scope_resolver_missing' => 'Der Geltungsbereich „:scope" kann noch nicht vergeben werden: Sein Resolver ist nicht registriert.',
        'permission_not_found' => 'Der Berechtigungscode „:code" existiert nicht im Katalog.',
        'permission_retired' => 'Der Berechtigungscode „:code" ist nicht mehr verfügbar.',
        'permission_duplicated' => 'Der Berechtigungscode „:code" kommt mehr als einmal vor.',
        'role_is_system' => 'Eine Rolle aus der Bereitstellung der Schule kann nicht gelöscht werden.',
        'role_has_assignments' => 'Dieser Rolle sind :users_count Benutzer zugewiesen und sie kann nicht gelöscht werden.',
    ],

    'authorization' => [
        'cannot_grant_unheld_permission' => 'Du kannst die Berechtigung „:code" mit Geltungsbereich „:scope" nicht vergeben: Du besitzt sie selbst nicht.',
        'special_data_access_not_held' => 'Du kannst den Zugriff auf besonders geschützte Daten nicht aktivieren: Du besitzt ihn selbst nicht.',
    ],

    'inert_reasons' => [
        'inerte_permiso_retirado' => 'Diese Berechtigung wird von keinem Modul mehr deklariert.',
        'inerte_modulo' => 'Das Modul dieser Berechtigung ist für diese Schule nicht aktiviert.',
        'inerte_datos_especiales' => 'Die Gewährung stammt von einer Rolle ohne Zugriff auf besonders geschützte Daten.',
        'inerte_sin_resolutor' => 'Der Geltungsbereich dieser Gewährung hat noch keinen Resolver.',
    ],

    'idempotency' => [
        'missing' => 'Der Header Idempotency-Key fehlt und ist für diesen Vorgang erforderlich.',
        'malformed' => 'Der Header Idempotency-Key muss eine ULID sein.',
        'body_mismatch' => 'Derselbe Idempotenzschlüssel wurde mit einem anderen Inhalt verwendet.',
        'in_progress' => 'Derselbe Idempotenzschlüssel wird noch verarbeitet.',
    ],

    'import' => [
        'campo_obligatorio_vacio' => 'Die Spalte „:column" ist erforderlich und leer.',
        'formato_invalido' => 'Der Wert der Spalte „:column" hat kein gültiges Format.',
        'duplicado_en_fichero' => 'Der Wert der Spalte „:column" kommt mehr als einmal in der Datei vor.',
        'duplicado_en_base_de_datos' => 'Der Wert der Spalte „:column" gehört bereits zu einer anderen Person oder einem anderen Benutzer der Schule.',
        'idioma_no_activo' => 'Die angegebene Sprache ist für diese Schule nicht aktiv.',
        'rol_no_encontrado' => 'Eine der angegebenen Rollen existiert in dieser Schule nicht.',
    ],
];
