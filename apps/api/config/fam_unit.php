<?php

/*
 * REQ-FAM-UNIT/api.md §2.5 y §7 (issue #470): configuración del módulo `fam_unit`.
 *
 * `revocation.rate_limit`: límite de las dos únicas acciones con consecuencias legales del módulo, revocar y levantar la
 * revocación de un vínculo (`POST` y `DELETE /guardian-links/{id}/revocation`). Por centro **y** usuario, con un cubo
 * propio cada acción, contando todos los intentos (también los que acaban en 404 o 409)
 * para que no sirvan de sondeo. Las lecturas y las demás escrituras no tienen límite propio; el limitador global de la
 * API es un paso transversal aparte.
 */
return [

    'revocation' => [
        'rate_limit' => [
            'attempts' => 30,
            'decay_seconds' => 3600,
        ],
    ],

];
