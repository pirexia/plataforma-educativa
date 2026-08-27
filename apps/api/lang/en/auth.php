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
    ],

];
