<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Host del backoffice (RN-BO-48, RN-BO-49)
    |--------------------------------------------------------------------------
    |
    | Nunca un literal en el código y nunca derivada de TENANCY_BASE_DOMAIN
    | — ni por concatenación, ni por valor por defecto, ni "para desarrollo"
    | (operacion.md §2). No puede ser un subdominio de TENANCY_BASE_DOMAIN:
    | TenantHost::slugFrom() resolvería su etiqueta como si fuera un
    | centro, y la única defensa sería que ningún tenant se llame así
    | (RN-BO-49, operacion.md §0.4). El nombre concreto sigue bloqueado por
    | OPEN-08; en desarrollo, un host propio de *.test que no termine en
    | el dominio base de los tenants.
    |
    */

    'host' => env('BACKOFFICE_HOST'),

    /*
    |--------------------------------------------------------------------------
    | Sesión de plataforma (ADR-046 §5, datos.md §2.6)
    |--------------------------------------------------------------------------
    |
    | Nombre de cookie propio, distinto del de la cookie del producto, y
    | vida más corta que la del tenant (RN-BO-09) — ninguna de las dos se
    | deriva de SESSION_COOKIE/SESSION_LIFETIME.
    |
    */

    'session_cookie' => env('BO_SESSION_COOKIE', 'pge-backoffice-session'),

    'session_lifetime' => (int) env('BO_SESSION_LIFETIME', 30),

    /*
    |--------------------------------------------------------------------------
    | Reautenticación para operaciones sensibles (RN-BO-08)
    |--------------------------------------------------------------------------
    */

    'reauthentication_window_minutes' => (int) env('BO_REAUTH_WINDOW', 10),

    /*
    |--------------------------------------------------------------------------
    | Doble autorización (REQ-BO-007)
    |--------------------------------------------------------------------------
    */

    'dual_authorization_ttl_minutes' => (int) env('BO_DUAL_AUTH_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Invitación de administradores de plataforma (issue #173)
    |--------------------------------------------------------------------------
    |
    | Mismo criterio y mismo valor por defecto que `core.invitation_ttl_days`
    | (REQ-CORE), pero propia: no se deriva de aquella — es una invitación
    | de plataforma, no de tenant.
    |
    */

    'invitation_ttl_days' => (int) env('BO_INVITATION_TTL_DAYS', 7),

];
