# REQ-SEED · API

Estado: PROPUESTO (2026-11-12).

## 1. API HTTP: no hay

`REQ-SEED` **no expone ningún *endpoint***. `REQ-SEED-006` pide un comando de consola y `RN-SEED-05` prohíbe cualquier otra vía. Por tanto no aplican, y se dice expresamente para que `doc-reviewer` no los eche en falta:

- `ADR-038` (envoltura, paginación, filtrado, errores RFC 9457, versionado): sin *endpoints*.
- OpenAPI: `openapi.yaml` no cambia.
- `INV-006` (API primero): no hay funcionalidad de interfaz; no hay nada que exponer antes.
- Exportación de listados (`ADR-054`, `ADR-055`): no hay.
- Eventos de dominio emitidos: ninguno. Webhooks: ninguno.

Un *endpoint* del backoffice para lanzar o ver ejecuciones no está en el documento de requisitos y no se propone.

Lo que sigue documenta la **interfaz de consola**, que es el contrato público del módulo. Se describe; no es código.

## 2. `demo:seed`

Genera centros de demostración o amplía una ejecución con bloques nuevos.

| Parámetro | Obligatorio | Valores | Descripción |
|---|---|---|---|
| `--profiles` | No | Lista de claves de `RN-SEED-26`; por defecto, los tres | Centros a generar (`REQ-SEED-006`: «centros a generar») |
| `--seed` | No | Entero | Semilla. Si se omite, se elige una y **se muestra**, para poder repetir la ejecución |
| `--volume` | No | `reduced` (por defecto), `full` | `RN-SEED-28` |
| `--academic-year` | No | Curso de referencia | Por defecto, el curso en vigor en la fecha de ejecución; se guarda con la ejecución (`RN-SEED-22`) |
| `--blocks` | No | Lista de códigos de `funcional.md §6.3`; por defecto, todos los disponibles | Con sus dependencias; un bloque sin su previo se rechaza |
| `--run` | No | `public_id` de una ejecución `completada` | Amplía esa ejecución con los bloques indicados (`funcional.md §3.3`); incompatible con `--profiles`, `--seed`, `--volume` y `--academic-year`, que se toman de ella |
| `--sync` | No | — | Ejecuta los lotes en línea (`OPEN-SEED-15`) |

**No existe** `--force`, `--env`, `--yes` ni ninguna opción que afecte a las guardas (`RN-SEED-02`, `CA-SEED-003`).

Salida: identificador de la ejecución, semilla, perfiles y número de alumnos por centro, y la contraseña generada si procede (`RN-SEED-30`, solo en `local`/`testing` y solo si no viene de variable).

| Código de salida | Significado |
|---|---|
| `0` | Ejecución creada y encolada (o completada, con `--sync`) |
| `1` | Fallo durante la ejecución síncrona, o el verificador no pasa |
| `2` | Rechazo previo sin escrituras: guarda, validación de entrada, slug ocupado, ejecución simultánea, contraseña ausente o no conforme |

El código `2` reutiliza la convención del precedente (`e2e-real-tenant.php`: «aborta» = `2`).

Idempotencia: no. Dos ejecuciones con los mismos parámetros sobre la misma base chocan en el slug y la segunda se rechaza. Sobre bases vacías producen la misma huella (`CA-SEED-026`).

## 3. `demo:status`

| Parámetro | Obligatorio | Descripción |
|---|---|---|
| `run` | No | `public_id` de la ejecución; por defecto, la última |
| `--json` | No | Salida estructurada, para guiones de medición y E2E |

Solo lectura. Muestra lo descrito en `funcional.md §3.2`. Pasa las guardas de entorno igual que los demás (en producción no existe).

## 4. `demo:purge` (`1.15b-c`, pendiente de `OPEN-SEED-04`)

| Parámetro | Obligatorio | Descripción |
|---|---|---|
| `tenant` | Sí | Slug del centro a purgar |
| `--confirm` | Sí | Debe repetir exactamente el slug (`RN-SEED-41`). No es una opción de salto: sin ella no se purga nada, y con ella se siguen aplicando todas las guardas |

Códigos de salida como en §2. Rechazos: los de `RN-SEED-40` a `-42`, ejecución en curso sobre ese centro, entorno no permitido.

## 5. Paginación, filtrado y ordenación

No aplica.

## 6. Contrato que consumen otros

- **E2E real y guiones de medición**: `demo:status --json` y la salida de `demo:seed`. Su forma se fija en el tramo `1.15b-a` T2 y, una vez usada por un guion, cambia solo de forma aditiva.
- **Bloques de módulos futuros**: el contrato de bloque de `funcional.md §6.2`.
