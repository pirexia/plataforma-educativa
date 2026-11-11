# REQ-ALUM · Permisos

> **Estado**: **APROBADA** (2026-11-06) junto con `funcional.md` (ver su cabecera); lo marcado [DERIVADA] o dependiente de un `OPEN-ALUM` no bloqueante sigue como propuesta. La siembra de §4 es la recomendación de `OPEN-ALUM-18`; los ámbitos son la decisión `OPEN-ALUM-16` = A; el cambio de grupo con aprobación, `OPEN-ALUM-13` = A. La fuente de verdad es `AlumServiceProvider::declaredPermissions()` (`ADR-034 §2`), que ya existe (`1.15-a`); esta tabla es su reflejo.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué así |
|---------|----------------|-------------|
| `alumno` | Faceta de alumno, ficha compuesta y fotografía | `RPERM-002` cita «alumnos». Singular, como `familia`. La foto va con el alumno: mismo dato, mismo perfil de riesgo [DERIVADA] |
| `matricula` | Matrículas, listas de clase, solicitudes de cambio de grupo | Matricular, dar de baja y mover de grupo es distinto de dar de alta una persona; separarlo permite que un rol vea fichas sin tocar matrículas |

Acciones solo de `RPERM-003`; ninguna inventada. `aprobar` es la de `RPERM-003` para el flujo de aprobación del cambio de grupo (`REQ-ALUM-003`). `resource_label_key = alum.permissions.resources.<recurso>` en los cuatro idiomas (`CA-PERM-134`).

## 2. Catálogo

`module_code = 'alum'`. `applicable_scopes` explícito en cada entrada (`ADR-044 §4.1`). Ninguno es de categoría especial.

| `code` | Acción | `is_special_category` | `applicable_scopes` | *Endpoints* (`api.md`) |
|--------|--------|-----------------------|---------------------|------------------------|
| `alumno.leer` | `leer` | `false` | `['todos','grupo','unidad_familiar']` (en `1.15-a` se declara `['todos','unidad_familiar']`; `1.15-b` añade `grupo` junto con su resolutor, ampliación compatible) | `GET /students`, `GET /students/{public_id}`, `GET /students/{public_id}/photo`. Los filtros `course`, `group` y `enrollment_status` de `GET /students` y `enrollments[]` de la ficha exigen además `matricula.leer` (M1) |
| `alumno.crear` | `crear` | `false` | `['todos']` | `POST /students` |
| `alumno.actualizar` | `actualizar` | `false` | `['todos']` | `PATCH /students/{public_id}`, `PUT`/`DELETE /students/{public_id}/photo` |
| `alumno.eliminar` | `eliminar` | `false` | `['todos']` | `DELETE /students/{public_id}`, `POST /students/{public_id}/restore`, `include_deleted` |
| `matricula.leer` | `leer` | `false` | `['todos','grupo','unidad_familiar']` | `GET /enrollments`, `GET /enrollments/{public_id}`, `GET /group-change-requests…`. Los filtros `course`, `group` y `enrollment_status` de `GET /students` y `enrollments[]` de la ficha exigen este permiso (M1). El permiso admite `unidad_familiar`, pero sobre **solicitudes** ese ámbito resuelve a conjunto vacío (lista vacía, detalle `404`) [DERIVADA: trámite interno; `RN-ALUM-46`] |
| `matricula.crear` | `crear` | `false` | `['todos']` | `POST /enrollments` |
| `matricula.actualizar` | `actualizar` | `false` | `['todos']` | `PATCH /enrollments/{public_id}`, `POST /enrollments/{public_id}/withdrawal`, `POST /group-change-requests`, `…/cancellation` |
| `matricula.eliminar` | `eliminar` | `false` | `['todos']` | `DELETE /enrollments/{public_id}` (anulación) |
| `matricula.aprobar` | `aprobar` | `false` | `['todos']` | `…/approval`, `…/rejection` (`OPEN-ALUM-13` = A) |
| `matricula.exportar` | `exportar` | `false` | `['todos']` | `POST /enrollments/exports`, `GET /data-exports/{public_id}` de tipo `alum_enrollments` |

### 2.1 Permisos que **no** se declaran

