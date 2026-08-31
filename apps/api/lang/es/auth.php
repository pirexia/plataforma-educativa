<?php

// REQ-AUTH, paso 1.2. Literales propios del módulo (INV-009): correos
// transaccionales (operacion.md §5) y mensajes de validación de negocio
// que no expresa una regla de Laravel (política de contraseñas, §6.3 de
// ADR-038: ValidationErrorBag, no ValidationErrorFormatter — issue #60).

return [

    'validation' => [
        'password' => [
            'min_length' => 'La contraseña debe tener al menos :min caracteres.',
            'max_bytes' => 'La contraseña es demasiado larga.',
            'uppercase' => 'La contraseña debe incluir al menos una letra mayúscula.',
            'lowercase' => 'La contraseña debe incluir al menos una letra minúscula.',
            'digit' => 'La contraseña debe incluir al menos un número.',
            'symbol' => 'La contraseña debe incluir al menos un símbolo.',
            'same_as_current' => 'La nueva contraseña no puede ser igual a la actual.',
        ],
        'current_password_incorrect' => 'La contraseña actual no es correcta.',
        'lockout_already_unlocked' => 'Este bloqueo ya está levantado.',
        'session_already_closed' => 'Esta sesión ya está cerrada.',
        // REQ-AUTH-003 (1.3), funcional.md §C.
        'mfa_factor_already_confirmed' => 'Ya tienes un factor confirmado de este método.',
        'mfa_method_not_available' => 'Este método de verificación no está disponible en este centro.',
        'mfa_code_invalid' => 'El código no es correcto.',
        'mfa_factor_required_by_role' => 'No puedes desactivar tu último factor: alguno de tus roles exige la verificación en dos pasos.',
        // REQ-AUTH-003 (1.3b), funcional.md §D.4.6, RN-AUTH-81.
        'mfa_exemption_already_live' => 'Este usuario ya tiene una excepción de MFA vigente.',
        // RN-AUTH-81/RN-AUTH-67, api.md §D.4/§C.5: distinto del 403 genérico
        // por falta de permiso — se distingue en el mensaje.
        'mfa_exemption_self' => 'No puedes concederte una excepción de MFA a ti mismo.',
        'mfa_reset_self' => 'No puedes restablecer tu propio MFA.',
    ],

    'mail' => [
        'account_locked' => [
            'subject' => 'Tu cuenta en :tenant se ha bloqueado temporalmente',
            'greeting' => 'Hola, :name.',
            'body' => 'Se han detectado :count intentos fallidos de acceso a tu cuenta en :tenant y se ha bloqueado temporalmente por seguridad.',
            'auto_unlock' => 'El bloqueo se levantará automáticamente el :time si no haces nada.',
            'cta' => 'Desbloquear mi cuenta ahora',
        ],
        'password_reset' => [
            'subject' => 'Restablece tu contraseña en :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Has solicitado restablecer tu contraseña en :tenant.',
            'cta' => 'Restablecer mi contraseña',
            'expires' => 'Este enlace caduca en :minutes minutos.',
            'ignore' => 'Si no has sido tú, puedes ignorar este mensaje: tu contraseña actual sigue siendo válida.',
        ],
        'password_changed' => [
            'subject' => 'Tu contraseña en :tenant ha cambiado',
            'greeting' => 'Hola, :name.',
            'body' => 'Tu contraseña de acceso a :tenant se acaba de cambiar.',
            'warning' => 'Si no has sido tú, contacta cuanto antes con la administración de tu centro.',
        ],
        // REQ-AUTH-003 (1.3), funcional.md §C.4.13: tres avisos, sin
        // enlace accionable (RN-AUTH-50).
        'mfa_factor_activated' => [
            'subject' => 'Se ha activado la verificación en dos pasos en :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Se acaba de activar un nuevo factor de verificación en dos pasos en tu cuenta de :tenant.',
            'warning' => 'Si no has sido tú, contacta cuanto antes con la administración de tu centro.',
        ],
        'mfa_factor_removed' => [
            'subject' => 'Se ha desactivado la verificación en dos pasos en :tenant',
            'greeting' => 'Hola, :name.',
            'body_by_self' => 'Se acaba de desactivar un factor de verificación en dos pasos en tu cuenta de :tenant.',
            'body_by_admin' => 'Un administrador de :tenant ha restablecido la verificación en dos pasos de tu cuenta: tus factores y códigos de respaldo anteriores ya no son válidos.',
            'warning' => 'Si no has sido tú, contacta cuanto antes con la administración de tu centro.',
        ],
        'recovery_code_used' => [
            'subject' => 'Se ha usado un código de respaldo en :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Se ha usado uno de tus códigos de respaldo para acceder a tu cuenta de :tenant en lugar de tu segundo factor habitual.',
            'warning' => 'Si no has sido tú, contacta cuanto antes con la administración de tu centro y revisa tus sesiones activas.',
        ],
        // REQ-AUTH-003 (1.3b), funcional.md §D.4.1, §D.4.2, RN-AUTH-84: sin
        // enlace accionable, el código nunca en el asunto.
        'mfa_enrollment_code' => [
            'subject' => 'Código para activar la verificación en dos pasos en :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Estás activando el correo como verificación en dos pasos en tu cuenta de :tenant. Usa este código para confirmarlo.',
            'code' => 'Tu código: :code',
            'expires' => 'Este código caduca en :minutes minutos.',
        ],
        'mfa_challenge_code' => [
            'subject' => 'Tu código de acceso a :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Estás iniciando sesión en :tenant y necesitas este código para completar la verificación en dos pasos.',
            'code' => 'Tu código: :code',
            'expires' => 'Este código caduca en :minutes minutos.',
            'warning' => 'Si no has intentado entrar, cambia tu contraseña cuanto antes.',
        ],
        'new_device_login' => [
            'subject' => 'Nuevo acceso a tu cuenta en :tenant',
            'greeting' => 'Hola, :name.',
            'body' => 'Se ha iniciado sesión en tu cuenta de :tenant desde un dispositivo que no habíamos visto antes, el :time.',
            'detail' => 'Detalles del acceso: :client, desde la IP :ip.',
            'location_line' => 'Ubicación aproximada: :location.',
            'what_to_do' => 'Si has sido tú, no tienes que hacer nada más. Si no reconoces este acceso, revisa tus sesiones activas y cambia tu contraseña cuanto antes.',
            'cta' => 'Revisar mis sesiones activas',
            'unknown_client' => 'un dispositivo desconocido',
        ],
    ],

];
