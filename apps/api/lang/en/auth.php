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
    ],

];
