<?php

// REQ-CORE, paso 1.1. Literales propios del módulo (INV-009): correo de
// invitación (funcional.md §4.3, capa 2 de REQ-CORE-006) y mensajes de
// validación de negocio que no expresa una regla de Laravel (§6.3 de
// ADR-038: ValidationErrorBag).

return [
    'mail' => [
        'invitation' => [
            'subject' => 'Activa tu cuenta en :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Te han invitado a unirte a :tenant en la plataforma de gestión educativa.',
            'cta' => 'Activar mi cuenta',
            'expires' => 'Este enlace caduca en :days días. Si no lo esperabas, puedes ignorar este mensaje.',
        ],
    ],

    'validation' => [
        'locale_not_active' => 'El idioma «:locale» no está activo en este centro.',
        'default_locale_not_active' => 'El idioma por defecto debe estar entre los idiomas activos.',
        'active_locales_empty' => 'Debe haber al menos un idioma activo.',
        'contrast_insufficient' => 'El contraste de la paleta (:ratio:1) no alcanza el mínimo exigido (:required:1, WCAG 2.2 AA).',
        'document_number_invalid' => 'El número de documento no es válido para el tipo indicado.',
        'document_duplicate' => 'Ya existe una persona viva en este centro con el mismo tipo y número de documento.',
        'email_duplicate' => 'Ya existe un usuario vivo en este centro con este correo de acceso.',
        'role_not_found' => 'Uno de los roles indicados no existe en este centro.',
        'role_permission_exceeds_own' => 'No puedes asignar un rol que concede permisos que tú no tienes.',
        'file_type_mismatch' => 'El tipo real del archivo no coincide con el declarado.',
        'svg_unrepairable' => 'El SVG no se puede sanear de forma segura.',
        'empty_string_not_allowed' => 'Para vaciar este campo, envía null en vez de una cadena vacía.',
        'query_boolean_invalid' => 'El valor debe ser «true» o «false».',
        'cannot_modify_self' => 'No puedes realizar esta acción sobre tu propia cuenta.',
        'last_school_administrator' => 'Debe existir al menos un Administrador de Centro activo en todo momento.',
        'invitation_requires_pending_user' => 'Solo se puede invitar a un usuario en estado pendiente.',
        'invitation_already_accepted' => 'Esta invitación ya ha sido aceptada y no se puede revocar.',
        'enabled_not_editable' => 'El estado de activación del módulo no se puede modificar por esta vía.',
        'import_unknown_header' => 'La cabecera del fichero no coincide con el formato esperado.',
        'cursor_invalid' => 'El cursor de paginación no es válido para esta consulta.',
        'export_range_too_large' => 'El rango solicitado supera el límite de filas permitido; acótalo e inténtalo de nuevo.',
        'pdf_export_not_available' => 'La exportación a PDF todavía no está disponible; usa CSV.',
        'export_not_ready' => 'La exportación todavía se está generando; inténtalo de nuevo en unos minutos.',
        'export_failed' => 'La generación de esta exportación ha fallado.',
    ],

    'idempotency' => [
        'missing' => 'Falta la cabecera Idempotency-Key, obligatoria para esta operación.',
        'malformed' => 'La cabecera Idempotency-Key debe ser un ULID.',
        'body_mismatch' => 'La misma clave de idempotencia se ha usado con un cuerpo distinto.',
        'in_progress' => 'La misma clave de idempotencia está siendo procesada todavía.',
    ],
];
