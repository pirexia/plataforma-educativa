<?php

// ADR-038 §6. Siehe lang/es/errors.php für den maßgeblichen Kommentar.

return [
    'title' => [
        'malformed' => 'Fehlerhafte Anfrage',
        'unauthenticated' => 'Nicht angemeldet',
        'forbidden' => 'Keine Berechtigung',
        'module-disabled' => 'Modul nicht verfügbar',
        'not-found' => 'Ressource nicht gefunden',
        'method-not-allowed' => 'Methode nicht erlaubt',
        'conflict' => 'Konflikt mit dem aktuellen Zustand',
        'gone' => 'Ressource nicht mehr verfügbar',
        'payload-too-large' => 'Datei zu groß',
        'unsupported-media-type' => 'Dateityp nicht unterstützt',
        'validation' => 'Die gesendeten Daten sind ungültig',
        'too-many-requests' => 'Zu viele Anfragen',
        'internal' => 'Interner Serverfehler',
        'unavailable' => 'Dienst vorübergehend nicht verfügbar',
    ],

    'detail' => [
        'unauthenticated' => 'Du musst dich anmelden, um auf diese Ressource zuzugreifen.',
        'forbidden' => 'Du hast keine Berechtigung für diese Aktion.',
        'module-disabled' => 'Dieses Modul ist für deine Schule nicht aktiv.',
        'not-found' => 'Die angeforderte Ressource existiert nicht.',
        'method-not-allowed' => 'Die HTTP-Methode ist für diese Route nicht erlaubt.',
        'validation' => 'Bitte überprüfe die angegebenen Felder.',
        'internal' => 'Ein unerwarteter Fehler ist aufgetreten. Notiere die Anfrage-ID, falls du den Support kontaktierst.',
        'unavailable' => 'Der Dienst ist vorübergehend nicht verfügbar. Bitte versuche es in ein paar Minuten erneut.',
    ],
];
