<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dominio base para la resolución por subdominio (ADR-014)
    |--------------------------------------------------------------------------
    |
    | El slug del tenant es la etiqueta más a la izquierda del host cuando el
    | resto coincide con este dominio (p.ej. "demo.plataforma.test" con base
    | "plataforma.test" resuelve el slug "demo"). Sin valor, ningún host
    | resuelve tenant (falla en cerrado: 404, nunca "sin filtro").
    |
    | Pendiente de infraestructura real: dominio y DNS con comodín (OPEN-08,
    | paso 0.10b, todavía bloqueante). ADR-014 también prevé dominio
    | personalizado por tenant; no implementado en 0.7 — requiere el campo
    | correspondiente en `tenants` y la gestión de certificados de RUX-DOM-003,
    | ninguno de los dos decidido todavía.
    |
    */

    'base_domain' => env('TENANCY_BASE_DOMAIN'),

];
