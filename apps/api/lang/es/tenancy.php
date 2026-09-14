<?php

// ADR-021, INV-009. Primeras claves del sistema de traducción: se amplía
// formalmente (herramienta de cobertura, detección de literales) en 0.9.

return [
    'suspended' => 'Este centro está temporalmente suspendido. Contacta con soporte si crees que es un error.',
    // REQ-BO/funcional.md §5.4.1 (1.6b, RN-BO-50): mensajes por defecto
    // para los otros tres estados sin acceso.
    'provisioning' => 'Este centro se está preparando. Vuelve a intentarlo en unos minutos.',
    'closing' => 'Este centro está en proceso de baja.',
    'closed' => 'Este centro ha dejado de estar disponible.',
];
