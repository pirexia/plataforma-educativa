# ADR-057 · Bloqueo de escritura sobre los datos de un curso académico cerrado

**Estado**: **ACEPTADA** (2026-10-07). `OPEN-057-01` y `OPEN-057-02` aceptadas por el usuario; `OPEN-057-03`/`-04` pendientes, no bloquean `1.10`. **Inmutable una vez aceptada**: cualquier cambio posterior exige un ADR nuevo que la sustituya explícitamente.
**Fecha**: 2026-10-07
**Paso**: `1.10` de `PLAN-IMPLEMENTACION.md` (`REQ-CURSO`). Resuelve `OPEN-CURSO-04` de `docs/modulos/REQ-CURSO/funcional.md §15`, bloqueante para implementar.
**Se apoya en**: `INV-001`, `INV-003`, `INV-007`, `INV-010`, `INV-015`; `ADR-029` (particionado por curso); `ADR-033 §1`, `§5`, `§6`; `ADR-034 §3`, `§4`, `§7`; `ADR-038 §6.2`, `§6.3`, `§6.4`; `ADR-044 §4.7`; `ADR-048 §4.9`; `ADR-056 §3.1`, `§3.2`.
**No sustituye** ningún ADR. **Amplía** el catálogo de tipos de error de `ADR-038 §6.2` con `urn:pge:error:academic-year-closed` y el catálogo de reglas de `ADR-056 §3.3` con `AR-13`.
**Afecta a**: `REQ-CURSO-001`, `REQ-CURSO-005`, criterio de aceptación 1 de `§5.28`; `RN-CURSO-20` a `-23` y `-32`; a todo módulo con tablas por curso (`ACAD`, `ALUM`, `CALIF`, `ASIST`, `FIN`…) como regla.
**Se aparta de la recomendación de la especificación**: `OPEN-CURSO-04` recomendaba la opción **B** (rasgo de modelo Eloquent + `AR-13` sobre modelos). Este ADR recomienda la **C** (disparador de PostgreSQL) y explica en `§4` por qué la B no cumple lo que la propia especificación le exige.

---

## 1 · Contexto

`REQ-CURSO-001` dice que un curso cerrado se consulta «en modo solo lectura»; `REQ-CURSO-005`, que el cierre bloquea la escritura; y el criterio de aceptación 1 de `§5.28` lo concreta: *«un profesor intenta modificar una calificación de un curso cerrado: el sistema lo impide e indica el motivo»*. El usuario aprobó el 2026-10-07 la especificación de `1.10` con todas sus recomendaciones, entre ellas:

- `OPEN-CURSO-20`: el bloqueo es **universal** (todo dato con `academic_year_id`), con excepciones declaradas en la especificación de cada módulo y aprobadas.
- `OPEN-CURSO-19`: la rectificación de datos de un curso cerrado es de `REQ-CALIF`; este ADR debe **dejarle sitio** como excepción explícita, auditada y por módulo, nunca como un «desactivar el bloqueo» libre.
- `OPEN-CURSO-05`: error `409` con `type` propio `urn:pge:error:academic-year-closed`.
- `RN-CURSO-23`: el bloqueo no depende de que cada controlador se acuerde («a nivel de *framework*, nunca solo en el controlador», mismo argumento que `INV-001`).
- `RN-CURSO-32` y `CA-CURSO-046`: una escritura concurrente con el cierre **nunca** se confirma después del cierre.

Es una decisión que heredan los cincuenta módulos que faltan. Hoy **ninguna** tabla tiene `academic_year_id`: `1.10` se adelanta a sus consumidores por diseño (`ADR-034 §4`), y el primero real será `1.11`.

### 1.1 Hechos verificados contra el código (rama `feature/REQ-CURSO-1-10-ciclo-vida-curso`, sobre `fcf9168`)

