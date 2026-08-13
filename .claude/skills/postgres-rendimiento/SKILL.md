---
name: postgres-rendimiento
description: Diseño de esquema, índices, particionado y rendimiento en PostgreSQL para este proyecto. Úsala al crear o revisar migraciones, al escribir consultas de listados o informes, y ante cualquier lentitud de base de datos.
---

# PostgreSQL: esquema y rendimiento

Reglas específicas de este proyecto. Complementan a `migracion-segura`, que cubre el despliegue sin corte.

## El orden de las columnas del índice no es opcional

Toda consulta de negocio filtra por `tenant_id`. Un índice que no lo lleve **en primera posición** no se usará para las consultas reales.

```sql
-- Correcto
CREATE INDEX ON calificaciones (tenant_id, academic_year_id, enrollment_id);

-- Inútil en la práctica
CREATE INDEX ON calificaciones (enrollment_id);
```

Antes de crear un índice, escribe la consulta que lo va a usar. Si no puedes escribirla, no lo crees.

## Particionado por curso académico

Tablas que crecen sin parar y deben ir particionadas por `academic_year_id`: **asistencia, calificaciones, auditoría, notificaciones, fichajes, agenda diaria de Infantil**.

Motivo: al cerrar un curso, la partición entera pasa a solo lectura y puede archivarse (`RDB-012`) sin afectar al rendimiento de las consultas del curso activo. Sin particionado, la tabla de asistencia de diez centros a cinco años es inmanejable.

Decide el particionado **al crear la tabla**. Añadirlo después implica reescribirla entera.

## RLS es la segunda barrera, no la primera

El aislamiento se aplica en el ORM (`INV-001`). La seguridad a nivel de fila existe para atrapar lo que se escape: consultas nativas, migraciones, scripts de mantenimiento.

No la trates como opcional ni la desactives para "arreglar" una consulta que no devuelve datos: si RLS bloquea algo, es que falta establecer el tenant en la sesión.

## Autovacuum en tablas de mucha rotación

Asistencia, notificaciones, sesiones y auditoría reciben muchas escrituras. Con la configuración por defecto, el autovacuum llega tarde, las tablas se hinchan y las consultas se degradan de forma progresiva y difícil de diagnosticar.

Ajusta `autovacuum_vacuum_scale_factor` a la baja en esas tablas concretas, no globalmente.

## Diagnóstico de lentitud, en este orden

1. `EXPLAIN (ANALYZE, BUFFERS)` de la consulta real, con datos reales de volumen. Nunca con la tabla de desarrollo vacía.
2. ¿`Seq Scan` sobre tabla grande? Falta índice o el índice no empieza por `tenant_id`.
3. ¿La estimación de filas se desvía mucho de la real? Estadísticas obsoletas: `ANALYZE`.
4. ¿Muchas consultas idénticas? Es un **N+1** del ORM: carga anticipada de relaciones.
5. ¿Lentitud solo en producción? Mira hinchazón de tabla, conexiones abiertas y espera de bloqueos.

## Convenciones de tipos (`ADR-029`)

| Regla | Decisión | Trampa en Laravel |
|-------|----------|-------------------|
| Marcas de tiempo | `TIMESTAMPTZ` siempre | `timestamps()` genera `timestamp` **sin** zona horaria. Usa `timestampsTz()` y `timestampTz()`. |
| Cadenas | `text` con validación en la aplicación | `string()` genera `varchar(255)`. En PostgreSQL no aporta rendimiento y limita. |
| Importes | **Entero de céntimos** | Divergencia deliberada respecto a `NUMERIC`: el riesgo está en PHP, no en la base. |
| Enumerados | `text` con `CHECK` o tabla de referencia | Los `ENUM` nativos son incómodos de modificar sin bloqueo. |
| Unicidad con nulos | `NULLS NOT DISTINCT` cuando aplique | Disponible desde PostgreSQL 15. |

## Identificadores (`ADR-029`)

- Clave primaria interna `bigint`. **No sale nunca** de la capa de aplicación.
- Columna `public_id` con **ULID**, única e indexada, en toda entidad que aparezca en URL, API o documento exportado.
- Exponer la clave interna en una ruta es un fallo de revisión: permite enumerar alumnos (`RSEC-OWASP-011`).
- Las claves foráneas usan la clave interna, no el identificador público.

## Reglas de esquema no negociables

- Fechas en **UTC**, convertidas en presentación.
- Claves foráneas y restricciones **declaradas en base de datos**, no solo en la aplicación.
- Datos de categoría especial (salud, NEAE, convivencia) en tablas separadas y cifradas.
- Tablas append-only para auditoría, fichajes, consentimientos y firmas: se añaden versiones, no se actualizan.

## Informes y exportaciones

Nunca contra el primario. Los informes y exportaciones masivas van a la **réplica de lectura** (`RDB-004`) y se ejecutan en cola (`INV-012`), no en la petición HTTP. Una exportación de listados no puede bloquear el paso de lista de las nueve de la mañana.

## Búsqueda

Full-text nativo de PostgreSQL en las fases 1 y 2 (`ADR-010`), siempre tras una interfaz propia para poder cambiar de motor sin tocar los módulos.
