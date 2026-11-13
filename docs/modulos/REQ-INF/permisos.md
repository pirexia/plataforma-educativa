# REQ-INF · Permisos

> **Estado**: **PROPUESTO** (2026-11-12), junto con `funcional.md`. La siembra de §4 es la recomendación de `OPEN-INF-23`. La fuente de verdad será `InfServiceProvider::declaredPermissions()` (`ADR-034 §2`).

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué así |
|---------|----------------|-------------|
| `ciclo_infantil` | Marca de primer ciclo, tramos, reglas y estado de ratio, excepciones, registro diario, autorización administrativa | Configuración y control; sin datos personales salvo la matrícula que consume una excepción |
| `agenda_infantil` | Parte diario y sus anotaciones | Dato diario de un menor, cercano a la salud (`OPEN-INF-04`): permiso propio, separado de `alumno` |
| `informe_desarrollo` | Indicadores, informes, valoraciones y observaciones | Evaluación: validar y publicar son acciones distintas de redactar |
| `adaptacion_infantil` | Plan de adaptación y su evolución | Se puede conceder sin la agenda ni los informes |

Acciones solo de `RPERM-003`; ninguna inventada. `resource_label_key = inf.permissions.resources.<recurso>` en los cuatro idiomas (`CA-PERM-134`). Ningún recurso se llama como un ámbito.

## 2. Catálogo

`module_code = 'inf'`. `applicable_scopes` explícito en cada entrada (`ADR-044 §4.1`). **Ninguno es de categoría especial** en estas entregas (`AR-09`).

| `code` | `is_special_category` | `applicable_scopes` | *Endpoints* (`api.md`) | Entrega |
|--------|-----------------------|---------------------|------------------------|---------|
| `ciclo_infantil.leer` | `false` | `['todos']` | Lecturas de §1 de `api.md` | `-a` |
| `ciclo_infantil.actualizar` | `false` | `['todos']` | Marca, tramos, reglas, autorizaciones (alta, cambio y baja) | `-a` |
| `ciclo_infantil.aprobar` | `false` | `['todos']` | Conceder y anular excepciones de ratio | `-a` |
| `agenda_infantil.leer` | `false` | `['todos','grupo','unidad_familiar']` | Día del grupo, parte, histórico | `-b` |
| `agenda_infantil.crear` | `false` | `['todos','grupo']` | Alta de parte, anotaciones, masivo | `-b` |
| `agenda_infantil.actualizar` | `false` | `['todos','grupo']` | Cambio de cabecera y de anotaciones, baja de una anotación | `-b` |
| `agenda_infantil.eliminar` | `false` | `['todos']` | Baja de un parte | `-b` |
| `agenda_infantil.publicar` | `false` | `['todos','grupo']` | Publicación individual y de grupo | `-b` |
| `informe_desarrollo.leer` | `false` | `['todos','grupo','unidad_familiar']` | Indicadores, informes | `-c` |
| `informe_desarrollo.crear` | `false` | `['todos','grupo']` | Alta de informe, rectificación | `-c` |
| `informe_desarrollo.actualizar` | `false` | `['todos','grupo']` | Contenido en borrador, envío a validación; con `todos`, además indicadores | `-c` |
| `informe_desarrollo.eliminar` | `false` | `['todos']` | Baja de un borrador y de indicadores | `-c` |
| `informe_desarrollo.aprobar` | `false` | `['todos']` | Validar y devolver | `-c` |
| `informe_desarrollo.publicar` | `false` | `['todos']` | Publicar | `-c` |
| `adaptacion_infantil.leer` | `false` | `['todos','grupo','unidad_familiar']` | Planes | `-d` |
| `adaptacion_infantil.crear` | `false` | `['todos','grupo']` | Alta | `-d` |
| `adaptacion_infantil.actualizar` | `false` | `['todos','grupo']` | Plan, días, evolución | `-d` |
| `adaptacion_infantil.eliminar` | `false` | `['todos']` | Baja | `-d` |
| `adaptacion_infantil.publicar` | `false` | `['todos','grupo']` | Publicación | `-d` |

Los indicadores no son por grupo: mantenerlos exige `informe_desarrollo.actualizar` o `.eliminar` con ámbito **`todos`**; con `grupo`, `403` [DERIVADA].

