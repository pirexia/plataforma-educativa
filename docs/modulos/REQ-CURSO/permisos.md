# REQ-CURSO · Permisos

> **Estado**: **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado). Nombres de recurso y siembra aprobados (`OPEN-CURSO-13`, `OPEN-CURSO-14`). **La fuente de verdad será `CursoServiceProvider::declaredPermissions()`** (`INV-007`, `ADR-034 §2`); esta tabla es su reflejo documental.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué es recurso propio |
|---------|----------------|---------------------------|
| `curso_academico` | La ficha de un curso: código y fechas | Recurso principal |
| `estado_curso_academico` | El **estado** de un curso: activarlo y cerrarlo | Cerrar un curso bloquea la escritura de todos los datos de ese curso en todos los módulos del centro. Separarlo de `curso_academico.actualizar` evita que quien corrige una fecha pueda cerrar el curso de todo el centro. **No se inventa acción** (`RPERM-003` es cerrado): mismo patrón que `rol_datos_especiales` (`REQ-PERM/permisos.md §1`) |
| `curso_historico` | El acceso a datos de **cualquier módulo** pertenecientes a un curso `cerrado` o `archivado` | Lectura literal de `REQ-CURSO-001`: «el usuario **con permiso** puede consultar datos de cursos cerrados». Se exige **además** del permiso de lectura del módulo dueño del dato, nunca en su lugar (`RN-CURSO-25`, `OPEN-CURSO-16`) |

Acciones solo de `RPERM-003`. **No se inventa ninguna.**

---

## 2. Catálogo

`module_code = 'curso'`, `is_special_category = false` en todos (§6). `applicable_scopes` **explícito** en cada entrada (plantilla, `ADR-044 §4.1`). `resource_label_key` = `curso.permissions.resources.<recurso>` en los cuatro idiomas (`CA-PERM-134`).

| `code` | Recurso | Acción | `applicable_scopes` | Endpoints / uso |
|--------|---------|--------|---------------------|-----------------|
| `curso_academico.leer` | `curso_academico` | `leer` | `['todos']` | `GET /academic-years`, `GET /academic-years/{id}` (`GET /academic-years/current` es autoservicio, §2.2) |
| `curso_academico.crear` | `curso_academico` | `crear` | `['todos']` | `POST /academic-years` |
| `curso_academico.actualizar` | `curso_academico` | `actualizar` | `['todos']` | `PATCH /academic-years/{id}` |
| `estado_curso_academico.actualizar` | `estado_curso_academico` | `actualizar` | `['todos']` | `POST /academic-years/{id}/status` |
| `curso_historico.leer` | `curso_historico` | `leer` | `['todos']` | **Ningún *endpoint* propio**: lo comprueba el contrato `AcademicYearReadAccess` desde los *endpoints* de lectura de otros módulos (desde 1.11). En 1.10 solo lo ejercita la ruta de test del contrato (`CA-CURSO-044`) |

**Todos `['todos']`, y no es pereza.** Un curso es del centro entero: no existe «los cursos de mi grupo». `curso_historico.leer` con `grupo` («el histórico de mi grupo») sería tentador, pero el ámbito del **dato** lo pone el permiso del módulo dueño (`calificacion.leer` con `grupo`); `curso_historico` solo responde «¿puede asomarse a cursos cerrados?». Con `todos` en ambos, la combinación ya da «el histórico de mi grupo» sin que este módulo sepa qué es un grupo.

### 2.1 Permisos que **no** se declaran

| No se declara | Motivo |
|---------------|--------|
| `curso_academico.eliminar` | Sin borrado en 1.10 (`OPEN-CURSO-12`). Se declara con el *endpoint*, no antes: un permiso sin *endpoint* invita a concederlo creyendo que hace algo (precedente `usuario.exportar`, `REQ-PERM/permisos.md §9.4`) |
| `curso_academico.exportar` | Decenas de filas sin datos personales; ningún requisito pide exportarlos |
| `curso_historico.actualizar` / excepción de escritura en curso cerrado | No hay requisito (`OPEN-CURSO-19`, `-20`). **No existe ni existirá un permiso genérico «escribir en cursos cerrados»** (`ADR-057 §5.7` punto 4): cada excepción la declara el módulo dueño de la tabla, con su especificación aprobada, y la autoriza el *endpoint* que la usa con el permiso que defina ese módulo |
| Permisos de rollover, promoción, renovación, cierre con paquete, archivado | `REQ-CURSO-002`-`005`, fuera de alcance |

### 2.2 *Endpoint* sin permiso (aprobado, `OPEN-CURSO-15`)

| *Endpoint* | Por qué no lleva permiso |
|------------|---------------------------|
| `GET /academic-years/current` | Devuelve **un dato del centro, no de una persona** (código y fechas del curso activo) que **todo** usuario autenticado necesita para entender su propia pantalla. Un permiso podría ponerse a `false` y dejar a un rol sin saber en qué curso trabaja (argumento de `REQ-PERM/permisos.md §2.2`). **Amplía la lista cerrada de excepciones de `AR-07a`** (hoy 33 rutas) con esta única ruta; ampliación aprobada por el usuario el 2026-10-07 al aceptar `OPEN-CURSO-15` (`ADR-056 §3.2`) |

---

## 3. Matriz recurso × acción × ámbito

`—` = el permiso no existe.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `curso_academico` | `todos` | `todos` | `todos` | — | — | — | — | — | — |
| `estado_curso_academico` | — | — | `todos` | — | — | — | — | — | — |
| `curso_historico` | — | `todos` | — | — | — | — | — | — | — |

