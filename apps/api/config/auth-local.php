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
    | Sesiones activas y dispositivos (1.2b, REQ-AUTH-005 puntos 2-4)
    |--------------------------------------------------------------------------
    |
    | operacion.md §B.2. AUTH_NEW_DEVICE_ALERTS_PER_DAY: número puesto sin
    | medición (operacion.md §B.2), a revisar con REQ-SEED (1.15b).
    |
    */
    'device_cookie_ttl_days' => (int) env('AUTH_DEVICE_COOKIE_TTL_DAYS', 365),
    'new_device_alerts_per_day' => (int) env('AUTH_NEW_DEVICE_ALERTS_PER_DAY', 5),
    'user_session_retention_days' => (int) env('AUTH_USER_SESSION_RETENTION_DAYS', 90),
    'known_device_retention_days' => (int) env('AUTH_KNOWN_DEVICE_RETENTION_DAYS', 365),
    'user_agent_max_length' => (int) env('AUTH_USER_AGENT_MAX_LENGTH', 1024),

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
        // REQ-AUTH-003 (1.3), operacion.md §C.6.
        'mfa_verification_ip' => ['max' => (int) env('AUTH_RATE_LIMIT_MFA_VERIFICATION_IP_MAX', 10), 'decay' => 60],
        'mfa_verification_session' => ['max' => (int) env('AUTH_RATE_LIMIT_MFA_VERIFICATION_SESSION_MAX', 5), 'decay' => 60],
        'mfa_challenge_session' => ['max' => (int) env('AUTH_RATE_LIMIT_MFA_CHALLENGE_SESSION_MAX', 3), 'decay' => 600],
        'mfa_enrollment_user' => ['max' => (int) env('AUTH_RATE_LIMIT_MFA_ENROLLMENT_USER_MAX', 10), 'decay' => 3600],
        'mfa_recovery_codes_user' => ['max' => (int) env('AUTH_RATE_LIMIT_MFA_RECOVERY_CODES_USER_MAX', 5), 'decay' => 3600],
        'mfa_resets_admin' => ['max' => (int) env('AUTH_RATE_LIMIT_MFA_RESETS_ADMIN_MAX', 20), 'decay' => 3600],
    ],

    /*
    |--------------------------------------------------------------------------
    | MFA — TOTP, códigos de respaldo, desafío del login en dos pasos
    | (REQ-AUTH-003, 1.3)
    |--------------------------------------------------------------------------
    |
    | operacion.md §C.2.1. Ninguna es un secreto. `totp_window` tiene guarda
    | de arranque en AuthServiceProvider: un valor por encima de 2 amplía la
    | ventana de validez de un código a más de dos minutos y medio.
    |
    */
    'mfa' => [
        'challenge_ttl_minutes' => (int) env('AUTH_MFA_CHALLENGE_TTL_MINUTES', 5),
        'max_attempts' => (int) env('AUTH_MFA_MAX_ATTEMPTS', 5),
        'enrollment_ttl_minutes' => (int) env('AUTH_MFA_ENROLLMENT_TTL_MINUTES', 10),
        'code_ttl_minutes' => (int) env('AUTH_MFA_CODE_TTL_MINUTES', 10),
        'max_deliveries' => (int) env('AUTH_MFA_MAX_DELIVERIES', 3),
        'recovery_code_count' => (int) env('AUTH_MFA_RECOVERY_CODE_COUNT', 10),
        'totp_window' => (int) env('AUTH_MFA_TOTP_WINDOW', 1),
        'grace_default_days' => (int) env('AUTH_MFA_GRACE_DEFAULT_DAYS', 7),
        'max_exemption_days' => (int) env('AUTH_MFA_MAX_EXEMPTION_DAYS', 90),
        'factor_purge_days' => (int) env('AUTH_MFA_FACTOR_PURGE_DAYS', 30),
        'challenge_retention_hours' => (int) env('AUTH_MFA_CHALLENGE_RETENTION_HOURS', 24),
    ],

];
