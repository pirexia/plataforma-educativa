---
name: i18n-cuatro-idiomas
description: Reglas de internacionalización del proyecto. Úsala al escribir cualquier texto visible, plantilla de documento, correo, notificación o campo de contenido editable por el centro.
---

# Internacionalización

Idiomas obligatorios: **es-ES (por defecto), en, de, fr** (`ADR-021`). El idioma se elige **por usuario**, no por tenant.

## Tres capas, no una

1. **Interfaz**: ningún literal escrito en el código (`INV-009`). Todo por clave de traducción.
2. **Documentos y comunicaciones generadas**: boletines, informes de desarrollo, facturas, recibos, autorizaciones, certificados, circulares y correos transaccionales se emiten **en el idioma del destinatario**, no en el del emisor ni en el del sistema.
3. **Contenido del centro**: nombres de asignaturas y actividades, condiciones de uso, páginas públicas. Campos multi-idioma con idioma de respaldo cuando falte traducción.

La capa 2 es la que se olvida y la que no se puede añadir después sin reescribir toda la generación de documentos.

## Reglas prácticas

- El idioma del destinatario se resuelve **al generar** el documento, no al encolarlo: un job que renderiza un PDF debe recibir el idioma en su payload.
- Fechas, monedas y números se formatean según la localización del usuario. La moneda es la del tenant.
- Nunca construyas frases concatenando fragmentos traducidos: el orden cambia entre idiomas. Usa una clave por frase completa con parámetros.
- Cuidado con plurales y géneros: usa el sistema de pluralización, no condicionales.
- Toda clave nueva se añade a los cuatro ficheros de idioma en el mismo commit, aunque sea con el texto en castellano pendiente de traducir.
- Antes de cerrar un módulo, ejecuta el informe de cobertura de traducción.
