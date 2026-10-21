# REQ-CURSO · Permisos

> **Estado**: **APROBADA** (2026-10-07), paso **1.10**, con los ajustes de `ADR-057` (aceptado). Nombres de recurso y siembra aprobados (`OPEN-CURSO-13`, `OPEN-CURSO-14`). **La fuente de verdad es `CursoServiceProvider::declaredPermissions()`** (`INV-007`, `ADR-034 §2`); esta tabla es su reflejo documental. **Ampliada el 2026-10-08 por `ADR-059`** (aceptado): recurso `reapertura_curso_academico` (`OPEN-059-02`, resuelta: recurso propio, sin doble confirmación); **declarado y sembrado el 2026-10-17 en `1.11c`**. **Ampliada el 2026-10-12 por `1.11-a`** (`REQ-ACAD-001`, `OPEN-ACAD-11`/`-12`, #409, #387 B-5): `GET /academic-years/selectable`, `AcademicYearDirectory` por identificador interno y comprobación de `retired_at` en `curso:grant-year-permissions`. **Ampliada el 2026-10-16 por `1.11d-a`** (`OPEN-ACAD-24` = B, aprobada por el usuario): validaciones del cambio de fechas (`funcional.md §5.6`). `1.11d-a` no añade permisos nuevos ni cambios de siembra; solo una regla más en §5. **Ampliada el 2026-10-20 por `1.12b`** (`REQ-CURSO-002`, apertura del curso siguiente; **APROBADA 2026-10-21 por el usuario, sin preguntas abiertas**, no implementada, `OPEN-CURSO-32` = A): recurso `apertura_curso_academico` (`leer`, `crear`, `eliminar`), siembra solo en `administrador_centro`, comando `curso:grant-rollover-permissions` y la autorización por elemento de §5, que con la casilla «copiar la asignación docente» (`RN-CURSO-82`) exige además los permisos de alta de asignaciones docentes, tutorías y sesiones.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué es recurso propio |
|---------|----------------|---------------------------|
| `curso_academico` | La ficha de un curso: código y fechas | Recurso principal |
| `estado_curso_academico` | El **estado** de un curso: activarlo y cerrarlo | Cerrar un curso bloquea la escritura de todos los datos de ese curso en todos los módulos del centro. Separarlo de `curso_academico.actualizar` evita que quien corrige una fecha pueda cerrar el curso de todo el centro. **No se inventa acción** (`RPERM-003` es cerrado): mismo patrón que `rol_datos_especiales` (`REQ-PERM/permisos.md §1`) |
| `curso_historico` | El acceso a datos de **cualquier módulo** pertenecientes a un curso `cerrado` o `archivado` | Lectura literal de `REQ-CURSO-001`: «el usuario **con permiso** puede consultar datos de cursos cerrados». Se exige **además** del permiso de lectura del módulo dueño del dato, nunca en su lugar (`RN-CURSO-25`, `OPEN-CURSO-16`) |
| `reapertura_curso_academico` | La **reapertura** de un curso cerrado (`cerrado → activo`, `ADR-059`) | Reabrir deshace la garantía de solo lectura de **todo** el centro para un curso entero; un centro que delega el cierre (`estado_curso_academico`) no tiene por qué delegar también deshacerlo. Mismo patrón que separó `estado_curso_academico` de `curso_academico` (`OPEN-CURSO-13`). Empezar separado es denegar por defecto: fusionarlos después es aditivo; separarlos después dejaría sin capacidad a los roles personalizados que ya la tuvieran (`ADR-059 §5.2`) |
| `apertura_curso_academico` (`1.12b`, APROBADA 2026-10-20) | La **copia** de datos de un curso a otro en `planificacion` (*rollover*, `REQ-CURSO-002`): consultarla, lanzarla y revertirla | Una copia crea de una vez la estructura, la configuración académica y los horarios de todo un curso del centro, y revertirla los borra (incluidos los cambios hechos sobre lo copiado). Ni `curso_academico.*` (la ficha del curso) ni los permisos de cada módulo expresan esa capacidad. Mismo criterio de separación que `estado_curso_academico` y `reapertura_curso_academico` (`OPEN-CURSO-32`). **No sustituye** a los permisos de los módulos: lanzar una copia exige además el permiso de alta de cada elemento seleccionado (§5) |

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
| `reapertura_curso_academico.actualizar` | `reapertura_curso_academico` | `actualizar` | `['todos']` | `POST /academic-years/{id}/reopen` (`ADR-059`). Acción de `RPERM-003`, ninguna inventada |
| `apertura_curso_academico.leer` (`1.12b`) | `apertura_curso_academico` | `leer` | `['todos']` | `GET /academic-year-rollovers`, `GET /academic-year-rollovers/{id}`; aviso de copia existente en el diálogo de reapertura |
| `apertura_curso_academico.crear` (`1.12b`) | `apertura_curso_academico` | `crear` | `['todos']` | `GET /academic-year-rollovers/elements`, `GET /academic-year-rollovers/preview`, `POST /academic-year-rollovers` (más el permiso de alta de cada elemento, §5) |
| `apertura_curso_academico.eliminar` (`1.12b`) | `apertura_curso_academico` | `eliminar` | `['todos']` | `POST /academic-year-rollovers/{id}/revert` y `POST /academic-year-rollovers/{id}/cancel` (copia huérfana, `RN-CURSO-85`; deshace una solicitud que no llegó a copiar nada). `eliminar` y no `actualizar` porque revertir deshace las altas de la copia; `RPERM-003` es cerrado (`OPEN-CURSO-32`) |

**Todos `['todos']`, y no es pereza.** Un curso es del centro entero: no existe «los cursos de mi grupo». `curso_historico.leer` con `grupo` («el histórico de mi grupo») sería tentador, pero el ámbito del **dato** lo pone el permiso del módulo dueño (`calificacion.leer` con `grupo`); `curso_historico` solo responde «¿puede asomarse a cursos cerrados?». Con `todos` en ambos, la combinación ya da «el histórico de mi grupo» sin que este módulo sepa qué es un grupo.

### 2.1 Permisos que **no** se declaran

| No se declara | Motivo |
|---------------|--------|
| `curso_academico.eliminar` | Sin borrado en 1.10 (`OPEN-CURSO-12`). Se declara con el *endpoint*, no antes: un permiso sin *endpoint* invita a concederlo creyendo que hace algo (precedente `usuario.exportar`, `REQ-PERM/permisos.md §9.4`) |
| `curso_academico.exportar` | Decenas de filas sin datos personales; ningún requisito pide exportarlos |
| `curso_historico.actualizar` / excepción de escritura en curso cerrado | No hay requisito (`OPEN-CURSO-19`, `-20`). **No existe ni existirá un permiso genérico «escribir en cursos cerrados»** (`ADR-057 §5.7` punto 4): cada excepción la declara el módulo dueño de la tabla, con su especificación aprobada, y la autoriza el *endpoint* que la usa con el permiso que defina ese módulo |
| Permisos de promoción, renovación, cierre con paquete, archivado | `REQ-CURSO-003`-`005`, fuera de alcance. Los del *rollover* (`REQ-CURSO-002`) se declaran en `1.12b` (§2) |
| `apertura_curso_academico.actualizar` / `.exportar` (`1.12b`) | Una copia no se edita (se revierte) ni se exporta; sin *endpoint*, no se declara (precedente `usuario.exportar`) |

### 2.2 *Endpoint* sin permiso (aprobado, `OPEN-CURSO-15`)

| *Endpoint* | Por qué no lleva permiso |
|------------|---------------------------|
| `GET /academic-years/current` | Devuelve **un dato del centro, no de una persona** (código y fechas del curso activo) que **todo** usuario autenticado necesita para entender su propia pantalla. Un permiso podría ponerse a `false` y dejar a un rol sin saber en qué curso trabaja (argumento de `REQ-PERM/permisos.md §2.2`). **Amplía la lista cerrada de excepciones de `AR-07a`** (33 en `1.7b`; 34 tras `current`, `1.10`; 35 con `selectable`, `1.11-a`) con esta única ruta; ampliación aprobada por el usuario el 2026-10-07 al aceptar `OPEN-CURSO-15` (`ADR-056 §3.2`) |
| `GET /academic-years/selectable` | Opciones del selector de curso académico (`1.11-a`, `REQ-ACAD-001`, `OPEN-ACAD-11`, issue #409, `CA-ACAD-043`). Devuelve datos del centro, no de una persona (código, fechas y estado de sus cursos) que todo usuario necesita para elegir en qué curso trabaja; con `curso_academico.leer` como exigencia, casi ningún rol podría usar el selector. **Amplía la lista cerrada de excepciones de `AR-07a`** (de 34 a 35 rutas) con esta única ruta, ampliación aprobada por el usuario con la especificación de `1.11`. El permiso `curso_historico.leer` no autoriza la ruta: solo decide si la lista incluye también `cerrado` y `archivado` (el mismo criterio de `RN-CURSO-25`). No concede la lectura de los datos de un curso cerrado, que sigue exigiendo `curso_historico.leer` en cada módulo (`AcademicYearReadAccess`) |

---

## 3. Matriz recurso × acción × ámbito

`—` = el permiso no existe.

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `curso_academico` | `todos` | `todos` | `todos` | — | — | — | — | — | — |
| `estado_curso_academico` | — | — | `todos` | — | — | — | — | — | — |
| `curso_historico` | — | `todos` | — | — | — | — | — | — | — |
| `reapertura_curso_academico` | — | — | `todos` | — | — | — | — | — | — |
| `apertura_curso_academico` (`1.12b`) | `todos` | `todos` | — | `todos` | — | — | — | — | — |

### 3.1 Ámbito restringido

**Ninguno.** Ningún recurso de este módulo tiene `applicable_scopes` distinto de `['todos']`: no hay resolutor de ámbito, `curso` **no** entra en el mapa cerrado de `AR-10` (`CA-CURSO-062`) y no registra nada en `ScopeResolverRegistry`.

---

## 4. Asignación en roles predefinidos (aprobada, `OPEN-CURSO-14`)

Denegación por defecto (`RPERM-011`): lo que no aparece no se concede. Se siembra en `ProvisionTenantDefaults` para centros nuevos y, para los existentes, con el mismo tipo de comando de migración de datos que usó `REQ-PERM` (`operacion.md §3`). **Ninguna regla de código compara estos códigos de rol** (`RN-PERM-46`).

| Rol (`code`) | Permisos | Ámbito | Motivo |
|--------------|----------|--------|--------|
| `administrador_centro` | Los seis (los cinco de 1.10 y `reapertura_curso_academico.actualizar`, `ADR-059 §5.2`) | `todos` | `§11.1`: «gestión completa de su tenant». Criterio constante del proyecto: las capacidades que afectan al centro entero, solo al administrador (`REQ-PERM/permisos.md §5.1`) |
| `direccion` | `curso_academico.leer`, `curso_historico.leer` | `todos` | `§11.1`: «informes académicos», que son de cursos anteriores tanto como del actual |
| `secretaria` | `curso_academico.leer`, `curso_historico.leer` | `todos` | `§11.1`: «documentación oficial, certificados, matrícula, traslados», que consultan cursos cerrados |
| Los 13 restantes | — | — | Sin base en `§11.1`. Un centro que quiera dárselo a docentes o tutores lo hace con un rol personalizado o ampliando el suyo |

**Consecuencia que conviene ver ahora**: con esta siembra, un **docente no puede consultar las calificaciones de cursos cerrados** aunque fueran sus alumnos, hasta que el centro se lo conceda. Es la opción restrictiva; la otra (dárselo a `docente`/`tutor_grupo`) es razonable y está en `OPEN-CURSO-14`. Lo mismo para familias y alumnado (`OPEN-CURSO-16`, a decidir con `REQ-FAM-PORTAL`/`REQ-EST`).

`soporte_plataforma`: sin permisos de este módulo (`REQ-PERM/permisos.md §5.4`). `super_administrador`: no es fila de `roles` (`ADR-034 §2`). La reapertura **no** tiene vía de plataforma (*backoffice*): la opción C de `ADR-059` se descartó y queda solo como ampliación aditiva futura.

**`1.12b` (APROBADA 2026-10-20)**: `administrador_centro` recibe además los tres de `apertura_curso_academico` (`leer`, `crear`, `eliminar`), con ámbito `todos`; **ningún otro rol** predefinido. Motivo: criterio constante (capacidades que afectan al centro entero, solo al administrador, `OPEN-CURSO-14`); `§11.1` no da base para `direccion` ni `secretaria`, y la copia exige además los permisos de alta de `acad`, que en la siembra solo tiene el administrador (`REQ-ACAD/permisos.md §4`, `§4.1`, `§10`). Un centro puede repartirlo con un rol personalizado. Centros nuevos: constante aparte en `ProvisionTenantDefaults` (precedente `ACADEMIC_YEAR_REOPEN_PERMISSION_GRANTS`); centros existentes: comando **`curso:grant-rollover-permissions`**, que concede **solo** esos tres, con el patrón de `curso:grant-reopen-permission` (`OPEN-CURSO-24` = b: no pisa decisiones del centro sobre los demás permisos; `operacion.md §3.3`).

**Centros ya existentes**: reciben `reapertura_curso_academico.actualizar` con `php artisan curso:grant-reopen-permission` (`OPEN-CURSO-24` = b, implementado en `1.11c`; `operacion.md §3.1`), que concede **solo** ese permiso y no reejecuta `curso:grant-year-permissions`; los centros nuevos lo reciben de `ProvisionTenantDefaults::ACADEMIC_YEAR_REOPEN_PERMISSION_GRANTS`.

---

## 5. Reglas de autorización que no son un permiso

| Regla | Dónde | Efecto |
|-------|-------|--------|
| **Curso de solo lectura** — nadie escribe datos de un curso `cerrado`/`archivado`, tenga los permisos que tenga | Toda tabla con `academic_year_id`, por el disparador de `ADR-057` (`academic_year_write_guard`), sea cual sea el camino de escritura; solo el propietario de la tabla está exento | `409 urn:pge:error:academic-year-closed` (`RN-CURSO-20`/`-21`), traducido desde `SQLSTATE` `YC001`. No es `403`: es estado del dato, no falta de permiso |
| **Lectura de curso cerrado** — exige `curso_historico.leer` **además** del permiso del módulo | Contrato `AcademicYearReadAccess`, en los *endpoints* de lectura de otros módulos | `404`, nunca `403` (no se confirma que haya datos, `ADR-038 §6.4`). Todo módulo con datos por curso lo comprueba con un criterio de aceptación de lectura denegada en listado **y** en detalle (`RN-CURSO-33`, `OPEN-057-03` resuelta) |
| **Un activo y un en planificación** | Índice único parcial + servicio | `409` |
| **Solo transiciones válidas** | Servicio de transiciones | `409 invalid_transition` |
| **Ventana de reapertura (T1)** — aun con `reapertura_curso_academico.actualizar`, solo se reabre el cerrado más reciente, sin otro curso activo y sin validaciones de reapertura fallidas (`RN-CURSO-41`, `-45`) | Servicio de transiciones, tras `FOR UPDATE` | `409 active_exists` / `reopen_not_latest` / `reopen_checks_failed`. No es `403`: es estado del centro, no falta de permiso |
| **Edición solo en `planificacion`** | Servicio | `409 not_editable` |
| **Validaciones del cambio de fechas** (`1.11d-a`) — aun con `curso_academico.actualizar`, un `PATCH` de fechas que rechace alguna validación registrada por un módulo contratado no se aplica (`RN-CURSO-49` a `-53`) | Servicio de administración de cursos, bajo `FOR UPDATE` | `409 curso.conflict.date_change_checks_failed`. No es `403`: es coherencia con datos de otro módulo, no falta de permiso. El rechazo se da **aunque el usuario no tenga ningún permiso del módulo dueño** de la validación (p. ej. sin `configuracion_academica.leer`); por eso la entrada de `errors.date_change[]` no lleva datos personales y se limita a lo necesario para entender el rechazo (`RN-CURSO-53`). Una validación nunca amplía ni restringe quién puede llamar al *endpoint* |
| **Permiso de alta de cada elemento copiado** (`1.12b`, `OPEN-CURSO-32` = A) — además de `apertura_curso_academico.crear`, simular o lanzar una copia exige el `createPermission` que declara cada elemento seleccionado (en `acad`: `estructura_academica.crear`, `estructura_academica.actualizar` para las asignaturas de cada grupo, `configuracion_academica.crear`, `horarios.crear`; y, con `copy_teaching_assignments: true`, `asignacion_docente.crear` para las asignaciones, `asignacion_docente.actualizar` para las tutorías y `horarios.crear` para las sesiones; `REQ-ACAD/funcional.md §21.2`, `RN-CURSO-82`). La casilla no tiene permiso propio **[DERIVADA]**: lo que autoriza es poder crear lo que copia. En la siembra, `direccion` tiene los de `asignacion_docente` y `horarios` (`REQ-ACAD/permisos.md §10.3`) pero no `apertura_curso_academico`, así que solo el administrador puede usarla salvo rol personalizado | Servicio de copia de `curso`, preguntando a `Core` por código de permiso con `PermissionResolver::can()` (`funcional.md §1.5`); `curso` no conoce el significado de esos códigos, solo los recibe del participante | `403 urn:pge:error:forbidden` con una entrada `curso.rollover.element_forbidden` por elemento. Sin este control, `apertura_curso_academico` sería una vía para crear estructura, escalas y horarios sin sus permisos (`INV-002`). La reversión no exige los de borrado (`RN-CURSO-78`) |
| **Destino en `planificacion`, una copia viva, sin incidencias** (`1.12b`) | Servicio de copia, bajo `FOR SHARE` del destino y `FOR UPDATE` de la copia | `409 rollover_target_not_planning` / `rollover_exists` / `rollover_blocked` / `rollover_not_revertible` / `rollover_revert_blocked`. No es `403`: es estado de los datos |
| **Origen cerrado** (`1.12b`) — copiar desde un curso `cerrado` exige `curso_historico.leer` (lectura de sus datos, `RN-CURSO-25`) | `AcademicYearReadAccess` en la simulación y la solicitud | `422 rollover_source_not_found`, indistinguible de un origen inexistente |
| **Lectura de copias de un destino cerrado** (`1.12b`) | `AcademicYearReadAccess` sobre el destino en listado y detalle (`RN-CURSO-80`) | `404` sin `curso_historico.leer` |
| **Activación con copia en vuelo** (`1.12b`, `OPEN-CURSO-36` = A) | Servicio de transiciones | `409 rollover_in_progress`, aun con `estado_curso_academico.actualizar` |
| **Trabajos en cola en nombre del solicitante** (`1.12b`, `RN-CURSO-83`) — el trabajo actúa como `created_by` (copia) o `reverted_by` (reversión), solo si sigue activo; no vuelve a autorizar (`RN-CURSO-78`) | Trabajos de `curso` | Usuario no activo ⇒ `fallido`/`revert_failure` `actor_unavailable`, sin ejecutar nada |
| **Cancelación solo de copias huérfanas** (`1.12b`, `RN-CURSO-85`) — aun con `apertura_curso_academico.eliminar`, solo en `pendiente`/`en_curso`/`revirtiendo` y pasados 30 minutos del último cambio | Servicio de copia, bajo `FOR UPDATE` de la copia | `409 rollover_not_cancellable` / `rollover_cancel_too_early` |
| **Aislamiento de tenant** | Todas las rutas | `public_id` de otro centro ⇒ `404` |
| `RPERM-013` | Concesión de cualquiera de los seis permisos a un rol | Sin cambios: nadie concede lo que no tiene. Al ser todos `todos`, sin matices de ámbito |

---

## 6. Datos de categoría especial

**Ninguno.** `academic_years` no contiene salud, NEAE ni convivencia. Los seis permisos (cinco de 1.10 y el de reapertura de `ADR-059`) llevan `is_special_category = false`; `AR-09` (cable trampa) no se activa. `curso_historico.leer` **no** da acceso a categoría especial: el dato especial de un curso cerrado sigue exigiendo su permiso especial y `special_data_access` en el rol que concede (`RPERM-012`, `ADR-044`), además de `curso_historico.leer`.

---

## 7. MFA

Ningún rol nuevo y ningún cambio de `mfa_required`. `estado_curso_academico.actualizar` y `reapertura_curso_academico.actualizar` son las capacidades más peligrosas del módulo y en la siembra aprobada solo las tiene `administrador_centro`, que ya lleva `mfa_required = true` desde 1.1. **Sin reautenticación reforzada (*step-up*)** para la reapertura: no existe el mecanismo en la aplicación de centros ni hay requisito que lo pida (`ADR-059 §5.2`, `REQ-AUTH/funcional.md`). **`1.12b`**: lo mismo para `apertura_curso_academico.*`, solo en `administrador_centro` en la siembra; sin *step-up*.

### 7.1 Datos personales que mueve la casilla de asignación docente (`1.12b`; complementa §6)

Con `copy_teaching_assignments: true` se copia el vínculo **persona del personal ↔ docencia** (asignaciones y tutorías, `REQ-ACAD/datos.md §9.6`): dato personal, no de categoría especial. No amplía quién lo ve (lo leen los mismos permisos de `acad` en el curso destino) ni lo expone `curso`: el informe y el resultado de la copia solo llevan cuentas (`RN-CURSO-73`). Se omiten los usuarios no activos (`REQ-ACAD RN-ACAD-154`).

---

## 8. Traducciones (`INV-009`, `ADR-021`)

En `lang/{es,en,de,fr}/curso.php`: las cuatro etiquetas de recurso (`curso.permissions.resources.*`, la cuarta `reapertura_curso_academico` de `ADR-059`; desde `1.12b`, cinco con `apertura_curso_academico`, `api.md §5.1`), las cuatro etiquetas de estado, y todos los mensajes de `api.md §5`. En `lang/{es,en,de,fr}/modules.php`: `modules.curso`. Paridad de claves vigilada por `AR-12`; la calidad de las cuatro traducciones, por revisión (el test no detecta un texto en español copiado a los cuatro).

---

## 9. Verificación

`funcional.md §13.4`: `CA-CURSO-060` a `CA-CURSO-065`; reapertura, `CA-CURSO-101` (`403` sin el permiso, también con `estado_curso_academico.actualizar`) y `CA-CURSO-107` (aislamiento). Además, el test de catálogo general (`PermissionCatalogTest`) debe conocer los códigos del módulo (cinco de 1.10 más `reapertura_curso_academico.actualizar`) con `module_code = 'curso'`; si falla, alguien ha declarado un permiso que esta especificación dice que no existe. **`1.12b`**: `CA-CURSO-137` (permisos propios y por elemento), `-138` (aislamiento), `-139` (lectura de curso cerrado), `-148` (catálogo y siembra), `-152` y `CA-ACAD-150` (permisos de la casilla de asignación docente), `-154` (actor de los trabajos) y `-156` (cancelación); `PermissionCatalogTest` pasa a conocer nueve códigos de `curso`. Los tres son de ámbito `todos`: `curso` sigue fuera del mapa de `AR-10` (`CA-CURSO-062`). `is_special_category = false` en los tres (§6).
