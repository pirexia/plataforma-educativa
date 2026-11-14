<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tope de tamaño de un valor registrado en `changes` (ADR-035 §5)
    |--------------------------------------------------------------------------
    |
    | Se aplica sobre el valor codificado en JSON. Todo valor que lo supere
    | se redacta como "oversized" — nunca se trunca. Red de seguridad contra
    | el campo de texto libre que nadie clasificó como identificativo.
    |
    */

    'max_value_length' => (int) env('AUDIT_MAX_VALUE_LENGTH', 256),

    /*
    |--------------------------------------------------------------------------
    | Patrón global de atributos secretos (ADR-035 §4, paso 1)
    |--------------------------------------------------------------------------
    |
    | Defensa en profundidad: un atributo cuyo nombre encaja aquí se redacta
    | como "secret" aunque el modelo no lo declare en
    | auditSecretAttributes(). fnmatch() contra el nombre del atributo,
    | insensible a mayúsculas.
    |
    */

    'secret_attribute_patterns' => [
        '*password*',
        '*token*',
        '*secret*',
        '*_key',
        '*totp*',
        '*recovery_code*',
    ],

];