### 2.1 Permisos que no se declaran

| No se declara | Motivo |
|---------------|--------|
| `salud_infantil.*` (`is_special_category = true`) | `1.18-h`, bloqueado por `ADR-085` (`OPEN-INF-03`). Declararlo antes rompe `AR-09` |
| `informe_desarrollo.firmar` | `1.18-g`, `ADR-082` |
| `*.exportar` | Ningún listado se exporta en estas entregas; el PDF del histórico es `1.18-g`. Cuando llegue: en cola, con enlace caducable y auditoría de lo exportado |
| `*.importar` | `REQ-ONB` |
| `clase`, `propios` en `applicable_scopes` | En 0-3 no hay asignaturas ni alumno usuario |
| `unidad_familiar` en acciones de escritura | La familia solo lee |

## 3. Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `ciclo_infantil` | — | `todos` | `todos` | — | — | — | `todos` | — | — |
| `agenda_infantil` | `todos`, `grupo` | `todos`, `grupo`, `unidad_familiar` | `todos`, `grupo` | `todos` | — | — | — | — | `todos`, `grupo` |
| `informe_desarrollo` | `todos`, `grupo` | `todos`, `grupo`, `unidad_familiar` | `todos`, `grupo` | `todos` | — | — | `todos` | — (`-g`) | `todos` |
| `adaptacion_infantil` | `todos`, `grupo` | `todos`, `grupo`, `unidad_familiar` | `todos`, `grupo` | `todos` | — | — | — | — | `todos`, `grupo` |

### 3.1 Ámbito restringido: resolutores (`ADR-044 §4.2`, `ADR-063 §5.2`)

`inf` es dueño de sus tres recursos con ámbito y registra sus resolutores en `Infrastructure`. Cada uno resuelve a una restricción de consulta sobre columnas propias (`datos.md §1.7`), no a un booleano.

| Par (ámbito, recurso) | Restricción de consulta |
|-----------------------|-------------------------|
| (`grupo`, `agenda_infantil`) | `group_id IN TeachingMembership::groupIdsFor(u, academic_year_id)` sobre `DailyLog`; las anotaciones, por su parte |
| (`grupo`, `informe_desarrollo`) | Ídem sobre `DevelopmentReport`; valoraciones y observaciones, por su informe |
| (`grupo`, `adaptacion_infantil`) | Ídem sobre `AdaptationPlan`; días, por su plan |
| (`unidad_familiar`, `agenda_infantil`) | `student_person_id IN FamilyMembership::studentPersonIdsReachedBy(persona, 'salud')` (vínculos **activos** que no restringen la categoría `salud`) **y** `status = 'publicado'` |
| (`unidad_familiar`, `informe_desarrollo`) | `student_person_id IN FamilyMembership::studentPersonIdsReachedBy(persona)` (vínculos **activos**, sin categoría) **y** `status = 'publicado'` (nunca `sustituido`, ni borradores) |
| (`unidad_familiar`, `adaptacion_infantil`) | Como `agenda_infantil`: con la categoría `'salud'`, **y** `status = 'publicado'` |

- **Categoría `salud` en el parte y la adaptación** (revisión C4-07; propuesta pendiente de `OPEN-INF-04`): el contenido de ambos roza la salud (`funcional.md` C3), así que el resolutor pide los alumnos con la categoría `'salud'`, que `fam_unit` ya admite y que excluye los vínculos que la restringen. Es la opción más restrictiva y reversible. Si el usuario decide que no aplica, se quita el argumento.

- La condición de publicación forma parte del resolutor de familia: es regla de negocio, pero colocarla ahí evita que un *endpoint* la olvide [DERIVADA]. Se comprueba además en el servicio.
- Sujeto sin asignación o sin vínculo: conjunto vacío. Sin caché entre peticiones. Unión entre ámbitos de roles distintos y `deny` ciego al ámbito (`ADR-044`, `RPERM-007`).
- El grupo es el del registro (`RN-INF-31`), no el actual del alumno.
- Escritura con ámbito `grupo`: se comprueba que el grupo destino está en `groupIdsFor` antes de crear; fuera, `404`.
- Cómo entra un educador en `groupIdsFor`: `OPEN-INF-10`.