| No se declara | Motivo |
|---------------|--------|
| `alumno.exportar` | Ningún requisito pide exportar fichas; la lista de clase es `matricula.exportar` |
| `*.importar` | `REQ-ONB` (`1.24`, `OPEN-ALUM-26`) |
| `*.firmar`, `*.publicar` | Contrato y firma diferidos (`OPEN-ALUM-04`) |
| `clase`, `propios` en `applicable_scopes` | `OPEN-ALUM-16`. Añadirlos después es ampliar `applicable_scopes`, compatible |
| `grupo` en `matricula.exportar` | Un tutor de grupo exporta su lista con `todos` acotado por su rol propio si el centro quiere; ampliar es compatible (`OPEN-ALUM-18`) |
| Permisos de salud, documentos, becas | Diferidos a sus módulos (`OPEN-ALUM-02`, `-05`, `-06`) |

## 3. Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `alumno` | `todos` | `todos`, `grupo`, `unidad_familiar` | `todos` | `todos` | — | — | — | — | — |
| `matricula` | `todos` | `todos`, `grupo`, `unidad_familiar` | `todos` | `todos` | `todos` | — | `todos` | — | — |

### 3.1 Ámbito restringido: resolutores (`ADR-044 §4.2`, `ADR-063 §5.2`)

`alum` es el **dueño** de `alumno` y `matricula`; registra sus resolutores con la pertenencia que aportan `acad` (asignación docente) y `fam_unit` (familia). El núcleo no sabe qué es un grupo ni una familia.

| Par (ámbito, recurso) | Implementación | Restricción de consulta |
|-----------------------|----------------|-------------------------|
| (`grupo`, `matricula`) | Resolutor de `alum`, en `Infrastructure` | `group_id IN TeachingMembership::groupIdsFor(u, academic_year_id)` para el curso académico de la consulta (obligatorio en el listado) (`RN-ALUM-60`) |
| (`grupo`, `alumno`) | Ídem | Alumnos con matrícula `activa` viva en un grupo de `groupIdsFor(u, Y)`, `Y` según `AcademicYearContext` (`RN-ALUM-61`, `OPEN-ALUM-17`) |
| (`unidad_familiar`, `alumno`) | Ídem | `person_id IN FamilyMembership` «alumnos alcanzados» por la persona del sujeto, sin categoría; solo vínculos **activos** (`RN-ALUM-62`) |
| (`unidad_familiar`, `matricula`) | Ídem | Sobre `Enrollment`: matrículas de esos alumnos. Sobre `GroupChangeRequest`: conjunto vacío (`RN-ALUM-46`) |
| (`grupo`, `matricula`) sobre `GroupChangeRequest` | El mismo resolutor de (`grupo`, `matricula`) | `enrollment_id IN` matrículas cuyo `group_id` está en `groupIdsFor(u, academic_year_id)` de la solicitud (`RN-ALUM-46`, `CA-ALUM-039`) |

**Entregas**: `1.15-a` registra solo (`unidad_familiar`, `alumno`); `1.15-b`, (`grupo`, `alumno`) y los dos de `matricula` sobre `Enrollment`; `1.15-c`, la parte de `GroupChangeRequest`.

Sujeto sin asignación docente o sin faceta de tutor ⇒ conjunto vacío (deniega). Sin caché entre peticiones (`RN-ALUM-63`). Unión entre ámbitos de roles distintos y `deny` ciego al ámbito (`ADR-044`).

**`TeachingMembership`**: especificada y aprobada en `REQ-ACAD/funcional.md §18.7` (`1.12`, 2026-10-17), **no construida** (`OPEN-ACAD-43`). `alum` es su primer consumidor y la construye en `1.15-b` dentro de `acad`, con el resolutor `grupo`. Con eso se cumple el plazo de `ADR-063 §5.4` (asignación docente especificada) y la condición del issue #471: **ningún módulo de alumnado da `todos` a `docente`** (`RN-ALUM-65`, `CA-ALUM-014a`/`-014b`).

**Mapa cerrado de `AR-10`** (ampliación que requiere aprobación expresa del usuario, como `OPEN-ACAD-42`): `alumno` → `Student` (servicios sancionados `StudentService` y `StudentPhotoService`; `StudentPhoto` se consulta solo en este último, con el alumno acotado antes por `ScopedQuery`); `matricula` → `Enrollment` (`EnrollmentService` y `EnrollmentStructureDeletionVeto`) y `GroupChangeRequest` (`GroupChangeService` y `GroupChangeRequestStructureDeletionVeto`; los dos vetos cuentan del centro entero, sin ámbito de actor). El test no ve accesos por relación ni `DB::table()`: lo cubren `CA-ALUM-010` a `-016`, `-012` y `-039` (listado **y** detalle).

