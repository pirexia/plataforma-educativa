# Matriz «flujo × rol × cobertura E2E real» (`E2E-CI-d0`, #737)

| Campo | Valor |
|-------|-------|
| Ubicación | `docs/proceso/e2e-real-ci-matriz.md`. Anexo de `docs/proceso/e2e-real-ci.md` (`§6`, `§9`); **no es un módulo ni un requisito nuevo** |
| Entrega | `E2E-CI-d0` (sin código). Inventario de `explorer`; roles, prioridad, partición y criterios de `spec-writer` |
| Estado | **BORRADOR**, pendiente de aprobación del usuario (2026-11-18). Nada implementado |
| Clasificación del paso: ordinario | `-d0` no toca código, guiones, flujos ni `e2e-real-tenant.php`. La clasificación de `-d1`..`-d5` está en `§4.2` (críticas hasta resolver `OPEN-E2ECI-21`) |
| Fuentes | `apps/web/src/modules/*/shell.ts` (rutas y `meta.permissions`); `docs/modulos/REQ-{AUTH,CORE,CURSO,ACAD,FAM-UNIT,PRL,ALUM}/permisos.md` (siembra de roles predefinidos); `apps/api/tests/Support/e2e-real-tenant.php` (usuarios del guion); `apps/web/e2e/e2e-real-esperados.json` (recuento); cabeceras de los *specs* reales citados |

> Convención (la de `e2e-real-ci.md`): **V** = comprobado leyendo el fichero en esta sesión; **V-cab** = comprobado solo por el comentario de cabecera o el título del *spec*, no por su cuerpo; **NV** = no verificado. **[DERIVADA]** = propuesta de esta especificación sin requisito o ADR detrás, con su motivo.
>
> **Límite de esta pasada**: esta sesión no tiene `Bash` ni búsqueda. **V** (leído entero): los siete `shell.ts`, los siete `permisos.md`, `e2e-real-tenant.php`, `e2e-real-esperados.json` y las líneas 110-181 de `apps/web/scripts/e2e-real-api.sh`. **V** (fragmentos): `alum-screens-real.spec.ts` l.1-120 y l.170-219; `prl-door-real.spec.ts` l.1-80, l.150-165 y l.390-410; `core-roles.spec.ts` l.1-60 y l.420-497. **V-cab** (solo cabecera): `alum-students-real`, `alum-group-change-real`, `data-table-real`, `fam-unit-pickup-real`. **No ha leído `§11.1` del documento de requisitos**: la lista de roles predefinidos sale de `REQ-CORE/permisos.md §4.1`, que declara reflejar `§11.1` (16 filas de `roles` más `super_administrador`, que no es fila). Los *specs* simulados solo se citan por nombre (como en `e2e-real-ci.md §6.3`).

---

## 1. Usuarios que crea el guion (V, `e2e-real-tenant.php`)

| Modo | Suites que lo usan | Usuarios con contraseña conocida | Observaciones |
|------|--------------------|----------------------------------|---------------|
| `setup` sin modo | `core`, `acad-*`, `curso-rollover` (**V**, `e2e-real-api.sh` l.155-170, modo `-`) | `administrador_centro` (con **exención de MFA viva de 7 días**) | Crea además `e2e-docente` y `e2e-docente2` **sin rol y sin contraseña fijada** (**NV** la contraseña por defecto de la fábrica): no sirven para iniciar sesión |
| `door` | `prl` (dos centros) y `fam-unit` (un centro; **V**, `e2e-real-api.sh` l.175, que exporta `E2E_SECRETARIA_EMAIL` y `E2E_STAFF_PASSWORD` solo en este modo, l.119-125) | administrador, `conserjeria_pas`, `secretaria` | Contrata `acad`, `fam_unit`, `prl`. Dos personas alumno sin usuario |
| `alum` | `alum-http`, `alum-browser` (dos centros) | administrador, `tutor_legal`, `docente` ×2 (con rol), `secretaria`, `direccion` | Contrata `acad`, `alum`, `fam_unit` (**no** `prl`) |
| `showcase` | `landing-shots` (no es E2E real del CI) | — | Fuera de esta matriz |

**Roles predefinidos que ningún modo crea** como usuario: `administrativo`, `tutor_grupo`, `orientador`, `coordinador_bienestar`, `estudiante`, `responsable_economico`, `bibliotecario`, `monitor_extraescolares`, `personal_sanitario`, `soporte_plataforma`. De ellos, con permisos sembrados en los módulos de esta matriz solo tienen `administrativo` (`usuario.leer`, `familia.leer`, `vinculo_tutor.leer`, `alumno.leer`, `matricula.leer`), `tutor_grupo` (igual que `docente`) y `orientador` (lecturas de `acad`) (V, `permisos.md`). `OPEN-E2ECI-19`.

**Consecuencia directa**: en ningún modo hay un usuario **obligado a MFA sin exención** (el único rol obligado sembrado, `administrador_centro`, la lleva), así que el alta de MFA, el segundo factor y el muro de MFA **no son alcanzables** con los datos de hoy (`OPEN-E2ECI-17`).

---

## 2. Columnas y códigos

Columnas de `e2e-real-ci.md §6.1`, más dos: **Hueco** (`C` cubierto en E2E real, `P` parcial, `N` sin E2E real, `X` E2E real no viable hoy) y **Prio./Entrega** (`P1` datos de menores o decisión de permisos o de acceso, `P2` escritura, `P3` lectura; `OPEN-E2ECI-12` = A; y la entrega de `§4` que lo cubre).

