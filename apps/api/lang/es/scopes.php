<?php

// REQ-PERM/permisos.md §10 (1.5), ADR-044 §4.1: el vocabulario cerrado de
// ámbitos. Clave = valor del enum `App\Support\Authorization\Scope`
// (INV-009: el valor del dominio del código no se traduce — `ADR-038
// §3.2` —, lo que se traduce es su etiqueta). 1.5 las crea aunque todavía
// no las pinte ninguna pantalla: la matriz de permisos de 1.5b las
// consumirá directamente.

return [
    'todos' => 'Todos',
    'propios' => 'Propios',
    'departamento' => 'Departamento',
    'grupo' => 'Grupo',
    'clase' => 'Clase',
    'unidad_familiar' => 'Unidad familiar',
];
