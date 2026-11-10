<?php

// Textos del módulo Alum (ADR-056 §3.5, INV-009). Claves a añadir en los
// cuatro idiomas (es-ES, en, de, fr) a la vez: AR-12 comprueba la paridad.

return [
    'permissions' => [
        'resources' => [
            'alumno' => 'Students, record and photograph',
            'matricula' => 'Enrolment, groups and change requests',
        ],
    ],
    'validation' => [
        'required' => 'This field is required.',
        'text_invalid' => 'The text provided is not valid: it must be between 1 and 255 characters long, with no control characters.',
        'birth_date_invalid' => 'The date of birth must use the YYYY-MM-DD format, be a real date and not be in the future.',
        'person_exists' => 'A person with that identity document already exists.',
        'person_already_student' => 'This person is already a student of the school.',
        'family_unit_not_found' => 'The family unit provided does not exist.',
        'field_not_editable' => 'This field cannot be changed this way.',
        'no_changes' => 'No data to change was provided.',
        'filter_forbidden' => 'You do not have permission to use this filter.',
        'student_not_found' => 'The student provided does not exist.',
        'parent_not_found' => 'The course, group or structure provided does not exist.',
        'academic_year_not_found' => 'The academic year provided does not exist.',
        'course_not_in_academic_year' => 'The course provided does not belong to the academic year.',
        'group_not_in_course' => 'The group provided does not belong to the course.',
        'already_enrolled' => 'The student already has an active enrolment in this academic year.',
        'date_outside_academic_year' => 'The date must fall within the academic year.',
        'date_invalid' => 'The date must use the YYYY-MM-DD format and be a real date.',
        'group_change_requires_request' => 'Changing the group of an enrolment that already has one requires a group change request.',
        'immutable' => 'This enrolment field cannot be changed.',
        'withdrawal_reason_invalid' => 'The withdrawal reason must be transfer, voluntary withdrawal or other.',
        'withdrawn_before_enrolled' => 'The withdrawal date cannot be earlier than the enrolment date.',
    ],
    'conflict' => [
        'cannot_restore' => 'This student cannot be restored: the person no longer exists or already has another student record.',
        'already_withdrawn' => 'The enrolment has already been withdrawn.',
        'has_active_enrollments' => 'A student with active enrolments cannot be deactivated.',
    ],
];
