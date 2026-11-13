# REQ-CALIF · Permisos

> **Estado**: PROPUESTO (2026-11-12) junto con `funcional.md`. Ámbitos y siembra son la recomendación de `OPEN-CALIF-05`. La fuente de verdad será `CalifServiceProvider::declaredPermissions()` (`ADR-034 §2`); esta tabla es su reflejo previsto.

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué así |
|---------|----------------|-------------|
| `configuracion_evaluacion` | Períodos de evaluación, asignación de escalas, ajustes del boletín (y el catálogo de competencias en `1.16-c`) | Configuración del centro entero, sin datos personales; análoga a `configuracion_academica` |
| `evaluacion` | Evaluaciones de grupo: abrir, cerrar, reabrir, publicar | Decidir cuándo se califica y cuándo lo ve la familia es distinto de poner notas (`REQ-CALIF-001`: «hasta que… las publique») |
| `calificacion` | Calificaciones, comentarios, elementos ponderados, notas de elemento, historial | `RPERM-002` cita «calificaciones» |
| `boletin` | Emisiones y boletines; su validación | Documento con vida propia (`REQ-CALIF-002`) |

Acciones solo de `RPERM-003`; ninguna inventada. `aprobar` = cerrar y reabrir; `publicar` = publicar; `firmar` = validación del tutor de grupo (`ADR-082`, nivel N1). `resource_label_key = calif.permissions.resources.<recurso>` en los cuatro idiomas (`CA-PERM-134`). Ningún recurso se llama como un ámbito ni como un rol.

## 2. Catálogo

`module_code = 'calif'`. `applicable_scopes` explícito en cada entrada (`ADR-044 §4.1`). **Ninguno es de categoría especial** (`is_special_category = false`; `AR-09` no se dispara).

| `code` | `applicable_scopes` | *Endpoints* (`api.md`) | Entrega |
|--------|---------------------|------------------------|---------|
| `configuracion_evaluacion.leer` | `['todos']` | `GET` de períodos, asignaciones y ajustes | `1.16-a` |
| `configuracion_evaluacion.crear` | `['todos']` | `POST` de períodos y asignaciones | `1.16-a` |
| `configuracion_evaluacion.actualizar` | `['todos']` | `PATCH` de períodos, asignaciones y ajustes | `1.16-a` |
| `configuracion_evaluacion.eliminar` | `['todos']` | `DELETE` de períodos y asignaciones | `1.16-a` |
| `evaluacion.leer` | `['todos','grupo']` | `GET /group-evaluations…`, `…/transitions` | `1.16-a` |
| `evaluacion.crear` | `['todos']` | `POST /evaluation-periods/{id}/group-evaluations` | `1.16-a` |
| `evaluacion.aprobar` | `['todos','grupo']` | `…/closure`, `…/reopening` | `1.16-b` |
| `evaluacion.publicar` | `['todos']` | `…/publication` (una y en bloque) | `1.16-b` |
| `calificacion.leer` | `['todos','grupo','clase','unidad_familiar']` | Rejilla, detalle, elementos (sin `unidad_familiar`), historial | `1.16-a` |
| `calificacion.actualizar` | `['todos','clase']` | `PUT` de calificaciones, elementos y notas de elemento | `1.16-a` |
| `boletin.leer` | `['todos','grupo','unidad_familiar']` | Emisiones (sin `unidad_familiar`), boletines, descarga | `1.17-a` |
| `boletin.crear` | `['todos','grupo']` | `POST /report-card-runs` | `1.17-a` |
| `boletin.firmar` | `['todos','grupo']` | Validación | `1.17-b` |

### 2.1 Permisos que **no** se declaran