*Interacción cubierta* (códigos de `§6.1`): `M` menú y migas, `T` tabla, `F` formulario con validación de servidor, `D` diálogo, `S` *scroll* y foco, `I` idioma, `C` tema, `A` accesibilidad, `R` responsive. Solo se anota lo leído (**V** o **V-cab**); el resto es **NV**. En lo leído no aparece `I` ni `C`; `R` (320 px) sí aparece en E2E real: `alum-screens-real` l.196-217 (`CA-ALUM-071`, tres rutas de `alum`, **V**) y `acad-scales` (**V** por el comentario de `e2e-real-api.sh` l.161; `acad-periods` lo cita `doc-reviewer`, **NV** en esta sesión). No es una búsqueda exhaustiva.

*Roles que lo ejercen*: concesión por defecto en la siembra (`permisos.md` de cada módulo, **V**). `(uf)` = ámbito `unidad_familiar`; `(grupo)` = ámbito `grupo`; `(propios)` = ámbito `propios`; sin marca, `todos`. «Autenticado» = cualquier usuario con sesión (autoservicio por identidad). Nunca se ha deducido un rol por el nombre de la pantalla.

---

## 3. Matriz

### 3.1 `auth`

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| A-01 | `login` | Inicio de sesión correcto; credencial errónea (respuesta genérica); bloqueo tras fallos consecutivos (`423`) | pública | Todos | Solo el camino feliz, como paso previo de los *specs* de navegador (**V** `prl-door-real` `signIn`; `core-scroll-real` según `§6.3`) | Por nombre: `apps/web/e2e/public-auth-shell.spec.ts` | `F` parcial | P | P1 · `-d3` |
| A-02 | `password-reset-request`, `password-reset` | Recuperar y restablecer la contraseña por enlace de correo | pública (token) | Todos | Ninguno | NV | — | N | P1 · `-d3` (cond. `OPEN-E2ECI-16`) |
| A-03 | `account-unlock` | Desbloqueo por enlace de correo | pública (token) | Todos | Ninguno | NV | — | N | P1 · `-d3` (cond. `-16`) |
| A-04 | `invitation-redemption` | Canje de invitación y primer acceso | pública (token) | Usuario invitado | Ninguno | NV | — | N | P1 · `-d3` (cond. `-16`) |
| A-05 | `oauth-google-callback` | Acceso con Google | pública | Todos | Ninguno | NV | — | X | P1 · ninguna (`OPEN-E2ECI-18`) |
| A-06 | `oauth-sso-callback` | Acceso por SSO institucional (OIDC/SAML) | pública | Todos | Ninguno | NV | — | X | P1 · ninguna (`-18`) |
| A-07 | `password-change` | Cambio de contraseña propia | `[]` | Autenticado | Ninguno | NV | — | N | P2 · `-d4` |
| A-08 | `sessions` | Ver y cerrar sesiones propias | `[]` | Autenticado | Ninguno | Por nombre: `auth-sessions.spec.ts` | — | N | P2 · `-d4` |
| A-09 | `mfa-security` | Alta de TOTP, códigos de respaldo, baja del factor | `[]` | Autenticado; obligados por rol: `administrador_centro`, `soporte_plataforma` (`REQ-CORE/permisos.md §4.2`) | Ninguno | NV | — | N | P1 · `-d3` (cond. `-17`) |
| A-10 | `mfa-enrollment-wall` | Muro de alta de MFA (usuario obligado sin factor, gracia vencida) | `[]` (`bare`) | Usuarios con rol obligado | Ninguno: inalcanzable, el administrador del guion está exento (`§1`) | NV | — | N | P1 · `-d3` (cond. `-17`) |
| A-11 | `login` (paso 2) | Inicio de sesión con segundo factor | pública | Usuarios con factor | Ninguno | NV | — | N | P1 · `-d3` (cond. `-17`) |
| A-12 | `mfa-administration` | Cumplimiento, restablecimiento de MFA de otro, conceder y revocar exenciones | `mfa.leer` y otros cinco (anyOf) | Solo `administrador_centro` (`REQ-AUTH/permisos.md §D.6.3`) | Ninguno | NV | — | N | P1 · `-d3` (exenciones sin cond.; restablecimiento cond. `-17`) |
| A-13 | `sso-administration`, `-new`, `-edit` | Catálogo de proveedores de identidad | `proveedor_identidad.leer` | Solo `administrador_centro` (`§F.7`) | Ninguno. No viable el alta: exige descubrimiento contra un emisor y las guardas `RN-AUTH-113` rechazan destinos internos | NV | — | X | P1 · `-d3` solo listado y validación (cond. `-18`) |

