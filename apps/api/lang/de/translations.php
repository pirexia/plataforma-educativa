<?php

// ADR-062 §5.2 Punkt 4 (REQ-ACAD-001, 1.11-a), INV-009: gemeinsame
// Fehlercodes für übersetzbare Schulinhalte.

return [
    'default_locale_required' => 'Der Text in der Standardsprache der Schule (:locale) fehlt.',
    'locale_not_active' => 'Die Sprache „:locale“ ist für diese Schule nicht aktiv.',
    'empty_value' => 'Der Text in „:locale“ darf nicht leer sein.',
];
