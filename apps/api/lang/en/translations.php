<?php

// ADR-062 §5.2 point 4 (REQ-ACAD-001, 1.11-a), INV-009: shared error codes
// for translatable school content.

return [
    'default_locale_required' => 'The text in the school\'s default language (:locale) is missing.',
    'locale_not_active' => 'The language ":locale" is not active for this school.',
    'empty_value' => 'The text in ":locale" cannot be empty.',
    'invalid_characters' => 'The text in ":locale" contains control characters that are not allowed.',
];