### 3.2 `core`

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| C-01 | `core-users` | Listado de usuarios con filtros | `usuario.leer` | `administrador_centro`, `direccion`, `secretaria`, `administrativo` | `core-scroll-real` (`M`, `S`, `T` parcial; `e2e-real-ci.md §6.3`); actor NV | Por nombre: `core-users.spec.ts` | `M`, `S`, `T` parcial | P | P3 · `-d5` |
| C-02 | `core-user-new` | Alta de usuario con roles (`RPERM-013`: nadie concede lo que no tiene) | `usuario.crear` | Solo `administrador_centro` (alta y `asignacion_rol.crear`) | Ninguno | NV | — | N | P1 · `-d1` |
| C-03a | `core-user-detail`, `core-user-edit` | Ver y editar datos de un usuario | `usuario.leer` / `.actualizar` | Ver: los cuatro de C-01; editar: `administrador_centro` | Ninguno | NV | — | N | P2 · `-d4` |
| C-03b | `core-user-detail` | Desactivar, dar de baja y restaurar, con «no a uno mismo» y «último administrador» (`RN-CORE-06`/`-07`, `409`) | `usuario.actualizar` / `.eliminar` | `administrador_centro` | Ninguno | NV | — | N | P1 · `-d1` |
| C-04 | `core-user-detail` | Cambiar los roles de un usuario | `asignacion_rol.crear`/`.eliminar` | `administrador_centro` | `core-roles` (`CA-PERM-133`, **V** l.461-473): el administrador **añade** un rol personalizado desde la ficha del usuario (región «Roles», guardar). Retirar un rol, sin E2E real | `core-roles.spec.ts` (parte simulada) | `F` | P | P1 · `-d1` (retirada y alta con rol, C-02) |
| C-05 | `core-user-effective-permissions` | Permisos efectivos de un usuario | `permiso_efectivo.leer` | `administrador_centro` | `core-roles` (`CA-PERM-133`, **V** l.475-480): un permiso **permitido** con ámbito `propios` y su procedencia. Sin E2E real: denegación (`deny`) y multi-rol (`RPERM-007`) | `core-roles.spec.ts` (`CA-PERM-132`, **V-cab**) | `A` (teclado) solo simulado | P | P1 · `-d1` (solo `deny`/multi-rol) |
| C-06 | `core-invitations` | Listar, reenviar y revocar invitaciones | `invitacion.leer` | Ver: `administrador_centro`, `secretaria`; reenviar y revocar: `administrador_centro` | Ninguno | NV | — | N | P1 · `-d1` (el canje es A-04) |
| C-07 | `core-user-imports`, `-detail` | Importación de usuarios por fichero (cola) | `usuario.importar` | `administrador_centro` | Ninguno | NV | — | N | P2 · `-d4` |
| C-08 | `core-roles` y sus cinco sub-rutas | Alta, clonado, edición y concesiones de un rol | `rol.leer` / `.crear` / `.actualizar` | Ver: `administrador_centro`, `direccion`; escribir: `administrador_centro` | `core-roles` (`CA-PERM-133`, **V** l.430-496: alta, concesión con ámbito, asignación, permisos efectivos, control de categoría especial). Clonado y borrado, NV; `R` y `A` solo en simulado | `core-roles.spec.ts` (`CA-PERM-128` 320 px, `CA-PERM-132` teclado; **V-cab**) | `F` | C | P1 · `-d5` solo `R` en real |
| C-09 | `core-audit` | Consultar, filtrar y exportar auditoría | `auditoria.leer` | `administrador_centro` | Ninguno | Por nombre: `core-audit.spec.ts` | — | N | P2 · `-d4` (exportar) |
| C-10 | `core-settings` | Ver y editar configuración del centro | `configuracion.leer` | Ver: `administrador_centro`, `direccion`; editar: `administrador_centro` | Ninguno | Por nombre: `core-settings.spec.ts` | — | N | P2 · `-d4` |
| C-11 | `core-branding-assets` | Sustituir y eliminar activos de marca (fichero, Garage) | `configuracion.leer` | Escribir: `administrador_centro` | Ninguno | NV | — | N | P2 · `-d4` |
| C-12 | `core-modules` | Módulos contratados (solo lectura) | `modulo.leer` | `administrador_centro`, `direccion` | Ninguno | NV | — | N | P3 · `-d5` |
| C-13 | `core-profile` | Editar correo y teléfono de contacto propios | `[]` | Autenticado | Ninguno | NV | — | N | P2 · `-d4` |
| C-14 | `home` + menú | Menú y accesos directos según `/me.permissions`; ruta sin permiso ⇒ «sin acceso» sin montar la vista (`CA-CORE-095`, `-100`) | autenticado | Todos | Ninguno por rol (ningún *spec* leído compara menús entre roles) | Por nombre: `apps/web/e2e/shell.spec.ts` (**V**: simulado con `page.route`, `REQ-CORE/funcional.md §18`/`§12.11`) | — | N | P1 · `-d1` |

### 3.3 `curso`

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| U-01 | `curso-academic-years`, `-detail` | Listado y ficha de cursos | `curso_academico.leer` | `administrador_centro`, `direccion`, `secretaria` | `curso-academic-years` (`CA-CURSO-086`); actor NV | Parte simulada del mismo fichero (NV) | NV | P | P3 · `-d5` (lectura por roles no administradores) |
| U-02 | `curso-academic-year-new`, `-edit` | Alta y edición de un curso | `curso_academico.crear` / `.actualizar` | `administrador_centro` | NV (contenido de `CA-CURSO-086` no leído) | NV | NV | P | P2 · `-d4` |
| U-03 | `curso-academic-year-detail` | Activar, cerrar y reabrir un curso | (acción) `estado_curso_academico.actualizar`, `reapertura_curso_academico.actualizar` | `administrador_centro` | El cierre se hace por la API en `alum-group-change-real` (**V-cab**: «el último test cierra el curso activo»); por pantalla, NV; reapertura, ninguno | NV | — | P | P2 · `-d4` |
| U-04 | `curso-rollover-wizard`, `-detail` | Apertura del curso siguiente (copia en cola) | `apertura_curso_academico.crear` / `.leer` | `administrador_centro` | `curso-rollover` (`CA-CURSO-147`) | NV | NV | C | — |

