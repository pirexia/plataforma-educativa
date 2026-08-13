---
name: migracion-segura
description: Cómo escribir migraciones de base de datos compatibles con despliegue sin interrupción. Úsala siempre que se cree o modifique una migración, o al revisar cambios de esquema.
---

# Migraciones sin interrupción

Durante un despliegue conviven la versión anterior y la nueva. El esquema debe funcionar con **ambas** (`RARQ-DEP-005`).

## Patrón expand/contract

Un cambio destructivo se reparte en tres entregas:

| Entrega | Acción |
|---------|--------|
| 1 · Expand | Añadir lo nuevo, siempre opcional y con valor por defecto. Rellenar en segundo plano. |
| 2 · Migrate | Desplegar el código que escribe y lee en lo nuevo. Doble escritura si hace falta. |
| 3 · Contract | Una vez ninguna versión viva usa lo antiguo, eliminarlo. |

**Nunca** renombres o elimines una columna en la misma entrega en que el código deja de usarla.

## Prohibido

- Añadir columna `NOT NULL` sin valor por defecto sobre tabla con datos.
- Crear índices bloqueando la tabla: usar creación concurrente.
- Cambiar el tipo de una columna con datos sin columna intermedia.
- Migraciones que recorran millones de filas dentro de la petición de despliegue: van a un job.
- Migraciones irreversibles sin aprobación explícita documentada.

## Obligatorio en toda tabla de negocio

- `tenant_id` y, si depende del curso, `academic_year_id`, con índice compuesto encabezando las consultas frecuentes.
- `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by`.
- Claves foráneas y restricciones declaradas en base de datos.
- Importes en enteros de céntimos o decimal exacto. Nunca coma flotante.
- Fechas en UTC.
- Particionado por curso académico en tablas de alto crecimiento: asistencia, calificaciones, auditoría, notificaciones.
