# ADR-058 · `SQLSTATE` del bloqueo de escritura de cursos cerrados: `YC001` en lugar de `CY001`

**Estado**: **ACEPTADA** (2026-10-08). Redactada como `PROPUESTA` sobre la decisión que el usuario ya había tomado ese día en el issue [#384](https://github.com/pirexia/plataforma-educativa/issues/384) (opción A), y aceptada con ella. **Inmutable**: cualquier cambio posterior exige un ADR nuevo que la sustituya explícitamente.
**Fecha**: 2026-10-08
**Paso**: `1.10` de `PLAN-IMPLEMENTACION.md` (`REQ-CURSO`), rama `feature/REQ-CURSO-1-10-ciclo-vida-curso`, PR [#386](https://github.com/pirexia/plataforma-educativa/pull/386).
**Sustituye**: **solo** la elección del código `SQLSTATE` de `ADR-057 §5.2` (y, por consecuencia, cada aparición de `CY001` en `ADR-057`: `§5.2`, `§5.4`, `§5.5`, `§9` y Anexo A, `CA-057-01`, `-02`, `-04`, `-08`). **Todo lo demás de `ADR-057` sigue vigente** sin cambios: disparador, exención del propietario, `FOR SHARE`, formato del mensaje con el `public_id`, traducción a `409 urn:pge:error:academic-year-closed`, `AR-13` y la forma de las excepciones futuras.
**Afecta a**: `REQ-CURSO-001`, `REQ-CURSO-005`; `CA-CURSO-040`; `CA-057-01`, `-02`, `-04`, `-08`.

---

## 1 · Contexto

`ADR-057 §5.2` eligió para el error del disparador el `SQLSTATE` `CY001` y lo justificó así: «clase `CY` no usada por PostgreSQL». Eso es cierto, pero no basta. El issue #384 señala que la norma SQL (ISO/IEC 9075) divide el espacio de clases de `SQLSTATE` en dos:

- clases cuyo primer carácter es `0`-`4` o `A`-`H`: **reservadas a la norma** (las define o las definirá ella);
- clases cuyo primer carácter es `5`-`9` o `I`-`Z`: **definidas por la implementación**, que es donde un producto puede poner las suyas.

`CY` empieza por `C`, así que cae en el rango reservado a la norma. Hoy no choca con nada, pero una revisión futura de la norma o una versión de PostgreSQL que la adopte podría asignar la clase `CY` a otra cosa, y entonces el traductor del módulo `Curso` (que reconoce el error **solo** por el `SQLSTATE`, `ADR-057 §5.5`) convertiría ese error ajeno en un `409 academic-year-closed` falso.

**Matiz sobre las fuentes, dicho sin adornos.** La regla del reparto de rangos se cita **de memoria**, a partir de la norma ISO/IEC 9075-2 (apartado de `SQLSTATE`); no se ha contrastado con el texto de la norma, que es de pago. La documentación de PostgreSQL (apéndice A, «PostgreSQL Error Codes») **no enuncia esa regla**: se limita a listar las clases que usa y a decir que algunas siguen la norma y otras son propias. Y el propio PostgreSQL no la respeta del todo: usa la clase `F0` («Configuration File Error»), que empieza por una letra del rango reservado. La decisión no depende de que la regla sea exacta al carácter: `YC` queda fuera de **cualquier** lectura razonable del rango reservado y fuera de las clases que PostgreSQL usa, y el test de `§6` comprueba ambas cosas en vez de confiar en este texto.

**Hechos verificados (2026-10-08).** `CY001` está escrito hoy en el código (migración de la función de guarda, traductor, excepción de dominio, `AcademicYearWriteGuard`, `AcademicYearTransitions`, `CursoServiceProvider`, OpenAPI), en los tests (`AcademicYearWriteGuardTest`, `CursoConcurrencyTest`) y en la documentación (`ADR-057`, `REQ-CURSO/{funcional,datos,api,permisos,operacion}.md`, `ARCHITECTURE.md`, `SYSADMIN.md`, `SECURITY.md`, `CHANGELOG.md`, `memory.md`). **Nada de ello ha llegado a `develop`**: todo está en la rama del PR #386, sin desplegar. No hay consumidores externos del código: el cliente de la API ve el `409` y su `type`, nunca el `SQLSTATE`.

## 2 · Decisión

1. El `SQLSTATE` del rechazo por curso cerrado es **`YC001`**: clase `YC`, subclase `001`. Sustituye a `CY001` en todos los sitios donde `ADR-057` lo nombra.
2. La clase `YC` queda **reservada al producto** para errores lanzados por funciones de base de datos propias. Un error propio futuro usa otra subclase de `YC` (`YC002`…) y se anota en el ADR que lo introduzca; no se abren clases nuevas sin ADR.
3. El resto de `ADR-057` no cambia.

## 3 · Motivo

- **Coste mínimo ahora, alto después.** El cambio es una sustitución literal en una rama que aún no se ha mezclado. Hacerlo después de mezclar exigiría una migración nueva que reemplace la función (`CREATE OR REPLACE`) y un traductor que acepte los dos códigos durante una ventana *expand/contract* (`CLAUDE.md §9`).
- **Elimina un riesgo silencioso.** El fallo que evita no daría error, sino una respuesta incorrecta (`409` con un motivo falso), que es el tipo de fallo más caro de diagnosticar.
- **Totalmente reversible.** Es un literal; no hay datos persistidos que lo contengan.

## 4 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **Mantener `CY001`** | Funciona hoy, pero apoya una regla de negocio en un hueco del espacio reservado a la norma. El ahorro (no tocar unos veinte ficheros de una rama sin mezclar) es menor que el coste de cambiarlo después de mezclar (`§3`) |
| **Clase con primer carácter `5`-`9`** (p. ej. `9C001`) | Igual de válida según la regla, pero PostgreSQL ya usa `53`, `54`, `55`, `57`, `58` y `72` en ese rango: la zona numérica alta es la que el motor tiende a ocupar. Una letra es más legible y menos probable de chocar |
| **Otras clases `I`-`Z`** (`PC`, `XC`, `ZC`…) | `P0` (PL/pgSQL) y `XX` (error interno) muestran que PostgreSQL usa las iniciales `P` y `X`; mejor evitarlas. Entre las restantes no hay criterio técnico para preferir una; `YC` («curso» con la `C` del código anterior, en una inicial que PostgreSQL no usa) fue la opción A del issue #384 que eligió el usuario |
| **`P0001` genérico de `RAISE EXCEPTION` distinguido por el texto del mensaje** | Ya descartado en `ADR-057 §5.2`: obliga al traductor a leer texto libre |

## 5 · Consecuencias

- **Código, tests y documentación ya escritos con `CY001`** pasan a `YC001` en la misma rama del PR #386, antes de mezclar. La migración de la función de guarda (`2026_10_07_100200_…`) **se edita en sitio** y no se añade otra: no se ha ejecutado fuera de los entornos de desarrollo y test, que se recrean. Quien ya la tenga aplicada en local la deshace y la vuelve a aplicar (su `down()` elimina la función), o recrea la base de desarrollo.
- **Sin consumidores externos**: la API expone el `409` con `type` `urn:pge:error:academic-year-closed`, sin cambio; el `SQLSTATE` solo lo ven el traductor del módulo `Curso`, los logs y los diagnósticos de `operacion.md §7`/`SYSADMIN.md`.
- `ADR-057` **no se edita** (inmutable). Quien lo lea encuentra `CY001`; el índice de la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` remite a este ADR. La documentación de módulo y raíz sí se actualiza al nuevo código, porque describe el estado vigente.
- `CHANGELOG.md` registra el cambio dentro de la entrada de `1.10`; no es un cambio incompatible porque `1.10` no se ha publicado.

## 6 · Criterios de aceptación

- **CA-058-01** — Todas las pruebas que hoy esperan `CY001` (`CA-057-01`, `-02`, `-08`; `CA-CURSO-040`) esperan `YC001` y están en verde. `CA-057-04` sigue comprobando que la clave foránea inexistente da `23503`, no `YC001`.
- **CA-058-02** — Un test comprueba, sobre el código que la función de guarda emite realmente (leído de la definición instalada, `pg_get_functiondef`, o de la constante única que comparten la migración y el traductor, nunca de un literal repetido en el test):
  1. que la clase (dos primeros caracteres) **no empieza por `0`-`4` ni por `A`-`H`**;
  2. que la clase **no está entre las que define PostgreSQL**. PostgreSQL no ofrece un catálogo SQL de códigos de error (`errcodes.txt` es un fichero de las fuentes y no se instala en la imagen), así que la lista se **mantiene en el test**, copiada del apéndice A de la documentación de la versión instalada (PostgreSQL 17: `00 01 02 03 08 09 0A 0B 0F 0L 0P 0Z 20 21 22 23 24 25 26 27 28 2B 2D 2F 34 38 39 3B 3D 3F 40 42 44 53 54 55 57 58 72 F0 HV P0 XX`, a contrastar al escribir el test);
  3. que la versión mayor del servidor (`current_setting('server_version_num')`) es la misma de la que se copió esa lista. Al subir de versión mayor el test falla a propósito hasta que alguien revise el apéndice A de la nueva versión y actualice la lista y la versión esperada.
- **CA-058-03** — Ninguna aparición de `CY001` queda en `apps/`, ni en la documentación vigente fuera de `ADR-057` y de este ADR (comprobación con `git grep` en la revisión del PR; no hace falta test).
