<?php

// ADR-038 §6: catálogo cerrado de tipos de error. `title` es genérico del
// tipo; `detail` es el texto por defecto cuando el llamador no da uno más
// específico. INV-009: ningún literal de error vive fuera de aquí.

return [
    'title' => [
        'malformed' => 'Solicitud malformada',
        'unauthenticated' => 'No has iniciado sesión',
        'forbidden' => 'No tienes permiso',
        'module-disabled' => 'Módulo no disponible',
        'not-found' => 'Recurso no encontrado',
        'method-not-allowed' => 'Método no permitido',
        'conflict' => 'Conflicto con el estado actual',
        'gone' => 'Recurso ya no disponible',
        'payload-too-large' => 'Archivo demasiado grande',
        'unsupported-media-type' => 'Tipo de archivo no admitido',
        'validation' => 'Los datos enviados no son válidos',
        'too-many-requests' => 'Demasiadas solicitudes',
        'internal' => 'Error interno del servidor',
        'unavailable' => 'Servicio no disponible temporalmente',
        // REQ-AUTH/api.md §1.1: 423, cuenta bloqueada por intentos fallidos.
        'account-locked' => 'Cuenta bloqueada',
    ],

    'detail' => [
        'unauthenticated' => 'Debes iniciar sesión para acceder a este recurso.',
        'forbidden' => 'No tienes permiso para realizar esta acción.',
        'module-disabled' => 'Este módulo no está activo en tu centro.',
        'not-found' => 'El recurso solicitado no existe.',
        'method-not-allowed' => 'El método HTTP no está permitido para esta ruta.',
        'validation' => 'Revisa los campos indicados.',
        'internal' => 'Se ha producido un error inesperado. Conserva el identificador de la solicitud si contactas con soporte.',
        'unavailable' => 'El servicio no está disponible temporalmente. Inténtalo de nuevo en unos minutos.',
        'account-locked' => 'Esta cuenta está bloqueada temporalmente por demasiados intentos fallidos. Revisa tu correo o inténtalo de nuevo más tarde.',
    ],
];
