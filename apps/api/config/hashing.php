<?php

// REQ-AUTH/funcional.md RN-AUTH-03: coste bcrypt mínimo 12. Sin este
// fichero, el hasher por defecto de Laravel cae al coste 10 del
// framework — insuficiente. `AUTH_BCRYPT_ROUNDS` es la misma variable que
// config/auth-local.php expone para lectura (ConfigPasswordPolicy no la
// usa para hashear, solo para no perder de vista el valor); aquí es
// donde el hasher de verdad la consume, vía el cast `'password' =>
// 'hashed'` de App\Models\User.

return [

    'driver' => 'bcrypt',

    'bcrypt' => [
        'rounds' => (int) env('AUTH_BCRYPT_ROUNDS', 12),
        'verify' => true,
    ],

    // RN-AUTH-03: reamasado automático cuando el coste almacenado queda
    // por debajo del configurado. Solo tiene efecto en el flujo de
    // Auth::attempt() del framework; LoginService reamasa a mano porque
    // no usa ese flujo (issue #18, verificación de credencial propia).
    'rehash_on_login' => true,

];
