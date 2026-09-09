<?php

// REQ-BO/api.md §5 (1.6): catálogo de errores propios del backoffice,
// prefijo `bo.`. INV-009: ningún literal fuera de aquí.

return [
    'mfa' => [
        'invalid_code' => 'El código introducido no es válido.',
    ],
    'admin' => [
        'self_modification' => 'No puedes realizar esta operación sobre tu propia cuenta.',
        'last_superadministrator' => 'Debe quedar siempre al menos un superadministrador vivo y activo.',
    ],
    'validation' => [
        'cursor_invalid' => 'El cursor de paginación no es válido.',
    ],
];
