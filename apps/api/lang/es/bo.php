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
    // REQ-BO-001 (1.6b): catálogo de errores del ciclo de vida de tenants.
    'tenant' => [
        'slug_taken' => 'Ya existe un centro con ese identificador.',
        'slug_reserved' => 'Ese identificador está reservado por la plataforma.',
        'invalid_transition' => 'Esa transición de estado no está permitida.',
        'name_mismatch' => 'El nombre escrito no coincide exactamente con el del centro.',
        'clone_source_invalid' => 'No se puede clonar un centro en este estado.',
    ],
    'dual_auth' => [
        'same_actor' => 'Quien aprueba una solicitud no puede ser quien la solicitó.',
        'expired' => 'Esta solicitud de doble autorización ha caducado.',
        'already_resolved' => 'Esta solicitud de doble autorización ya está resuelta.',
        'payload_mismatch' => 'La operación ya no se puede ejecutar: sus condiciones han cambiado desde que se solicitó.',
    ],
    // RN-BO-54: claves de motivo para transiciones con actor `system`,
    // nunca frases escritas en el código.
    'tenant_lifecycle' => [
        'reason' => [
            'provisioned' => 'Aprovisionamiento inicial del centro completado.',
        ],
    ],
    'module_subscription' => [
        'reason' => [
            'cloned' => 'Copiado al clonar el centro de origen.',
        ],
    ],
    // REQ-BO-002 (1.6c): catálogo de errores de contratación de módulos.
    'module' => [
        'essential' => 'El módulo «:module_code» es esencial: no se puede contratar ni descontratar.',
        'retired' => 'El módulo «:module_code» ya no existe en el catálogo: no se puede contratar.',
        'reason_required' => 'El motivo es obligatorio y no puede estar vacío.',
        'tenant_state_invalid' => 'El centro está en un estado que no admite escritura de módulos.',
        'missing_dependencies' => 'Faltan dependencias por contratar: :modules. Confirma el arrastre para continuar.',
        'dependent_modules' => 'Otros módulos dependen de éste: :modules. Confirma el arrastre para continuar.',
    ],
    // REQ-BO-004 (1.6d): catálogo de errores de salud y reintento de trabajos.
    'job' => [
        'reason_required' => 'El motivo es obligatorio y no puede estar vacío.',
        'tenant_state_invalid' => 'El centro está en un estado que no admite reintento de trabajos.',
        'reason' => [
            'retried_via_console' => 'Reencolado mediante bo:retry-provisioning.',
        ],
    ],
    // REQ-BO-006 (1.6d): catálogo de errores de métricas de plataforma.
    'metrics' => [
        'invalid_period' => 'El principio del período no puede ser posterior al final.',
    ],
    // REQ-BO-005 puntos 1-2 (1.6e): catálogo de errores del motor de feature flags.
    'flag' => [
        'retired' => 'Este flag ha sido retirado y ya no admite escritura.',
        'invalid_rule' => 'La regla no es coherente: revisa que lleve el campo correspondiente a su tipo y, si nombra un centro, que exista.',
        'duplicate_rule' => 'Ya existe una regla de este tipo para el mismo objetivo en el conjunto enviado.',
        'tenant_state_invalid' => 'El centro está en un estado que no admite esta operación.',
    ],
    // Issue #173. Correo de invitación de un administrador de plataforma.
    'mail' => [
        'invitation' => [
            'subject' => 'Activa tu cuenta de administrador de plataforma',
            'greeting' => 'Hola, :name.',
            'body' => 'Te han invitado como administrador del backoffice de la plataforma de gestión educativa.',
            'cta' => 'Activar mi cuenta',
            'expires' => 'Este enlace caduca en :days días. Si no lo esperabas, puedes ignorar este mensaje.',
        ],
    ],
];