### 3.4 `acad`

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| D-01 | `acad-structure` y cuatro fichas | Estructura académica | `estructura_academica.leer` | Ver: `administrador_centro`, `direccion`, `secretaria`, `docente`, `tutor_grupo`, `orientador`; escribir: `administrador_centro` | `acad-structure` (`CA-ACAD-053`), `acad-periods` (`CA-ACAD-101`, ficha de etapa); actor: el administrador (único usuario del modo por defecto, `§1`). Falta: roles de solo lectura | NV | NV | P | P1 · `-d1` (lectura sin acciones de escritura para quien no las tiene) |
| D-02 | `acad-grading-scales` y tres sub-rutas | Escalas de calificación | `configuracion_academica.leer` / `.crear` / `.actualizar` | Igual que D-01 | `acad-scales` (`CA-ACAD-101`) | NV | `R` (320 px, **V** por `e2e-real-api.sh` l.161) | C | — |
| D-03 | `acad-timetable`, `acad-timetable-frame` | Horarios, marcos y franjas | `horarios.leer` | Ver: los seis de D-01; escribir: `administrador_centro`, `direccion` (`REQ-ACAD/permisos.md §10.3`) | `acad-timetable` (`CA-ACAD-135`); solo administrador. Falta la escritura por `direccion` | NV | NV | P | P2 · `-d4` |
| D-04 | `acad-facilities` | Alta, edición y baja de espacios | `espacios.leer` | Ver: los seis de D-01; escribir: `administrador_centro` | NV (`acad-timetable` crea un aula; si por pantalla, NV) | NV | — | N | P2 · `-d4` |
| D-05 | `acad-bookings` | Reservar y cancelar; `reservas.eliminar` con ámbito `propios` (`CA-ACAD-139`) | `reservas.leer` | Ver: los seis; crear: `administrador_centro`, `direccion`, `docente`, `tutor_grupo`; cancelar: `administrador_centro`, `direccion` (todos), `docente`, `tutor_grupo` (propios) | Ninguno | NV | — | N | P1 · `-d1` |

### 3.5 `fam-unit` (pantallas de personal)

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| F-01 | `fam-unit-list`, `fam-unit-detail` | Alta de unidad familiar, tutores y vínculos tutor ↔ alumno | `familia.leer` | Ver: `administrador_centro`, `secretaria`, `direccion`, `administrativo`; escribir: `administrador_centro`, `secretaria` | Solo la sección «Personas autorizadas» de la ficha (F-02); el alta de la unidad se hace por la API en el *spec* | Por nombre: `fam-unit-staff.spec.ts` | — | N | P1 · `-d2` |
| F-02 | `fam-unit-detail` (sección) | Personas autorizadas a recoger: alta de tercero, fotografía, retirada | `familia.leer` (+ `autorizado_recogida.*` en la acción) | `administrador_centro`, `secretaria` (los cuatro); `direccion` leer | `fam-unit-pickup-real` (`CA-FAM-UNIT-142`/`-141`, **V-cab**); actor de navegador `secretaria` del modo `door` (**V**: la suite usa `door`, `§1`) | NV | `F`, `A` (aserciones explícitas) | C | — |
| F-03 | `fam-unit-detail` | Revocar y levantar el acceso de un tutor | `familia.leer` (+ `revocacion_tutor.crear`/`.eliminar`) | Solo `administrador_centro`; ver revocados: `administrador_centro`, `secretaria`, `direccion` | Por la API: `alum-students-real` revoca y comprueba `404` del tutor (**V-cab** `CA-ALUM-012`); por pantalla, ninguno | NV | — | P | P1 · `-d2` |
| F-04 | `fam-unit-consents` | Consentimientos de imagen: ver, registrar decisión presencial, exportar | `consentimiento_imagen.leer` | Ver: `administrador_centro`, `secretaria`, `direccion`; registrar y exportar: `administrador_centro`, `secretaria` | Ninguno | NV | — | N | P1 · `-d2` |
| F-05 | `fam-unit-terms` | Redactar, publicar y retirar textos legales (condiciones y consentimiento por nivel) | `texto_legal.leer` | Ver: `administrador_centro`, `secretaria`; escribir y publicar: `administrador_centro` | Ninguno | NV | — | N | P1 · `-d2` |
| F-06 | `fam-unit-terms-wall` | Muro de condiciones de uso y aceptación | `[]` (`bare`) | Quien tenga condiciones pendientes (**NV** qué usuarios quedan sujetos: no se ha leído `REQ-FAM-UNIT/funcional.md §19`) | Ninguno | Por nombre: `fam-unit-terms-wall.spec.ts` | — | N | P1 · `-d2` (cond. `OPEN-E2ECI-25`) |
| F-07 | — (sin ruta) | Consulta de aceptaciones (`GET /terms-acceptances`) | `aceptacion_condiciones.leer` | `administrador_centro`, `secretaria` | Ninguno | — | — | N | P2 · `-d2` (HTTP) |

### 3.6 Familia: solo API (`OPEN-E2ECI-13` = A; prueba HTTP real con cookie y CSRF)

Usuario del guion: `tutor_legal` del modo `alum` (`§1`). Estudiante: **ninguna fila**, no hay usuario estudiante en el guion (`OPEN-E2ECI-13`).

| # | *Endpoints* | Flujo | Permiso | Ámbito | E2E real hoy | Hueco | Prio./Entrega |
|---|-------------|-------|---------|--------|--------------|-------|---------------|
| T-01 | `GET /students*` | El tutor ve solo a su alumno; `404` al ajeno y tras revocar | `alumno.leer` | uf | `alum-students-real` (`CA-ALUM-012`, **V-cab**) | C | — |
| T-02 | `GET /enrollments*` | Matrículas de sus alumnos | `matricula.leer` | uf | NV (`alum-enrollments-real` no leído) | NV | P1 · `-d2` (si falta) |
| T-03 | `GET /family-units*`, `/guardians*`, `/guardian-links*` | Su unidad, sus tutores y vínculos activos, nunca revocados (`RN-FAM-UNIT-36`) | `familia.leer`, `vinculo_tutor.leer` | uf | Ninguno (NV) | N | P1 · `-d2` |
| T-04 | `GET /image-consents`, `/image-consent-texts`; `POST /image-consent-decisions` | Ver y decidir el consentimiento de imagen de sus alumnos | `consentimiento_imagen.leer` / `.actualizar` | uf | Ninguno (NV) | N | P1 · `-d2` |
| T-05 | `GET /pickup-authorizations*`, `/pickup-lists/*` | Ver las personas autorizadas de sus alumnos | `autorizado_recogida.leer` | uf | Ninguno (NV) | N | P1 · `-d2` |
| T-06 | Escrituras de alumno, familia, vínculo y autorizados | Denegadas (`403`) | — | — | `alum-students-real` para alumno (`CA-ALUM-014a`, **V-cab**); el resto, ninguno | P | P1 · `-d2` |
| T-07 | `GET /me/terms`, `POST /me/terms-acceptances` | Condiciones pendientes y aceptación; `403 terms-acceptance-required` antes | identidad | — | Ninguno | N | P1 · `-d2` |

