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
        'export_query_not_allowed' => 'An export cannot use a search term: use only the list filters.',
        'status_invalid' => 'The status must be activa or baja.',
        'request_already_pending' => 'The enrolment already has a pending group change request.',
        'reason_invalid' => 'The reason must be between 1 and 500 characters long and contain no control characters.',
        'decision_note_required' => 'A reason must be given when rejecting a request.',
        'decision_note_invalid' => 'The decision note must be between 1 and 500 characters long and contain no control characters.',
        'enrollment_not_active' => 'A group change can only be requested for an active enrolment.',
        'enrollment_without_group' => 'The enrolment has no group: assign one directly, without a request.',
    ],
    'photo' => [
        'file_required' => 'An image must be attached in the "file" field.',
        'type_unsupported' => 'Only JPEG or PNG photographs are accepted.',
        'invalid_image' => 'The file content is not a valid JPEG or PNG image, or it exceeds the maximum dimensions allowed.',
        'too_large' => 'The image exceeds the maximum size allowed.',
    ],
    'errors' => [
        'photo_read_rate_limited' => 'You have reached the limit of student photograph requests. Please try again in about :minutes minutes.',
        'export_rate_limited' => 'You have reached the limit of enrollment exports. Try again in about :minutes minutes.',
    ],
    'conflict' => [
        'cannot_restore' => 'This student cannot be restored: the person no longer exists or already has another student record.',
        'already_withdrawn' => 'The enrolment has already been withdrawn.',
        'has_active_enrollments' => 'A student with active enrolments cannot be deactivated.',
        'request_closed' => 'The request has already been resolved and cannot be changed.',
        'enrollment_changed' => 'The enrolment has changed since the request was made: it is no longer active or no longer in the original group.',
    ],
    'forbidden' => [
        'self_approval' => 'You cannot approve a request that you made yourself.',
    ],
];
