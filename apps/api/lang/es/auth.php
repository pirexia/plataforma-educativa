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
    ],

];
