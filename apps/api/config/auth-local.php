<?php

// operacion.md §2.1. REQ-AUTH (paso 1.2). Ninguna es secreta. Nombre de
// fichero "auth-local" (no "auth"): config/auth.php ya existe y es del
// framework (guards, providers, password broker) — reutilizarlo mezclaría
// dos orígenes de configuración distintos bajo la misma clave.

return [

    /*
    |--------------------------------------------------------------------------
    | Bloqueo de cuenta (RN-AUTH-14)
    |--------------------------------------------------------------------------
    */
    'max_login_attempts' => (int) env('AUTH_LOGIN_MAX_ATTEMPTS', 5),
    'lockout_minutes' => (int) env('AUTH_LOCKOUT_MINUTES', 15),
    'unlock_token_ttl_hours' => (int) env('AUTH_UNLOCK_TOKEN_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Recuperación de contraseña (RN-AUTH-12)
    |--------------------------------------------------------------------------
    */
    'password_reset_ttl_minutes' => (int) env('AUTH_PASSWORD_RESET_TTL_MINUTES', 60),

    /*
    |--------------------------------------------------------------------------
    | Expiración de sesión por inactividad (REQ-AUTH-005 punto 1)
    |--------------------------------------------------------------------------
    */
    'session_timeout_default_minutes' => (int) env('AUTH_SESSION_TIMEOUT_DEFAULT_MINUTES', 30),
    'session_timeout_max_minutes' => (int) env('AUTH_SESSION_TIMEOUT_MAX_MINUTES', 480),

    /*
    |--------------------------------------------------------------------------
    | Retención de telemetría (datos.md §A.9)
    |--------------------------------------------------------------------------
    */
    'login_attempt_retention_days' => (int) env('AUTH_LOGIN_ATTEMPT_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Política de contraseñas (RN-AUTH-01, RN-AUTH-02, RN-AUTH-03)
    |--------------------------------------------------------------------------
    |
    | No son conmutadores de política: existen para poder endurecerla. Las
    | guardas de arranque de AuthServiceProvider rechazan valores por
    | debajo del mínimo exigido por la especificación.
    |
    */
    'password_min_length' => (int) env('AUTH_PASSWORD_MIN_LENGTH', 12),
    'password_max_bytes' => 72,
    'bcrypt_rounds' => (int) env('AUTH_BCRYPT_ROUNDS', 12),

    /*
    |--------------------------------------------------------------------------
    | Límites de tasa (operacion.md §6)
    |--------------------------------------------------------------------------
    |
    | Todos "número/ventana en segundos". Toda clave de limitación incluye
    | el tenant (ADR-033 §9, TenantContext::rateLimitKey()).
    |
    */
    'rate_limits' => [
        'session_ip' => ['max' => (int) env('AUTH_RATE_LIMIT_SESSION_IP_MAX', 10), 'decay' => 60],
        'session_email' => ['max' => (int) env('AUTH_RATE_LIMIT_SESSION_EMAIL_MAX', 5), 'decay' => 60],
        'password_reset_request_ip' => ['max' => (int) env('AUTH_RATE_LIMIT_RESET_REQUEST_IP_MAX', 5), 'decay' => 3600],
        'password_reset_request_email' => ['max' => (int) env('AUTH_RATE_LIMIT_RESET_REQUEST_EMAIL_MAX', 3), 'decay' => 3600],
        'token_endpoints_ip' => ['max' => (int) env('AUTH_RATE_LIMIT_TOKEN_ENDPOINTS_IP_MAX', 10), 'decay' => 3600],
        'csrf_cookie_ip' => ['max' => (int) env('AUTH_RATE_LIMIT_CSRF_COOKIE_IP_MAX', 60), 'decay' => 60],
    ],

];
