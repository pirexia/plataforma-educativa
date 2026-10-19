<?php

// REQ-ACAD (paso 1.11-b), permisos.md §8. INV-009: ningún literal visible
// vive fuera de aquí. `stage` y `regime` viajan como código técnico
// (RN-ACAD-12) y la SPA traduce su etiqueta. Los mensajes de error de
// api.md §6 se añaden con los endpoints (lote 2).

return [
    'permissions' => [
        'resources' => [
            'estructura_academica' => 'Structure académique',
            'configuracion_academica' => 'Paramètres académiques',
            'asignacion_docente' => 'Affectations d’enseignement',
            'espacios' => 'Espaces',
            'horarios' => 'Emplois du temps',
            'reservas' => 'Réservations',
        ],
    ],

    'stage' => [
        'infantil' => 'Petite enfance',
        'primaria' => 'Primaire',
        'eso' => 'Collège (ESO)',
        'bachillerato' => 'Lycée (Bachillerato)',
        'fp' => 'Formation professionnelle',
        'universidad' => 'Université',
    ],

    'regime' => [
        'publico' => 'Public',
        'privado' => 'Privé',
        'concertado' => 'Sous contrat',
    ],

    'period_type' => [
        'trimestre' => 'Trimestre',
        'cuatrimestre' => 'Quadrimestre',
        'semestre' => 'Semestre',
    ],

    'scale_type' => [
        'numerica' => 'Numérique',
        'cualitativa' => 'Qualitative',
        'mixta' => 'Mixte',
    ],

    'validation' => [
        'code_required' => 'Le code est obligatoire.',
        'invalid_characters' => 'Le texte contient des caractères de contrôle non autorisés, ou des sauts de ligne là où ils ne sont pas admis.',
        'code_taken' => 'Un élément avec ce code existe déjà parmi ses éléments frères.',
        'name_required' => 'Le nom est obligatoire.',
        'stage_invalid' => 'Le type d\'étape n\'est pas valide.',
        'regime_invalid' => 'Le régime n\'est pas valide.',
        'position_invalid' => 'La position doit être un entier supérieur ou égal à 1.',
        'academic_year_required' => 'L\'année scolaire est obligatoire.',
        'academic_year_not_found' => 'L\'année scolaire indiquée n\'existe pas.',
        'academic_year_not_allowed' => 'L\'année scolaire ne s\'indique pas : c\'est celle de l\'élément parent.',
        'parent_not_found' => 'L\'élément parent indiqué n\'existe pas.',
        'parent_immutable' => 'L\'élément parent et l\'année scolaire ne peuvent pas être modifiés.',
        'subject_not_in_course' => 'Une matière n\'existe pas ou n\'appartient pas au niveau du groupe.',
        'period_type_invalid' => 'Le type de période n’est pas valide.',
        'date_invalid' => 'La date n’est pas valide (format AAAA-MM-JJ).',
        'period_ends_before_start' => 'La date de fin doit être postérieure à la date de début.',
        'period_outside_academic_year' => 'La période doit se situer dans l’année scolaire (:academic_year_starts_on – :academic_year_ends_on).',
        'period_overlap' => 'La période chevauche une autre période de la même étape.',
        'period_type_mismatch' => 'Toutes les périodes d’une étape doivent être du même type (:period_type).',
        'scale_type_invalid' => 'Le type d’échelle n’est pas valide.',
        'scale_type_immutable' => 'Le type d’une échelle ne peut pas être modifié.',
        'scale_field_required' => 'Ce champ est obligatoire pour ce type d’échelle.',
        'not_applicable_to_scale_type' => 'Ce champ n’est pas admis pour ce type d’échelle.',
        'decimal_invalid' => 'Saisissez un nombre avec un point décimal, de −999.99 à 999.99.',
        'decimals_invalid' => 'Les décimales doivent être 0, 1 ou 2.',
        'decimal_precision_exceeded' => 'La valeur a plus de décimales que l’échelle n’en admet (:decimals).',
        'range_invalid' => 'Le minimum doit être inférieur au maximum.',
        'passing_out_of_range' => 'La note minimale de réussite doit être comprise entre le minimum et le maximum.',
        'values_required' => 'L’échelle doit comporter au moins une valeur.',
        'values_too_many' => 'Une échelle admet au maximum :max valeurs.',
        'is_passing_required' => 'Indiquez si cette valeur correspond à une réussite.',
        'passing_value_required' => 'Au moins une valeur doit correspondre à une réussite.',
        'value_not_in_scale' => 'La valeur n’appartient pas à cette échelle.',
        'band_range_invalid' => 'La tranche doit se situer dans l’échelle et son début ne peut pas dépasser sa fin.',
        'bands_not_contiguous' => 'Les tranches doivent couvrir toute l’échelle, dans l’ordre, sans trou ni chevauchement.',
        'passing_threshold_inside_band' => 'La note minimale de réussite doit coïncider avec le début d’une tranche.',
        'user_not_found' => 'L’utilisateur indiqué n’existe pas ou n’est pas actif.',
        'subject_not_in_group' => 'La matière n’est pas affectée à ce groupe.',
        'subgroup_not_in_group' => 'Le sous-groupe n’existe pas ou n’appartient pas à ce groupe.',
        'assignment_exists' => 'Cette affectation d’enseignement existe déjà.',
        'facility_type_invalid' => 'Le type d’espace n’est pas valide.',
        'facility_type_immutable' => 'Le type d’un espace ne peut pas être modifié.',
        'frame_type_invalid' => 'Le type de cadre d’emploi du temps n’est pas valide.',
        'frame_type_immutable' => 'Le type d’un cadre d’emploi du temps ne peut pas être modifié.',
        'frame_ordinary_exists' => 'Cette étape a déjà un cadre d’emploi du temps ordinaire.',
        'frame_dates_invalid' => 'Les dates de validité ne sont pas valides pour ce type de cadre d’emploi du temps.',
        'frame_outside_academic_year' => 'La validité du cadre doit être comprise dans l’année scolaire (:academic_year_starts_on – :academic_year_ends_on).',
        'frame_overlap' => 'La validité chevauche celle d’un autre cadre spécial de la même étape.',
        'booking_outside_academic_year' => 'La date de la réservation doit être comprise dans l’année scolaire (:academic_year_starts_on – :academic_year_ends_on).',
        'booking_conflict' => 'L’espace est déjà réservé ou occupé par une séance de l’emploi du temps à cette date et à cette heure.',
        'weekday_invalid' => 'Le jour de la semaine doit être un nombre de 1 (lundi) à 7 (dimanche).',
        'time_invalid' => 'L’heure doit être au format HH:MM (24 heures).',
        'slot_ends_before_start' => 'L’heure de fin doit être postérieure à l’heure de début.',
        'slot_overlap' => 'Le créneau chevauche un autre créneau du même jour.',
        'slot_not_in_frame' => 'Le créneau n’appartient pas à ce cadre ou il est en double.',
        'slots_too_many' => 'Un cadre admet au maximum :max créneaux.',
        'slots_invalid' => 'Les créneaux doivent être indiqués sous forme de liste d’objets.',
        'slot_not_for_group' => 'Le créneau appartient à un cadre d’une étape autre que celle du groupe.',
        'facility_not_found' => 'L’espace indiqué n’existe pas.',
        'facility_not_room' => 'L’espace indiqué n’est pas une salle de classe.',
        'timetable_conflict' => 'La séance coïncide dans l’emploi du temps avec une autre séance du même enseignant, de la même salle ou du même groupe.',
        'view_filter_required' => 'Indiquez au moins un des filtres : teacher, facility, group ou subject.',
        'view_too_large' => 'La vue comporte trop de séances (maximum :max).',
    ],

    'staff' => [
        'unnamed' => 'Utilisateur sans nom',
    ],

    'conflict' => [
        'has_dependents' => 'Impossible de supprimer « :code » (:entity) : il a des éléments dépendants (:dependents). Supprimez-les d\'abord.',
        'periods_outside_academic_year' => 'Des périodes scolaires se trouveraient en dehors de l’année scolaire : ses dates doivent inclure la période du :periods_starts_on au :periods_ends_on.',
    ],

    'entities' => [
        'education_level' => 'étape',
        'course' => 'niveau',
        'group' => 'groupe',
        'subject' => 'matière',
        'subgroup' => 'sous-groupe',
        'facility' => 'espace',
        'timetable_frame' => 'cadre d’emploi du temps',
        'teaching_assignment' => 'affectation d’enseignement',
    ],

    'dependents' => [
        'courses' => 'niveaux',
        'groups' => 'groupes',
        'subgroups' => 'sous-groupes',
        'subjects' => 'matières',
        'academic_periods' => 'périodes scolaires',
        'group_tutors' => 'professeurs principaux',
        'teaching_assignments' => 'affectations d’enseignement',
        'timetable_frames' => 'cadres d’emploi du temps',
        'timetable_sessions' => 'séances d’emploi du temps',
        'facility_bookings' => 'réservations d’espaces',
    ],
];
