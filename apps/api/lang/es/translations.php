<?php

// ADR-062 §5.2 punto 4 (REQ-ACAD-001, 1.11-a), INV-009: códigos de error
// comunes del contenido del centro traducible. Los `code` de la API son a la
// vez la clave del mensaje (`translations.default_locale_required`…). Los
// citan las especificaciones de módulo; ninguna los redefine.

return [
    'default_locale_required' => 'Falta el texto en el idioma por defecto del centro (:locale).',
    'locale_not_active' => 'El idioma «:locale» no está activo en el centro.',
    'empty_value' => 'El texto en «:locale» no puede estar vacío.',
];