Reglas que no son permiso y se comprueban además: curso cerrado (`RN-ALUM-23`, `AcademicYearReadAccess`), autoaprobación (`RN-ALUM-44`), y la visibilidad de tutores en la ficha, que exige además `vinculo_tutor.leer` (`api.md §1`).

## 4. Asignación en roles predefinidos (`OPEN-ALUM-18`, recomendación)

**Por entrega**: `1.15-a` siembra solo `alumno.*` con `todos` (`administrador_centro`, `secretaria`, `direccion`, `administrativo`) y `alumno.leer` con `unidad_familiar` (`tutor_legal`) (`CA-ALUM-014a`); `1.15-b` siembra `matricula.*` y las concesiones de ámbito `grupo` de `docente` y `tutor_grupo`, cuando el resolutor existe (`CA-ALUM-014b`). El comando `alum:grant-permissions` es idempotente y se vuelve a ejecutar tras `1.15-b`.

Denegación por defecto (`RPERM-011`). Siembra en `ProvisionTenantDefaults` para centros nuevos y comando `alum:grant-permissions` para los existentes: idempotente, solo roles predefinidos, sin tocar personalizados, sin conceder nada si falta o está retirado algún permiso (issue #387). Ninguna regla compara estos códigos (`RN-PERM-46`, `AR-08`).

| Rol (`code`) | Permisos | Ámbito | Motivo (`§11.1`) |
|--------------|----------|--------|------------------|
| `administrador_centro` | Todos | `todos` | Gestión completa |
| `secretaria` | `alumno.*`, `matricula.leer`, `.crear`, `.actualizar`, `.eliminar`, `.exportar` | `todos` | «Matrícula, traslados, registro». Sin `aprobar`: solicita, no aprueba |
| `direccion` | `alumno.leer`, `matricula.leer`, `matricula.aprobar`, `matricula.exportar` | `todos` | «Aprobaciones» |
| `administrativo` | `alumno.leer`, `matricula.leer` | `todos` | «Matrículas, documentación» |
| `docente` | `alumno.leer`, `matricula.leer` | **`grupo`** | «Sus clases, alumnos»; nunca `todos` (`ADR-063 §5.4`) |
| `tutor_grupo` | `alumno.leer`, `matricula.leer` | **`grupo`** | «Acceso ampliado a su grupo» (la tutoría entra en `grupo`, `RN-ACAD-98`) |
| `tutor_legal` | `alumno.leer`, `matricula.leer` | **`unidad_familiar`** | «Información de los estudiantes vinculados» (API; pantallas en `1.22`) |
| Los restantes (`orientador`, `estudiante`, `conserjeria`…) | — | — | Sin base suficiente en este paso. `orientador` necesitaría `todos` o un ámbito que no existe; lo decide el usuario si lo quiere |

**Nota sobre `alumno.actualizar` y la identidad en `Core`** (`OPEN-ALUM-19` = A): `alumno.actualizar` implica una escritura **limitada sobre la identidad de la persona en `Core`**: nombre, apellidos, documento, fecha de nacimiento e idioma, también cuando la persona tiene usuario. Nunca toca credenciales, correo de acceso ni estado del usuario, y la audita `Core` como cambio de la persona. Quien concede `alumno.actualizar` concede, por tanto, esa corrección de identidad, que `usuario.actualizar` protege en `Core` para el personal.

## 5. Datos de categoría especial

**Ninguno** en `1.15`. El historial médico y las alergias de `REQ-ALUM-001` son salud y se difieren (`OPEN-ALUM-02`): cuando lleguen, permiso separado con `is_special_category = true`, fuera de todo rol por defecto (`RPERM-012`), auditoría de lectura (`RPERM-015`) y el mecanismo que exige `AR-09`.

## 6. MFA

Ningún cambio: `mfa_obligatorio` es atributo del rol (`RPERM-014`) y lo decide el centro. Observación para el usuario, no decisión: `secretaria` pasa a crear personas menores y a exportar listas; si el centro quiere MFA obligatorio en ese rol, lo activa en el editor de roles.