### 3.7 `prl`

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| P-01 | `prl-door`, `prl-door-student` | Buscar alumno, verificar por fotografía, registrar salida, abrir alerta | `entrega_menor.leer` | Ver: `administrador_centro`, `secretaria`, `direccion`, `conserjeria_pas`; registrar: `administrador_centro`, `conserjeria_pas` | `prl-door-real` (test «`CA-PRL-035`: el conserje busca, abre la lista, verifica por la foto, registra la salida y abre la alerta», **V** l.158; cabecera: `CA-PRL-012`) y `prl-door-api` (HTTP) | NV | `F`, `A` (aserciones explícitas, **V-cab**) | C | — |
| P-02 | `prl-alerts`, `prl-alert-detail` | Listado con filtro por fecha, registrar llamada y cerrar | `alerta_entrega.leer` | Ver: los cuatro de P-01; abrir: `administrador_centro`, `direccion`, `conserjeria_pas`; cerrar: `administrador_centro`, `direccion` | `prl-door-real`, test «`CA-PRL-035`: el administrador ve la alerta con nombres, filtra por fecha, registra la llamada y la cierra» (**V** l.399; la cabecera del *spec* lo describe como `CA-PRL-022` de extremo a extremo). Falta el cierre por `direccion`, que el modo `door` no crea | NV | `T` parcial (filtro) | P | P2 · `-d4` (cond. `OPEN-E2ECI-19`) |
| P-03 | `prl-door-student`, `prl-alert-detail` | Secretaría ve la puerta sin registrar salida; conserje ve la alerta sin cerrarla | — | `secretaria` (sin `entrega_menor.crear`), `conserjeria_pas` (sin `alerta_entrega.actualizar`) | Conserje: el test de l.158 comprueba que no ve «Cerrar la alerta» ni «Guardar la llamada» (**V** l.390-391); falta el `403` por HTTP. Secretaría sin registrar salida: ninguno (NV) | NV | — | P | P1 · `-d1` |

### 3.8 `alum`

| # | Ruta (nombre) | Flujo | Permiso de la ruta | Roles que lo ejercen | E2E real hoy | E2E simulado hoy | Interacción | Hueco | Prio./Entrega |
|---|---------------|-------|--------------------|----------------------|--------------|------------------|-------------|-------|---------------|
| L-01 | `alum-students` | Listado; docente con asignación ve al alumno, docente sin asignación no | `alumno.leer` | `administrador_centro`, `secretaria`, `direccion`, `administrativo`; `docente`, `tutor_grupo` (grupo) | `alum-screens-real` (`CA-ALUM-072`, secretaría y dos docentes, **V**); `data-table-real` (`RNF-MANT-007`, `CA-CORE-178`, **V-cab**) | NV | `T` (orden, búsqueda, paginación, columnas, URL), `F` | C | — |
| L-02 | `alum-students` → ficha | Alta de alumno en una unidad y matrícula | `alumno.leer` (+ `alumno.crear`, `matricula.crear`) | `administrador_centro`, `secretaria` | `alum-screens-real` (**V**) | NV | `F`, `D` | C | — |
| L-03 | `alum-student-detail` | Editar ficha, dar de baja y restaurar | `alumno.leer` (+ `alumno.actualizar`/`.eliminar`) | `administrador_centro`, `secretaria` | Por la API: `alum-students-real` (PATCH, baja y restauración, **V-cab** `CA-ALUM-001`); por pantalla, ninguno | NV | — | P | P1 · `-d2` |
| L-04 | `alum-student-detail` | Fotografía del alumno (Garage) | `alumno.leer` (+ `alumno.actualizar`) | `administrador_centro`, `secretaria` | Por la API: `alum-photo-real` (**V-cab** por `§1.3`); por pantalla, NV | NV | — | P | P1 · `-d2` |
| L-05 | `alum-class-list` | Lista de clase y exportación (Garage) | `matricula.leer` (+ `.exportar`) | Ver: los de L-01; exportar: `administrador_centro`, `secretaria`, `direccion` | `alum-screens-real` (secretaría exporta, **V-cab** título) | NV | NV | C | — |
| L-06 | `alum-group-changes` | Solicitar, aprobar por otro, autoaprobación rechazada, rechazo, cancelación, curso cerrado | `matricula.leer` (+ `.actualizar`, `.aprobar`) | Solicitar: `administrador_centro`, `secretaria`; aprobar: `administrador_centro`, `direccion` | `alum-group-change-real` (`CA-ALUM-070`..`-072`, **V-cab**) | NV | `D`, `F`, `A` (foco) | C | — |
| L-07 | `alum-student-detail` | Baja y anulación de matrícula | `alumno.leer` (+ `matricula.actualizar`/`.eliminar`) | `administrador_centro`, `secretaria` | NV (`alum-enrollments-real` no leído) | NV | NV | NV | P1 · `-d2` (si falta) |

### 3.9 Transversal

