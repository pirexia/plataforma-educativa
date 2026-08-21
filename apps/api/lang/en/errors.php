<?php

// ADR-038 §6. See lang/es/errors.php for the authoritative comment.

return [
    'title' => [
        'malformed' => 'Malformed request',
        'unauthenticated' => 'You are not signed in',
        'forbidden' => 'You do not have permission',
        'module-disabled' => 'Module not available',
        'not-found' => 'Resource not found',
        'method-not-allowed' => 'Method not allowed',
        'conflict' => 'Conflict with the current state',
        'gone' => 'Resource no longer available',
        'payload-too-large' => 'File too large',
        'unsupported-media-type' => 'Unsupported file type',
        'validation' => 'The submitted data is not valid',
        'too-many-requests' => 'Too many requests',
        'internal' => 'Internal server error',
        'unavailable' => 'Service temporarily unavailable',
    ],

    'detail' => [
        'unauthenticated' => 'You must sign in to access this resource.',
        'forbidden' => 'You do not have permission to perform this action.',
        'module-disabled' => 'This module is not active for your school.',
        'not-found' => 'The requested resource does not exist.',
        'method-not-allowed' => 'The HTTP method is not allowed for this route.',
        'validation' => 'Please review the indicated fields.',
        'internal' => 'An unexpected error occurred. Keep the request identifier if you contact support.',
        'unavailable' => 'The service is temporarily unavailable. Please try again in a few minutes.',
    ],
];
