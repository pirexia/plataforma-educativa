<?php

// REQ-AUTH (1.2), issue #68. Sin este fichero, Laravel aplica el CORS por
// defecto del paquete (`allowed_origins: ['*']`, `supports_credentials:
// false`) — incompatible con `credentials: 'include'`, que
// `apps/web/src/api/client.ts` envía en toda petición desde 1.2 (ADR-025).
// Un navegador rechaza cualquier respuesta `Access-Control-Allow-Origin:
// *` cuando la petición lleva credenciales; es una restricción del propio
// navegador, no configurable desde el cliente.
//
// Solo importa en desarrollo: en producción/*staging* la SPA y la API
// comparten origen a través de Traefik (`ADR-028`), así que el navegador
// nunca envía una petición cross-origin y esta configuración no entra en
// juego. `CORS_ALLOWED_ORIGINS` permite añadir puertos de depuración
// adicionales sin tocar código (p. ej. `apps/web` levantado fuera de
// contenedor en un puerto distinto de 5173).

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173'
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    // RN-AUTH-*, ADR-038 §6.5/§11: cabeceras que el cliente necesita leer
    // desde JavaScript en una respuesta cross-origin. Sin exponerlas
    // explícitamente, `fetch()` las recibe pero `Headers.get()` devuelve
    // `null` para todo lo que no sea una cabecera "segura" por defecto.
    'exposed_headers' => ['Retry-After', 'Content-Language', 'X-Request-Id'],

    'max_age' => 0,

    // Imprescindible: sin esto, toda petición con `credentials: 'include'`
    // (la cookie de sesión de ADR-025) es rechazada por el navegador desde
    // el momento en que SPA y API están en orígenes distintos.
    'supports_credentials' => true,

];