| # | Qué | E2E real hoy | E2E simulado hoy | Hueco | Prio./Entrega |
|---|-----|--------------|------------------|-------|---------------|
| X-01 | *Shell*: menú, migas, *scroll* al cambiar de ruta | `core-scroll-real` (`M`, `S`) | `apps/web/e2e/shell.spec.ts` (**V**, simulado) | P | P3 · `-d5` |
| X-02 | Idioma es/en/de/fr (interfaz) | Ninguno (`I` no aparece) | NV | N | P3 · `-d5` |
| X-03 | Tema claro/oscuro | Ninguno (`C` no aparece) | Por nombre: `apps/web/e2e/design-system.spec.ts`, `apps/web/e2e/radius.spec.ts` | N | P3 · `-d5` (comparación visual: `E2E-CI-e`) |
| X-04 | Responsive (320 px) | `alum-screens-real` (`CA-ALUM-071`: `/alumnado`, `/alumnado/listas` y `/alumnado/cambios-de-grupo` sin desbordamiento horizontal, **V** l.196-217); `acad-scales` (**V** por el guion), `acad-periods` (**NV**). Falta en `core`, `curso`, `fam-unit` y `prl` | `core-roles.spec.ts` (`CA-PERM-128`, **V-cab**) | P | P3 · `-d5` (solo lo que falta) |
| X-05 | Tabla de datos (TanStack) | `data-table-real` | Por nombre: `apps/web/e2e/data-table.spec.ts` | C | — |
| X-06 | Selector de curso académico en la URL (`?academic_year=`) | Se navega con el parámetro (**V** `alum-screens-real`); el selector en sí, NV | NV | P | P3 · `-d5` |

---

## 4. Resumen y partición propuesta

### 4.1 Recuento

**67 filas** (A 13, C 15, U 4, D 5, F 7, T 7, P 3, L 7, X 6; sin estudiante ni *backoffice*, que no tienen pantallas). Contadas fila a fila:

| Estado | Nº | Filas |
|--------|---:|-------|
| `C` cubierto | 11 | C-08, U-04, D-02, F-02, T-01, P-01, L-01, L-02, L-05, L-06, X-05 |
| `P` parcial | 18 | A-01, C-01, C-04, C-05, U-01, U-02, U-03, D-01, D-03, F-03, T-06, P-02, P-03, L-03, L-04, X-01, X-04, X-06 |
| `N` sin E2E real | 33 | A-02..A-04, A-07..A-12, C-02, C-03a, C-03b, C-06, C-07, C-09..C-14, D-04, D-05, F-01, F-04..F-07, T-03..T-05, T-07, X-02, X-03 |
| `NV` | 2 | T-02, L-07 |
| `X` no viable hoy | 3 | A-05, A-06, A-13 |

Huecos (`P` + `N` + `NV` = **53**) por prioridad:

| Prioridad | Nº | Filas | Entrega |
|-----------|---:|-------|---------|
| **P1** | 31 | A-01..A-04, A-09..A-12, C-02, C-03b, C-04, C-05, C-06, C-14, D-01, D-05, F-01, F-03..F-06, T-02..T-07, P-03, L-03, L-04, L-07 | `-d1` (9), `-d2` (14 de P1 + F-07), `-d3` (8) |
| **P2** | 14 | A-07, A-08, C-03a, C-07, C-09, C-10, C-11, C-13, U-02, U-03, D-03, D-04, F-07, P-02 | `-d4` (13); F-07 va en `-d2` por afinidad |
| **P3** | 8 | C-01, C-12, U-01, X-01, X-02, X-03, X-04, X-06 | `-d5` (más `R` de C-08) |

Los tres `X` no entran en ninguna entrega salvo A-13 en su parte de listado y validación (`-d3`, `OPEN-E2ECI-18`).

### 4.2 Partición

Orden por `OPEN-E2ECI-12` = A. Dentro de P1, `-d1` y `-d2` van antes que `-d3` porque `-d3` depende de tres preguntas abiertas y de tocar `e2e-real-tenant.php` **[DERIVADA]**.

| Entrega | Filas | Suite donde vive (propuesta) | Tramos | Clasificación | Datos nuevos en el guion (`REQ-SEED-005`) | Coste estimado de reloj (**NV**) |
|---------|-------|-------------------------------|--------|---------------|--------------------------------------------|----------------------------------|
| **`-d1`** · autorización en pantalla | C-02, C-03b, C-04, C-05, C-06, C-14, D-01, D-05, P-03 | `alum-browser` (secretaría, dirección, docentes, administrador; `acad` contratado) y `prl` (conserje, secretaría) | 2-3 | **Crítica hasta resolver `OPEN-E2ECI-21`**: registra *specs* en `e2e-real-alum.sh`/`e2e-real-prl.sh` y cambia `e2e-real-esperados.json` | No (usa los usuarios de `alum` y `door`) | +45-60 s (**suposición sin medir**) |
| **`-d2`** · datos de menores y familias | F-01, F-03..F-07, T-02..T-07, L-03, L-04, L-07 (15) | Propuesta [DERIVADA]: F-01, F-05, F-06 y F-07 en la suite **`fam-unit`** (modo `door`: administrador y `secretaria`; hoy 1 test, `e2e-real-esperados.json`; `e2e-real-api.sh` l.175); F-03 y F-04 en **`alum-browser`**, porque comprueban el efecto sobre el tutor y solo el modo `alum` crea `tutor_legal` (y contrata `fam_unit`, `e2e-real-tenant.php` l.223-236); T-02..T-07 en **`alum-http`**; L-03, L-04 y L-07 en **`alum-browser`**. Sin suite nueva | 2-3 | **Crítica hasta resolver `-21`** (registra *specs* en `e2e-real-api.sh` y `e2e-real-alum.sh`) | No (textos, unidades y vínculos los crea el *spec* por la API) | +30-45 s (**suposición sin medir**) |
| **`-d3`** · autenticación | A-01..A-04, A-09..A-12, A-13 (listado y validación) | `core` o suite nueva `auth` (`OPEN-E2ECI-22`) | 3 | **Crítica hasta resolver `-21`**, como las demás; y además crítica por sí misma, porque toca `e2e-real-tenant.php` (usuario obligado a MFA sin exención) y, según `OPEN-E2ECI-16`, el guion: la resolución de `-21` no la cambia | **Sí**: un usuario con rol obligado a MFA y sin exención; según `-16`, acceso a los correos | +40-60 s (**suposición sin medir**) |
| **`-d4`** · escrituras restantes de personal | A-07, A-08, C-03a, C-07, C-09, C-10, C-11, C-13, U-02, U-03, D-03, D-04, P-02 | `core`, `curso-rollover`, `acad-*` según el módulo | 3 | **Crítica hasta resolver `-21`** | No, salvo `direccion` en modo `door` para P-02 (`OPEN-E2ECI-19`) | +50-70 s (**suposición sin medir**) |
| **`-d5`** · lecturas y transversal | C-01, C-08 (`R`), C-12, U-01, X-01..X-04 (X-04 solo en `core`, `curso`, `fam-unit` y `prl`), X-06 | `core` (y `curso`, que corre en `core`: `curso-academic-years`), `fam-unit` y `prl` para X-04 | 1-2 | **Crítica hasta resolver `-21`** | No | +30-40 s (**suposición sin medir**) |