**Mapa cerrado de `AR-10`** (ampliación que requiere aprobación expresa del usuario): `agenda_infantil` → `DailyLog` (servicios sancionados `DailyLogService`, `DailyLogReader` y el veto de borrado de matrícula); `informe_desarrollo` → `DevelopmentReport` (`DevelopmentReportService`, `DevelopmentReportReader` y sus vetos); `adaptacion_infantil` → `AdaptationPlan` (`AdaptationPlanService` y su veto). Los nombres de clase son orientativos. Los vetos cuentan del centro entero, sin ámbito de actor. Lo que el test no ve (relaciones, `DB::table`) lo cubren `CA-INF-020` a `-024`, `-047`, `-048`, `-051`, `-061` y `-063`, en listado **y** en detalle.

Reglas que no son permiso y se comprueban además: publicación (`RN-INF-24`, `-48`, `-61`), curso cerrado (`RN-INF-70`), validador distinto del redactor (`RN-INF-47`), inmutabilidad (`RN-INF-49`).

## 4. Asignación en roles predefinidos (`OPEN-INF-23`, recomendación)

Denegación por defecto (`RPERM-011`). Siembra en `ProvisionTenantDefaults` para centros nuevos y comando idempotente `inf:grant-permissions` para los existentes, que solo toca roles predefinidos y no concede nada si falta un permiso (precedente `alum:grant-permissions`, #387). Se ejecuta tras cada entrega que añade permisos. Ninguna regla compara estos códigos (`AR-08`).

| Rol (`code`) | Permisos | Ámbito | Motivo |
|--------------|----------|--------|--------|
| `administrador_centro` | Todos | `todos` | Gestión completa |
| `direccion` | `ciclo_infantil.*`; `agenda_infantil.leer`; `informe_desarrollo.leer`, `.aprobar`, `.publicar`, `.actualizar`, `.eliminar`; `adaptacion_infantil.leer` | `todos` | Configura, aprueba excepciones, valida y publica; mantiene indicadores |
| `secretaria` | `ciclo_infantil.leer` | `todos` | Ve el estado de ratio al matricular. No lee partes ni informes |
| `docente`, `tutor_grupo` | `agenda_infantil.leer`, `.crear`, `.actualizar`, `.publicar`; `informe_desarrollo.leer`, `.crear`, `.actualizar`; `adaptacion_infantil.leer`, `.crear`, `.actualizar`, `.publicar` | **`grupo`** | El educador de su aula; nunca `todos` (`ADR-063 §5.4`, `CA-INF-073`) |
| `tutor_legal` | `agenda_infantil.leer`, `informe_desarrollo.leer`, `adaptacion_infantil.leer` | **`unidad_familiar`** | Lo publicado de sus hijos (API; pantallas en `1.22`) |
| Los restantes | — | — | Sin base en el documento |

No se crea un rol «educador infantil»: el centro puede clonar `docente` (`RPERM-006`), y todo funciona sobre roles personalizados.

## 5. Datos de categoría especial

- **En estas entregas, no**, con la propuesta de `OPEN-INF-04`. Aun así, por tratarse de datos delicados de menores, **toda lectura** de parte, informe y plan se audita antes de devolver y con cupo (`RN-INF-30`, `-51`, `-63`), y sus valores no entran en `audit_logs` (`datos.md §5`).
- **`REQ-INF-006` (salud) sí lo es** y no se implementa aquí: permiso propio `is_special_category = true`, fuera de todo rol por defecto (`RPERM-012`), concedido a personas concretas, con auditoría de lectura (`RPERM-015`) y tabla cifrada. Espera a `ADR-085` (`OPEN-INF-03`).
- Si `OPEN-INF-04` se decide como B (todo el parte es salud), `agenda_infantil.*` y `adaptacion_infantil.*` pasan a `is_special_category = true`, salen de la tabla de §4 y `1.18-b`/`-d` quedan bloqueadas.

## 6. MFA

Ningún cambio en estas entregas: `mfa_obligatorio` sigue siendo atributo de cada rol. Recomendación para `1.18-h`: el rol que reciba el permiso de salud debería llevar `mfa_obligatorio` (decisión del usuario entonces).

## 7. Impersonación y soporte

Sin regla propia: cuando exista `REQ-SUP-003` (fase 2), el acceso de soporte a estos recursos se rige por él y queda auditado; `inf` no añade ninguna vía de plataforma.
