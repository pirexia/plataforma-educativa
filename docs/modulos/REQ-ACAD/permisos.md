# REQ-ACAD · Permisos

> **Estado**: **APROBADA** (2026-10-10), paso **1.11**. Recurso y siembra aprobados (`OPEN-ACAD-09` = A + X); ámbitos según `ADR-063` (ACEPTADA; `OPEN-ACAD-08` = A). La fuente de verdad será `AcadServiceProvider::declaredPermissions()` (`INV-007`, `ADR-034 §2`); esta tabla es su reflejo.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué así |
|---------|----------------|-------------|
| `estructura_academica` | Etapas, cursos de etapa, grupos, subgrupos, asignaturas y la asignación de asignaturas a grupos | Un solo recurso (`OPEN-ACAD-09` = A, aprobada): las seis entidades tienen el mismo perfil de riesgo (datos del centro, sin datos personales) y nadie ha pedido repartirlas. Un recurso llamado `grupo` se confundiría con el **ámbito** `grupo`, y uno llamado `curso` con el módulo `curso` (`funcional.md §0.1`) |
| `configuracion_academica` (`1.11d`) | Períodos lectivos y escalas de calificación | Sub-paso `1.11d` (`OPEN-ACAD-01` = A); es «configurar parámetros académicos» de `REQ-CORE-002`. Se declara con sus *endpoints* en `1.11d`, no en `1.11` |

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

## 3. Matriz recurso × acción × ámbito

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `estructura_academica` | `todos` | `todos` | `todos` | `todos` | — | — | — | — | — |

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

## 5. Reglas de autorización que no son un permiso

| Regla | Dónde | Efecto |
|-------|-------|--------|
| Módulo contratado | `module-enabled:acad`, antes del permiso (`OPEN-ACAD-10` = no esencial) | `403 module-disabled` (`RMOD-009`) |
| Código de permiso existente | `AR-07a` valida que el código de cada `permission:` está declarado en el catálogo (issue #378 B3, `CA-ACAD-039`) | Una errata en una ruta falla en CI, no en tiempo de ejecución |
| Curso académico de solo lectura | Disparador `academic_year_write_guard` | `409 academic-year-closed`, no `403` |
| Lectura de curso cerrado | `AcademicYearReadAccess` en cada lectura | `404` sin `curso_historico.leer` (`RN-CURSO-25`, `RN-ACAD-08`) |
| Aislamiento de tenant | RLS + `TenantModel` | `404` para `public_id` ajeno |
| `RPERM-013` | Concesión de los permisos de `acad` | **Cambia en `Core` (1.11-b, decisión del usuario)**: la comprobación «nadie concede lo que no tiene» ignora los permisos de módulos no contratados (inertes también para el destinatario). `acad` es no esencial y se siembra en roles predefinidos: sin este cambio, un centro sin `acad` contratado no podía asignar ni clonar `administrador_centro`, `direccion`, `secretaria`, `docente`, `tutor_grupo` ni `orientador`. Con `acad` contratado se exige igual (todos `todos`) |

## 6. Datos de categoría especial

Ninguno. `AR-09` no se activa.

## 7. MFA

Ningún cambio. Las capacidades de escritura solo las tiene, en la siembra propuesta, `administrador_centro`, que ya lleva `mfa_required = true`.

## 8. Traducciones

`lang/{es,en,de,fr}/acad.php`: etiqueta del recurso (`acad.permissions.resources.estructura_academica`), etiquetas de `stage` y `regime`, y todos los mensajes de `api.md §6`. `lang/{es,en,de,fr}/modules.php`: `modules.acad`. Paridad: `AR-12`.

## 9. Verificación

`funcional.md §13.6` (`CA-ACAD-032` a `-037`) y `§13.5` (lectura de curso cerrado, `CA-ACAD-024` a `-031`). `PermissionCatalogTest` debe conocer los cuatro códigos con `module_code = 'acad'`. El *endpoint* `GET /academic-years/selectable` (módulo `curso`, #409) no lleva permiso: amplía la lista cerrada de `AR-07a` con esa única ruta (aprobado, `CA-ACAD-043`).