La comparación visual con referencias **no** está en `-d`: es `E2E-CI-e` (`OPEN-E2ECI-10` = A, capturas como artefacto sin comparación). `-d5` solo comprueba que el cambio de idioma, de tema y de anchura **funciona** (textos, ausencia de errores, sin desbordamiento horizontal), no cómo se ve.

### 4.3 Tiempo del job y `E2E-CI-c2`

Datos medidos (`e2e-real-ci.md §9.3`, V): tabla oficial de tres ejecuciones (7:39, 8:22 y 8:15), **mediana 8:15, máximo 8:22**; suma de fases `playwright` ≈ 238 s (mediana); `alum` ≈ 95 s con 36 tests; `setup`/`teardown` 1-7 s por suite. Observaciones aparte, fuera de la tabla oficial: 8:27 (cuarta ejecución en la rama, run 44338603732) y 7:28 (en `develop`, dato de la sesión; **NV** en esta pasada).

**Todo lo demás de esta sección son suposiciones sin medir** [DERIVADA]: el coste por test, el coste fijo del job (≈ 3:30-3:45, deducido de 8:15 menos ≈ 4:00 de Playwright y ≈ 0:30 de preparación) y todas las cifras «+N s» de `§4.2`. Se recalibran con las tres ejecuciones que exige `CA-E2ECI-20` tras cada entrega.

- **Holgura hasta el disparador de `D3`** (~10 min): ≈ **1:45** sobre la mediana oficial y ≈ **1:38** sobre el máximo oficial (8:22).
- **Qué admite** (suposición): del orden de 20-30 tests HTTP o 10-15 de navegador añadidos a suites existentes, o una o dos suites nuevas de 6-8 tests (cada una paga su `setup`/`teardown` y el arranque de Playwright, ≈ 10-15 s supuestos). Lo que más puede encarecer es el límite de **10 accesos por minuto y centro** (`e2e-real-ci.md §1.2`): cada actor nuevo puede costar una espera de `429` (`OPEN-E2ECI-23`).
- **Previsión con esas suposiciones**: `-d1` lleva la mediana a ≈ 9:00-9:15; `-d1` + `-d2`, a ≈ 9:30-10:00, **sin superar ~10 min** salvo en el extremo alto de la horquilla y partiendo del máximo (8:22 + 105 s ≈ 10:07). La suma de las cinco entregas (+195-275 s supuestos) **sí excede la holgura**, así que `E2E-CI-c2` es probable **hacia el final** de la serie (en `-d3`, `-d4` o `-d5`), no antes. La regla de `D3` no cambia (`e2e-real-ci.md §5.3`): tras cada entrega, tres ejecuciones; si la mediana supera ~10 min, la sesión propone el reparto con esas medidas y **no se paraleliza sin aprobación**.
- **Orden propuesto para `-c2` si salta** [DERIVADA, propuesta para cuando haya medidas]: dos *shards* antes que tres (cada *shard* repite el coste fijo): *shard* 1 = `alum` (la suite más larga, y donde caen `-d1` y `-d2`); *shard* 2 = el resto (`core`, `acad-*`, `curso-rollover`, `fam-unit`, `prl`, y `-d3`..`-d5`). Tercer *shard* solo si el segundo supera al primero.

### 4.4 Qué flujos exige cada entrega (lista cerrada para su prompt de `implementer`)