| No se declara | Motivo |
|---------------|--------|
| `calificacion.crear`, `.eliminar` | Una calificación se escribe por conjunto (`RN-CALIF-33`): poner, cambiar y quitar son la misma operación y la misma responsabilidad [DERIVADA, precedente `RN-ACAD-50`] |
| `*.exportar`, `*.importar` | Ningún requisito pide exportar notas; importación en `REQ-ONB` |
| `boletin.eliminar`, `.actualizar` | Un boletín es inmutable; se sustituye al reemitir |
| `boletin.publicar` | La publicación es un solo acto sobre la evaluación (`OPEN-CALIF-22`) |
| `propios` | `OPEN-CALIF-05`: llega con `1.23` (ampliar `applicable_scopes` es compatible) |
| `departamento` | Sin dueño ni resolutor (`ADR-063 §5.5`) |
| `grupo` en `calificacion.actualizar` | Un tutor de grupo no califica asignaturas que no imparte (`RN-CALIF-34`) |
| `clase` en `evaluacion.*` y `boletin.*` | El cierre y el boletín son del grupo entero |

## 3. Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `configuracion_evaluacion` | `todos` | `todos` | `todos` | `todos` | — | — | — | — | — |
| `evaluacion` | `todos` | `todos`, `grupo` | — | — | — | — | `todos`, `grupo` | — | `todos` |
| `calificacion` | — | `todos`, `grupo`, `clase`, `unidad_familiar` | `todos`, `clase` | — | — | — | — | — | — |
| `boletin` | `todos`, `grupo` | `todos`, `grupo`, `unidad_familiar` | — | — | — | — | — | `todos`, `grupo` | — |

### 3.1 Ámbito restringido: resolutores (`ADR-044 §4.2`, `ADR-063 §5.2`)

`calif` es el **dueño** de `evaluacion`, `calificacion` y `boletin` y registra sus resolutores, en `Calif\Infrastructure`, con la pertenencia que aportan `acad` y `fam_unit`. Cada resolutor devuelve una **restricción de consulta**, no un booleano.

| Par (ámbito, recurso) | Restricción |
|-----------------------|-------------|
| (`grupo`, `evaluacion`) | `group_evaluations.group_id IN TeachingMembership::groupIdsFor(u, academic_year_id)` |
| (`grupo`, `calificacion`) | Calificaciones (y elementos, notas de elemento) cuya evaluación de grupo cumple lo anterior |
| (`clase`, `calificacion`) | Pares (`group_id` de la evaluación de grupo, `subject_id`) ∈ `TeachingMembership::classPairsFor(u, academic_year_id)`. **Primer uso del ámbito `clase` en el producto** |
| (`unidad_familiar`, `calificacion`) | `enrollment_id IN` matrículas de los alumnos alcanzados por la persona del sujeto (`FamilyMembership`, solo vínculos **activos**; matrículas por `Alum\Domain`), **y** evaluación de grupo `cerrada` y publicada (`RN-CALIF-26`). Sobre elementos y notas de elemento: conjunto vacío |
| (`grupo`, `boletin`) | `report_cards.group_evaluation_id` en evaluaciones de grupo de `groupIdsFor`; igual para `report_card_runs` |
| (`unidad_familiar`, `boletin`) | Como (`unidad_familiar`, `calificacion`), y `status = 'generado'`. Sobre `report_card_runs`: conjunto vacío |

Reglas comunes: sujeto sin asignación docente o sin faceta de tutor legal ⇒ conjunto vacío (deniega); unión entre ámbitos de roles distintos; `deny` gana y es ciego al ámbito (`RPERM-007`, `ADR-044`); sin caché entre peticiones (`ADR-044 §4.7`); en el **historial**, el curso académico de cada matrícula se resuelve por separado (la pertenencia docente es por curso, `ADR-063 §5.4`).

**Escritura con ámbito**: `calificacion.actualizar` en `clase` comprueba el par de la **ruta** (evaluación de grupo y asignatura) contra `classPairsFor` antes de leer nada del cuerpo; fuera de ámbito ⇒ `404`.

**Regla de negocio que no es permiso y se comprueba además**: evaluación `abierta` para escribir (`RN-CALIF-22`), publicación para familias (`RN-CALIF-26`), curso cerrado (`AcademicYearReadAccess`, disparador). La publicación se evalúa **dentro** del resolutor de `unidad_familiar` para que listado, detalle, historial y descarga no puedan divergir.

