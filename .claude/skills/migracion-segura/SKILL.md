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
- Ampliar o corregir un `CHECK` con `ADD CONSTRAINT ... CHECK (...)` sin `NOT VALID` sobre una tabla que ya puede tener filas en producción: ese `ADD CONSTRAINT` a secas obliga a PostgreSQL a validar **todas** las filas existentes bajo un lock `ACCESS EXCLUSIVE` mientras dura el escaneo — bloquea lecturas y escrituras de esa tabla por la duración completa del escaneo. Patrón correcto, en dos sentencias:

  ```sql
  ALTER TABLE t DROP CONSTRAINT t_check;
  ALTER TABLE t ADD CONSTRAINT t_check CHECK (...) NOT VALID;
  ALTER TABLE t VALIDATE CONSTRAINT t_check; -- solo SHARE UPDATE EXCLUSIVE, no bloquea lecturas/escrituras
  ```

  Sobre una tabla nueva o verificablemente vacía en todos los entornos (el `CHECK` va en la misma migración que crea la tabla), el `ADD CONSTRAINT` sin `NOT VALID` es inocuo y no hace falta el patrón de tres sentencias — la regla es para **ampliar o corregir** un `CHECK` ya desplegado sobre una tabla que ya puede tener filas. Motivado por issues [#186](https://github.com/pirexia/plataforma-educativa/issues/186)/[#201](https://github.com/pirexia/plataforma-educativa/issues/201): dos migraciones reales de `admin_action_logs`/`dual_authorizations` repitieron el mismo patrón bloqueante antes de que existiera esta norma — inocuo entonces porque las tablas estaban vacías, pero exactamente el riesgo que golpearía la próxima vez que se toque ese `CHECK` con la tabla ya en producción.

## Obligatorio en toda tabla de negocio

- `tenant_id` y, si depende del curso, `academic_year_id`, con índice compuesto encabezando las consultas frecuentes.
- `created_at`, `updated_at`, `deleted_at`, `created_by`, `updated_by`.
- Claves foráneas y restricciones declaradas en base de datos.
- Importes en enteros de céntimos o decimal exacto. Nunca coma flotante.
- Fechas en UTC.
- Particionado por curso académico en tablas de alto crecimiento: asistencia, calificaciones, auditoría, notificaciones.