- **`-d1`**: (a) para cada par de la tabla de `§4.5` sin permiso, navegación a la ruta ⇒ «sin acceso» sin montar la vista, la entrada no está en el menú, y la llamada HTTP real al *endpoint* principal ⇒ `403` (`CA-E2ECI-22`); para los pares con permiso de ámbito restringido, vista montada, sin filas ajenas, listado vacío y detalle `404` por HTTP (`CA-E2ECI-22b`); (b) dentro de una pantalla permitida, las acciones sin permiso no se ofrecen y su *endpoint* da `403` (`RN-CORE-61`): docente en estructura (D-01), secretaría en la puerta (P-03, modo `door`), y el `403` por HTTP del conserje al cerrar una alerta (P-03; la ausencia del botón ya la comprueba `prl-door-real`); (c) alta de usuario **con rol elegido en el formulario de alta** (C-02) y permisos efectivos con **`deny` y multi-rol** (C-05); asignar un rol desde la ficha y ver un permiso permitido ya lo cubre `core-roles` (`CA-PERM-133`) y no se repite; (d) **retirar** un rol desde la ficha (C-04); (e) desactivar o dar de baja al propio usuario y al último administrador ⇒ mensaje del `409` en pantalla (C-03b); (f) reservas: `docente` crea y cancela la suya; `docente2` no puede cancelar la ajena (D-05, `CA-ACAD-139`); (g) invitaciones: el administrador reenvía y revoca; `secretaria` las ve sin acciones (C-06).
- **`-d2`**: (a) HTTP del tutor sobre T-02..T-07 (solo lo suyo, `404` a lo ajeno, nada revocado, `403` en escrituras); (b) en navegador: alta de unidad, tutor y vínculo por `secretaria` (F-01); revocación y levantamiento por el administrador y efecto inmediato en el tutor por HTTP (F-03); publicación de texto legal por el administrador (F-05); registro de una decisión de imagen presencial por `secretaria` y exportación descargada de Garage (F-04); edición, baja y restauración de la ficha de alumno por `secretaria` (L-03), fotografía por pantalla (L-04); muro de condiciones (F-06, según `OPEN-E2ECI-25`); (c) consulta de aceptaciones por HTTP (F-07).
- **`-d3`**: (a) credencial errónea ⇒ mensaje genérico y sin sesión (A-01); (b) según `OPEN-E2ECI-17`: alta de TOTP, cierre de sesión, inicio con segundo factor, muro de MFA y restablecimiento por el administrador (A-09..A-12); (c) exención concedida a otro usuario y revocada; «no a sí mismo» (A-12, `CA-AUTH-161`); (d) según `OPEN-E2ECI-16`: invitación → canje → primer acceso, recuperación → restablecimiento, desbloqueo (A-02..A-04); (e) listado de SSO y validación del formulario sin descubrimiento (A-13); A-05 y A-06 se declaran no viables en el informe con su sustituto (Pest) y su *issue*.
- **`-d4`**: cada flujo de escritura de la fila se completa por la interfaz, con al menos un error de validación del servidor mostrado (`F`) y el resultado comprobado con una lectura HTTP real; la auditoría (C-09) encuentra filtrando una escritura hecha por el propio *spec* y la exporta.
- **`-d5`**: idioma (X-02), tema (X-03), 320 px solo en `core`, `curso`, `fam-unit` y `prl` (X-04; `alum` y `acad` ya lo tienen), selector de curso (X-06), filtros con estado en la URL en `core-users` (C-01) y lectura de `core-modules` (C-12) y de cursos por `secretaria` (U-01).

### 4.5 Pares de denegación de `-d1` (rol del guion × ruta sin su permiso)

Sacados de la siembra (V). Solo usuarios que existen hoy (`§1`); el tutor queda fuera de la pantalla por `OPEN-E2ECI-13` = A y se prueba por HTTP en `-d2`.

| Rol (modo) | Rutas sin permiso (muestra; el *spec* elige una por módulo como mínimo) |
|------------|-----------------------------------------------------------------------|
| `docente` (`alum`) | Sin permiso (`CA-E2ECI-22`, `403`): `core-users`, `curso-academic-years`, `fam-unit-list`. Con permiso de ámbito `grupo` (`CA-E2ECI-22b`, vacío/`404`, nunca `403`): `alum-class-list` de un grupo en el que no imparte |
| `secretaria` (`alum`) | `core-roles`, `core-audit`, `core-settings`, `mfa-administration`, `curso-academic-year-new` |
| `direccion` (`alum`) | `core-user-new`, `core-audit`, `fam-unit-terms` |
| `conserjeria_pas` (`door`) | `fam-unit-list`, `core-users`, `acad-structure` |
| `secretaria` (`door`) | `core-roles` (repetido solo si `alum` no lo cubre) |

---

## 5. Observaciones para la sesión (no son decisiones)

1. **Por qué las cinco entregas `-d` son críticas hasta resolver `OPEN-E2ECI-21`** (y `-d3`, además, por `e2e-real-tenant.php`). `CLAUDE.md §3` exige que cada E2E nuevo quede **registrado en el guion** de E2E real; si los guiones enumeran sus *specs* (**V-cab**: `e2e-real-alum.sh` «tres *specs* HTTP y tres de navegador», `e2e-real-ci.md §1.2`), añadir uno toca `apps/web/scripts/*`, que `§9` clasifica como crítico. `OPEN-E2ECI-21`.
2. **`OPEN-E2ECI-13` = A choca con el muro de condiciones.** `§1.4` dice que el muro (`/condiciones-de-uso`) lo alcanza cualquier usuario autenticado, «incluida una familia», y `OPEN-E2ECI-13` = A dice «ninguna fila de pantalla» para la familia. No se reabre la decisión: se pregunta solo por el muro (`OPEN-E2ECI-25`).
3. **`e2e-real-ci.md §6.3` decía de las alertas de `prl` «NV (posible en `prl-door-*`)»**: el test de `prl-door-real` l.399 (**V**, título con `CA-PRL-035`) hace que el administrador encuentre la alerta, la filtre por fecha, registre la llamada y la cierre. Se corrige aquí (P-02), no en `§6.3`, que es la primera pasada histórica. Los títulos de los tests citan `CA-PRL-035` y la cabecera del *spec*, `CA-PRL-012` y `CA-PRL-022`: la matriz cita el título.
4. **`e2e-real-ci.md §6.3` daba la 320 px como solo simulada y los permisos efectivos como NV**: `alum-screens-real` (l.196-217) y `core-roles` (l.475-480) los cubren en real (**V**). Corregido en X-04 y C-05.
5. Ninguna entrega propone relajar guardas (`E2E_ALLOW_DESTRUCTIVE`, marca del centro, entorno), subir el límite de accesos en CI, ni escribir `127.0.0.1`/`:8000` nuevos en los *specs* (`e2e-real-ci.md §3` punto 4 y `§8`).

---

## 6. Preguntas abiertas de `-d0`

Están en `e2e-real-ci.md §10.1` (`OPEN-E2ECI-16` a `-25`), con la opción recomendada primero. Ninguna se resuelve aquí.
