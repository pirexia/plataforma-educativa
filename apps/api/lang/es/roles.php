<?php

// ADR-034 §2, sección 11.1 del documento de requisitos. Clave =
// `roles.code` (roles.name_key). Super Administrador no aparece: no es
// una fila de `roles` (permisos.md §4.5, issue #48).

return [
    'administrador_centro' => 'Administrador de Centro',
    'direccion' => 'Dirección / Jefatura de Estudios',
    'secretaria' => 'Secretaría',
    'administrativo' => 'Administrativo',
    'docente' => 'Docente',
    'tutor_grupo' => 'Tutor de grupo',
    'orientador' => 'Orientador',
    'coordinador_bienestar' => 'Coordinador de Bienestar y Protección',
    'estudiante' => 'Estudiante',
    'tutor_legal' => 'Tutor legal / Familia',
    'responsable_economico' => 'Responsable económico',
    'bibliotecario' => 'Bibliotecario',
    'monitor_extraescolares' => 'Monitor de extraescolares',
    'personal_sanitario' => 'Personal sanitario / Enfermería',
    'conserjeria_pas' => 'Conserjería / PAS',
    'soporte_plataforma' => 'Soporte de la plataforma',
];