| Premisa | Dato |
|---|---|
| `funcional.md §6` (última fila): «el DML crudo en `app/Modules/**` lo prohíbe ya el test de DML crudo» | **Falso.** Ningún test recorre `app/Modules` buscando `DB::table`, `DB::update`, `DB::statement` ni equivalentes. `ADR-034 §3` prometió ese test como compensación de la vía ORM de la auditoría («el test de arquitectura de 0.7.11 ampliado para prohibir DML crudo»), y no existe. Lo que sí existe es la prohibición de `withoutGlobalScope` (`IsolationBatteryTest` #9) |
| La vía ORM ve todas las escrituras de los módulos | **No.** Hay al menos cuatro actualizaciones masivas por constructor de consultas sobre modelos Eloquent en módulos, que no disparan eventos de modelo: `UserMfaObligation::query()->…->update()` (`MfaEnrollmentService`), `MfaExemptionService`, `SamlAcsService`, `PlatformAuthenticationService`. Dos de esos modelos (`UserMfaObligation`, `UserMfaExemption`) son `Auditable`: esas escrituras **no se auditan** hoy. Es el idioma habitual de Laravel para «cerrar todas las filas abiertas», y en módulos de negocio será frecuente (`$matricula->calificaciones()->update(...)`) |
| `Model::save()` abre transacción | **No.** Eloquent no envuelve `save()`/`delete()` en transacción. Un evento `creating`/`updating` que lea el estado del curso y la escritura posterior son dos sentencias, en autocommit si el llamador no abrió transacción |
| Roles y privilegios | `plataforma_app` y `plataforma_platform` tienen `SELECT, INSERT, UPDATE, DELETE` por defecto (`infra/containers/postgres/init/01-tenancy.sql.tpl`); **ninguno tiene `TRUNCATE`**. Las migraciones se ejecutan por `pgsql_owner` (`TenantMigration`) |
| Funciones de base de datos existentes | Solo `app.current_tenant_id()`, en SQL. **Ninguna en PL/pgSQL**: esta sería la primera |
| Motor | PostgreSQL 17 (`ADR-033 §10`): admite disparadores `BEFORE … FOR EACH ROW` en tablas particionadas, que se clonan a cada partición (desde 13) |
| Ayudantes de esquema | `TenantMigration::tenantTable()`/`tenantTableAppendOnly()` crean la tabla por `pgsql_owner` y aplican RLS después de la *closure* de columnas; `tenantForeignId()` solo opera sobre el `Blueprint` (no puede emitir DDL posterior a la creación) |

---

## 2 · Qué NO decide este ADR

- **Qué excepciones concretas existen.** Cero en `1.10`. Cada una se declara y aprueba en la especificación de su módulo (`OPEN-CURSO-20`).
- **El mecanismo de lectura de cursos cerrados** (`RN-CURSO-25`, `AcademicYearReadAccess`, `curso_historico.leer`). La especificación aprobada lo resuelve con un contrato que cada *endpoint* de lectura invoca; ver la observación de `§10`, `OPEN-057-03`.
- **La reapertura** (`OPEN-CURSO-08`) ni el **archivado** (`OPEN-CURSO-09`). Este ADR solo fija qué deben hacer con el bloqueo cuando se decidan (`§5.7`).
- **El conflicto con el derecho de supresión** (`ADR-004`, `REQ-PRIV-006`): se señala en `§7` y `OPEN-057-04`; no se resuelve aquí.

---

## 3 · Opciones reales

| | Opción | Qué es |
|---|---|---|
| **A** | Guarda explícita | Interfaz `AcademicYearWriteGuard` que cada servicio llama antes de escribir |
| **B** | Rasgo de modelo | *Trait* en `Curso\Domain` con *hooks* `creating`/`updating`/`deleting`/`restoring` que consultan el estado del curso; `AR-13` falla si un modelo cuya tabla tiene `academic_year_id` no lo usa. **Recomendación de la especificación** |
| **C** | Disparador de PostgreSQL | Función genérica `BEFORE INSERT OR UPDATE OR DELETE FOR EACH ROW` en toda tabla con `academic_year_id`, enganchada por los ayudantes de `TenantMigration`; `AR-13` sobre el esquema real |
| **D** | Política RLS restrictiva | Política `AS RESTRICTIVE FOR INSERT/UPDATE/DELETE` con `USING`/`WITH CHECK (app.academic_year_writable(academic_year_id))` en cada tabla |
| **B+C** | Dos capas | Rasgo para el error «bonito» y disparador como barrera |

Descarto de entrada, por inviables y no por gusto: **particiones por curso con privilegios revocados** (PostgreSQL comprueba privilegios sobre la tabla padre cuando se accede a través de ella; además obligaría a particionar todas las tablas por curso desde el primer día, contra lo que `ADR-034 §3` razonó para `audit_logs`), y **comprobación en un *middleware* HTTP** (no sabe qué filas va a tocar una petición, y no cubre colas ni consola).

---

## 4 · Evaluación

Criterios del rol: coste de implementación en solitario, mantenimiento a 3 años, impacto en invariantes y reversibilidad. Añado el que la especificación ya fijó como exigencia: **¿cumple `RN-CURSO-23` y `RN-CURSO-32`?**

| Criterio | A · guarda explícita | B · rasgo de modelo | C · disparador | D · RLS restrictiva |
|---|---|---|---|---|
| **Cubre `save()`/`delete()`/`restore()` Eloquent** | Si se acuerdan | Sí | Sí | Sí |
| **Cubre actualización/borrado masivo por constructor (`Model::query()->update()`, `$rel->update()`)** | Si se acuerdan | **No** | Sí | Sí |
| **Cubre `DB::table()`, `insert()` masivo, SQL crudo** | Si se acuerdan | **No** (y el test que lo prohibiría no existe, `§1.1`) | Sí | Sí |
| **Serializa con el cierre (`RN-CURSO-32`, `CA-CURSO-046`)** | Solo si cada llamador abre transacción y bloquea | **No sin más**: el *hook* y la escritura van en sentencias distintas y `save()` no abre transacción; habría que sobrescribir `save()`/`delete()` en el rasgo para envolverlos en transacción y tomar un bloqueo compartido sobre `academic_years` | **Sí, gratis**: el disparador corre dentro de la sentencia que escribe, y `SELECT … FOR SHARE` sobre la fila del curso choca con el `FOR UPDATE` del cierre | **No**: la política no toma bloqueos |
| **Fallo ante escritura bloqueada** | Excepción de dominio | Excepción de dominio | Error de motor con `SQLSTATE` propio, traducible a `409` | `INSERT`: error `42501`, **indistinguible de una violación de RLS de tenant**. `UPDATE`/`DELETE`: **cero filas afectadas, sin error** — Eloquent devuelve éxito y la API respondería `200` sin haber escrito nada |
| **Comprobación en CI** | Imposible estáticamente | Reflexión modelo→tabla; ciega a lo no Eloquent | **Esquema real** (`pg_trigger`), la técnica que `ADR-056 §3.1` prefiere porque «ve la verdad» | Esquema real |
| **Coste en solitario** | Bajo por módulo, recurrente ×50 | Medio: rasgo, sobrescritura transaccional de `save`/`delete`, `AR-13` por reflexión, **y** el detector de DML crudo y de actualizaciones masivas que falta, que además no puede ver relaciones (mismo límite que `AR-10`) | Medio y **una sola vez**: una función PL/pgSQL (~40 líneas), enganche en dos ayudantes, traducción del error en `Curso`, `AR-13` por esquema | Medio, pero inservible por la fila anterior |
| **Mantenimiento a 3 años** | Crece con cada servicio | Crece con cada modelo; los huecos crecen con cada módulo que use el idioma masivo | Estable: la función no cambia al añadir tablas; el ayudante engancha | — |
| **Impacto en invariantes** | `RN-CURSO-23` incumplido por diseño | `INV-010` se cumple a medias (hay caminos que no pasan) | Ninguno negativo; `INV-001` intacto (función `SECURITY INVOKER`, sujeta a RLS) | Mezcla un error de negocio con el código de error de aislamiento |
| **Reversibilidad** | Alta | Alta | **Alta**: `CREATE OR REPLACE FUNCTION … RETURN` desactiva el bloqueo en todas las tablas con una migración; quitarlo del todo es `DROP TRIGGER` por tabla | Alta |

**Por qué la recomendación de la especificación no vale tal cual.** La especificación descartó C con un argumento heredado: «`ADR-034 §3` ya descartó disparadores para el caso general por coste en migraciones y por no conocer el contexto de aplicación». Los dos motivos de `ADR-034 §3` eran **de la auditoría**, y no se trasladan:

1. *No conocer el contexto de aplicación*: la auditoría necesita actor, IP y `request_id`, que el motor no tiene. **El bloqueo no necesita nada de la aplicación**: solo `academic_year_id`, `tenant_id` y el estado del curso, que están en la base de datos.
2. *Coste en migraciones*: un disparador que salta en cada relleno de datos encarecería las migraciones *expand/contract*. Aquí se resuelve con una regla de una línea: **el propietario del esquema no está sujeto** (`§5.2`), y las migraciones las ejecuta el propietario.

Y B, frente a lo que la especificación le exige, **no cumple ni `RN-CURSO-23` ni `RN-CURSO-32`**: deja fuera el idioma masivo que el repositorio ya usa (`§1.1`) y no serializa con el cierre sin convertir cada `save()` en una transacción. La especificación lo intuyó en su última fila de `§6` y lo dio por cubierto con un test que no existe.

**Por qué no B+C.** Con C, el rasgo solo aporta que el error nazca como excepción de dominio en vez de como error de motor traducido. Eso no compensa un segundo mecanismo obligatorio en cada modelo ni una segunda regla `AR`. Para lo que sí importa —fallar pronto en un servicio antes de generar un PDF, o saltar filas en un proceso por lotes sin abortar la transacción— basta la interfaz de comprobación previa de `§5.6`, que no es obligatoria. Si algún día el error de motor resultara insuficiente, añadir el rasgo es aditivo.

---

## 5 · Decisión

**Opción C.** El bloqueo de escritura de un curso de solo lectura es una **invariante de datos impuesta por el motor**, igual que «como mucho un curso activo» (`ADR-034 §4`, índice único parcial). Reparto por capas, en el mismo espíritu que `ADR-033 §1`:

| Capa | Papel | Obligatoria |
|---|---|---|
| **Disparador en PostgreSQL** | Barrera: nadie escribe filas de un curso `cerrado`/`archivado`, por ningún camino, salvo el propietario del esquema | Sí, en toda tabla con `academic_year_id`, vigilada por `AR-13` |
| **Traducción del error en `Curso`** | Convierte el error de motor en `409 urn:pge:error:academic-year-closed` con el código del curso | Sí, una vez, en el módulo |
| **`AcademicYearWriteGuard` (comprobación previa)** | Ergonomía: fallar antes de efectos laterales, saltar filas en lotes, ocultar acciones | No. Es consultiva; la garantía es el disparador |

### 5.1 Semántica

Un curso es **de solo lectura** si su `status` ∈ {`cerrado`, `archivado`}. Ninguna fila de una tabla con `academic_year_id` puede, si el curso es de solo lectura:

- insertarse con ese curso (`INSERT`, `NEW`);
- modificarse si pertenecía a ese curso (`UPDATE`, `OLD`) **ni** si pasa a pertenecer a él (`UPDATE`, `NEW`): mover una fila hacia o desde un curso cerrado está prohibido;
- borrarse lógica (`UPDATE` de `deleted_at`, ya cubierto) ni físicamente (`DELETE`, `OLD`), ni restaurarse (`UPDATE`).

`planificacion` y `activo` admiten escritura. `TRUNCATE` no dispara disparadores de fila y **ningún rol de aplicación tiene ese privilegio** (`§1.1`); si alguna vez se concede, este ADR deja de garantizar lo que dice y eso se considera regresión.

### 5.2 La función

Una sola función, en el esquema `app` (junto a `app.current_tenant_id()`), propiedad de `plataforma_owner`, `SECURITY INVOKER` (sujeta a RLS como quien escribe; nada de `SECURITY DEFINER`), con nombres totalmente cualificados. Forma orientativa; el texto exacto es de la implementación y lo revisa `db-reviewer`:

```sql
CREATE FUNCTION app.assert_academic_year_writable() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_status    text;
    v_public_id text;
BEGIN
    -- El propietario de la tabla (migraciones, mantenimiento) no está sujeto (§5.2).
    IF current_user = (SELECT pg_catalog.pg_get_userbyid(c.relowner)
                         FROM pg_catalog.pg_class c WHERE c.oid = TG_RELID) THEN
        IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
        RETURN NEW;
    END IF;

    -- Para cada curso afectado (OLD en UPDATE/DELETE, NEW en INSERT/UPDATE; una vez si coinciden):
    SELECT y.status, y.public_id INTO v_status, v_public_id
      FROM public.academic_years y
     WHERE y.tenant_id = <tenant_id de la fila> AND y.id = <academic_year_id de la fila>
       FOR SHARE;

    IF v_status IN ('cerrado', 'archivado') THEN
        RAISE EXCEPTION USING ERRCODE = 'CY001',
                              MESSAGE = 'academic_year_closed:' || v_public_id;
    END IF;
    -- Fila de curso inexistente: no se decide aquí; la rechaza la FK compuesta (23503).

    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END $$;
```

Decisiones dentro de la función:

- **El propietario está exento, y es una asimetría deliberada con `FORCE ROW LEVEL SECURITY`.** RLS obliga también al propietario porque una fuga entre tenants es catastrófica en cualquier mano. Este bloqueo protege una regla de negocio, y los trabajos legítimos que tienen que escribir en cursos cerrados —relleno de columnas en migraciones *expand/contract*, purga física de un tenant (issue #371), archivado en frío (`OPEN-CURSO-09`)— son precisamente los del propietario. Se compara con el **propietario real de la tabla**, no con un nombre de rol escrito en SQL (`DB_OWNER_USERNAME` es configurable).
- **`FOR SHARE`, no lectura simple.** Es lo que da `RN-CURSO-32` (`§5.4`). Su coste es del mismo orden que el que ya paga la clave foránea compuesta, que en cada inserción toma `FOR KEY SHARE` sobre la misma fila de `academic_years`. Requiere privilegio `UPDATE` sobre `academic_years`, que los dos roles de aplicación tienen.
- **`SQLSTATE` propio, `CY001`** (clase `CY` no usada por PostgreSQL; la implementación lo comprueba contra el apéndice A de la versión instalada). Nunca `P0001` genérico ni `42501`: el traductor debe distinguirlo sin leer texto libre.
- **El mensaje lleva el `public_id` del curso**, que es identificador de exposición (`ADR-029`), de alfabeto cerrado y sin datos personales, para que el traductor pueda nombrar el curso. El mensaje lo compone el producto y no depende de `lc_messages`.
- **Vocabulario de estados duplicado en SQL y en PHP**, a sabiendas: un test de paridad (`CA-057-07`) comprueba que el conjunto de estados de solo lectura de la función coincide con el del enumerado `AcademicYearStatus`. Añadir un estado ya exige migración (`CHECK` de `academic_years`), así que la función se actualiza en la misma entrega.

La migración que crea la función vive en `apps/api/database/migrations/`, junto a la de `academic_years` (esquema del núcleo, `ADR-034 §4`; `OPEN-CURSO-03` no mueve migraciones), con `down()` que la elimina. Es **la única migración de `1.10`**; `db-reviewer` pasa a ser obligatorio en el paso.

### 5.3 Enganche y regla `AR-13`

**Enganche automático.** `TenantMigration::tenantTable()` y `tenantTableAppendOnly()`, tras crear la tabla, comprueban si tiene la columna `academic_year_id` y, si la tiene, crean el disparador `academic_year_write_guard` (`BEFORE INSERT OR UPDATE OR DELETE … FOR EACH ROW EXECUTE FUNCTION app.assert_academic_year_writable()`). Un método con nombre propio, `TenantMigration::guardAcademicYearWrites(string $table)`, cubre el caso de añadir la columna a una tabla existente. `tenantForeignId()` no cambia: opera sobre el `Blueprint` y no puede emitir DDL posterior. Ningún módulo escribe el `CREATE TRIGGER` a mano.

**`AR-13`** (nueva, `RN-CURSO-23`), con el régimen de `ADR-056 §3.2`:

| Campo | Valor |
|---|---|
| Regla | Toda tabla del esquema `public` (ordinaria o particionada) con columna `academic_year_id` tiene un disparador **habilitado** (`tgenabled` distinto de `D`) llamado `academic_year_write_guard`, `BEFORE`, `FOR EACH ROW`, sobre `INSERT`, `UPDATE` y `DELETE`, que ejecuta `app.assert_academic_year_writable()` |
| Técnica | Esquema real (`pg_catalog`: `pg_attribute` + `pg_trigger` + `pg_proc`), como `AR-04`/`AR-05`. Las particiones heredan el disparador del padre; la regla se evalúa sobre la tabla padre |
| Excepciones | Lista nominal **vacía al nacer**; ampliarla exige especificación aprobada expresamente por el usuario. Una tabla sin el disparador sería una tabla de curso escribible para siempre: no se prevé ninguna |
| No vacuidad | En `1.10` no hay ninguna tabla real con la columna. El test crea dentro de sí una tabla sonda con el ayudante (debe pasar) y otra con `Schema::create` sin él (debe fallar), y la aserción de no vacuidad se hace sobre la unión de esquema real y sonda. `1.11` añade la aserción de no vacuidad sobre el esquema real solo |
| Test | `apps/api/tests/Feature/Architecture/AcademicYearWriteGuardTest.php`, grupo `arch` |

Se registra en `ARCHITECTURE.md §3.4` en el cierre de `1.10`.

### 5.4 Serialización con el cierre (`RN-CURSO-32`)

- **El cierre** (servicio de transiciones de `Curso`) abre transacción, toma `SELECT … FOR UPDATE` sobre la fila del curso **antes que ningún otro bloqueo**, ejecuta las validaciones de cierre registradas (`RN-CURSO-30`), actualiza `status` y confirma.
- **Toda escritura** sobre una tabla de curso toma, dentro del disparador, `FOR SHARE` sobre la misma fila. `FOR SHARE` y `FOR UPDATE` son incompatibles: o la escritura confirma antes de que el cierre obtenga su bloqueo, o espera a que el cierre confirme y entonces ve `cerrado` y falla con `CY001`. Funciona en cualquier nivel de aislamiento (en `REPEATABLE READ` la segunda rama da error de serialización en vez de `CY001`, que también es un rechazo).
- **Orden de bloqueos**: el cierre bloquea el curso primero. Una validación de cierre futura que tome bloqueos de filas de otros módulos (`FOR UPDATE` sobre calificaciones, por ejemplo) **antes** que el del curso podría producir interbloqueos; lo prohíbe este ADR.
- **Duración del bloqueo**: mientras el cierre lo retiene, toda escritura del centro en ese curso espera. Las validaciones de cierre deben ser acotadas; si una deja de serlo, su especificación decide si el cierre pasa a cola (`REQ-CURSO/operacion.md §4`) o si valida fuera del bloqueo y revalida dentro.
- **Consecuencia para el futuro paquete de cierre** (`REQ-CURSO-005` punto 2): todo lo que el cierre tenga que escribir en tablas del propio curso debe escribirse **antes** de cambiar `status` en la misma transacción, o mediante excepción declarada (`§5.7`); después del cambio, el propio cierre ve el curso cerrado.

### 5.5 Error de API

Se ratifica `OPEN-CURSO-05` y se **amplía el catálogo cerrado de `ADR-038 §6.2`**:

| `type` | Estado | Cuándo |
|---|---|---|
| `urn:pge:error:academic-year-closed` | 409 | Escritura sobre datos de un curso `cerrado` o `archivado`, desde cualquier módulo |

Forma: la de `REQ-CURSO/api.md §4` (`errors.academic_year[0]` con `code` `curso.academic_year_closed` y `params` `{code, status}`), `title`/`detail` traducidos desde `lang/*/curso.php`. No es `403`: el usuario puede tener el permiso; lo que falla es el estado del dato.

**Traducción.** El módulo `Curso` registra en su `ServiceProvider` el mapeo de `QueryException` con `SQLSTATE` `CY001` a su excepción de dominio de solo lectura, que el `ProblemResponseFactory` existente presenta como cualquier otro error de API. El núcleo no conoce el `SQLSTATE`. El traductor obtiene el `public_id` del mensaje y resuelve código y estado por el propio modelo de `Curso` (dentro del módulo, `AR-01` intacto). Si esa resolución falla (por ejemplo, el llamador dejó una transacción abortada sin revertir), responde igualmente `409` con el mismo `type` y un `detail` sin código de curso: el motivo se sigue indicando.

**Fuera de HTTP** (colas, consola), el error llega como `QueryException`. Un trabajo que quiera reaccionar al curso cerrado sin abortar su transacción usa la comprobación previa de `§5.6` o una transacción anidada (punto de guardado) por unidad de trabajo. Es una limitación conocida del enfoque: en PostgreSQL, un error dentro de una transacción la deja abortada.

### 5.6 `AcademicYearWriteGuard`: comprobación previa, no barrera

La interfaz de `REQ-CURSO/funcional.md §7.1` se mantiene con un papel distinto del que tenía en la opción B: **consultiva**. Ofrece «¿admite escritura este curso?» y «afirma que la admite o lanza la excepción de dominio», que el manejador traduce al mismo `409`. No toma bloqueos ni sustituye al disparador: entre la comprobación y la escritura puede cerrarse el curso, y entonces responde el disparador. Su firma es de la implementación.

### 5.7 Excepciones al bloqueo: forma fijada ahora, construida con su primer consumidor

`1.10` no construye ningún mecanismo de excepción: hoy no hay ninguna aprobada, y construir la maquinaria sin consumidor es lo que `ADR-048` y `OPEN-CURSO-18` rechazan. Lo que este ADR fija es **la forma** que tendrá, para que `REQ-CALIF` (rectificación, `OPEN-CURSO-19`), `REQ-FIN` (cobro de un recibo de un curso cerrado, `OPEN-CURSO-20`) o `REQ-PRIV` (`§7`) no la improvisen:

1. **Declarada en código por el módulo dueño de la tabla**, como sus permisos (`ADR-034 §2`): un código de excepción, las tablas **propias** a las que aplica y las operaciones (`INSERT`/`UPDATE`/`DELETE`). Un módulo no puede declarar excepciones sobre tablas de otro. Cada declaración exige especificación aprobada por el usuario (régimen de `ADR-056 §3.2`).
2. **Ejecutada solo a través de `Curso\Domain`**, con un método que recibe el código de excepción, un **motivo obligatorio** y la unidad de trabajo, abre transacción y fija una variable de configuración **local a la transacción** (`set_config(…, true)`, con parámetro ligado) que enumera los pares `tabla:operación` permitidos. La función del disparador gana entonces una comprobación de esa variable. Al confirmar o revertir, la variable desaparece: no sobrevive a la transacción ni a la conexión, ni siquiera con un *pooler* por transacción (la preocupación de `ADR-033 §5`).
3. **Auditada**: las escrituras exentas pasan por el ORM y quedan en `audit_logs` como cualquier otra, con el código de excepción y el motivo en `context`.
4. **Autorizada por el *endpoint* que la usa** con el permiso que defina su módulo. No existe un permiso genérico «escribir en cursos cerrados» (`REQ-CURSO/permisos.md §2.1`).
5. **Confinada por test**: el literal de la variable solo aparece en la implementación de `Curso`, comprobado con el escáner de tokens compartido (`ADR-056 §3.1`), como `runAsPlatform()`.
6. **Prohibido**: un `withoutAcademicYearGuard()` libre, una variable de sesión (tercer argumento `false`), desactivar el disparador con `ALTER TABLE … DISABLE TRIGGER` desde código de aplicación, o escribir como propietario desde un proceso de aplicación para saltarse el bloqueo.

`ADR-033 §5` descartó una variable de configuración como «modo plataforma» porque cualquier ruta de código puede activarla. Aquí se acepta porque el alcance es otro: la variable solo habilita tablas y operaciones declaradas, del mismo tenant (RLS sigue en pie), durante una transacción, y su uso está confinado por test. Una credencial de base de datos distinta por excepción sería desproporcionada.

**Reapertura y archivado.** Si se decide la reapertura (`OPEN-CURSO-08`, opción B), es una transición de estado de `academic_years`, que no tiene `academic_year_id`, y no necesita excepción: al volver a `activo`, el disparador deja de bloquear. El archivado en frío (`OPEN-CURSO-09`), si mueve o borra filas, lo hace como propietario o con excepción declarada; lo decide su ADR.

### 5.8 Convención del curso de referencia: ratificada

Se ratifican sin cambios `REQ-CURSO/funcional.md §3.2` y `api.md §3`: el curso de una escritura sale de la propia entidad o de su padre, nunca de un curso por omisión (`RN-CURSO-22`); las colecciones aceptan `academic_year` (`public_id`) y, si se omite, usan el curso activo, con `404 curso.no_active_year` si no lo hay; ninguna selección de curso vive en la sesión del servidor. Encaja con este ADR: el disparador no necesita saber «qué curso está mirando el usuario», solo a qué curso pertenece la fila.

---

## 6 · Motivo

1. **La regla es una invariante de datos y debe vivir donde viven las otras.** «Un activo por centro» se impuso con un índice único parcial y no con validación de aplicación porque «una condición de carrera entre dos peticiones simultáneas la rompería sin que el servidor se enterara» (`ADR-034 §4`). El bloqueo de un curso cerrado tiene exactamente el mismo modo de fallo, y además una carrera explícita con el cierre (`RN-CURSO-32`).
2. **Es la única opción que cumple lo que la especificación aprobada exige.** `RN-CURSO-23` (no depender de que nadie se acuerde) y `RN-CURSO-32` (nunca confirmar después del cierre) las cumple C sin piezas adicionales; B no cumple ninguna de las dos sin añadir un detector que no puede ver relaciones y una sobrescritura transaccional de `save()` en cada modelo.
3. **Los motivos con que el proyecto descartó disparadores no aplican a este caso** (`§4`): no hace falta contexto de aplicación, y el coste en migraciones se anula con la exención del propietario.
4. **Se comprueba donde está la verdad.** `AR-13` lee `pg_trigger`; no hay mapa modelo→tabla que mantener ni camino de escritura que escape a la regla.
5. **Reversible**: la función se reescribe con una migración y el bloqueo se apaga en todas las tablas a la vez; ningún dato cambia de forma.

---

## 7 · Consecuencias

**Buenas**

- El criterio de aceptación 1 de `§5.28` se cumple por cualquier camino de escritura: ORM, constructor de consultas, relaciones, `DB::table()`, SQL crudo, colas y consola.
- Los módulos consumidores no hacen nada: crean la tabla con el ayudante y el disparador viene puesto; si no, `AR-13` falla.
- `RN-CURSO-32` y `CA-CURSO-046` se cumplen sin bloqueos aplicativos ni transacciones forzadas en el ORM.
- La rectificación de `REQ-CALIF` y las excepciones de `REQ-FIN` tienen una forma decidida que no exige tocar ninguna tabla, solo la función y código de `Curso`.

**Malas, y hay que asumirlas**

- **Primera función PL/pgSQL del proyecto.** Una lógica de negocio en el motor, menos visible al leer PHP. Se mitiga con una sola función, documentada en `REQ-CURSO/datos.md` y `operacion.md`, con tests de punta a punta.
- **Dos fuentes del vocabulario de estados** (SQL y enumerado PHP), unidas por un test de paridad.
- **El error nace como `QueryException`** fuera de HTTP y aborta la transacción en curso. Los procesos por lotes deben comprobar antes (`§5.6`) o usar puntos de guardado.
- **Coste por fila escrita**: una búsqueda por clave única en una tabla de decenas de filas por centro y un bloqueo compartido de fila, comparable al que ya impone la clave foránea. Se **mide** en la implementación (`CA-057-09`) y se anota, como se hizo con RLS en `0.8.12`; no se da por bueno.
- **Escrituras en espera durante el cierre**, acotadas por la duración de las validaciones (`§5.4`).
- **Exención del propietario**: un proceso que tenga las credenciales del propietario escribe en cursos cerrados. Es coherente con `ADR-033 §5` (esas credenciales solo existen para migraciones y mantenimiento) y se refuerza con la prohibición de `§5.7` punto 6.

**Restricciones que este ADR impone a decisiones futuras**

- **Derecho de supresión** (`ADR-004` nivel 2, `REQ-PRIV-006`): si una tabla de curso contiene datos personales que haya que anonimizar o suprimir en un curso cerrado, esa escritura choca con el bloqueo. Tendrá que hacerse como propietario desde una tarea de mantenimiento o con una excepción declarada de `REQ-PRIV`. Lo decide `REQ-PRIV-006` antes de admitir datos reales (`OPEN-057-04`).
- **Purga física de un tenant** (issue #371) y **archivado en frío**: como propietario, o con excepción declarada.
- **`REQ-SEED`** (`1.15b`): para generar histórico de cursos cerrados, crear los datos con el curso activo y cerrarlo después, o sembrar como propietario.
- **Rollover** (`REQ-CURSO-002`): escribe en el curso en `planificacion` y lee del cerrado o activo; no le afecta.

---

## 8 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **A · guarda explícita en cada servicio** | Depende de que nadie se olvide nunca en cincuenta módulos; es exactamente lo que `INV-001` rechaza para el tenant y `RN-CURSO-23` para este caso |
| **B · rasgo de modelo + `AR-13` por reflexión** (recomendación de la especificación) | No cubre actualizaciones y borrados masivos por constructor de consultas ni por relación, que el repositorio ya usa (`§1.1`), ni `DB::table()`/SQL crudo, cuyo test de prohibición prometido en `ADR-034 §3` no existe; no serializa con el cierre sin sobrescribir `save()`/`delete()` para forzar transacción y bloqueo en cada modelo. Los motivos que la especificación dio contra C eran de la auditoría y no aplican aquí |
| **B+C · dos capas** | El rasgo solo añade una excepción de dominio que la traducción de `§5.5` ya produce en HTTP; sería un segundo mecanismo obligatorio y una segunda regla `AR` sin beneficio proporcional. Añadirlo después es aditivo |
| **D · política RLS restrictiva** | Sobre `UPDATE`/`DELETE` una política que no se cumple **filtra filas en silencio**: cero filas afectadas, sin error, y la API respondería `200`. Sobre `INSERT` devuelve `42501`, el mismo código que una violación de aislamiento de tenant. No serializa con el cierre. Además el propietario está sujeto por `FORCE` y las migraciones necesitarían su propia vía de escape |
| **Particiones por curso con privilegios revocados** | PostgreSQL comprueba privilegios sobre la tabla padre al acceder a través de ella; y exigiría particionar todas las tablas por curso desde el primer día |
| ***Middleware* HTTP** | No sabe qué filas tocará la petición; no cubre colas ni consola |
| **Variable de configuración de sesión para las excepciones** | Sobrevive a la petición en la conexión; con un *pooler* por transacción la heredaría otra petición (`ADR-033 §5`). Solo local a la transacción |
| **Permiso genérico «escribir en cursos cerrados»** | Convertiría el bloqueo en una casilla que alguien acaba marcando a un rol entero; las excepciones son por módulo, tabla y operación, con motivo (`§5.7`) |
| **Bloqueo consultivo (`pg_advisory_xact_lock_shared`) en vez de `FOR SHARE`** | Evita escribir en la tupla de `academic_years`, pero en `REPEATABLE READ` la escritura que espera vería el estado antiguo y se colaría; la clave foránea ya paga un bloqueo de fila equivalente, así que el ahorro no compensa la fragilidad |

---

## 9 · Ajustes necesarios en la especificación de `REQ-CURSO` (no los edita `architect`)

La especificación está aprobada; si este ADR se acepta, la sesión principal (o `spec-writer`) debe ajustar:

1. **`funcional.md §5.3`, `RN-CURSO-23`**: el mecanismo es el disparador de `§5.2` enganchado por los ayudantes de `TenantMigration` y vigilado por `AR-13` sobre el esquema, no un rasgo de modelo. Referenciar `ADR-057`.
2. **`funcional.md §5.4`, `RN-CURSO-32`**: concretar «cierre con `FOR UPDATE` primero; escritura con `FOR SHARE` dentro del disparador» y la prohibición de tomar otros bloqueos antes del del curso (`§5.4`).
3. **`funcional.md §6`, última fila** («Módulo consumidor que escribe con `DB::table()`…»): es **falsa** hoy (el test de DML crudo no existe) y con este ADR el caso queda cubierto por el motor. Reescribirla.
4. **`funcional.md §7.1`, `AcademicYearWriteGuard`**: pasa a ser comprobación previa consultiva (`§5.6`); el consumidor previsto deja de ser «el mecanismo de *framework* de `RN-CURSO-23`».
5. **`funcional.md §13.3`**:
   - `CA-CURSO-040`: la escritura se rechaza por **todos** los caminos —`save()`, borrado lógico, `restore()`, `forceDelete()`, `Model::query()->…->update()`/`->delete()`, actualización por relación, `DB::table()->insert/update/delete` y `DB::statement`— con `SQLSTATE` `CY001`, y la fila no cambia.
   - `CA-CURSO-043`: pasa a ser la regla `AR-13` sobre el esquema (tabla con `academic_year_id` sin disparador, o con el disparador deshabilitado, hace fallar el test).
   - `CA-CURSO-046`: se implementa con el par `FOR UPDATE`/`FOR SHARE` de `§5.4`.
   - Añadir los criterios `CA-057-01` a `-09` de este ADR (o sus equivalentes `CA-CURSO-*`).
6. **`funcional.md §1.3` y `datos.md §1.3`**: la tabla sonda se crea con `TenantMigration::tenantTable()` y por tanto recibe el disparador; una segunda sonda **sin** ayudante sirve a los casos fijos de `AR-13`.
7. **`funcional.md §14`**: el riesgo «se especifica sin consumidor real» se mantiene; añadir el de la primera función PL/pgSQL y el de transacción abortada en procesos por lotes.
8. **`datos.md`**: el resumen («no crea ninguna tabla ni altera ninguna columna») sigue siendo cierto, pero `1.10` **sí trae una migración** (la función). En `§2`, la fila «Todo modelo cuya tabla lleve `academic_year_id` usa el mecanismo de bloqueo» pasa a «toda tabla con `academic_year_id` lleva el disparador `academic_year_write_guard`, que ponen los ayudantes; lo comprueba `AR-13`».
9. **`operacion.md §6`** («Ninguna migración de esquema») y **`§9`** (reversión): hay una migración, con `down()`; `db-reviewer` es obligatorio. **`§7`**: añadir el síntoma «`409 academic-year-closed` o `SQLSTATE CY001` en el log» con su diagnóstico, y «una migración de relleno falla con `CY001`» (se está ejecutando con un rol que no es el propietario).
10. **`permisos.md §5`**, primera fila: «Todo modelo con `academic_year_id`» → «toda tabla con `academic_year_id`, por el disparador de `ADR-057`».
11. **`api.md §5`**: el `type` nuevo ya no es «propuesta de `OPEN-CURSO-05`», sino ampliación de `ADR-038 §6.2` por `ADR-057 §5.5`.
12. **Documentos fuera de la especificación**, en el cierre de `1.10`: `ARCHITECTURE.md §3.4` (fila `AR-13` y su test), `docs/modulos/_PLANTILLA/datos.md` (la casilla de `academic_year_id` dice «lo comprueba `AR-13`»), la *skill* `modulo-nuevo` (crear las tablas de curso con el ayudante), y la entrada de historial del documento de requisitos por la alta de este ADR.

---

## 10 · Preguntas abiertas

| ID | Pregunta | Recomendación | Bloquea |
|---|---|---|---|
| **`OPEN-057-01`** | ¿Se acepta la opción C (disparador) en lugar de la B que aprobó la especificación? | **Sí**, por `§4`: B no cumple `RN-CURSO-23` ni `RN-CURSO-32` | Implementación de `1.10` |
| **`OPEN-057-02`** | ¿Se acepta la exención del propietario de la tabla (`§5.2`)? | **Sí**: sin ella cada migración de relleno, la purga de #371 y el archivado necesitarían su propia vía de escape | Implementación |
| **`OPEN-057-03`** | **Asimetría con la lectura**: `RN-CURSO-25` (`curso_historico.leer`) depende de que cada *endpoint* de lectura invoque `AcademicYearReadAccess`, que es el modelo que `RN-CURSO-23` rechaza para la escritura. El equivalente automático sería un ámbito global de curso, que `ADR-034 §4` prohíbe con buen motivo | **Mantener el contrato invocado**, y exigir en la especificación de cada módulo con datos por curso un criterio de aceptación de **denegación de lectura de curso cerrado en listado y en detalle**, como `ADR-044 §4.2` exige para el ámbito. El fallo aquí es una lectura de más dentro del mismo centro, no una fuga entre tenants | No bloquea `1.10`; obliga a `1.11` |
| **`OPEN-057-04`** | Supresión y anonimización de datos personales en tablas de cursos cerrados (`§7`) | Decidirlo en `REQ-PRIV-006`, con la forma de `§5.7` o como tarea del propietario | No `1.10`; sí antes de datos reales |

---

## 11 · Hallazgos fuera del alcance de este ADR (reportados, no corregidos)

1. **El test de prohibición de DML crudo en `app/Modules/**` que `ADR-034 §3` estableció como compensación de la auditoría por ORM no existe.** Contradicción entre ADR aceptado y código: severidad **Media** como mínimo (`CLAUDE.md §6.6`). `REQ-CURSO/funcional.md §6` lo da por existente.
2. **Escrituras sin auditar hoy**: `UserMfaObligation` y `UserMfaExemption` son `Auditable`, pero se actualizan con `Model::query()->…->update()` en `MfaEnrollmentService` y `MfaExemptionService`, que no disparan el *observer*. Afecta a `INV-003`. Severidad **Media**. Mismo origen que el hallazgo 1: la limitación de la vía ORM que `ADR-034 §3` declaró «compensada» no lo está. Corregirlo es decidir si el detector prohíbe también las actualizaciones masivas sobre modelos `Auditable` (con el límite de `AR-10`: no ve relaciones) o si esas operaciones emiten su propio registro. No es de este ADR.
3. **`REQ-CURSO/funcional.md §15`, `OPEN-CURSO-04`**, atribuye a `ADR-034 §3` un descarte general de disparadores, cuando ese ADR los descartó **como mecanismo de auditoría**, por motivos propios de la auditoría (actor, IP, `request_id`), y los dejó anotados como «la salida prevista si algún día un requisito legal exige garantía a nivel de motor». Se corrige con el ajuste 1 de `§9`.

---

## Anexo A · Criterios de aceptación de este ADR

Se suman a los de `REQ-CURSO/funcional.md §13.3` y referencian `ADR-057` y `RN-CURSO-*` (`INV-015`). Base de datos real (`ADR-033 §10`).

- **CA-057-01** — Con una tabla sonda de curso creada con `tenantTable()`, un curso `cerrado` y una fila suya: cada camino de escritura del ajuste 5 de `§9` falla con `SQLSTATE` `CY001` y la fila no cambia. Con el curso en `activo` o `planificacion`, los mismos caminos funcionan.
- **CA-057-02** — `UPDATE` que mueve una fila de un curso abierto a uno cerrado, y de uno cerrado a uno abierto: ambos fallan con `CY001`.
- **CA-057-03** — Por la conexión `pgsql_owner`, una actualización masiva sobre filas de un curso cerrado tiene éxito (relleno de migración).
- **CA-057-04** — Una inserción con `academic_year_id` inexistente falla con la violación de clave foránea (`23503`), no con `CY001`.
- **CA-057-05** — Por HTTP (ruta de test), la escritura bloqueada responde `409` con `type` `urn:pge:error:academic-year-closed`, `errors.academic_year[0].code` = `curso.academic_year_closed`, `params.code` y `params.status` del curso, y `detail` en los cuatro idiomas según `Accept-Language` (`CA-CURSO-041`).
- **CA-057-06** — `AR-13`: verde con la sonda creada por el ayudante; rojo con una tabla con `academic_year_id` creada sin él y con una tabla cuyo disparador se ha deshabilitado; aserción de no vacuidad (`§5.3`); lista de excepciones vacía y que solo puede reducirse.
- **CA-057-07** — Paridad: para cada valor de `AcademicYearStatus`, la función bloquea si y solo si el enumerado lo declara de solo lectura.
- **CA-057-08** — Concurrencia (`CA-CURSO-046`, dos procesos reales en `tests/Concurrency/`): un cierre y una escritura simultáneos sobre el mismo curso nunca dejan una fila escrita con fecha de confirmación posterior a la del cierre; la escritura que pierde recibe `CY001`.
- **CA-057-09** — Se mide la sobrecarga del disparador en una inserción masiva (mismo volumen con y sin disparador, sobre una tabla sonda) y el número real se anota en `memory.md` y `CHANGELOG.md`. Si supera la sobrecarga medida para RLS en `0.8.12`, es un hallazgo que se registra como issue, no una cifra que se ajusta.
- **CA-057-10** — Ningún rol de aplicación (`plataforma_app`, `plataforma_platform`) tiene privilegio `TRUNCATE` sobre ninguna tabla con `academic_year_id` (comprobado en el esquema).
