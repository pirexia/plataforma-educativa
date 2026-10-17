# REQ-ACAD · Permisos

> **`1.11d`** (APROBADA, 2026-10-16): recurso `configuracion_academica` en §1, §2.2, §3 y §4.1.
>
> **`1.12`** (**PROPUESTA**, pendiente de aprobación): recursos `asignacion_docente`, `espacios`, `horarios` y `reservas` en **§10**.
>
> **Estado**: **`1.11d-a` (períodos) y `1.11d-b` (escalas) implementadas (2026-10-17, ramas `feature/REQ-ACAD-1-11d-a-periodos` y `feature/REQ-ACAD-1-11d-b-escalas`; la segunda sin PR todavía).** **APROBADA** (2026-10-10), paso **1.11**. Recurso y siembra aprobados (`OPEN-ACAD-09` = A + X); ámbitos según `ADR-063` (ACEPTADA; `OPEN-ACAD-08` = A). La fuente de verdad será `AcadServiceProvider::declaredPermissions()` (`INV-007`, `ADR-034 §2`); esta tabla es su reflejo. **Implementado en `1.11-b`** (2026-10-12): ver `funcional.md §16` para las desviaciones aprobadas respecto a este documento.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué así |
|---------|----------------|-------------|
| `estructura_academica` | Etapas, cursos de etapa, grupos, subgrupos, asignaturas y la asignación de asignaturas a grupos | Un solo recurso (`OPEN-ACAD-09` = A, aprobada): las seis entidades tienen el mismo perfil de riesgo (datos del centro, sin datos personales) y nadie ha pedido repartirlas. Un recurso llamado `grupo` se confundiría con el **ámbito** `grupo`, y uno llamado `curso` con el módulo `curso` (`funcional.md §0.1`) |
| `configuracion_academica` (`1.11d`, APROBADA) | Períodos lectivos, escalas de calificación y sus valores o tramos | Sub-paso `1.11d` (`OPEN-ACAD-01` = A); es «configurar parámetros académicos» de `REQ-CORE-002`. Se declara con sus *endpoints* en `1.11d` (§2.2). **Comprobado el 2026-10-16**: no existe todavía en el catálogo (`AcadServiceProvider::declaredPermissions()` solo declara los cuatro de `estructura_academica`). Recurso separado y no ampliación de `estructura_academica`: la forma aprobada de §4.8/§4.9 ya lo nombra, y separa «ver la estructura» (que la siembra da a seis roles) de «configurar cómo se evalúa» |

Acciones solo de `RPERM-003`; ninguna inventada.

## 2. Catálogo

`module_code = 'acad'`, `is_special_category = false`, `applicable_scopes = ['todos']` explícito en cada entrada, `resource_label_key = acad.permissions.resources.estructura_academica` en los cuatro idiomas (`CA-PERM-134`).

| `code` | Acción | `applicable_scopes` | *Endpoints* |
|--------|--------|---------------------|-------------|
| `estructura_academica.leer` | `leer` | `['todos']` | Todos los `GET` de `api.md §2` |
| `estructura_academica.crear` | `crear` | `['todos']` | Los `POST` |
| `estructura_academica.actualizar` | `actualizar` | `['todos']` | Los `PATCH` y `PUT /groups/{id}/subjects` |
| `estructura_academica.eliminar` | `eliminar` | `['todos']` | Los `DELETE` |

`PUT /groups/{id}/subjects` exige `actualizar` y no `crear`+`eliminar` **[DERIVADA]**: es la configuración del grupo, no el alta de una entidad con identidad propia (precedente: `PUT` de roles de un usuario en `REQ-CORE`).

### 2.1 Permisos que no se declaran

| No se declara | Motivo |
|---------------|--------|
| `estructura_academica.exportar` | Ningún requisito lo pide; sin *endpoint*, un permiso invita a concederlo creyendo que hace algo (precedente `REQ-PERM/permisos.md §9.4`) |
| `estructura_academica.importar` | La importación es de `REQ-ONB` (`1.24`), que declarará lo suyo |
| Permiso para escribir en cursos cerrados | No existe ni existirá uno genérico (`ADR-057 §5.7` punto 4) |

### 2.2 Catálogo de `1.11d` (APROBADA)

Mismas propiedades: `module_code = 'acad'`, `is_special_category = false`, `applicable_scopes = ['todos']` explícito, `resource_label_key = acad.permissions.resources.configuracion_academica` en los cuatro idiomas (`funcional.md §10.5.5`). Tras `1.11d`, `acad` declara **ocho** códigos (`CA-ACAD-096`).

