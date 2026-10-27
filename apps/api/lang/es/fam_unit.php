<?php

// REQ-FAM-UNIT (pasos 1.14-a y 1.14-b), docs/modulos/REQ-FAM-UNIT/api.md §7. INV-009: ningún literal visible vive fuera de aquí.
// El `code` de cada error de api.md §7 es a la vez la clave de su mensaje. Los enumerados (`relationship`,
// `access_level`, `status`...) viajan como código técnico (`ADR-038 §3.2`) y la SPA traduce su etiqueta.

return [
    'permissions' => [
        'resources' => [
            'familia' => 'Unidades familiares, tutores y alumnado',
            'vinculo_tutor' => 'Vínculos tutor-alumno',
            'revocacion_tutor' => 'Revocación del acceso de un tutor',
            'consentimiento_imagen' => 'Consentimiento de uso de imagen',
            'texto_legal' => 'Textos legales del centro',
        ],
    ],

    'family_unit' => [
        'code_taken' => 'Ya existe una unidad familiar con ese código.',
        'code_invalid' => 'El código de la unidad familiar debe tener entre 1 y 40 caracteres y no puede contener caracteres de control.',
        'principal_not_member' => 'El tutor principal debe ser un tutor de esta misma unidad familiar.',
        'has_members' => 'La unidad familiar tiene tutores o alumnado: retíralos antes de darla de baja.',
    ],

    'guardian' => [
        'already_exists' => 'Esa persona ya es tutora de una unidad familiar.',
        'not_editable' => 'Un tutor no cambia de unidad: dalo de baja y créalo de nuevo en la otra unidad.',
        'user_not_eligible' => 'El usuario debe estar pendiente de activar o activo para ser tutor.',
    ],

    'student' => [
        'already_in_unit' => 'Ese alumno ya pertenece a una unidad familiar.',
    ],

    'link' => [
        'already_exists' => 'Ya existe un vínculo entre ese tutor y ese alumno.',
        'self_link' => 'Una persona no puede ser tutora de sí misma.',
        'emergency_contact_taken' => 'Ese alumno ya tiene otro contacto de emergencia.',
        'invalid_transition' => 'El vínculo no está en un estado que admita esta operación.',
        'not_editable' => 'El estado del vínculo y su revocación no se editan: usa el recurso de revocación.',
        'emergency_contact_revoked' => 'Un vínculo revocado no puede ser contacto de emergencia.',
        'revoked_not_deletable' => 'Un vínculo revocado no se da de baja: levanta antes la revocación.',
    ],

    'image_consent' => [
        'text_version_outdated' => 'La versión del texto que se mostró ya no es la vigente: vuelve a leer el texto publicado antes de autorizar.',
        'not_legal_guardian' => 'Solo un tutor con representación legal sobre el alumno puede decidir el consentimiento de imagen.',
        'guardian_required' => 'Indica el tutor que decidió (on_behalf_of) al registrar su decisión en nombre de la familia.',
        'guardian_not_linked' => 'El tutor indicado no está vinculado con ese alumno.',
        'text_not_published' => 'El centro no ha publicado el texto del consentimiento de este nivel: no se puede autorizar sin texto.',
        'text_version_invalid' => 'La versión del texto indicada no es válida para este nivel o este canal.',
    ],

    'terms' => [
        'kind_not_available' => 'Este tipo de texto legal todavía no está disponible.',
        'kind_not_editable' => 'El tipo de un texto legal no se puede cambiar una vez creado.',
        'kind_invalid' => 'El tipo de texto legal no es válido.',
        'requires_reacceptance_not_allowed' => 'La re-aceptación solo se puede exigir en las condiciones de uso, no en el consentimiento de imagen.',
        'not_draft' => 'Solo se puede editar, borrar o publicar una versión en borrador.',
        'default_locale_missing' => 'El título y el texto deben estar escritos en el idioma por defecto del centro (:locale) antes de publicar.',
    ],

    'export' => [
        'q_not_allowed' => 'El informe de consentimientos no admite búsqueda libre (q).',
    ],

    'errors' => [
        'consent_rate_limited' => 'Has alcanzado el límite de decisiones de imagen o de informes de consentimientos. Inténtalo de nuevo en unos :minutes minutos.',
        'revocation_rate_limited' => 'Has alcanzado el límite de revocaciones o levantamientos de revocación. Inténtalo de nuevo en unos :minutes minutos.',
    ],

    'validation' => [
        'user_not_found' => 'El usuario indicado no existe.',
        'student_not_found' => 'El alumno indicado no existe.',
        'guardian_not_found' => 'El tutor indicado no existe.',
        'family_unit_not_found' => 'La unidad familiar indicada no existe.',
        'principal_guardian_not_found' => 'El tutor principal indicado no existe.',
        'relationship_invalid' => 'El tipo de relación no es válido.',
        'access_level_invalid' => 'El nivel de acceso no es válido.',
        'restricted_category_invalid' => 'La categoría restringida no es válida.',
        'reason_invalid' => 'El motivo de revocación no es válido.',
        'required' => 'Este campo es obligatorio.',
        'boolean_invalid' => 'El valor debe ser verdadero o falso.',
        'reference_invalid' => 'El identificador indicado no es válido.',
        'filter_forbidden' => 'No tienes permiso para usar este filtro.',
        'level_invalid' => 'El nivel de consentimiento no es válido.',
        'decision_invalid' => 'La decisión no es válida.',
        'channel_invalid' => 'El canal no es válido para este registro.',
        'state_invalid' => 'El estado no es válido.',
        'cursor_invalid' => 'El cursor no es válido para esta consulta.',
    ],
];