**Mapa cerrado de `AR-10`** (ampliación que requiere aprobación **expresa** del usuario, `OPEN-CALIF-05`): `evaluacion` → `GroupEvaluation` (servicio sancionado de evaluaciones de grupo y la validación de cierre del curso, que cuenta del centro entero); `calificacion` → `Grade`, `GradingComponent`, `ComponentGrade` (servicio de calificaciones y los vetos, que cuentan del centro entero sin ámbito de actor); `boletin` → `ReportCard`, `ReportCardRun` (servicio de boletines y el trabajo de generación, que corre con el actor que pidió la emisión). El test no ve accesos por relación ni `DB::table()`: lo cubren `CA-CALIF-012` a `-015`, `-038`, `-039`, `-043`, `-058`, `-059` (listado **y** detalle).

**El trabajo en cola no hereda permisos de una petición**: fija el centro, y genera exactamente los boletines creados por la petición ya autorizada; no vuelve a resolver ámbitos ni lee nada fuera de esos identificadores (`CA-CALIF-057`).

## 4. Asignación en roles predefinidos (recomendación de `OPEN-CALIF-05`)

Denegación por defecto (`RPERM-011`). Siembra en `ProvisionTenantDefaults` para centros nuevos y comando `calif:grant-permissions` para los existentes: idempotente, solo roles predefinidos, sin tocar personalizados, sin conceder nada si falta o está retirado algún permiso (#387). Se vuelve a ejecutar tras cada entrega que declare permisos nuevos. Ninguna regla compara estos códigos (`RN-PERM-46`, `AR-08`).

| Rol (`code`) | Permisos | Ámbito | Motivo (`§11.1`) |
|--------------|----------|--------|------------------|
| `administrador_centro` | Todos | `todos` | Gestión completa |
| `direccion` | `configuracion_evaluacion.*`, `evaluacion.*`, `calificacion.leer`, `boletin.leer`, `boletin.crear` | `todos` | «Informes académicos, aprobaciones»; es quien publica (`REQ-CALIF-001`). Sin `calificacion.actualizar`: no pone notas de clases ajenas |
| `secretaria` | `configuracion_evaluacion.leer`, `evaluacion.leer`, `calificacion.leer`, `boletin.leer` | `todos` | «Documentación» |
| `docente` | `configuracion_evaluacion.leer`, `calificacion.leer`, `calificacion.actualizar` | **`clase`** (`configuracion_evaluacion.leer` en `todos`) | «Sus clases, calificaciones». Nunca `todos` (`ADR-063 §5.4`, #471) |
| `tutor_grupo` | `evaluacion.leer`, `evaluacion.aprobar`, `calificacion.leer`, `boletin.leer`, `boletin.crear`, `boletin.firmar` | **`grupo`** | «Acceso ampliado a su grupo: seguimiento, boletines» |
| `tutor_legal` | `calificacion.leer`, `boletin.leer` | **`unidad_familiar`** | «Información de los estudiantes vinculados» (API; pantallas en `1.22`) |
| `orientador`, `estudiante`, `administrativo` y resto | — | — | Sin base suficiente en este paso (`OPEN-CALIF-05`) |

Consecuencia que el usuario debe conocer (`OPEN-CALIF-30`): un usuario con los roles `docente` y `tutor_grupo` lee con `grupo` todas las asignaturas de **todos** los grupos donde tutoriza o imparte (`RN-ACAD-98` une ambos conjuntos). Escribir sigue acotado a sus clases.

## 5. Datos de categoría especial

**Ninguno declarado.** Las calificaciones son datos personales de menores, no categoría especial del art. 9 RGPD (afirmación de trabajo, **pendiente de validación jurídica**). Aun así se aplica auditoría de lectura por consulta (`RN-CALIF-08`), por ser menores. Dos puntos que sí rozan la categoría especial y quedan fuera: la escala adaptada (`REQ-NEAE-003`, fase 2, con permiso propio fuera de todo rol por defecto) y el comentario libre del docente, donde nada impide técnicamente escribir un dato de salud (`OPEN-CALIF-27`).

## 6. MFA

Ningún cambio: `mfa_obligatorio` es atributo del rol (`RPERM-014`) y lo decide el centro. Observación, no decisión: `evaluacion.publicar` y `boletin.firmar` son acciones con efecto hacia las familias; si el centro quiere MFA obligatorio en `direccion` y `tutor_grupo`, lo activa en el editor de roles.
