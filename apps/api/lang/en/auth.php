<?php

// REQ-AUTH, step 1.2. See lang/es/auth.php for the authoritative comment.

return [

    'validation' => [
        'password' => [
            'min_length' => 'The password must be at least :min characters long.',
            'max_bytes' => 'The password is too long.',
            'uppercase' => 'The password must include at least one uppercase letter.',
            'lowercase' => 'The password must include at least one lowercase letter.',
            'digit' => 'The password must include at least one number.',
            'symbol' => 'The password must include at least one symbol.',
            'same_as_current' => 'The new password cannot be the same as the current one.',
        ],
        'current_password_incorrect' => 'The current password is incorrect.',
        'lockout_already_unlocked' => 'This lockout has already been lifted.',
        'session_already_closed' => 'This session is already closed.',
        'mfa_factor_already_confirmed' => 'You already have a confirmed factor for this method.',
        'mfa_method_not_available' => 'This verification method is not available at this school.',
        'mfa_code_invalid' => 'The code is not correct.',
        'mfa_factor_required_by_role' => 'You cannot disable your last factor: one of your roles requires two-step verification.',
        'mfa_exemption_already_live' => 'This user already has a live MFA exemption.',
        'mfa_exemption_self' => 'You cannot grant yourself an MFA exemption.',
        'mfa_reset_self' => 'You cannot reset your own MFA.',
        'oauth_provider_not_configured' => 'This sign-in provider is not available at this school.',
        'oauth_intent_requires_session' => 'You must be signed in to link an account.',
        'identity_would_leave_user_without_access' => 'You cannot unlink this account: it is your only way to sign in.',
    ],

    'mail' => [
        'account_locked' => [
            'subject' => 'Your account at :tenant has been temporarily locked',
            'greeting' => 'Hello, :name.',
            'body' => ':count failed login attempts were detected on your account at :tenant, and it has been temporarily locked for security.',
            'auto_unlock' => 'The lock will be lifted automatically on :time if you do nothing.',
            'cta' => 'Unlock my account now',
        ],
        'password_reset' => [
            'subject' => 'Reset your password at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'You requested to reset your password at :tenant.',
            'cta' => 'Reset my password',
            'expires' => 'This link expires in :minutes minutes.',
            'ignore' => 'If this was not you, you can ignore this message: your current password remains valid.',
        ],
        'password_changed' => [
            'subject' => 'Your password at :tenant has changed',
            'greeting' => 'Hello, :name.',
            'body' => 'Your password at :tenant has just been changed.',
            'warning' => 'If this was not you, contact your school administration as soon as possible.',
        ],
        'mfa_factor_activated' => [
            'subject' => 'Two-step verification has been turned on at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'A new two-step verification factor has just been activated on your account at :tenant.',
            'warning' => 'If this was not you, contact your school administration as soon as possible.',
        ],
        'mfa_factor_removed' => [
            'subject' => 'Two-step verification has been turned off at :tenant',
            'greeting' => 'Hello, :name.',
            'body_by_self' => 'A two-step verification factor has just been removed from your account at :tenant.',
            'body_by_admin' => 'An administrator at :tenant has reset two-step verification on your account: your previous factors and backup codes are no longer valid.',
            'warning' => 'If this was not you, contact your school administration as soon as possible.',
        ],
        'recovery_code_used' => [
            'subject' => 'A backup code was used at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'One of your backup codes was used to sign in to your account at :tenant instead of your usual second factor.',
            'warning' => 'If this was not you, contact your school administration as soon as possible and review your active sessions.',
        ],
        'mfa_enrollment_code' => [
            'subject' => 'Code to turn on two-step verification at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'You are turning on email as two-step verification on your account at :tenant. Use this code to confirm it.',
            'code' => 'Your code: :code',
            'expires' => 'This code expires in :minutes minutes.',
        ],
        'mfa_challenge_code' => [
            'subject' => 'Your sign-in code for :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'You are signing in to :tenant and need this code to complete two-step verification.',
            'code' => 'Your code: :code',
            'expires' => 'This code expires in :minutes minutes.',
            'warning' => 'If you did not try to sign in, change your password as soon as possible.',
        ],
        'new_device_login' => [
            'subject' => 'New sign-in to your account at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'Your account at :tenant was just accessed from a device we had not seen before, on :time.',
            'detail' => 'Sign-in details: :client, from IP address :ip.',
            'location_line' => 'Approximate location: :location.',
            'what_to_do' => 'If this was you, there is nothing else to do. If you do not recognise this sign-in, review your active sessions and change your password as soon as possible.',
            'cta' => 'Review my active sessions',
            'unknown_client' => 'an unknown device',
        ],
        'identity_linked' => [
            'subject' => 'A Google account has been linked at :tenant',
            'greeting' => 'Hello, :name.',
            'body_fusion' => 'When you signed in with Google at :tenant, the system automatically linked the account :email to your profile because the email matched and was verified.',
            'body_profile' => 'You just linked the Google account :email to your profile at :tenant.',
            'warning' => 'If this was not you, contact your school administration as soon as possible.',
        ],
        'identity_unlinked' => [
            'subject' => 'A Google account has been unlinked at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'The Google account :email has been unlinked from your profile at :tenant.',
            'warning' => 'If this was not you, contact your school administration as soon as possible.',
        ],
        'identity_matched' => [
            'subject' => 'Your account has been linked at :tenant',
            'greeting' => 'Hello, :name.',
            'body' => 'When you signed in with :provider at :tenant, the system automatically linked the account :email to your profile because the email matched.',
            'warning' => 'If this was not you, contact your school administration as soon as possible.',
        ],
    ],

    'sso' => [
        'identity_provider_issuer_already_catalogued' => 'This school already has a provider catalogued with that issuer.',
        'identity_provider_enable_without_secret' => 'You cannot enable this provider without a current client credential.',
        'identity_provider_secret_last_active' => 'You cannot retire the last active credential of an enabled provider: disable it first.',
        'discovery' => [
            'esquema_no_admitido' => 'The discovery URL must use https.',
            'destino_no_publico' => 'That address cannot be reached from this server.',
            'demasiadas_redirecciones' => 'The discovery document redirects too many times.',
            'sin_respuesta' => 'The discovery document could not be downloaded.',
            'respuesta_demasiado_grande' => 'The discovery document is too large.',
            'documento_no_valido' => 'The discovery document is invalid or is missing required fields.',
            'emisor_no_coincide' => 'The declared issuer does not match the origin of the discovery URL.',
            'endpoint_no_seguro' => 'One of the declared endpoints does not use https.',
            'flujo_no_admitido' => 'This issuer does not support the required authorization flow.',
        ],
    ],

];