| `code` | Acción | `applicable_scopes` | *Endpoints* (`api.md §11.3`) |
|--------|--------|---------------------|------------------------------|
| `configuracion_academica.leer` | `leer` | `['todos']` | `GET /academic-periods`, `GET /academic-periods/{id}`, `GET /grading-scales`, `GET /grading-scales/{id}` |
| `configuracion_academica.crear` | `crear` | `['todos']` | `POST /academic-periods`, `POST /grading-scales` (incluidos sus `values`) |
| `configuracion_academica.actualizar` | `actualizar` | `['todos']` | `PATCH /academic-periods/{id}`, `PATCH /grading-scales/{id}` (incluida la sincronización de `values`, que crea y borra valores) |
| `configuracion_academica.eliminar` | `eliminar` | `['todos']` | `DELETE /academic-periods/{id}`, `DELETE /grading-scales/{id}` (borra también los valores) |

Que `PATCH` con `values` cree y borre valores con solo `actualizar` es **[DERIVADA]**, mismo razonamiento que `PUT /groups/{id}/subjects`: los valores son parte de la escala (`RN-ACAD-80`). Ni `exportar` ni `importar` (§2.1, mismo motivo). Ningún permiso para escribir en cursos cerrados.

Independencia de los dos recursos (`RN-ACAD-50`): `estructura_academica.*` no da acceso a períodos ni escalas, y `configuracion_academica.*` no da acceso a la estructura. La sección de períodos de la interfaz vive en la ficha de etapa, que exige `estructura_academica.leer`; la API de períodos no lo exige (`OPEN-ACAD-29`).

## 3. Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `estructura_academica` | `todos` | `todos` | `todos` | `todos` | — | — | — | — | — |
| `configuracion_academica` (`1.11d`) | `todos` | `todos` | `todos` | `todos` | — | — | — | — | — |

`configuracion_academica` tampoco tiene ámbito restringido: períodos y escalas son del centro entero, sin datos personales (`RN-ACAD-50`), y lo dicho en §3.1 sobre `grupo`/`clase`/`departamento` vale igual.

### 3.1 Ámbito restringido

**Ninguno en `1.11`**: `acad` no registra ningún resolutor (`ADR-063 §5.1`, `OPEN-ACAD-08` = A). Motivos:

1. **Los datos no son de nadie en concreto.** La estructura es del centro entero y no contiene datos personales (`RN-ACAD-09`). Que un docente vea todos los grupos no expone a ningún alumno; los datos de alumnos llevarán su propio permiso con ámbito en su módulo.
2. **No hay todavía con qué resolver `grupo` ni `clase`.** Sus significados están fijados en **`ADR-063 §5.3`**: `grupo` = los grupos con los que el usuario tiene asignación (tutor o docente de cualquier asignatura en él); `clase` = los pares (grupo, asignatura) que imparte. Ambos necesitan la **asignación docente**, que `REQ-ACAD-001` no pide y que nace en el paso de `ADR-063 §5.4` (candidato `1.12`; especificada y aprobada antes de aprobar la especificación de `1.13` o `1.16`). **`departamento`** queda en el vocabulario **sin dueño** ni resolutor hasta que un requisito lo defina (`ADR-063 §5.5`).
3. **Quién registrará los resolutores.** `ADR-063 §5.2` **enmienda `ADR-044 §3`**: un resolutor es por par (ámbito, recurso) y su `constrain()` recibe la consulta del recurso, así que `acad` **aporta la pertenencia** (asignación docente e interfaz en `Acad\Domain`) y **cada módulo dueño del recurso** (asistencia, calificaciones…) registra el resolutor de su par. `acad` solo registraría resolutores de sus propios recursos, y ninguno admite hoy `grupo`/`clase`.

Mientras tanto, la garantía de `ADR-044 §3` sigue en pie: un permiso de cualquier módulo con ámbito `grupo`, `clase` o `departamento` **no se puede conceder** (`422`) y, si existiera, **deniega**. Y está prohibido sustituir la falta de resolutor concediendo `todos` a `docente` en la siembra de un módulo con datos de alumnado (`ADR-063 §5.4`).

`acad` no entra en el mapa cerrado de `AR-10` ni registra nada en `ScopeResolverRegistry` (`CA-ACAD-037`).

