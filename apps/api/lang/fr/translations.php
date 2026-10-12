<?php

// ADR-062 §5.2 point 4 (REQ-ACAD-001, 1.11-a), INV-009 : codes d'erreur
// communs du contenu de l'établissement traduisible.

return [
    'default_locale_required' => 'Le texte dans la langue par défaut de l\'établissement (:locale) est manquant.',
    'locale_not_active' => 'La langue « :locale » n\'est pas active pour cet établissement.',
    'empty_value' => 'Le texte en « :locale » ne peut pas être vide.',
];