### 3.1 Ámbito restringido

**Ninguno.** Ningún recurso de este módulo tiene `applicable_scopes` distinto de `['todos']`: no hay resolutor de ámbito, `curso` **no** entra en el mapa cerrado de `AR-10` (`CA-CURSO-062`) y no registra nada en `ScopeResolverRegistry`.

---

## 4. Asignación en roles predefinidos (aprobada, `OPEN-CURSO-14`)

Denegación por defecto (`RPERM-011`): lo que no aparece no se concede. Se siembra en `ProvisionTenantDefaults` para centros nuevos y, para los existentes, con el mismo tipo de comando de migración de datos que usó `REQ-PERM` (`operacion.md §3`). **Ninguna regla de código compara estos códigos de rol** (`RN-PERM-46`).

| Rol (`code`) | Permisos | Ámbito | Motivo |
|--------------|----------|--------|--------|
| `administrador_centro` | Los cinco | `todos` | `§11.1`: «gestión completa de su tenant». Criterio constante del proyecto: las capacidades que afectan al centro entero, solo al administrador (`REQ-PERM/permisos.md §5.1`) |
| `direccion` | `curso_academico.leer`, `curso_historico.leer` | `todos` | `§11.1`: «informes académicos», que son de cursos anteriores tanto como del actual |
| `secretaria` | `curso_academico.leer`, `curso_historico.leer` | `todos` | `§11.1`: «documentación oficial, certificados, matrícula, traslados», que consultan cursos cerrados |
| Los 13 restantes | — | — | Sin base en `§11.1`. Un centro que quiera dárselo a docentes o tutores lo hace con un rol personalizado o ampliando el suyo |

**Consecuencia que conviene ver ahora**: con esta siembra, un **docente no puede consultar las calificaciones de cursos cerrados** aunque fueran sus alumnos, hasta que el centro se lo conceda. Es la opción restrictiva; la otra (dárselo a `docente`/`tutor_grupo`) es razonable y está en `OPEN-CURSO-14`. Lo mismo para familias y alumnado (`OPEN-CURSO-16`, a decidir con `REQ-FAM-PORTAL`/`REQ-EST`).

`soporte_plataforma`: sin permisos de este módulo (`REQ-PERM/permisos.md §5.4`). `super_administrador`: no es fila de `roles` (`ADR-034 §2`).

---

## 5. Reglas de autorización que no son un permiso

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **Curso de solo lectura** — nadie escribe datos de un curso `cerrado`/`archivado`, tenga los permisos que tenga | Toda tabla con `academic_year_id`, por el disparador de `ADR-057` (`academic_year_write_guard`), sea cual sea el camino de escritura; solo el propietario de la tabla está exento | `409 urn:pge:error:academic-year-closed` (`RN-CURSO-20`/`-21`), traducido desde `SQLSTATE` `YC001`. No es `403`: es estado del dato, no falta de permiso |
| **Lectura de curso cerrado** — exige `curso_historico.leer` **además** del permiso del módulo | Contrato `AcademicYearReadAccess`, en los *endpoints* de lectura de otros módulos | `404`, nunca `403` (no se confirma que haya datos, `ADR-038 §6.4`) Todo módulo con datos por curso lo comprueba con un criterio de aceptación de lectura denegada en listado **y** en detalle (`RN-CURSO-33`, `OPEN-057-03` resuelta) |
| **Un activo y un en planificación** | Índice único parcial + servicio | `409` |
| **Solo transiciones válidas** | Servicio de transiciones | `409 invalid_transition` |
| **Edición solo en `planificacion`** | Servicio | `409 not_editable` |
| **Aislamiento de tenant** | Todas las rutas | `public_id` de otro centro ⇒ `404` |
| `RPERM-013` | Concesión de cualquiera de los cinco permisos a un rol | Sin cambios: nadie concede lo que no tiene. Al ser todos `todos`, sin matices de ámbito |

---

## 6. Datos de categoría especial

**Ninguno.** `academic_years` no contiene salud, NEAE ni convivencia. Los cinco permisos llevan `is_special_category = false`; `AR-09` (cable trampa) no se activa. `curso_historico.leer` **no** da acceso a categoría especial: el dato especial de un curso cerrado sigue exigiendo su permiso especial y `special_data_access` en el rol que concede (`RPERM-012`, `ADR-044`), además de `curso_historico.leer`.

---

## 7. MFA

Ningún rol nuevo y ningún cambio de `mfa_required`. `estado_curso_academico.actualizar` es la capacidad más peligrosa del módulo y en la siembra aprobada solo la tiene `administrador_centro`, que ya lleva `mfa_required = true` desde 1.1.

---

## 8. Traducciones (`INV-009`, `ADR-021`)

En `lang/{es,en,de,fr}/curso.php`: las tres etiquetas de recurso (`curso.permissions.resources.*`), las cuatro etiquetas de estado, y todos los mensajes de `api.md §5`. En `lang/{es,en,de,fr}/modules.php`: `modules.curso`. Paridad de claves vigilada por `AR-12`; la calidad de las cuatro traducciones, por revisión (el test no detecta un texto en español copiado a los cuatro).

---

## 9. Verificación

`funcional.md §13.4`: `CA-CURSO-060` a `CA-CURSO-065`. Además, el test de catálogo general (`PermissionCatalogTest`) debe conocer los cinco códigos nuevos con `module_code = 'curso'`; si falla, alguien ha declarado un permiso que esta especificación dice que no existe.
