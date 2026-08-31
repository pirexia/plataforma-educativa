# ADR-029 · Identificadores públicos y convenciones de tipos en PostgreSQL

**Estado**: ACEPTADA (2026-08-18, implementada desde el paso 0.8 — modelo de datos núcleo)
**Fecha**: 2026-08-11
**Afecta a**: `RDB-008`, `RDB-009`, `RSEC-OWASP-011`, sección 16 del documento de requisitos

## Contexto

Dos asuntos que llegan juntos porque afectan al mismo sitio: la definición de las tablas.

**Primero**, exponer claves primarias secuenciales en URLs y API permite **enumerar registros**: probando identificadores consecutivos se recorre el censo de alumnos de un centro. Un fallo puntual de permisos deja de exponer un registro y pasa a exponer el colegio entero.

**Segundo**, se ha evaluado la skill `design-postgres-tables` de Timescale (`timescale/pg-aiguide`). Su contenido es correcto y complementario al nuestro, pero choca en tres puntos con los valores por defecto de Laravel. Sin decisión explícita, el esquema acabaría mezclando criterios.

## Decisión

### Identificadores

- **Clave primaria interna**: `bigint` autoincremental. No sale nunca de la base de datos ni de la capa de aplicación.
- **Identificador público**: columna `public_id` con **ULID**, única e indexada, en toda entidad que aparezca en una URL, en la API o en un documento exportado.
- Las rutas y la API usan exclusivamente el identificador público. Exponer la clave interna es un fallo de revisión.
- ULID y no UUID v4 porque es ordenable temporalmente, lo que evita la fragmentación de índice del UUID aleatorio y facilita la depuración.

Entidades afectadas como mínimo: alumnos, tutores, unidades familiares, empleados, matrículas, facturas, documentos, autorizaciones, incidencias y expedientes.

### Convenciones de tipos

| Regla | Decisión | Nota sobre Laravel |
|-------|----------|--------------------|
| Marcas de tiempo | **`TIMESTAMPTZ` siempre** | `timestamps()` genera `timestamp` sin zona horaria. Usar `timestampsTz()` y `timestampTz()` de forma sistemática. |
| Cadenas | **`text`**, con validación de longitud en la aplicación y `CHECK` donde la regla sea de negocio | `string()` genera `varchar(255)`. En PostgreSQL `varchar(n)` no aporta rendimiento y cambiar el límite obliga a alterar la tabla. |
| Importes | **Entero de céntimos** | Divergencia deliberada respecto a la recomendación de `NUMERIC`. Ambas son correctas en base de datos, pero el riesgo real está en PHP, donde un valor decimal puede acabar convertido a coma flotante en una operación intermedia. Con enteros, ese riesgo no existe. |
| Enumerados | Columna `text` con `CHECK`, o tabla de referencia | Los tipos `ENUM` de PostgreSQL son incómodos de modificar sin bloqueo. |
| Unicidad con nulos | `NULLS NOT DISTINCT` cuando la regla lo exija | Disponible desde PostgreSQL 15. |

### Adopción de la skill

Se adopta `timescale/pg-aiguide` como referencia de diseño de tablas, con dos salvedades:

- Las tres divergencias anteriores prevalecen sobre la guía y están documentadas aquí.
- El particionado se hace con **particionado declarativo nativo** por curso académico. No se adoptan hypertables: son una solución de series temporales y Timescale es parte interesada.

## Consecuencias

- Toda tabla de negocio suma una columna `public_id` con su índice único. Coste de almacenamiento asumible y beneficio de seguridad alto.
- Hay que fijar las convenciones de tipos **antes de la primera migración**. Corregirlas después implica reescribir el esquema.
- La skill `postgres-rendimiento` incorpora estas convenciones.
- Las claves foráneas siguen usando la clave interna: el identificador público es solo de exposición.

## Alternativas descartadas

- **ULID como clave primaria**: penaliza el tamaño de todos los índices y de las claves foráneas sin aportar nada, porque la protección se obtiene igual con una columna adicional.
- **UUID v4**: aleatorio, fragmenta el índice y es más difícil de leer en depuración.
- **Ofuscar el identificador secuencial**: seguridad por oscuridad. Reversible y falsa.
