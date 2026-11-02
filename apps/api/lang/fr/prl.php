<?php

// Textos del módulo Prl (ADR-056 §3.5, INV-009), docs/modulos/REQ-PRL/api.md §8.4, §8.6. Claves en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad. El `code` de cada error de api.md §8.6 es a la vez
// la clave de su mensaje. Los enumerados (`direction`, `basis`, `verification_method`, `status`...) viajan como código técnico
// (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'entrega_menor' => 'Remise des mineurs à la porte',
            'alerta_entrega' => 'Alertes concernant une personne non autorisée lors de la remise',
        ],
    ],

    'movement' => [
        'person_not_authorized' => 'Cette personne ne figure pas sur la liste de récupération de cet élève : l\'élève ne peut pas lui être remis.',
        'verification_requires_document' => 'Cette personne n\'a pas de photographie enregistrée : la remise ne peut être vérifiée que par document.',
        'reason_required' => 'Un mouvement hors horaire nécessite un motif (et une note si le motif est « autre »).',
    ],

    'alert' => [
        'closed' => 'L\'alerte est déjà clôturée et ne peut plus être modifiée.',
    ],

    'idempotency_mismatch' => 'Cette clé d\'idempotence a déjà été utilisée avec un contenu différent.',

    'protocol' => [
        'minimal' => 'No entregues al alumno. Retenlo en el centro, avisa a dirección y llama al tutor principal con el teléfono que figura en esta alerta. Anota después la hora de la llamada y la resolución.',
    ],

    'errors' => [
        'read_rate_limited' => 'Vous avez atteint la limite de consultations des alertes et des mouvements de la porte. Réessayez dans environ :minutes minutes.',
    ],

    'validation' => [
        'required' => 'Ce champ est obligatoire.',
        'reference_invalid' => 'L\'identifiant indiqué n\'est pas valide.',
        'enum_invalid' => 'La valeur indiquée n\'est pas valide.',
        'text_invalid' => 'Le texte n\'est pas valide ou dépasse :max caractères.',
        'boolean_invalid' => 'La valeur doit être vraie ou fausse.',
        'datetime_invalid' => 'La date et l\'heure ne sont pas valides (format ISO 8601).',
        'student_not_found' => 'L\'élève indiqué n\'existe pas.',
        'user_not_found' => 'L\'utilisateur indiqué n\'existe pas ou n\'est pas actif.',
        'reference_not_found' => 'Le mouvement à corriger n\'existe pas pour cet élève.',
        'entry_without_pickup' => 'Une entrée ne comporte ni personne venant chercher l\'élève ni méthode de vérification.',
        'off_schedule_reason_unexpected' => 'Seul un mouvement hors horaire comporte un motif.',
        'cursor_invalid' => 'Le curseur n\'est pas valide pour cette requête.',
        'idempotency_key_invalid' => 'La clé d\'idempotence doit comporter entre 1 et 128 caractères visibles.',
        'field_not_editable' => 'Cette donnée de l\'alerte ne peut pas être modifiée.',
        'notes_without_resolution' => 'Les notes de résolution ne s\'indiquent qu\'avec la résolution.',
        'empty_body' => 'Indiquez au moins une donnée : l\'appel au tuteur principal ou la résolution.',
        'contact_time_out_of_range' => 'L\'heure de l\'appel ne peut être ni future ni antérieure à l\'ouverture de l\'alerte.',
    ],
];