## 4. Asignación en roles predefinidos (aprobada, `OPEN-ACAD-09` = X)

Denegación por defecto (`RPERM-011`). Se siembra en `ProvisionTenantDefaults` para centros nuevos y con un comando para los existentes (`operacion.md §3`). Ninguna regla de código compara estos códigos (`RN-PERM-46`).

| Rol (`code`) | Permisos | Ámbito | Motivo (`§11.1`) |
|--------------|----------|--------|------------------|
| `administrador_centro` | Los cuatro | `todos` | «Gestión completa de su tenant»; criterio constante: lo que afecta al centro entero, solo al administrador (`OPEN-CURSO-14`) |
| `direccion` | `leer` | `todos` | «Informes académicos, horarios» |
| `secretaria` | `leer` | `todos` | «Matrícula, traslados»: necesita ver grupos |
| `docente` | `leer` | `todos` | «Sus clases»: ver la estructura no es ver alumnos |
| `tutor_grupo` | `leer` | `todos` | Ídem |
| `orientador` | `leer` | `todos` | Trabaja con grupos y etapas |
| Los 10 restantes | — | — | Sin base en `§11.1`; familias y alumnado verán su grupo desde sus portales con su propio ámbito |

Se descartó la alternativa Y (`direccion` con los cuatro); el centro puede hacerlo con un rol propio. Los centros existentes reciben la siembra con `acad:grant-structure-permissions` (`operacion.md §3`), que no concede nada si algún permiso está retirado (issue #387, `CA-ACAD-036`).

`soporte_plataforma`: nada (`REQ-PERM/permisos.md §5.4`). Super Administrador: no es fila de `roles`.

### 4.1 Siembra de `configuracion_academica` (`1.11d`, `OPEN-ACAD-28` = X + 1, decidida)

El usuario decidió **X + 1** en `OPEN-ACAD-28` el 2026-10-16 (`funcional.md §15.2`).

| Rol (`code`) | Permisos | Ámbito | Motivo |
|--------------|----------|--------|--------|
| `administrador_centro` | Los cuatro (`leer`, `crear`, `actualizar`, `eliminar`) | `todos` | Lo que afecta al centro entero, al administrador (`OPEN-CURSO-14`, `OPEN-ACAD-09`) |
| `direccion` | `leer` | `todos` | «Informes académicos» |
| `secretaria` | `leer` | `todos` | Documentos que citarán períodos y escalas |
| `docente` | `leer` | `todos` | Calificar en `1.16` con la escala y el período; ver la configuración no es ver alumnos |
| `tutor_grupo` | `leer` | `todos` | Ídem |
| `orientador` | `leer` | `todos` | Ídem |
| Los 10 restantes | — | — | Sin base en `§11.1` |

**Dónde se siembra**: en `ProvisionTenantDefaults` (centros nuevos) y, para los existentes, con el comando `acad:grant-structure-permissions` (en `Core`, `INV-007`), que se amplía para sembrar los **ocho** códigos de `acad`, con las mismas garantías (idempotente, solo roles predefinidos, no toca roles personalizados, solo centros `Activo`, y **no concede nada** si alguno de los ocho falta en `permissions` o tiene `retired_at` informado, issue #387). `RN-PERM-46`: ninguna regla de código compara códigos de rol.

**`RPERM-013`** (nadie concede lo que no tiene): sin cambios; la acotación de `1.11-b` (§5) cubre igual los permisos de `configuracion_academica` de un rol que ya los contiene en un centro sin `acad` contratado.

**Categoría especial**: no aplica. Períodos y escalas no contienen datos personales; `is_special_category = false` y `AR-09` no se activa. Las **calificaciones** que usarán estas escalas sí serán datos personales de menores y llevarán su propio permiso con ámbito en `CALIF` (`1.16`), no este.

**MFA**: sin cambios (§7): las capacidades de escritura solo las tiene, en la siembra, `administrador_centro`.

## 5. Reglas de autorización que no son un permiso

| Regla | Dónde | Efecto |
|-------|-------|--------|
| Módulo contratado | `module-enabled:acad`, antes del permiso (`OPEN-ACAD-10` = no esencial) | `403 module-disabled` (`RMOD-009`) |
| Código de permiso existente | `AR-07a` valida que el código de cada `permission:` está declarado en el catálogo (issue #378 B3, `CA-ACAD-039`) | Una errata en una ruta falla en CI, no en tiempo de ejecución |
| Curso académico de solo lectura | Disparador `academic_year_write_guard` | `409 academic-year-closed`, no `403` |
| Lectura de curso cerrado | `AcademicYearReadAccess` en cada lectura | `404` sin `curso_historico.leer` (`RN-CURSO-25`, `RN-ACAD-08`) |
| Aislamiento de tenant | RLS + `TenantModel` | `404` para `public_id` ajeno |
| `RPERM-013` | Concesión de los permisos de `acad` | **Acotada en `Core` (1.11-b, decisión del usuario)**; fuente: `REQ-PERM/funcional.md §8`. Al asignar o clonar un rol que **ya contiene** permisos de `acad` sin que el centro haya contratado el módulo (o de cualquier otro módulo no contratado, no retirado), esos permisos —inertes también para el destinatario— no se exigen al asignador. Se exigen igual con un `deny` explícito suyo (`RPERM-007`), con un código desconocido o retirado, y al **introducir un permiso inerte nuevo en un rol** (lista explícita, `PUT` de concesiones), que exige poseerlo: evita una escalada latente al contratar. Motivo: `acad` es no esencial y se siembra en `administrador_centro`, `direccion`, `secretaria`, `docente`, `tutor_grupo` y `orientador`; sin esto no se podían asignar ni clonar sin contratarlo. Con `acad` contratado se exige todo igual (todos `todos`) |

## 6. Datos de categoría especial

Ninguno. `AR-09` no se activa.

## 7. MFA

Ningún cambio. Las capacidades de escritura solo las tiene, en la siembra decidida, `administrador_centro`, que ya lleva `mfa_required = true`.

## 8. Traducciones

`lang/{es,en,de,fr}/acad.php`: etiqueta del recurso (`acad.permissions.resources.estructura_academica`), etiquetas de `stage` y `regime`, y todos los mensajes de `api.md §6`. `lang/{es,en,de,fr}/modules.php`: `modules.acad`. Paridad: `AR-12`. `1.11d` añade `acad.permissions.resources.configuracion_academica` («Configuración académica», «Academic settings», «Akademische Einstellungen», «Paramètres académiques») y los mensajes de `api.md §11.7`.

## 9. Verificación

`funcional.md §13.6` (`CA-ACAD-032` a `-037`) y `§13.5` (lectura de curso cerrado, `CA-ACAD-024` a `-031`). `1.12` (PROPUESTA): `funcional.md §18.9` (`CA-ACAD-129`, `-131` a `-133`, `-139`). `1.11d`: `§13.14` (`CA-ACAD-090` a `-092`) y `§13.15` (`CA-ACAD-093` a `-097`); `PermissionCatalogTest` debe conocer los ocho códigos con `module_code = 'acad'` (los cuatro de `estructura_academica` y los cuatro de `configuracion_academica`). El *endpoint* `GET /academic-years/selectable` (módulo `curso`, #409) no lleva permiso: amplía la lista cerrada de `AR-07a` con esa única ruta (aprobado, `CA-ACAD-043`).

---

## 10. `1.12` · Horarios y asignación docente (**PROPUESTA**)

Pendiente de aprobación; la siembra y el ámbito `propios` dependen de `OPEN-ACAD-42` (`funcional.md §18.11`), cuya recomendación se aplica aquí.

### 10.1 Recursos y catálogo

`module_code = 'acad'`, `is_special_category = false`, etiqueta `acad.permissions.resources.<recurso>` en los cuatro idiomas. Acciones solo de `RPERM-003`.

| Recurso | Qué representa | Acciones | `applicable_scopes` |
|---------|----------------|----------|---------------------|
| `asignacion_docente` | Asignaciones docentes y tutorías de grupo | `leer`, `crear`, `actualizar` (solo `PUT /groups/{id}/tutors`), `eliminar` | `['todos']` |
| `espacios` | Aulas y recursos | `leer`, `crear`, `actualizar`, `eliminar` | `['todos']` |
| `horarios` (nombrado en `RPERM-002`) | Marcos, franjas y sesiones | `leer`, `crear`, `actualizar`, `eliminar` | `['todos']` |
| `reservas` (`1.12-b`) | Reservas de espacios | `leer`, `crear`, `eliminar` | `leer`, `crear`: `['todos']`; `eliminar`: **`['todos', 'propios']`** |

Escribir franjas (`slots` dentro del marco) exige el permiso de la operación sobre el marco (precedente `RN-ACAD-50`). **No se declaran** `horarios.exportar` ni `horarios.importar` hasta que exista la exportación PDF o la importación (`OPEN-ACAD-38`, `-39`; precedente §2.1), ni `reservas.actualizar` (las reservas no se editan, `RN-ACAD-142`).

**Por qué cuatro recursos**: perfiles distintos. La asignación docente relaciona personas con grupos y determinará ámbitos de otros módulos (`ADR-063`); los espacios son un catálogo de administración; los horarios se consultan por casi todos; las reservas las hace el profesorado. Un solo recurso obligaría a dar escritura de horarios a quien solo debe reservar.

### 10.2 Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `asignacion_docente` | `todos` | `todos` | `todos` | `todos` | — | — | — | — | — |
| `espacios` | `todos` | `todos` | `todos` | `todos` | — | — | — | — | — |
| `horarios` | `todos` | `todos` | `todos` | `todos` | — (`OPEN-ACAD-38`) | — (`OPEN-ACAD-39`) | — | — | — |
| `reservas` (`1.12-b`) | `todos` | `todos` | — | `todos`, `propios` | — | — | — | — | — |

**Ámbito `propios` en `reservas.eliminar`** (`1.12-b`): `acad` registra el resolutor (`propios`, `reservas`) en `ScopeResolverRegistry`, que restringe a `created_by = usuario` con `ScopedQuery`. `ADR-063 §5.2` punto 3 lo permite (recurso propio de `acad`), pero **amplía el mapa cerrado de `AR-10`**, que solo se amplía con aprobación expresa del usuario: la pide `OPEN-ACAD-42`. Con ámbito `propios`, una reserva ajena responde `404` en `DELETE` (y en `GET` de detalle si el `leer` también fuera `propios`, que no se propone).

**`grupo` y `clase`**: ningún recurso de `1.12` los admite. `acad` aporta la pertenencia (`funcional.md §18.7`) y los registra cada módulo dueño de recurso. **Ningún recurso de `1.12` contiene datos de alumnado**, así que dar `todos` a `docente` en `horarios.leer` no es la sustitución prohibida por `ADR-063 §5.4`.

### 10.3 Siembra en roles predefinidos (`OPEN-ACAD-42` = X, recomendada)

| Rol | `asignacion_docente` | `espacios` | `horarios` | `reservas` (`1.12-b`) |
|-----|----------------------|------------|------------|-----------------------|
| `administrador_centro` | los cuatro | los cuatro | los cuatro | los tres, `todos` |
| `direccion` | los cuatro | `leer` | los cuatro | los tres, `todos` |
| `secretaria` | `leer` | `leer` | `leer` | `leer` |
| `docente` | `leer` | `leer` | `leer` | `leer`, `crear`; `eliminar` con `propios` |
| `tutor_grupo` | `leer` | `leer` | `leer` | ídem `docente` |
| `orientador` | `leer` | `leer` | `leer` | `leer` |
| Los 10 restantes | — | — | — | — |

Motivo de `direccion` con escritura: `§11.1` («Dirección / Jefatura de Estudios: … horarios»); difiere del criterio «solo el administrador» de `OPEN-CURSO-14`/`OPEN-ACAD-28` porque aquí el requisito nombra el rol. Estudiante y familia verán horarios desde sus portales (`1.22`, `1.23`) con su propio ámbito; nada en `1.12`. Centros nuevos: `ProvisionTenantDefaults`; existentes: `acad:grant-structure-permissions` ampliado a los códigos nuevos, con sus garantías (idempotente, solo roles predefinidos, solo centros `Activo`, nada si algún código falta o está retirado; precedente `OPEN-ACAD-28` = 1).

**MFA** (`§7`): `direccion` gana capacidades de escritura; si `direccion` no lleva ya `mfa_required`, **no** se cambia aquí (es atributo del rol, `REQ-AUTH-003`): se señala para que el usuario lo valore.

**`RPERM-013`**: sin cambios; la acotación de §5 cubre los permisos nuevos en centros sin `acad`.

### 10.4 Reglas de autorización que no son un permiso

Las de §5 (módulo contratado, código existente, curso de solo lectura, lectura de curso cerrado, aislamiento). Además: asignarse docencia o tutoría **no concede permisos ni roles** (`RN-ACAD-92`); que un usuario sea tutor no le da el rol `tutor_grupo`.
