<?php

// INV-009. Subconjunto de las reglas estándar de Laravel que usa el
// producto (no se publican las ~90 reglas del paquete completo: se amplía
// según cada módulo las use de verdad). ADR-038 §6.3: este es el mensaje
// que ValidationErrorFormatter reexpone como `message`, ya traducido.

return [
    'required' => 'El campo :attribute es obligatorio.',
    'email' => 'El campo :attribute debe ser una dirección de correo válida.',
    'string' => 'El campo :attribute debe ser una cadena de texto.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'array' => 'El campo :attribute debe ser una lista.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_format' => 'El campo :attribute debe tener el formato :format.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'in' => 'El valor seleccionado para :attribute no es válido.',
    'not_in' => 'El valor seleccionado para :attribute no es válido.',
    'distinct' => 'El campo :attribute contiene un valor repetido.',
    'exists' => 'El valor seleccionado para :attribute no existe.',
    'url' => 'El campo :attribute debe ser una URL válida.',
    'ulid' => 'El campo :attribute debe ser un identificador válido.',
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'max' => [
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'string' => 'El campo :attribute no debe tener más de :max caracteres.',
        'array' => 'El campo :attribute no debe tener más de :max elementos.',
        'file' => 'El campo :attribute no debe superar :max kilobytes.',
    ],
    'min' => [
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
    ],
    'size' => [
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],

    'attributes' => [
        'email' => 'correo de acceso',
        'given_name' => 'nombre',
        'family_name_1' => 'primer apellido',
        'family_name_2' => 'segundo apellido',
        'document_type' => 'tipo de documento',
        'document_number' => 'número de documento',
        'birth_date' => 'fecha de nacimiento',
        'contact_email' => 'correo de contacto',
        'contact_phone' => 'teléfono de contacto',
        'locale' => 'idioma',
        'role_ids' => 'roles',
        'status' => 'estado',
        'default_locale' => 'idioma por defecto',
        'active_locales' => 'idiomas activos',
        'timezone' => 'zona horaria',
        'currency' => 'moneda',
        'autonomous_community' => 'comunidad autónoma',
        'color_primary' => 'color primario',
        'color_secondary' => 'color secundario',
        'file' => 'archivo',
        'from' => 'desde',
        'to' => 'hasta',
        'format' => 'formato',
        'settings' => 'configuración',
        'reason' => 'motivo',
        'expires_at' => 'caducidad',
        'user' => 'usuario',
    ],
];
