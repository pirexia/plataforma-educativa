# REQ-FAM-UNIT · Unidad familiar y tutores · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-FAM-UNIT` (sección 5.3 del documento de requisitos). Este paso cubre **`REQ-FAM-UNIT-001`** (unidad familiar), **`-002`** (vinculación de tutores, custodia y restricciones de acceso por tutor), **`-003`** (condiciones de uso) y **`-004`** (consentimiento de imagen granular). **`-005`** (personas autorizadas a recoger, `ADR-032`) es del paso **`1.14b`** y queda fuera (§1.4) |
| Prioridad | MUST |
| Fase | 1 · paso **1.14** del plan (bloque D, Personas) |
| Depende de | Documento: `CORE`, `AUTH`. Real: `CORE` y `AUTH` (esenciales, ya implementados). Ninguna tabla de este módulo depende del curso académico (`ADR-034 §4`: «unidad familiar» está en la lista expresa de entidades **sin** `academic_year_id`). Propuesta: `depends_on: []` (`OPEN-FAM-UNIT-14`) |
| Esencial | Propuesta: `essential: false` (`OPEN-FAM-UNIT-14`) |
| Estado | **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). Los cinco bloqueantes de §13.1 están resueltos con su recomendación; persisten las condiciones listadas al inicio de §13. **`1.14-a` IMPLEMENTADA (2026-10-26)**: unidades, tutores, alumnado, vínculos, revocación, resolutor `unidad_familiar`, interfaces de §7.1 a §7.4 y ampliación de `Core\Domain`; decisiones de detalle en §15. **`1.14-b` LISTA PARA IMPLEMENTAR (2026-10-27), aprobada por el usuario**: enmendada según `ADR-066` (**ACEPTADA** el 2026-10-27, `OPEN-066-01..05` resueltas), con los 27 cambios de su §9 aplicados (trazabilidad en §16) y los defectos de numeración corregidos (§14.2); el usuario aprueba las recomendaciones de `OPEN-FAM-UNIT-22..25` (§13.3 y §13.4, resueltas, aplicadas en §17; no queda ninguna pregunta abierta de `1.14-b`), acepta los puntos [DERIVADA] de la enmienda y confirma los dos niveles `web` y `redes_sociales`, sin separar por red social. **Condiciones previas a cargar datos reales** (no bloquean `1.14-b` con datos sintéticos de `REQ-SEED`): validación jurídica de `OPEN-FAM-UNIT-08`, criterio jurídico sobre 14 y 18 años (`OPEN-FAM-UNIT-18`, `OPEN-066-05`) y respuesta sobre si un texto nuevo invalida los `autorizo` anteriores (`OPEN-066-02`). **Pendiente**: `1.14-c`, `1.14-d` (pantallas de personal de §10 y `CA-FAM-UNIT-084`, §15 D-9) y el detalle de custodia (ADR). |
| Decisiones vinculantes de partida | `ADR-020`, `ADR-021`, `ADR-029`, `ADR-032` (solo para lo que `1.14` debe dejar preparado, §1.4), `ADR-033`, `ADR-034 §1`/`§4`/`§6`/`§7`, `ADR-035`, `ADR-038`, `ADR-043 §8.1`, `ADR-044` (enmendado por `ADR-063 §5.2`), `ADR-045`, `ADR-048`, `ADR-053`, `ADR-054`, `ADR-055`, `ADR-056`, `ADR-062` (contenido del centro traducible) |

> Donde esta especificación añade algo que ni los requisitos ni un ADR fijan, se marca **[DERIVADA]** y se argumenta. Donde falta información que no corresponde inventar, hay una pregunta abierta en §13 con una recomendación, y **no** una decisión.

---

## 0. Terminología, contradicciones y huecos

### 0.1 Terminología (se usa igual en los cinco ficheros)

| Término | Entidad / tabla | Qué es |
|---------|-----------------|--------|
| **Unidad familiar** | `FamilyUnit` / `family_units` | Agrupación de tutores y alumnado identificada por un código único en el centro (`REQ-FAM-UNIT-001`) |
| **Tutor** (en interfaz, «tutor/a familiar») | `Guardian` / `guardians` | **Faceta** de una `Person` (`ADR-034 §1`): la persona en su papel de tutor familiar. No es un rol. **No confundir** con «Tutor de grupo» (rol docente de `§11.1`) |
| **Alumno/a de la unidad** | `FamilyUnitStudent` / `family_unit_students` | Pertenencia de la persona de un alumno a una unidad. El alumno como entidad (`Student`) es de `REQ-ALUM` (`1.15`); aquí solo existe su persona (§0.3, C1) |
| **Vínculo** | `GuardianStudentLink` / `guardian_student_links` | Relación tutor ↔ alumno con tipo de relación, nivel de acceso, categorías restringidas, contacto de emergencia y estado (`REQ-FAM-UNIT-002`) |
| **Tutor principal** / **tutores secundarios** | Columna de `family_units` | Jerarquía de `REQ-FAM-UNIT-001` |
| **Revocación** | Estado `revocado` del vínculo | Retirada del acceso de un tutor concreto a un alumno concreto por resolución judicial, sin afectar al resto (`REQ-FAM-UNIT-002`, nota legal) |
| **Restricción de custodia** | `CustodyRestriction` / `custody_restrictions` | Documentación de la resolución que motiva la revocación. **Condicionada** a `OPEN-FAM-UNIT-05` |
| **Consentimiento de imagen** | `ImageConsentDecision` / `image_consent_decisions` (histórico, única tabla). `ImageConsent` es la **representación calculada** del estado vigente, sin tabla (`ADR-066 §5.2`) | Decisión por alumno y por nivel (`web`, `redes_sociales`) (`REQ-FAM-UNIT-004`) |
| **Quienes deciden** | — (se calcula) | Tutores con vínculo **vivo** (`activo` o `revocado`) y representación legal sobre el alumno (`ADR-066 §5.4`, `RN-FAM-UNIT-41`) |
| **Texto legal** | `TermsVersion` / `terms_versions` con `kind` (`condiciones_uso`, `consentimiento_imagen_web`, `consentimiento_imagen_redes`) | Texto del centro, versionado y traducible. Recurso de permisos `texto_legal` (`OPEN-066-03`) |
| **Condiciones de uso** | `TermsVersion` de `kind = condiciones_uso` / `TermsAcceptance` | Texto del centro, versionado, y su aceptación por cada tutor (`REQ-FAM-UNIT-003`) |

Reglas de nombre **[DERIVADA]**: el **ámbito** de permiso se llama `unidad_familiar` (`RPERM-004`, `ADR-044 §4.1`); ningún **recurso** de permiso de este módulo usa ese nombre para no confundirlos (mismo criterio que `REQ-ACAD/permisos.md §1` con `grupo`). El código de módulo propuesto es `fam_unit` y el prefijo de traducciones y errores, `fam_unit.`.

### 0.2 Contradicciones y huecos detectados

| # | Hallazgo | Fuentes | Cómo queda en esta propuesta |
|---|----------|---------|------------------------------|
| C1 | **Dependencia circular en la práctica con `REQ-ALUM`.** `REQ-FAM-UNIT` vincula tutores con alumnos, pero el alumno (`Student`) es de `REQ-ALUM` (`1.15`), que a su vez **depende** de `FAM-UNIT` (`§5.0`). No se puede depender de `ALUM` | `§5.0`, `§5.3`, `§5.6`, `§16.2` | El lado «alumno» se modela sobre **`people`** (Core, ya existe), no sobre `students`: `ADR-034 §1` ya fija que las facetas cuelgan de `people`. `ALUM` creará después la faceta `student` sobre la misma persona. Contrato en §7.4. **Consecuencia que hay que aceptar**: hasta `1.15` no hay forma por API de crear la persona de un alumno, así que las pantallas de alta de alumnos en una unidad no tienen datos reales hasta entonces. Decisión en `OPEN-FAM-UNIT-01` |
| C2 | **Cardinalidad de `§16.2` frente al caso real.** `FAMILY_UNIT ||--o{ GUARDIAN` (un tutor pertenece a una sola unidad) y `FAMILY_UNIT ||--o{ STUDENT` (un alumno a una sola unidad), pero `GUARDIAN }o--o{ STUDENT`. El caso real de un padre con hijos de dos parejas distintas, en dos unidades, no cabe si el vínculo solo puede ser dentro de la unidad | `§16.2`, `REQ-FAM-UNIT-001`/`-002` | Se respetan **literalmente** las tres cardinalidades de `§16.2` y se permite que un vínculo tutor ↔ alumno cruce unidades (opción C de `OPEN-FAM-UNIT-02`). Cambiar la cardinalidad tutor ↔ unidad exigiría ADR (`§16`: «no deben modificarse sin ADR») |
| C3 | **`INV-008` no lo puede cumplir este módulo solo.** Exige «base legal registrada y consentimiento del tutor» para **cualquier** dato personal de un menor. La base legal por campo sigue sin catálogo (`OPEN-13`, `REQ-PRIV-006`, ya señalado en `ADR-043 §8.1`), y el tratamiento ordinario de datos escolares no descansa en el consentimiento sino en otras bases legales | `INV-008`, `RSEC-GDPR-009`, `REQ-PRIV-002`, `ADR-043` | `1.14` aporta lo que es suyo: **quién es representante legal** de cada menor, con su verificación (§3.3), y el consentimiento allí donde el requisito lo pide expresamente (imagen). La lista de bases legales por campo sigue en `OPEN-13`. Se dice explícitamente que `INV-008` queda **cubierto parcialmente** (`OPEN-FAM-UNIT-08`) |
| C4 | **Tres sitios para el consentimiento de imagen.** `REQ-FAM-UNIT-004` lo define; `REQ-AUT-002` lo remite a `-004` (coherente); `REQ-PRIV-002` (fase 2) pide un «registro centralizado de todos los consentimientos: imagen…». Es el mismo riesgo de lista duplicada que `ADR-032` resolvió para la recogida | `§5.3`, `§5.13`, `§5.45` | **Fuente única en este módulo** desde `1.14`, con los campos que `REQ-PRIV-002` pedirá (versión del texto, fecha, hora, IP, canal) para que `PRIV` lo **consuma** por interfaz y no lo duplique. **Resuelto por `ADR-066` (ACEPTADA, 2026-10-27)**: `fam_unit` es la fuente única y permanente; `AUT` y `PRIV` leen por interfaz y no guardan copia (§7.3) |
| C5 | **Custodia como dato de categoría especial.** El encargo pide tratar la resolución judicial según `CLAUDE.md §8` (tabla separada, cifrada, permiso propio, auditoría de lectura). Pero (a) `§8` y `RPERM-012` enumeran solo salud, NEAE y convivencia; (b) `RPERM-012` prohíbe incluir esos permisos en **ningún** rol por defecto y `administrador_centro` tiene `special_data_access = false`, así que de serie **nadie** podría registrar una custodia; (c) `AR-09` es un cable trampa que falla en cuanto un permiso declare `is_special_category = true` hasta que la especificación de ese primer permiso diseñe el mecanismo de auditoría de lectura (`REQ-PERM/funcional.md §5.4`); y (d) **no existe mecanismo de cifrado a nivel de campo** (`RSEC-ISO-008`) diseñado en ningún ADR | `CLAUDE.md §8`, `RPERM-012`, `RPERM-015`, `RSEC-ISO-008`, `ADR-044 §4.4`, `AR-09` | Se **separa el efecto del detalle**: la revocación (lo que protege al menor) es un permiso ordinario y entra en `1.14`; el detalle documental de la resolución es categoría especial y queda **condicionado** a un ADR de cifrado y auditoría de lectura (`OPEN-FAM-UNIT-05`, bloqueante de esa parte) |
| C6 | **«Sin aceptar las condiciones, el tutor no puede acceder a la plataforma»** exige un muro tras el inicio de sesión, que hoy solo existe para MFA (`REQ-AUTH`). Un módulo no puede engancharse a la sesión de otro sin una interfaz | `REQ-FAM-UNIT-003`, `INV-007` | Contrato de «muro de sesión» en §7.5; su forma exacta es `OPEN-FAM-UNIT-12` |
| C7 | **Requisitos sin definir** que el texto da por supuestos: qué «autoriza» el tutor principal, qué escribe un tutor secundario «de lectura/escritura», qué categorías de información se pueden restringir, cómo se combinan las decisiones de dos tutores legales, qué pasa al cumplir el alumno 18 años o 14 años (edad de consentimiento de la `LOPDGDD`), qué es «periódico» en el informe de consentimientos | `REQ-FAM-UNIT-001`/`-002`/`-004` | Cada uno es una pregunta abierta con recomendación (§13). No se inventa la respuesta |

---

## 1. Alcance

### 1.1 Qué entra en `1.14`

| Pieza | Qué se entrega | Requisito |
|-------|----------------|-----------|
| Módulo `fam_unit` | `apps/api/app/Modules/FamUnit/` con su `ServiceProvider` (descriptor, catálogo de permisos, migraciones, *morph map*, resolutores), rutas, `lang/{es,en,de,fr}/fam_unit.php` y `modules.fam_unit` | `RMOD-001`, `AR-03`, `AR-12` |
| Unidades familiares | Alta, edición, consulta, baja lógica y restauración; código único por centro; tutor principal | `REQ-FAM-UNIT-001` |
| Tutores | Faceta `guardian` sobre la persona de un usuario existente; pertenencia a una unidad; alta y baja | `REQ-FAM-UNIT-001` |
| Alumnado de la unidad | Pertenencia de la persona de un alumno a una unidad (contrato con `ALUM`, §7.4) | `REQ-FAM-UNIT-001` |
| Vínculos tutor ↔ alumno | Tipo de relación, representación legal verificada, nivel de acceso, categorías restringidas, contacto de emergencia | `REQ-FAM-UNIT-002` |
| Revocación por custodia | Revocar y levantar el acceso de un tutor a un alumno, con efecto inmediato en todo el producto vía las interfaces de §7 | `REQ-FAM-UNIT-002` (nota legal) |
| Resolutor `unidad_familiar` | Para los recursos propios del módulo (§6 de `permisos.md`) y la **interfaz de pertenencia** para que otros módulos registren el suyo (`ADR-063 §5.2`) | `RPERM-004`, `ADR-044 §3` |
| Consentimiento de imagen | Decisiones por alumno y nivel, histórico inmutable, estado vigente **calculado en origen** (`ADR-066 §5.2`), texto del consentimiento versionado por nivel, informe por unidad familiar e interfaz de comprobación para `INV-014` | `REQ-FAM-UNIT-004`, `INV-014`, `RSEC-GDPR-011`, `ADR-066` |
| Textos legales versionados | Tabla `terms_versions` con `kind` y sus *endpoints* de gestión y publicación (**`1.14-b`**, limitados a los dos tipos de imagen; `condiciones_uso` en `1.14-c`) | `REQ-FAM-UNIT-003`/`-004`, `ADR-066 §5.5` |
| Condiciones de uso | Tipo `condiciones_uso` de los textos legales, re-aceptación, histórico de aceptaciones y muro de aceptación | `REQ-FAM-UNIT-003` |
| Pantallas de personal del centro (**`1.14-d`**, §1.2) | Listado y ficha de unidad familiar, gestión de vínculos y consentimientos, versiones de condiciones y muro de aceptación (§10) | `INV-006` |

### 1.2 Entregas propuestas [DERIVADA]

El paso es grande y mezcla tres riesgos distintos. Precedente: `1.11-a`/`1.11-b`, `1.12-a`/`1.12-b`. Partición propuesta (`OPEN-FAM-UNIT-15`):

| Entrega | Contenido | Por qué junto |
|---------|-----------|---------------|
| **`1.14-a`** | Módulo, unidades, tutores, alumnado de la unidad, vínculos, revocación, resolutor e interfaces de §7.1-§7.4 | Es la base de todo lo demás y de `1.14b` |
| **`1.14-b`** | Consentimiento de imagen (§4.6-§4.8) e interfaz `INV-014`. **Además** (`ADR-066 §9.1`): tabla `terms_versions` **con `kind`** y sus *endpoints* de gestión y publicación (§4.9), **limitados a los tipos `consentimiento_imagen_web` y `consentimiento_imagen_redes`**: `kind = condiciones_uso` se rechaza con `422` hasta `1.14-c`, para que no se puedan publicar unas condiciones sin muro (`RN-FAM-UNIT-73`). **Y, por la aprobación del 2026-10-27 (§17)**: lectura del texto publicado de cada nivel para el tutor (`GET /image-consent-texts`, `RN-FAM-UNIT-75`), `texto_legal.leer` sembrado en `secretaria` (`OPEN-FAM-UNIT-22`) y el **cambio de comportamiento de `1.14-a`** en `GuardianLinkService`: `legal_guardian_verified_at` solo cambia en la transición `false → true` (`RN-FAM-UNIT-74`), con test de regresión | Riesgo propio (dato de menores con efecto de publicación) y no lo necesita `1.14-a`. `autorizo` exige texto publicado (`RN-FAM-UNIT-68`): sin `terms_versions` no se podría registrar |
| **`1.14-c`** | Condiciones de uso (tipo `condiciones_uso` de `terms_versions`), aceptaciones (`terms_acceptances`) y muro de aceptación (§4.9-§4.10) | Toca la sesión (C6) y depende de `OPEN-FAM-UNIT-12` |
| **`1.14-d`** | Pantallas de personal de §10 (`/familias`, `/familias/:id`, y con `1.14-b`/`-c` las de consentimientos y condiciones) y E2E `CA-FAM-UNIT-084` | Frontend de las entregas anteriores; decisión del usuario del 2026-10-26 (§15, D-9) |
| Condicionada | Detalle de la restricción de custodia (§4.5) | Solo si se aprueba antes el ADR de `OPEN-FAM-UNIT-05`; si no, se difiere sin bloquear las demás |

> Nota de nombres: `1.14-a`/`-b`/`-c` (con guion) son entregas de este paso; **`1.14b`** (sin guion) es el paso siguiente del plan (autorizados y entrega de menores). Es la misma convención que `1.12-b` frente a `1.12b`.

### 1.3 Qué NO entra, explícitamente

| Fuera de alcance | Requisito | Por qué | Dónde se retoma |
|------------------|-----------|---------|-----------------|
| Personas autorizadas a recoger, autorizaciones puntuales, salida autónoma, fotografía del autorizado, historial de entregas | `REQ-FAM-UNIT-005`, `REQ-PRL-004` | Paso propio del plan, crítico (`ADR-032`) | **`1.14b`** (§1.4) |
| Alta de la persona de un alumno, expediente, matrícula | `REQ-ALUM-001`/`-002` | Es `ALUM` (C1) | `1.15` |
| Pantallas del portal de familias (el tutor gestionando sus consentimientos, viendo su unidad) | `REQ-FAM-PORTAL-001`/`-004` | El portal es su propio módulo | `1.22`. La **API** sí entra aquí (`INV-006`): el tutor podrá operar por API con su permiso de ámbito `unidad_familiar` (`OPEN-FAM-UNIT-13`) |
| Alta de tutores por el propio tutor principal desde el portal | `REQ-FAM-UNIT-001` («añade/elimina tutores») | Crear cuentas desde una familia es una superficie de ataque (cualquier persona añadida accede a datos de un menor) y el requisito no dice cómo se valida | `OPEN-FAM-UNIT-04` |
| Bloqueo de publicación de fotos, marcado de galerías, informe sobre imágenes publicadas | `REQ-FAM-UNIT-004` (puntos 4 y 5), sus dos criterios de aceptación | No existe ningún módulo que publique imágenes. Este módulo entrega la **interfaz** que los consumidores están obligados a consultar (§7.3) | `REQ-COM` (`1.19`), `REQ-INF` (`1.18`), `REQ-WEB` (fase 3) |
| Notificación a todos los tutores de notas, incidencias y autorizaciones | `REQ-FAM-UNIT-002` | No hay motor de notificaciones | `REQ-COM` (`1.19`), con la interfaz de §7.2 |
| Aplicación efectiva de «solo el tutor principal ve datos médicos» | `REQ-FAM-UNIT-002` | No hay datos médicos hasta `REQ-SALUD` | Fase 2; aquí se guarda la restricción y se expone por §7.1 |
| Firma electrónica de consentimientos y condiciones | `REQ-AUT-001`, `ADR-012` | Prestador cualificado sin integrar | `REQ-DOC`/`REQ-AUT` (fase 2) |
| Registro centralizado de consentimientos, RAT, derechos ARCO | `REQ-PRIV-001`/`-002`/`-003` | Fase 2 | `REQ-PRIV`, que **agrega** el consentimiento de imagen leyéndolo de este módulo y no guarda filas propias (`ADR-066 §5.7`, C4) |
| Importación de unidades familiares | `REQ-ONB-002`/`-003` | Importador genérico | `1.24`/`1.25` |
| Exportación de unidades y vínculos | Ningún requisito | — | No se declara `exportar` salvo el informe de consentimientos (§4.8) |
| Eventos de dominio | — | `ADR-048`: no se emiten eventos sin consumidor. `1.14b` y `1.19` son los primeros consumidores previsibles y los declararán con su especificación | §7.6 |

**Nada de `REQ-FAM-UNIT` está marcado `FUTURO`** en el documento de requisitos. Lo anterior se difiere a pasos o fases concretos; no se descarta.

### 1.4 Qué deja preparado `1.14` para `1.14b` (`ADR-032`)

`ADR-032` exige que un tutor revocado por resolución judicial quede **excluido de la lista maestra de autorizados y de todos los servicios a la vez**, y que el sistema **lo impida, no lo advierta**. Para eso `1.14` entrega, y `1.14b` consumirá sin reabrirlo:

1. El vínculo con estado `revocado` y motivo `resolucion_judicial` (§3.5, `RN-FAM-UNIT-30`).
2. La consulta `FamilyMembership::isAccessRevoked()` (§7.1), evaluada en el momento de la consulta, sin caché (`RN-FAM-UNIT-34`).
3. La regla de que **revocar no borra el vínculo**: el histórico es la prueba ante un incidente (`REQ-FAM-UNIT-005`, último punto).

`1.14` **no** crea `AuthorizedPickup` ni `PickupRecord` ni ninguna lista de autorizados.

### 1.5 Dependencias

- **`REQ-CORE`** (implementado): `people`, `users`, `UserDirectory`. **Hacen falta dos ampliaciones aprobadas de `Core\Domain`** (precedente: `activeIdsAmong`, `findActiveByPublicId`): una interfaz de lectura de personas y la resolución usuario → persona (`OPEN-FAM-UNIT-03`). Sin ellas el módulo tendría que leer `people` directamente, que `INV-007` prohíbe.
- **`REQ-AUTH`** (implementado): la sesión. El muro de condiciones necesita un punto de extensión (C6, `OPEN-FAM-UNIT-12`).
- **`REQ-PERM`** (implementado): `ScopeResolver`, `ScopedQuery`, vocabulario de ámbitos. El ámbito `unidad_familiar` existe sin resolutor desde `1.5` (`REQ-PERM/funcional.md §1.2`).
- **`REQ-ALUM`** (`1.15`, **no implementado**): ver C1 y §7.4. `1.14` no depende de él en código; lo deja escrito como contrato.
- **`ADR-062`** (implementado en `1.11-a`): columnas traducibles para el texto de las condiciones.
- **`data_exports`** de `Core` (implementado): primitiva de exportación en cola para el informe de consentimientos; requiere ampliar su catálogo de tipos (`kind`), como hizo `1.9b` (`OPEN-FAM-UNIT-11`).

---

## 2. Actores y roles implicados

| Actor | Qué hace en este módulo |
|-------|--------------------------|
| **Administrador de Centro** | Todo lo no especial: unidades, tutores, vínculos, revocaciones, versiones de condiciones, consulta de consentimientos e informe |
| **Secretaría** | Gestión diaria de unidades, tutores y vínculos; registro de decisiones de imagen entregadas en papel; revocaciones si el centro lo decide (siembra en `OPEN-FAM-UNIT-10`) |
| **Dirección** | Consulta |
| **Tutor legal / Familia** (rol `tutor_legal`) | Consulta su unidad y sus vínculos activos; registra el consentimiento de imagen de los alumnos que representa legalmente; acepta las condiciones de uso. Todo con ámbito `unidad_familiar` |
| **Docentes, tutor de grupo** | Nada en `1.14`. Ver a los tutores de sus alumnos exige el ámbito `grupo`, que necesita matrícula (`1.15`) |
| **Conserjería / PAS** | Nada en `1.14`; es el usuario principal de `1.14b` |
| **Roles personalizados** | Ciudadanos de primera: ninguna regla de este módulo compara códigos de rol (`RN-PERM-46`, `AR-08`). La siembra por código de rol vive en un comando de `Core`, como en `acad` |
| **Módulos consumidores** | `ALUM` (registra alumnado en la unidad), `COM` (destinatarios), módulos con datos de alumnado (resolutor `unidad_familiar`), publicadores de imagen (`INV-014`), `1.14b` (revocaciones). Solo por las interfaces de §7 |
| **`soporte_plataforma`**, Super Administrador | Sin permisos de este módulo |

---

## 3. Conceptos

### 3.1 Unidad familiar

- Tiene un **código único** entre las unidades vivas del centro (`REQ-FAM-UNIT-001`). Si lo genera el sistema o lo escribe el centro: `OPEN-FAM-UNIT-06`.
- Agrupa **tutores** (cada tutor pertenece a una sola unidad, `§16.2`) y **alumnos** (cada alumno a una sola unidad, `§16.2`, «cada estudiante pertenece a una Unidad Familiar»).
- Tiene como mucho **un tutor principal**, que es uno de sus tutores (`REQ-FAM-UNIT-001`). El resto son secundarios.
- No depende del curso académico (`ADR-034 §4`).

### 3.2 Tutor (faceta)

- Es la persona de un **usuario** del centro en su papel de tutor familiar. `REQ-FAM-UNIT-001`: «cada tutor tiene su propia cuenta de usuario independiente». Por eso el alta de un tutor parte de un usuario ya creado en `Core` (con invitación o activo) y no crea personas ni cuentas (`RN-FAM-UNIT-10`).
- Es una faceta 0..1 de `people` con la forma que fija `ADR-034 §1`: `person_id` único entre las vivas y clave foránea compuesta.
- **No asigna roles.** Que el usuario tenga un rol con los permisos de familia (`tutor_legal` u otro personalizado) lo decide quien administra usuarios en `Core`. Este módulo comprueba permisos, nunca roles (`AR-08`).
- Una persona puede ser a la vez tutora y docente (`§16.3`, regla 1): dos facetas, una persona, un usuario, dos roles. La resolución multi-rol es la de `RPERM-007`.

### 3.3 Vínculo tutor ↔ alumno

Un vínculo une un tutor con la persona de un alumno. Atributos (`REQ-FAM-UNIT-002`):

| Atributo | Valores | Fuente |
|----------|---------|--------|
| Tipo de relación | `padre`, `madre`, `tutor_legal`, `abuelo`, `apoderado`, `otro` | `REQ-FAM-UNIT-001`/`-002` enumeran los cinco primeros con «…»; `otro` cierra la lista sin inventar más (`OPEN-FAM-UNIT-07`) |
| Representación legal | Sí / no, con fecha y autor de la verificación | **[DERIVADA]** de `REQ-PRIV-002` («verificación de la relación de tutela») e `INV-008`: solo quien ostenta la patria potestad o la tutela puede consentir por el menor. El tipo de relación no basta (un abuelo puede ser tutor legal por resolución; un padre puede estar privado de la patria potestad) |
| Nivel de acceso | `lectura`, `lectura_escritura` | `REQ-FAM-UNIT-001` («acceso de lectura o lectura/escritura configurable»). Qué permite escribir: `OPEN-FAM-UNIT-04` |
| Categorías restringidas | Subconjunto de un vocabulario cerrado | `REQ-FAM-UNIT-002` («restringir información sensible a ciertos tutores, ej. datos médicos»). Vocabulario: `OPEN-FAM-UNIT-07` (recomendación: solo `salud`, el único ejemplo del requisito) |
| Contacto de emergencia | Sí / no; como mucho uno por alumno | `REQ-FAM-UNIT-002` («designación de **un** tutor») |
| Estado | `activo`, `revocado` | Nota legal de `REQ-FAM-UNIT-002` |

Un vínculo **puede cruzar unidades** (C2): el padre que pertenece a la unidad A puede tener vínculo con un alumno de la unidad B. Lo que da acceso a los datos del alumno es el **vínculo activo**, no la pertenencia a la unidad (§3.6).

### 3.4 Tutor principal

Gestiona la unidad, añade o elimina tutores y «autoriza cambios» (`REQ-FAM-UNIT-001`). En `1.14` el tutor principal es un **dato** (quién es) que queda expuesto por API y por §7.1 para los módulos que lo necesiten (`1.14b` lo necesita: «el alta de un tercero requiere autorización del tutor principal»). Las **capacidades de autoservicio** del tutor principal sobre su unidad no se implementan en `1.14` hasta resolver `OPEN-FAM-UNIT-04`.

### 3.5 Revocación por custodia

- Revocar pasa el vínculo a `revocado` con motivo `resolucion_judicial`, fecha y autor. **Efecto inmediato** en todo el producto: el tutor deja de ver al alumno, deja de recibir sus comunicaciones y (desde `1.14b`) queda fuera de la lista de autorizados (§7.1).
- **No afecta al resto de la unidad** (nota legal de `REQ-FAM-UNIT-002`): los demás vínculos del mismo tutor y los de los demás tutores siguen igual.
- **Levantar** la revocación (nueva resolución) devuelve el vínculo a `activo`, con fecha y autor.
- No se borra nunca el vínculo por revocarlo. El histórico queda en `audit_logs` (`INV-003`).
- Motivo único `resolucion_judicial`: el requisito no contempla otros. Una retirada que no sea judicial es una **baja** del vínculo, no una revocación (`OPEN-FAM-UNIT-07`).
- El detalle documental de la resolución es otra cosa (§3.7, condicionado).

### 3.6 Ámbito `unidad_familiar`

**[DERIVADA]**, y es la decisión central del módulo: el ámbito se resuelve por **vínculos activos**, no por pertenencia a la unidad. Un sujeto (usuario autenticado) con ámbito `unidad_familiar` alcanza:

- de un **alumno**: solo si existe un vínculo **activo** entre la faceta de tutor de la persona del sujeto y la persona de ese alumno;
- de una **unidad familiar**: solo si el sujeto tiene al menos un vínculo activo con un alumno de esa unidad;
- de un **vínculo**: solo los vínculos **activos** cuyo alumno está alcanzado por el sujeto (ve a los demás tutores activos de sus hijos, nunca los vínculos revocados).

Por qué así: es la única forma de que la revocación de un tutor sobre un alumno «no afecte al resto de la unidad» y, a la vez, de que el tutor revocado no vea nada de ese alumno aunque siga perteneciendo a la unidad. Una resolución por pertenencia a la unidad le seguiría dejando ver al alumno.

### 3.7 Restricción de custodia (condicionada a `OPEN-FAM-UNIT-05`)

Documentación de la resolución judicial asociada a una revocación. Es la parte que el encargo pide tratar como **categoría especial** (`CLAUDE.md §8`): tabla separada, cifrado a nivel de campo, permiso propio con `is_special_category = true`, no incluido en ningún rol por defecto (`RPERM-012`) y auditoría de **lectura** (`RPERM-015`). Ningún requisito enumera sus campos: la propuesta mínima (referencia de la resolución, fecha, documento adjunto) es **[DERIVADA]** y se somete a `OPEN-FAM-UNIT-05`.

### 3.8 Consentimiento de imagen

- **Dos niveles independientes** (`REQ-FAM-UNIT-004`): `web` (portal web del centro: web pública, galerías, publicaciones internas) y `redes_sociales` (Facebook, Instagram, X, LinkedIn, *newsletters*, prensa). **Decisión del usuario (2026-10-27)**: son exactamente estos dos niveles; **no** se separa el consentimiento por red social ni por medio. Las redes, *newsletters* y prensa que enumera el requisito son ejemplos del nivel `redes_sociales`, no niveles propios. Añadir un nivel exigiría ampliar el `CHECK` de `level`, el enumerado público de §7.3 y un `kind` de texto nuevo, con su especificación.
- **Fuente única** (`ADR-066 §5.1`): este módulo es el único dueño de las decisiones, del texto al que se refieren y del estado vigente. Ningún otro módulo guarda decisiones, estados, marcas ni copias, ni en tablas, ni en columnas, ni en caché entre peticiones.
- **Estado por alumno y nivel**: `autorizo`, `no_autorizo` o `pendiente`.
- Cada **decisión** de un tutor es una fila **inmutable** (`§16.3`, regla 6: consentimientos *append-only*) con la versión del texto del consentimiento, fecha y hora, IP, canal y autor.
- El **estado vigente se calcula en cada consulta** a partir de quienes deciden (vínculos vivos con representación legal, `RN-FAM-UNIT-41`) y de sus decisiones (`RN-FAM-UNIT-45`). **No hay tabla de estado vigente ni proyección** (`ADR-066 §5.2`, `OPEN-066-01` ratificada): así el estado cambia en el instante en que cambia el conjunto de quienes deciden (alta, baja o restauración de un vínculo, cambio de `is_legal_guardian`), sin depender de ningún recálculo.
- Revocar el consentimiento es registrar `no_autorizo`: efecto inmediato (`REQ-FAM-UNIT-004`).
- Sin ninguna decisión que cuente, el estado es `pendiente`, que **bloquea** igual que `no_autorizo` (`REQ-FAM-UNIT-004`, `INV-014`).
- **Texto del consentimiento versionado** (`ADR-066 §5.5`, `OPEN-FAM-UNIT-09`): un texto por nivel (`kind` `consentimiento_imagen_web` y `consentimiento_imagen_redes` de `terms_versions`, §3.9), redactado por el centro y traducible (`ADR-062`). `autorizo` exige texto publicado; `no_autorizo`, no (`RN-FAM-UNIT-68`).
- **El tutor puede leer el texto publicado** de cada nivel, en su idioma, antes de decidir (`GET /image-consent-texts`, `RN-FAM-UNIT-75`, `OPEN-FAM-UNIT-23` resuelta): sin eso, el canal `portal` registraría la versión de un texto que el tutor no tiene forma de leer por la API.

### 3.9 Textos legales versionados y condiciones de uso

- Un único mecanismo de versiones (`terms_versions`) para tres **tipos** (`kind`): `condiciones_uso`, `consentimiento_imagen_web` y `consentimiento_imagen_redes` (`ADR-066 §5.5`). Un tipo por nivel de imagen, porque son consentimientos distintos con finalidades distintas. `1.14-b` crea la tabla y gestiona los dos tipos de imagen; `condiciones_uso` entra con `1.14-c` (`RN-FAM-UNIT-73`).
- Texto del centro (`REQ-FAM-UNIT-003`: «personalizables por el Administrador de Centro»), contenido traducible según `ADR-062` (capa 3 de `REQ-CORE-006`).
- **Versionado**: `borrador` → `publicada` → `retirada`. Como mucho una versión `publicada` a la vez **por tipo**; numeración correlativa por centro y tipo.
- Al publicar una versión nueva **de condiciones de uso**, el centro decide si **exige re-aceptación** (`REQ-FAM-UNIT-003`: «posibilidad de requerir re-aceptación»). En los tipos de imagen la re-aceptación no tiene sentido (`CHECK` de `datos.md §1.8`): publicar un texto nuevo de imagen no altera ningún estado (`RN-FAM-UNIT-70`).
- Cada aceptación es una fila inmutable con usuario, versión, fecha y hora, IP y agente de usuario (`REQ-FAM-UNIT-003`: «fecha, hora, IP y versión»).
- Las traducciones del texto legal las escribe el centro. El agente `traductor` **no** las genera (`CLAUDE.md §2`: nunca textos legales ni de consentimiento).

---

## 4. Flujos principales

Todos son **API** (`INV-006`); rutas y cuerpos en `api.md`.

### 4.1 Dar de alta una unidad familiar

1. Secretaría crea la unidad (`POST /family-units`). Código según `OPEN-FAM-UNIT-06`.
2. Añade tutores (§4.2) y alumnos (§4.3).
3. Designa el tutor principal (`PATCH /family-units/{id}` con `principal_guardian`).

### 4.2 Dar de alta un tutor

1. El usuario ya existe en `Core` (`POST /users`, con la invitación y el rol que corresponda: fuera de este módulo).
2. Secretaría crea la faceta (`POST /guardians` con el `public_id` del **usuario** y la unidad). El servidor resuelve la persona del usuario por la interfaz ampliada de `Core` (`OPEN-FAM-UNIT-03`).
3. Validación: el usuario existe, está vivo y es del centro; su persona no tiene ya una faceta de tutor viva (`RN-FAM-UNIT-11`).

### 4.3 Añadir un alumno a una unidad

1. Se indica la persona del alumno (`POST /family-units/{id}/students` con `student_person`, §7.4) — o lo hace `ALUM` por interfaz al dar de alta un alumno (§7.4).
2. Validación: la persona existe en el centro, no pertenece ya a otra unidad viva (`RN-FAM-UNIT-20`).

### 4.4 Vincular, configurar y revocar

1. Secretaría crea el vínculo (`POST /guardian-links`): tutor, alumno, tipo de relación, representación legal, nivel de acceso, categorías restringidas, contacto de emergencia.
2. Lo modifica con `PATCH /guardian-links/{id}`. Reenviar `is_legal_guardian = true` sobre un vínculo que ya lo tiene **no** reverifica la representación legal (`RN-FAM-UNIT-74`, desde `1.14-b`).
3. **Revocación**: `POST /guardian-links/{id}/revocation`. Efecto inmediato (§3.5). **Levantamiento**: `DELETE /guardian-links/{id}/revocation`.
4. Baja del vínculo (no judicial): `DELETE /guardian-links/{id}` (lógica).

### 4.5 Registrar la documentación de una custodia (condicionado a `OPEN-FAM-UNIT-05`)

`PUT /guardian-links/{id}/custody-restriction` sobre un vínculo revocado. Cada lectura (`GET`) registra un evento `read` en `audit_logs` (`RPERM-015`).

### 4.6 Registrar una decisión de imagen

**Por el propio tutor** (API; pantalla en `1.22`):

0. Lee el texto publicado de cada nivel en su idioma (`GET /image-consent-texts`, permiso `consentimiento_imagen.leer`, `RN-FAM-UNIT-75`).
1. `POST /image-consent-decisions` con alumno, nivel y decisión. Permiso `consentimiento_imagen.actualizar` con ámbito `unidad_familiar` (o `todos`).
2. Validación de negocio, además del permiso: el sujeto tiene vínculo **activo** con el alumno **y** con representación legal (`RN-FAM-UNIT-41`). Un tutor con el vínculo **revocado** no alcanza al alumno con su ámbito y no puede registrarla por sí mismo (`RN-FAM-UNIT-35`, `CA-FAM-UNIT-053`).
3. Si la decisión es `autorizo`, debe existir una versión `publicada` del texto de ese nivel; se registra esa versión y el idioma en que se mostró (`RN-FAM-UNIT-68`, `-69`, `-43`).
4. Se escribe la decisión (inmutable) y se audita. **No hay recálculo ni bloqueo de fila**: el estado se calcula al consultarlo (`RN-FAM-UNIT-44`). La respuesta incluye el estado resultante, calculado tras la escritura.

**Por el centro, en nombre del tutor** (papel entregado en secretaría): igual, con `on_behalf_of` (el tutor que decidió) y canal `presencial`; el autor es el usuario del centro (`RN-FAM-UNIT-43`). Puede indicar la versión del texto que firmó la familia (`publicada` o `retirada` del mismo tipo; por defecto, la publicada, `RN-FAM-UNIT-69`). **El tutor puede tener el vínculo revocado** (revocar quita el acceso, no la patria potestad, `RN-FAM-UNIT-37`): entonces el centro necesita además `consentimiento_imagen.actualizar` de ámbito `todos` **y** `revocacion_tutor.leer`; sin este último, el tutor revocado es invisible (`RN-FAM-UNIT-40`) y la petición responde igual que con un tutor no vinculado (`RN-FAM-UNIT-41`, `ADR-066 §5.4`).

### 4.7 Consultar consentimientos

`GET /image-consents` (estado vigente calculado; filtros por unidad, alumno, nivel y estado), `GET /image-consent-decisions` (histórico de un alumno) y `GET /image-consent-texts` (texto publicado de cada nivel, `RN-FAM-UNIT-75`). El listado parte de los alumnos **vivos** de las unidades (`family_unit_students`), de modo que `state=pendiente` incluye a los alumnos sin ninguna decisión (`ADR-066 §5.2`, punto 2). El histórico, con ámbito `unidad_familiar`, omite las decisiones de tutores cuyo vínculo con ese alumno está revocado (`RN-FAM-UNIT-71`).

### 4.8 Informe de consentimientos por unidad familiar

`POST /image-consents/exports`: CSV en cola por la primitiva `data_exports` de `Core` (`INV-012`), con enlace caducable, auditado como `exported`. «Informe periódico» (`REQ-FAM-UNIT-004`): si además debe generarse solo cada cierto tiempo, `OPEN-FAM-UNIT-11`.

### 4.9 Publicar una versión de un texto legal (`1.14-b` para los tipos de imagen; `1.14-c` para `condiciones_uso`)

1. El administrador crea una versión en `borrador` (`POST /terms-versions`) con su `kind`, título y texto traducibles. En `1.14-b`, `kind = condiciones_uso` ⇒ `422` (`RN-FAM-UNIT-73`).
2. La publica (`POST /terms-versions/{id}/publish`); en `condiciones_uso`, indicando si exige re-aceptación. La versión publicada anterior **del mismo tipo** pasa a `retirada` en la misma transacción; las de otros tipos no cambian.

### 4.10 Aceptar las condiciones (muro)

1. Tras iniciar sesión, un usuario **obligado** (§5, `RN-FAM-UNIT-61`) sin aceptación de la versión exigible ve el muro (`BareLayout`, como el muro de MFA de `REQ-AUTH-003`).
2. `GET /me/terms` devuelve la versión pendiente; `POST /me/terms-acceptances` registra la aceptación. Ambos por identidad, sin permiso (precedente `GET /me`).
3. Hasta aceptar, el resto de la API responde `403` con `urn:pge:error:terms-acceptance-required` (mecanismo: `OPEN-FAM-UNIT-12`).

---

## 5. Reglas de negocio

### 5.1 Unidades y tutores

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-01` | El código de unidad es único entre las unidades **vivas** del centro (`UNIQUE` parcial sobre `deleted_at IS NULL`, `ADR-034 §6`). |
| `RN-FAM-UNIT-02` | Una unidad tiene como mucho un tutor principal, y debe ser un tutor **vivo de esa misma unidad**. Se comprueba en servidor (`INV-010`) y con clave foránea. |
| `RN-FAM-UNIT-03` | Dar de baja (lógica) una unidad exige que no tenga tutores ni alumnos vivos: `409 fam_unit.family_unit.has_members`. [DERIVADA] Evita dejar alumnos «sin unidad», contra `REQ-FAM-UNIT-001`. |
| `RN-FAM-UNIT-04` | La baja de una unidad, de un tutor, de una pertenencia o de un vínculo es **lógica** (`INV-004`). La restauración reaplica las mismas validaciones que el alta. |
| `RN-FAM-UNIT-10` | El alta de un tutor parte de un **usuario** del centro, vivo, en estado `pendiente` o `activo`. Este módulo no crea personas, cuentas, invitaciones ni asignaciones de rol. |
| `RN-FAM-UNIT-11` | Una persona tiene como mucho **una** faceta de tutor viva (`ADR-034 §1`), y esa faceta pertenece a **una** unidad (`§16.2`). |
| `RN-FAM-UNIT-12` | Dar de baja un tutor da de baja, en la misma transacción, sus vínculos vivos; si era tutor principal, la unidad queda sin principal (`principal_guardian = null`) y la respuesta lo indica. [DERIVADA] |
| `RN-FAM-UNIT-13` | Si el usuario de un tutor se da de baja en `Core`, la faceta **no** se borra sola en `1.14` (no hay consumidor del evento `UserDeactivated` en este módulo; `ADR-048`). El tutor deja de poder actuar porque no tiene sesión. Ver `OPEN-FAM-UNIT-16`. |
| `RN-FAM-UNIT-14` | Un tutor **no cambia de unidad**: el cuerpo de `PATCH /guardians/{id}` rechaza `family_unit` con `422 fam_unit.guardian.not_editable` (incluso si es la misma unidad). Cambiar la unidad de un tutor es darlo de baja (`RN-FAM-UNIT-12`, que limpia el principal y sus vínculos) y crearlo de nuevo en la otra. [DERIVADA, decisión del usuario del 2026-10-26] Evita decidir qué pasa con el principal de la unidad de origen y con vínculos que pueden cruzar unidades. |

### 5.2 Alumnado de la unidad

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-20` | Una persona de alumno pertenece como mucho a **una** unidad viva (`§16.2`). |
| `RN-FAM-UNIT-21` | La persona de un alumno no puede ser a la vez la persona de un tutor **vinculado a sí misma**: `422 fam_unit.link.self_link`. |
| `RN-FAM-UNIT-22` | Quitar un alumno de una unidad no borra sus vínculos (que pueden cruzar unidades, C2): se informa en la respuesta del número de vínculos vivos que conserva. [DERIVADA] |

### 5.3 Vínculos y revocación

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-30` | Hay como mucho **un** vínculo vivo por par (tutor, alumno). |
| `RN-FAM-UNIT-31` | Como mucho **un** contacto de emergencia por alumno entre sus vínculos vivos y **activos** (índice único parcial). Revocar el vínculo que lo era retira la marca en la misma transacción. |
| `RN-FAM-UNIT-32` | Las categorías restringidas son un subconjunto del vocabulario cerrado (`CHECK` en base de datos); un valor fuera de él ⇒ `422`. |
| `RN-FAM-UNIT-33` | Solo se puede revocar un vínculo `activo` y solo levantar uno `revocado`; lo contrario ⇒ `409 fam_unit.link.invalid_transition`. |
| `RN-FAM-UNIT-34` | La revocación tiene **efecto inmediato**: ninguna interfaz de §7 cachea pertenencias ni revocaciones entre peticiones; se memoiza como mucho dentro de una petición (`ADR-044 §4.7`). |
| `RN-FAM-UNIT-35` | Un vínculo `revocado` no cuenta para ningún ámbito `unidad_familiar`, ni como destinatario (§7.2), ni como contacto de emergencia, y **el tutor revocado no puede registrar por sí mismo** decisiones de imagen sobre ese alumno. El centro **sí** puede registrarlas en su nombre por el canal `presencial` (`RN-FAM-UNIT-41`), y esas decisiones cuentan para el estado: revocar quita el acceso, no la patria potestad (`RN-FAM-UNIT-37`, `ADR-066 §5.4`). Marcar como contacto de emergencia un vínculo `revocado` ⇒ `422 fam_unit.link.emergency_contact_revoked` (la base de datos lo impide además con un `CHECK`). |
| `RN-FAM-UNIT-36` | Un sujeto que solo tiene ámbito `unidad_familiar` **nunca** ve vínculos revocados, ni motivo, ni fechas de revocación, ni la existencia de una restricción de custodia, aunque sean de su propia unidad. [DERIVADA] Evita que un tutor revocado o un tercero infiera una resolución judicial. |
| `RN-FAM-UNIT-37` | Revocar no cambia la representación legal del vínculo. Perder el acceso no es perder la patria potestad: el tutor revocado sigue entre quienes deciden el consentimiento de imagen (`OPEN-FAM-UNIT-08` aprobada, `ADR-066 §5.4`, `RN-FAM-UNIT-41`). |
| `RN-FAM-UNIT-66` | Revocar y levantar la revocación (`POST`/`DELETE /guardian-links/{id}/revocation`) tienen un límite por centro y usuario, con un cubo propio cada acción (30 intentos por hora por defecto, `config/fam_unit.php`); superado, `429` con `Retry-After`. Cuenta todos los intentos. Lecturas y demás escrituras, sin límite propio. [DERIVADA, decisión del usuario del 2026-10-27, issue #470] |
| `RN-FAM-UNIT-40` | Sin `revocacion_tutor.leer`, los vínculos `revocado` no se ven: `GET /guardian-links` los omite y el detalle de uno es `404` (indistinguible de un id inexistente). El filtro `status=revocado` sigue siendo `403`. Con el permiso se ven con su `revocation`. La regla vale también para `PATCH`, `DELETE` y la revocación (`GuardianLinkService::find()`, #472); el levantamiento queda exento: actúa justo sobre un revocado y lo autoriza `revocacion_tutor.eliminar`. [DERIVADA, hallazgo de `security-reviewer`, 2026-10-26] El estado revela una resolución judicial: es el motivo del permiso separado (`permisos.md §1`, `§5`). |
| `RN-FAM-UNIT-39` | Un vínculo `revocado` **no se da de baja**: ni directamente (`DELETE /guardian-links/{id}`) ni arrastrado por la baja del tutor (`RN-FAM-UNIT-12`), que se bloquea entera si alguno de sus vínculos está revocado. Ambos responden `409 fam_unit.link.revoked_not_deletable`. La revocación solo se levanta de forma explícita (`DELETE …/revocation`). [DERIVADA, decisión del usuario del 2026-10-26] Así `isAccessRevoked()` no pierde una revocación por una baja. |
| `RN-FAM-UNIT-38` | El tutor principal de una unidad no puede tener **todos** sus vínculos con alumnos de esa unidad revocados: revocar el último ⇒ la unidad queda sin principal en la misma transacción y la respuesta lo indica. [DERIVADA] |
| `RN-FAM-UNIT-74` | **La representación legal solo se verifica en la transición `false → true`** (`OPEN-FAM-UNIT-24`, aprobada por el usuario el 2026-10-27). `legal_guardian_verified_at` y `legal_guardian_verified_by` se fijan con el instante y el usuario de la petición **solo** cuando `is_legal_guardian` pasa de `false` a `true` (en `PATCH`) o cuando el vínculo se crea con `true` (`POST`). Un `PATCH` que reenvía `is_legal_guardian = true` sobre un vínculo que ya lo tiene **no** cambia ninguno de los dos campos, ni deja cambio de esos atributos en `audit_logs`. Pasar a `false` los vacía (sin cambios). **Es un cambio de comportamiento de `1.14-a`** (`GuardianLinkService`, ya mezclado, que hoy los vuelve a fijar en todo `PATCH` con `true`); se implementa en `1.14-b` con test de regresión (`CA-FAM-UNIT-102`). Efecto sobre `RN-FAM-UNIT-72`: una edición rutinaria del vínculo ya no deja sin efecto las decisiones de imagen del tutor; solo una reverificación **explícita** (desmarcar y volver a marcar, `CA-FAM-UNIT-096`) lo hace. |

### 5.4 Consentimiento de imagen (`1.14-b`, enmendada por `ADR-066`)

> **Numeración**: la regla de niveles y decisiones era un segundo `RN-FAM-UNIT-40`, duplicado con el de §5.3 (visibilidad de revocados, ya referenciado por tests y código de `1.14-a`). Pasa a **`RN-FAM-UNIT-67`** (§14.2). Las reglas nuevas de `ADR-066` son `RN-FAM-UNIT-68` a `-73`; las de la aprobación del 2026-10-27 (§17), `RN-FAM-UNIT-74` (en §5.3) y `-75`.

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-67` | Niveles cerrados: `web`, `redes_sociales`. Decisiones: `autorizo`, `no_autorizo`. `pendiente` es la **ausencia** de decisión vigente que cuente, no un valor que se registra. Los dos niveles son cerrados por decisión del usuario (2026-10-27): no hay consentimiento por red social (§3.8). *(Antes, segundo `RN-FAM-UNIT-40`.)* |
| `RN-FAM-UNIT-41` | **Quienes deciden** el consentimiento de imagen de un alumno: los tutores con vínculo **vivo** (`deleted_at IS NULL`) con `is_legal_guardian = true` sobre ese alumno, **estén `activo` o `revocado`** (`OPEN-FAM-UNIT-08` aprobada, `ADR-066 §5.4`). Un tutor sin representación legal (abuelo, apoderado) no decide. Lo comprueba el servidor además del permiso: **(a) por el propio tutor** (canal `portal`): exige vínculo **activo** propio con representación legal; sin representación ⇒ `422 fam_unit.image_consent.not_legal_guardian`; con el vínculo revocado no alcanza al alumno ⇒ `404` (`RN-FAM-UNIT-35`). **(b) Por el centro en nombre de un tutor** (`on_behalf_of`, canal `presencial`, ámbito `todos`): el tutor indicado debe estar entre quienes deciden; sin vínculo vivo con el alumno ⇒ `422 fam_unit.image_consent.guardian_not_linked`; sin representación legal ⇒ `422 fam_unit.image_consent.not_legal_guardian`; si su vínculo está **revocado**, se acepta solo si el sujeto tiene además `revocacion_tutor.leer`; sin él, el revocado es invisible (`RN-FAM-UNIT-40`) y se responde exactamente igual que con un tutor no vinculado (`422 guardian_not_linked`). |
| `RN-FAM-UNIT-42` | Las decisiones son **inmutables** (`§16.3`, regla 6): tabla de solo anexar, `REVOKE UPDATE, DELETE` en el motor y modelo `AppendOnlyModel` (`ADR-034 §6`). Corregir es registrar otra decisión. |
| `RN-FAM-UNIT-43` | Cada decisión registra: alumno, nivel, decisión, tutor que decide, usuario que la registra (el propio tutor o el del centro), canal (`portal` o `presencial`), **versión del texto del consentimiento** (obligatoria con `autorizo`, registrada si existe con `no_autorizo`, `RN-FAM-UNIT-68`), **idioma en que se mostró el texto** (`shown_locale`, solo por el canal `portal`, mismo criterio que `RN-FAM-UNIT-65`: el idioma **realmente mostrado** tras la caída de `ADR-062`, no el de la petición; si no hay traducción al idioma de la petición, el idioma por defecto del centro en que se sirvió; nulo por `presencial`) [DERIVADA, `ADR-066 §9.5`; **aceptada por el usuario el 2026-10-27**; idioma efectivo por `OPEN-FAM-UNIT-25`, resuelta el mismo día], fecha y hora, IP y agente de usuario. |
| `RN-FAM-UNIT-44` | *(Sustituida por `ADR-066 §5.2`.)* **No hay estado vigente almacenado, ni recálculo, ni bloqueo de fila**: el estado se calcula en cada consulta desde quienes deciden y sus decisiones. Dos decisiones simultáneas son dos filas, y el cálculo siempre ve las dos. |
| `RN-FAM-UNIT-45` | **Regla de combinación** (`OPEN-FAM-UNIT-08` aprobada, `ADR-066 §5.4`). Decisión vigente de cada uno de quienes deciden: su última decisión **que cuente** para ese alumno y nivel, por `(occurred_at, id)` (`RN-FAM-UNIT-72`). Estado: **`no_autorizo`** si alguno tiene como decisión vigente `no_autorizo`; **`autorizo`** si hay al menos uno y **todos** tienen como decisión vigente `autorizo`; **`pendiente`** en cualquier otro caso (nadie decide, o alguno no ha decidido todavía). Una decisión de un tutor que ya no está entre quienes deciden (vínculo dado de baja o sin representación legal) se conserva como evidencia, pero no cuenta. **Condición previa a datos reales**: validar la unanimidad y que el revocado siga contando con el DPO o la asesoría del centro piloto; si cambia, cambia esta tabla (dentro de `fam_unit`, sin tocar el contrato de §7.3) y hace falta un ADR que sustituya a `ADR-066` en esa parte. |
| `RN-FAM-UNIT-46` | `pendiente` y `no_autorizo` **bloquean** la publicación en ese nivel (`INV-014`). La interfaz de §7.3 responde «no publicable», con el alumno entre los que bloquean, también si el alumno no tiene ninguna decisión, si no tiene a nadie que decida, si no existe, si no pertenece al centro o si el módulo no está contratado (fallo en cerrado). **Una lista de alumnos vacía es un error de programación y lanza una excepción**; nunca se interpreta como «publicable» (`ADR-066 §5.3`). |
| `RN-FAM-UNIT-47` | Los dos niveles son independientes: una decisión sobre `web` no altera `redes_sociales`. |
| `RN-FAM-UNIT-68` | **`autorizo` exige texto publicado**: sin una versión `publicada` del texto de su nivel (`consentimiento_imagen_web` para `web`, `consentimiento_imagen_redes` para `redes_sociales`), la petición se rechaza con `422 fam_unit.image_consent.text_not_published`, no se escribe nada y el estado sigue `pendiente`. **`no_autorizo` no la exige**: negarse no necesita información previa y no puede bloquearse por falta de texto; se registra la versión si existe. En base de datos: `CHECK (decision = 'no_autorizo' OR consent_text_version_id IS NOT NULL)` (`ADR-066 §5.5.4`). |
| `RN-FAM-UNIT-69` | **Versión elegible por canal** (`ADR-066 §5.5.5`): por `portal`, la `publicada` en ese momento; el cuerpo no admite elegirla (`422 fam_unit.image_consent.text_version_invalid`). Por `presencial` (papel firmado antes), el centro puede indicar una versión `publicada` o `retirada` **del tipo que corresponde al nivel**; nunca un `borrador` ni otro tipo (`422 text_version_invalid`). Sin indicarla, la `publicada`. |
| `RN-FAM-UNIT-70` | **Publicar una versión nueva del texto no altera ningún estado** (`ADR-066 §5.5.6`, `OPEN-066-02`): las decisiones anteriores siguen contando con la versión a la que se refieren, y el estado vigente y el histórico la muestran para que el centro sepa qué familias decidieron sobre un texto anterior. Si la asesoría responde que un texto nuevo invalida los `autorizo` anteriores (**condición previa a datos reales**), se implementará como filtro por versión mínima en el cálculo; no se construye antes (`ADR-048`). |
| `RN-FAM-UNIT-71` | En el **histórico consultado con ámbito `unidad_familiar`** no aparecen las decisiones de tutores cuyo vínculo con ese alumno esté **revocado**. Cuentan para el estado (`RN-FAM-UNIT-45`), pero mostrarlas revelaría la existencia de esa persona y su relación con el centro (`RN-FAM-UNIT-36`, `ADR-066 §5.6`). |
| `RN-FAM-UNIT-72` | **Solo cuentan las decisiones posteriores a la verificación de la representación legal** del vínculo vivo: `occurred_at > legal_guardian_verified_at` (`OPEN-066-04`). Si un tutor pierde y recupera la representación legal, o se vuelve a verificar, sus decisiones anteriores se conservan como evidencia pero dejan de contar (fallo en cerrado). Con `legal_guardian_verified_at` nulo, ninguna decisión de ese tutor cuenta [DERIVADA: el `CHECK` de `datos.md §1.4` admite `is_legal_guardian = true` con fecha nula, aunque la API la rellena siempre; **aceptada por el usuario el 2026-10-27**]. La fecha solo cambia en la transición `false → true` (`RN-FAM-UNIT-74`, `OPEN-FAM-UNIT-24` resuelta): reenviar `true` en un `PATCH` no deja sin efecto las decisiones del tutor. |
| `RN-FAM-UNIT-73` | **Textos legales por tipo** (`ADR-066 §5.5.1-2`): `kind` ∈ {`condiciones_uso`, `consentimiento_imagen_web`, `consentimiento_imagen_redes`}. Como mucho una versión `publicada` por centro **y tipo**; numeración correlativa por centro y tipo. Una versión `publicada` o `retirada` no se edita ni se borra; solo se borra un `borrador`. Una versión a la que apunta una decisión es evidencia (`datos.md §6`). **En `1.14-b`, `kind = condiciones_uso` se rechaza** en `POST /terms-versions` con `422 fam_unit.terms.kind_not_available` [DERIVADA: el código; `ADR-066 §9.1` fija solo el `422`; **aceptada por el usuario el 2026-10-27**] hasta `1.14-c`. El `kind` es inmutable tras la creación: `PATCH` con `kind` ⇒ `422 fam_unit.terms.kind_not_editable` [DERIVADA, `api.md §1.7`; **aceptada por el usuario el 2026-10-27**]. |
| `RN-FAM-UNIT-75` | **Lectura del texto publicado por el tutor** (`OPEN-FAM-UNIT-23`, aprobada por el usuario el 2026-10-27). `GET /image-consent-texts` devuelve, para cada nivel (`web`, `redes_sociales`), la versión **`publicada`** de su tipo con título y cuerpo resueltos en el idioma de la petición con la caída de `ADR-062`, y el idioma en que vienen. Un nivel sin versión publicada aparece con la versión a `null`. **Nunca** devuelve `borrador` ni `retirada` (la gestión de versiones sigue siendo `texto_legal.*`, `RN-FAM-UNIT-73`). Permiso `consentimiento_imagen.leer`, con ámbito `todos` o `unidad_familiar`. [DERIVADA; **aceptada por el usuario el 2026-10-27**] El ámbito **no filtra** la respuesta: el texto es el mismo para todo el centro y no contiene datos de ningún alumno ni tutor; basta tener el permiso concedido con cualquiera de sus dos ámbitos, también un tutor sin ningún vínculo activo. La respuesta incluye `locale`, el idioma en que vienen título y cuerpo (el mismo que se registra en `shown_locale`, `RN-FAM-UNIT-43`). Solo lectura, sin efectos ni auditoría de lectura (no es categoría especial). |

### 5.5 Condiciones de uso

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-60` | Como mucho una versión `publicada` **por tipo** (`RN-FAM-UNIT-73`). Publicar retira la anterior del mismo tipo en la misma transacción. Una versión `publicada` o `retirada` no se edita: `409`. Vale para los tres tipos; en `1.14-b`, solo para los de imagen. |
| `RN-FAM-UNIT-61` | **Usuario obligado** a aceptar: el que tiene una faceta de tutor viva con al menos un vínculo activo. [DERIVADA] `REQ-FAM-UNIT-003` habla del «tutor»; comprobarlo por faceta y no por rol mantiene `AR-08`. Si debe ampliarse a alumnado: `OPEN-FAM-UNIT-12`. |
| `RN-FAM-UNIT-62` | **Versión exigible**: la publicada, si exige re-aceptación o si el usuario nunca aceptó ninguna; si la publicada no exige re-aceptación, basta haber aceptado cualquier versión anterior. |
| `RN-FAM-UNIT-63` | Sin versión publicada, nadie está obligado y no hay muro. |
| `RN-FAM-UNIT-64` | Las aceptaciones son **inmutables** (tabla de solo anexar, como `RN-FAM-UNIT-42`). Aceptar dos veces la misma versión es idempotente: devuelve la aceptación existente. |
| `RN-FAM-UNIT-65` | El texto de la versión se exige en el **idioma por defecto del centro** y solo en idiomas activos (`ADR-062 §5.2`, `OPEN-062-01`). Se muestra en el idioma del usuario con la caída de `ADR-062`; la aceptación registra además **en qué idioma** se mostró. [DERIVADA] Es prueba de lo que el tutor leyó. |

---

## 6. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Tutor que es además docente del alumno al que se le ha revocado el acceso | El vínculo revocado no le da nada como familia; como docente, ve lo que su ámbito `grupo`/`clase` le dé cuando exista. Si una resolución judicial debe bloquear también ese acceso es una cuestión legal: `OPEN-FAM-UNIT-17`. **No se resuelve aquí.** |
| Padre con hijos en dos unidades | Pertenece a una; tiene vínculos con alumnos de ambas (C2). Su ámbito `unidad_familiar` alcanza a los alumnos de los dos vínculos |
| Alumno que cumple 18 años | Los tutores siguen vinculados; el requisito no dice nada. `OPEN-FAM-UNIT-18` |
| Alumno de 14 años o más y el consentimiento de imagen | `REQ-FAM-UNIT-004` asigna la decisión al tutor; la `LOPDGDD` (art. 7) permite al mayor de 14 consentir por sí mismo. No se inventa: `OPEN-FAM-UNIT-18`. Mientras no haya regla, deciden los de `RN-FAM-UNIT-41` sea cual sea la edad (`ADR-066 §5.6`); el comportamiento puede no ser conforme con alumnado de ESO o Bachillerato, y por eso el criterio jurídico es **condición previa a datos reales** (`OPEN-066-05`) |
| Dos peticiones simultáneas de decisión de imagen sobre el mismo alumno y nivel | Sin bloqueo (`RN-FAM-UNIT-44`, sustituida): quedan las dos filas y el estado es el que dicta la regla de combinación sobre ellas, ordenadas por `(occurred_at, id)` |
| Alta de un segundo representante legal de un alumno con estado `autorizo` | El estado pasa a `pendiente` **en ese instante**, sin ninguna decisión nueva, hasta que el segundo decida; la baja de ese vínculo lo devuelve (`ADR-066 §5.2`, `CA-FAM-UNIT-087`/`-088`) |
| Alumno con un progenitor revocado que nunca decidió | Bloqueado (`pendiente`) hasta que el centro registre en papel la decisión del revocado, con `revocacion_tutor.leer` (`RN-FAM-UNIT-41`). Fallo en cerrado, aceptado en `ADR-066 §7` |
| Centro sin texto de consentimiento publicado | Nadie puede registrar `autorizo`; todo el alumnado queda `pendiente`. `no_autorizo` sí se registra (`RN-FAM-UNIT-68`) |
| Revocación mientras el tutor tiene una sesión abierta | La siguiente petición ya no alcanza al alumno (`RN-FAM-UNIT-34`). No se cierra su sesión: sigue viendo a sus otros hijos |
| Tutor sin ningún vínculo activo | Sigue siendo tutor (faceta viva) pero su ámbito `unidad_familiar` es vacío y no está obligado al muro (`RN-FAM-UNIT-61`) |
| Usuario de otro centro como tutor | `POST /guardians` con un `public_id` de otro centro ⇒ `422 fam_unit.validation.user_not_found` (nunca se revela la existencia, `INV-001`) |
| Alumno de otro centro en un vínculo o pertenencia | Igual: `422 fam_unit.validation.student_not_found` |
| Módulo no contratado | Toda ruta ⇒ `403 urn:pge:error:module-disabled` (`RMOD-009`); las interfaces de §7 fallan en cerrado (`RN-FAM-UNIT-46`, §9) |
| Cambio de idiomas activos del centro con una versión publicada | La versión publicada no se toca; se muestra con la caída de `ADR-062` |

---

## 7. Interacción con otros módulos

Todas las interfaces viven en `App\Modules\FamUnit\Domain` (nunca en `Domain\Models`) y se registran en el contenedor desde el `ServiceProvider` del módulo. Ningún módulo lee las tablas de este (`INV-007`, `RARQ-ARC-003`). Firmas exactas: trabajo de implementación; aquí se fija el **contrato funcional**. Cada interfaz trabaja con **identificadores internos de persona** (`people.id`), igual que `UserDirectory::activeIdsAmong`, porque es lo que los módulos consumidores guardan en sus claves foráneas.

### 7.1 `FamilyMembership` (lectura)

| Consulta | Devuelve | Consumidores previstos |
|----------|----------|------------------------|
| Alumnos alcanzados por una persona (con o sin una categoría de información) | Ids de persona de los alumnos con vínculo **activo** con esa persona; si se pide una categoría, excluye los vínculos que la restringen | Resolutores `unidad_familiar` de otros módulos (`ADR-063 §5.2`): `ALUM`, `CALIF`, asistencia, `SALUD` |
| Tutores activos de un alumno | Ids de persona, con tipo de relación, representación legal, nivel de acceso y si es contacto de emergencia | `1.14b`, `ALUM` (ficha) |
| ¿Está revocado el acceso de la persona P al alumno A? | Booleano. `true` si existe un vínculo revocado entre ambos | **`1.14b`** (exclusión automática de la lista maestra, `ADR-032`) |
| Unidad de un alumno y su tutor principal | `public_id` de la unidad e id de persona del principal | `1.14b` (autorización del principal) |

Contrato de rendimiento: una consulta por llamada, sin N+1, memoizada **solo** dentro de la petición (`RN-FAM-UNIT-34`).

### 7.2 `GuardianRecipients` (lectura)

Para un conjunto de alumnos y, opcionalmente, una categoría de información: los **usuarios** a los que notificar (vínculo activo, no restringido para esa categoría, usuario vivo). Consumidor: `REQ-COM` (`1.19`), para «notificación a todos los tutores de eventos relevantes» (`REQ-FAM-UNIT-002`). El idioma de cada destinatario lo resuelve `COM` con `UserDirectory`.

### 7.3 `ImageConsentPolicy` (lectura, `INV-014`, `ADR-066 §5.3`)

| Consulta | Devuelve |
|----------|----------|
| `isPublishable(nivel, alumnos)` | Booleano: `true` solo si **todos** los alumnos tienen estado `autorizo` en ese nivel |
| `blockingAmong(nivel, alumnos)` | Ids de persona de los alumnos que no están en `autorizo` |

**Contrato** (las firmas exactas son de implementación):

1. **Entrada**: el nivel es un enumerado público del propio `Domain` (`web`, `redes_sociales`); los alumnos, ids internos de persona (`people.id`). El centro es el del contexto de tenant, nunca un parámetro.
2. **Lista vacía ⇒ excepción** (error de programación), nunca «publicable» (`RN-FAM-UNIT-46`).
3. **Denegación por defecto**: «no publicable», con el alumno entre los que bloquean, si no tiene ninguna decisión que cuente, si no tiene a nadie que decida, si no existe o es de otro centro, o si el módulo no está contratado.
4. **Una consulta por llamada**, sin N+1, sea cual sea el número de alumnos; memoizada como mucho dentro de la petición, nunca entre peticiones (`ADR-044 §4.7`, `RN-FAM-UNIT-34`).
5. **Sin efectos**: no escribe, no audita lecturas (no es categoría especial, `permisos.md §5`) y no emite eventos.
6. No recibe edad, curso ni tipo de publicación: una regla jurídica futura (14 o 18 años, invalidación por texto nuevo) cambia el cálculo **dentro** de `fam_unit`, no el contrato (`ADR-066 §5.6`).

Reglas para el consumidor, que se copian en su especificación (`ADR-066 §5.7`):

1. Se consulta **en el momento de publicar y en el momento de servir** la imagen, no solo al subirla: así una revocación deja de mostrar la imagen «inmediatamente» (segundo criterio de aceptación de `REQ-FAM-UNIT-004`). Un evento no basta. El «marcado automático» de fotos se calcula al servir.
2. Falla en cerrado: alumno desconocido, de otro centro o módulo no contratado ⇒ no publicable.
3. Una foto de grupo es publicable solo si **todos** los alumnos identificables lo son (`REQ-INF-008`).
4. **Prohibido** guardar el estado, una marca o una copia del consentimiento como base de la decisión de publicar o mostrar, y esperar un evento para retirar una imagen.
5. **`REQ-AUT`** (`REQ-AUT-002`/`-003`): si muestra la autorización de imagen, la **lee** de este módulo. No ofrece plantilla, tipo ni respuesta de «uso de imagen» (ni general ni por actividad), y su caducidad no se aplica a la imagen (`OPEN-FAM-UNIT-19`).
6. **`REQ-PRIV`** (`REQ-PRIV-002`): su registro centralizado **agrega** el consentimiento de imagen leyéndolo de este módulo y no guarda filas propias. Si ofrece retirarlo, lo hace por un contrato síncrono de escritura de este módulo (`ADR-048`). No propaga revocaciones: los publicadores consultan en origen.

**Interfaces que no se construyen en `1.14-b`** (`ADR-048`): `ImageConsentRecords` (lectura de estados e histórico para `AUT` y `PRIV`) e `ImageConsentRecorder` (escritura). Las define la especificación de su primer consumidor, sobre el mismo servicio de dominio que usan los *endpoints* de este módulo.

### 7.4 Contrato con `REQ-ALUM` (`1.15`)

`ALUM` depende de `FAM-UNIT`; este módulo **no** depende de `ALUM`. El contrato:

1. El alumno se identifica **por su persona** (`people`). `ALUM` crea la persona y la faceta `student` (`ADR-034 §1`); este módulo nunca crea personas.
2. `ALUM` registra la pertenencia del alumno a una unidad mediante una interfaz de escritura de este módulo, `FamilyUnitStudents` (añadir / quitar una persona de alumno en una unidad), con las mismas reglas de §5.2, en la transacción del alta del alumno si así lo especifica `1.15`.
3. En la API de este módulo, el alumno se nombra `student_person` (`public_id` de la **persona**). Si `1.15` expone un `public_id` propio de `students`, la API de este módulo **añadirá** el campo (`ADR-038 §7`, cambio compatible), nunca lo sustituirá en la misma versión. Decisión en `OPEN-FAM-UNIT-01`.
4. Los nombres de alumnos y tutores se leen de `Core` (interfaz de personas, `OPEN-FAM-UNIT-03`), nunca de `students`.

### 7.5 Muro de sesión (`REQ-FAM-UNIT-003`)

Este módulo aporta una comprobación «¿tiene el usuario U una aceptación pendiente?» y un código de error. Cómo se engancha a la sesión (registro de muros en `Core`/`Auth`, igual que el de MFA) es `OPEN-FAM-UNIT-12`: no se decide aquí porque toca código de otros dos módulos.

### 7.6 Eventos de dominio

**Ninguno en `1.14`** (`ADR-048`: sin consumidor no se emite). Los primeros consumidores previsibles los declararán con su especificación: `1.14b` (`GuardianLinkRevoked` para avisar a conserjería, si lo pide). **El consentimiento de imagen no emite eventos**, tampoco para `REQ-PRIV`: la propagación de `REQ-PRIV-002` se cumple porque toda consulta va a la fuente (`ADR-066 §5.7`). **Consume**: ninguno (ver `RN-FAM-UNIT-13` y `OPEN-FAM-UNIT-16`).

---

## 8. Descriptor del módulo (`ADR-045`, `ADR-034 §5`)

| Campo | Propuesta |
|-------|-----------|
| `code` | `fam_unit` |
| `name_key` | `modules.fam_unit` en los cuatro idiomas: «Unidad familiar», «Family unit», «Familieneinheit», «Unité familiale» |
| `phase` | `1` |
| `depends_on` | `[]` — `core` y `auth` son esenciales y siempre están activos (mismo criterio que `acad`, que solo declara `curso`) |
| `essential` | `false` (`OPEN-FAM-UNIT-14`) |
| `feature_flags` | Ninguno |

## 9. Comportamiento con el módulo desactivado

- **Interfaz**: desaparecen las entradas de navegación, rutas y bloques del panel (`RMOD-008`); el muro de condiciones no se muestra.
- **API**: toda ruta responde `403 urn:pge:error:module-disabled` (`RMOD-009`).
- **Interfaces de §7**: `ImageConsentPolicy` responde «no publicable» para todo (`RN-FAM-UNIT-46`); `FamilyMembership` y `GuardianRecipients` responden conjuntos vacíos. **Consecuencia que hay que aceptar**: con el módulo descontratado, ningún tutor alcanza a ningún alumno por ámbito `unidad_familiar` y ningún módulo puede publicar imágenes de alumnos. Es fallo en cerrado y es lo correcto con datos de menores. Los datos se conservan (`RMOD-003`).

## 10. Interfaz: *shell* del frontend (`ADR-053`)

`apps/web/src/modules/fam-unit/` con `shell.ts`, `api/`, `types/`, `locales/{es,en,de,fr}.json` (`AR-11`). Pantallas de **personal del centro**, entrega **`1.14-d`** (las del tutor son `1.22`, `OPEN-FAM-UNIT-13`):

| Ruta | `meta.layout` | `meta.permissions` (anyOf) | Contenido |
|------|---------------|----------------------------|-----------|
| `/familias` | aplicación | `familia.leer` | Listado con la tabla de datos de `ADR-054` (código, tutor principal, nº de tutores, nº de alumnos) |
| `/familias/:id` | aplicación | `familia.leer` | Ficha: tutores, alumnos, vínculos (con revocación si se tiene `revocacion_tutor.leer`) y consentimientos (si se tiene `consentimiento_imagen.leer`) |
| `/familias/consentimientos` | aplicación | `consentimiento_imagen.leer` | Estado vigente filtrable, histórico por alumno, informe |
| `/configuracion/condiciones-de-uso` | aplicación | `texto_legal.leer` | Versiones de los textos legales (condiciones y consentimiento de imagen por nivel, filtro por `kind`), edición del borrador con el campo traducible de `ADR-062 §5.7`, publicación. Ruta y título definitivos: `1.14-d` |
| `/condiciones-de-uso` | desnudo | `[]` | Muro de aceptación. **Amplía la lista cerrada** de rutas de autoservicio por identidad (`src/navigation/modules.spec.ts`); requiere aprobación (`OPEN-FAM-UNIT-12`) |

Navegación: entrada «Familias» (visible si y solo si se puede montar `/familias`) y entrada de configuración «Condiciones de uso». Bloques del panel: ninguno en `1.14`.

Accesibilidad (WCAG 2.2 AA): los estados `autorizo`/`no_autorizo`/`pendiente` y `activo`/`revocado` nunca se transmiten solo por color; el muro de condiciones es navegable por teclado, con el texto completo accesible y el botón de aceptar deshabilitado solo con explicación textual.

## 11. Internacionalización (`INV-009`, `ADR-021`)

- Claves de interfaz en `apps/web/src/modules/fam-unit/locales/{es,en,de,fr}.json` y de servidor en `apps/api/lang/{es,en,de,fr}/fam_unit.php`: nombres de recurso (`resource_label_key`), códigos de error, tipos de relación, niveles y estados de consentimiento, estados de vínculo, categorías restringidas, estados de versión.
- **Ningún literal** en código. Los enumerados viajan sin traducir en la API (`ADR-038 §3.2`) y se traducen en la SPA.
- Contenido del centro traducible: título y texto de cada versión de `terms_versions` (`ADR-062`), es decir, las condiciones de uso y el texto de cada nivel de consentimiento de imagen (`ADR-066 §5.5`).
- **Textos legales y de consentimiento**: las claves de interfaz que rodean el consentimiento («Autorizo», «No autorizo») se traducen como el resto; el **texto legal** lo redacta el centro. El agente `traductor` no traduce textos legales ni de consentimiento (`CLAUDE.md §2`): esas claves, si las hubiera fijas en código, las revisa una persona.
- El informe CSV es contrato técnico estable sin literales de interfaz (`ADR-055`).

---

## 12. Criterios de aceptación

Formato Dado/Cuando/Entonces. Cada uno es un test que referencia su ID (`INV-015`). Los marcados *(E2E)* se verifican además en navegador.

### 12.1 Unidades y tutores (`1.14-a`)

- **CA-FAM-UNIT-001** — *Dado* un administrador con `familia.crear`, *cuando* crea una unidad con un código libre, *entonces* recibe `201` con `public_id` ULID y la creación queda en `audit_logs`.
- **CA-FAM-UNIT-002** — *Dado* una unidad viva con código `X`, *cuando* se crea otra con `X`, *entonces* `422 fam_unit.family_unit.code_taken`; *y cuando* la primera se da de baja, *entonces* el código vuelve a estar disponible (`RN-FAM-UNIT-01`).
- **CA-FAM-UNIT-003** — *Dado* un usuario del centro en estado `pendiente`, *cuando* se crea su faceta de tutor en una unidad, *entonces* `201`, y no se ha creado ninguna persona, usuario, invitación ni asignación de rol (`RN-FAM-UNIT-10`).
- **CA-FAM-UNIT-004** — *Dado* una persona que ya es tutora viva, *cuando* se intenta crear otra faceta para ella (en la misma o en otra unidad), *entonces* `422 fam_unit.guardian.already_exists` (`RN-FAM-UNIT-11`).
- **CA-FAM-UNIT-005** — *Dado* un tutor de la unidad B, *cuando* se le designa principal de la unidad A, *entonces* `422 fam_unit.family_unit.principal_not_member` (`RN-FAM-UNIT-02`).
- **CA-FAM-UNIT-006** — *Dado* una unidad con un alumno vivo, *cuando* se pide su baja, *entonces* `409 fam_unit.family_unit.has_members` (`RN-FAM-UNIT-03`).
- **CA-FAM-UNIT-007** — *Dado* un tutor principal con dos vínculos vivos, *cuando* se da de baja el tutor, *entonces* sus dos vínculos quedan dados de baja en la misma transacción y la unidad queda sin principal (`RN-FAM-UNIT-12`).
- **CA-FAM-UNIT-008** — *Dado* un usuario de otro centro, *cuando* se usa su `public_id` para crear un tutor, *entonces* `422 fam_unit.validation.user_not_found` con el mismo cuerpo que para un `public_id` inexistente.
- **CA-FAM-UNIT-009** — *Dado* un tutor de la unidad A, *cuando* se envía `PATCH /guardians/{id}` con `family_unit` (la misma o cualquier otra), *entonces* `422 fam_unit.guardian.not_editable`, el tutor y el principal de la unidad no cambian, y no se escribe ninguna fila de auditoría (`RN-FAM-UNIT-14`); *y* un `PATCH` sin `family_unit` responde `200` sin cambios.

### 12.2 Alumnado de la unidad (`1.14-a`)

- **CA-FAM-UNIT-010** — *Dado* la persona de un alumno ya en la unidad A, *cuando* se añade a la unidad B, *entonces* `422 fam_unit.student.already_in_unit` (`RN-FAM-UNIT-20`).
- **CA-FAM-UNIT-011** — *Dado* la interfaz `FamilyUnitStudents`, *cuando* se invoca con las mismas entradas que el *endpoint*, *entonces* aplica exactamente las mismas reglas y produce la misma auditoría (§7.4).
- **CA-FAM-UNIT-012** — *Dado* un alumno con un vínculo vivo con un tutor de otra unidad, *cuando* se quita el alumno de su unidad, *entonces* el vínculo se conserva y la respuesta informa `remaining_links = 1` (`RN-FAM-UNIT-22`).

### 12.3 Vínculos (`1.14-a`)

- **CA-FAM-UNIT-020** — *Dado* un tutor y un alumno sin vínculo, *cuando* se crea con relación `madre`, representación legal `true`, acceso `lectura_escritura`, *entonces* `201` y queda `activo`.
- **CA-FAM-UNIT-021** — *Dado* un vínculo vivo (tutor T, alumno A), *cuando* se crea otro igual, *entonces* `422 fam_unit.link.already_exists` (`RN-FAM-UNIT-30`).
- **CA-FAM-UNIT-022** — *Dado* un alumno con un contacto de emergencia, *cuando* se marca otro vínculo suyo como contacto, *entonces* `422 fam_unit.link.emergency_contact_taken`; *y* el índice único lo impide también ante dos peticiones simultáneas (test de concurrencia, `RN-FAM-UNIT-31`).
- **CA-FAM-UNIT-023** — *Dado* un vínculo, *cuando* se envía una categoría restringida fuera del vocabulario, *entonces* `422`, y una escritura directa en base de datos con ese valor la rechaza el `CHECK` (`RN-FAM-UNIT-32`).
- **CA-FAM-UNIT-024** — *Dado* un vínculo de un tutor con un alumno de otra unidad, *cuando* se crea, *entonces* se acepta (C2) y el ámbito `unidad_familiar` del tutor alcanza a ese alumno.
- **CA-FAM-UNIT-025** — *Dado* la persona P, *cuando* se intenta vincular P como tutor de sí misma, *entonces* `422 fam_unit.link.self_link` (`RN-FAM-UNIT-21`).
- **CA-FAM-UNIT-048** — *Dado* un vínculo revocado, *cuando* lo pide quien tiene `vinculo_tutor.leer` (ámbito `todos`) sin `revocacion_tutor.leer`, *entonces* el listado no lo incluye, el detalle es `404` como un id inexistente, `status=revocado` es `403`, y los recuentos de la unidad no cambian; *y* con el permiso lo ve (`RN-FAM-UNIT-40`).
- **CA-FAM-UNIT-049** — *Dado* un vínculo revocado, *cuando* quien no tiene `revocacion_tutor.leer` pide `PATCH`, `DELETE` o `POST …/revocation` sobre él, *entonces* `404` con el mismo cuerpo que un id inexistente y no cambia nada; *y* con el permiso, `PATCH` es `200`, `DELETE` es `409 revoked_not_deletable` y revocar de nuevo es `409 invalid_transition`; *y* el levantamiento (`DELETE …/revocation`) no se oculta a quien tiene `revocacion_tutor.eliminar` (#472, `RN-FAM-UNIT-40`).
- **CA-FAM-UNIT-050** — *Dado* un límite de N intentos, *cuando* un usuario hace N+1 revocaciones (o N+1 levantamientos) en el mismo centro, *entonces* la última es `429` con `Retry-After` y texto traducido y no cambia nada; *y* el cubo de la otra acción, otro usuario y otro centro no se ven afectados, y las lecturas y otras escrituras no se limitan (`RN-FAM-UNIT-66`).
- **CA-FAM-UNIT-027** — *Dado* un vínculo `revocado`, *cuando* se pide su baja o la baja de su tutor, *entonces* `409 fam_unit.link.revoked_not_deletable`, no se escribe nada y el vínculo sigue `revocado`; *y cuando* se levanta la revocación, *entonces* la baja procede (`RN-FAM-UNIT-39`).
- **CA-FAM-UNIT-029** — *Dado* el tutor principal P de la unidad U con un único vínculo activo con un alumno de U, *cuando* se revoca ese vínculo, *entonces* en la misma transacción `principal_guardian` de U pasa a `null` y la respuesta trae `principal_cleared = true`; *y* si P conserva otro vínculo activo con un alumno de U, *entonces* sigue siendo principal y `principal_cleared = false` (`RN-FAM-UNIT-38`).

### 12.4 Revocación por custodia (`1.14-a`)

- **CA-FAM-UNIT-030** — *Dado* una madre M y un padre P vinculados a los alumnos A1 y A2, *cuando* se revoca el vínculo (P, A1) con motivo `resolucion_judicial`, *entonces* P sigue alcanzando a A2 y M sigue alcanzando a A1 y A2 (nota legal de `REQ-FAM-UNIT-002`).
- **CA-FAM-UNIT-031** — *Dado* el vínculo (P, A1) revocado, *cuando* P pide con su ámbito `unidad_familiar` el listado de vínculos, *entonces* no aparece A1 ni ningún vínculo revocado; *y cuando* pide por `public_id` el vínculo revocado o la unidad de A1 (si A1 era su único alumno en ella), *entonces* `404` como si no existiera (`ADR-044 §4.2`, `RN-FAM-UNIT-36`).
- **CA-FAM-UNIT-032** — *Dado* el vínculo (P, A1) revocado en la petición N, *cuando* P hace la petición N+1, *entonces* ya no alcanza a A1, sin esperar a ninguna caché ni a cerrar sesión (`RN-FAM-UNIT-34`).
- **CA-FAM-UNIT-033** — *Dado* el vínculo (P, A1) revocado, *cuando* se consulta `FamilyMembership::isAccessRevoked(P, A1)`, *entonces* `true`; *y* `GuardianRecipients` para A1 no devuelve a P (contrato para `1.14b` y `1.19`).
- **CA-FAM-UNIT-034** — *Dado* un vínculo `activo`, *cuando* se pide levantar la revocación, *entonces* `409 fam_unit.link.invalid_transition`; *y* lo mismo al revocar uno `revocado` (`RN-FAM-UNIT-33`).
- **CA-FAM-UNIT-035** — *Dado* un vínculo revocado, *cuando* se levanta la revocación, *entonces* vuelve a `activo`, P vuelve a alcanzar a A1, y `audit_logs` conserva las dos transiciones con autor y fecha.
- **CA-FAM-UNIT-036** — *Dado* el vínculo (P, A1) que era contacto de emergencia, *cuando* se revoca, *entonces* deja de serlo en la misma transacción (`RN-FAM-UNIT-31`).
- **CA-FAM-UNIT-037** — *Dado* un usuario con `vinculo_tutor.actualizar` pero sin `revocacion_tutor.crear`, *cuando* intenta revocar, *entonces* `403`; *y* ni `PATCH /guardian-links/{id}` acepta `status` (`422`): revocar solo se hace por su *endpoint*.
- **CA-FAM-UNIT-038** — *Dado* el vínculo (P, A1) revocado, *cuando* se evalúan los consumidores de un vínculo, *entonces* no cuenta para el ámbito `unidad_familiar` de P (`FamilyMembership` no devuelve a A1), no es destinatario (`GuardianRecipients`), no es contacto de emergencia, y `PATCH` con `is_emergency_contact = true` sobre él responde `422 fam_unit.link.emergency_contact_revoked` (`RN-FAM-UNIT-35`; la parte de decisiones de imagen se prueba en `CA-FAM-UNIT-053`, `1.14-b`).
- **CA-FAM-UNIT-039** — *Dado* un vínculo con `is_legal_guardian = true` verificado, *cuando* se revoca y después se levanta la revocación, *entonces* `is_legal_guardian`, `legal_guardian_verified_at` y `legal_guardian_verified_by` no cambian en ningún momento (`RN-FAM-UNIT-37`).

### 12.5 Ámbito `unidad_familiar` y aislamiento (`1.14-a`)

- **CA-FAM-UNIT-040** — *Dado* un tutor con `familia.leer` de ámbito `unidad_familiar` y otra familia en el centro, *cuando* pide el listado de unidades, *entonces* solo ve la suya; *y cuando* pide por `public_id` la unidad de la otra familia, *entonces* `404` (listado **y** detalle, `ADR-044 §4.2`).
- **CA-FAM-UNIT-041** — Igual que `-040` para `vinculo_tutor.leer` (vínculos) y `consentimiento_imagen.leer` (estado vigente e histórico).
- **CA-FAM-UNIT-042** — *Dado* un permiso de este módulo concedido con un ámbito que no admite (por ejemplo `grupo`), *cuando* se intenta conceder, *entonces* `422` (`ADR-044 §4.1`).
- **CA-FAM-UNIT-043** — *Dado* dos centros con unidades, tutores, vínculos, decisiones y aceptaciones equivalentes, *cuando* se ejercitan **todos** los *endpoints* del módulo y las interfaces de §7 desde el centro A, *entonces* ningún dato del centro B se devuelve, modifica ni cuenta (`INV-001`).
- **CA-FAM-UNIT-044** — *Dado* un usuario que es docente y tutor (dos roles), con `deny` sobre `familia.leer` en uno de ellos, *cuando* pide el listado, *entonces* `403` (`RPERM-007`).
- **CA-FAM-UNIT-045** — Lectura de curso cerrado: **no aplica**. Ninguna tabla del módulo tiene `academic_year_id` (`ADR-034 §4`).
- **CA-FAM-UNIT-046** — *Dado* el mapa cerrado de `AR-10`, *cuando* se ejecuta el grupo `arch`, *entonces* `familia`, `vinculo_tutor` y `consentimiento_imagen` figuran con sus modelos y sus únicos ficheros sancionados.

### 12.6 Consentimiento de imagen (`1.14-b`, enmendado por `ADR-066`)

> **Numeración**: el antiguo `CA-FAM-UNIT-050` de esta sección (alumno sin decisiones) estaba duplicado con el `CA-FAM-UNIT-050` de §12.3 (límite de revocaciones, ya referenciado por `RevocationRateLimitTest.php` y `CHANGELOG.md`). Pasa a **`CA-FAM-UNIT-086`** (§14.2). Los criterios nuevos de `ADR-066` son `CA-FAM-UNIT-087` a `-101`. En todos, «texto publicado» significa una versión `publicada` del tipo del nivel, salvo que se diga lo contrario.

- **CA-FAM-UNIT-086** *(antes `-050` de §12.6)* — *Dado* un alumno vivo en una unidad sin ninguna decisión, *cuando* se consulta su estado en `web` y `redes_sociales`, *entonces* ambos son `pendiente` y `ImageConsentPolicy` responde «no publicable» en los dos, con el alumno entre los que bloquean.
- **CA-FAM-UNIT-051** — *Dado* un tutor con vínculo activo y representación legal, único representante legal del alumno, y texto publicado de `web`, *cuando* registra `autorizo` en `web`, *entonces* `201`, la decisión es inmutable, guarda la versión publicada, el estado de `web` es `autorizo` y `redes_sociales` no cambia (`RN-FAM-UNIT-47`, `-68`).
- **CA-FAM-UNIT-052** — *Dado* un tutor con vínculo activo **sin** representación legal, *cuando* intenta registrar una decisión por sí mismo, *entonces* `422 fam_unit.image_consent.not_legal_guardian` y no se escribe nada (`RN-FAM-UNIT-41`).
- **CA-FAM-UNIT-053** — *Dado* un tutor con vínculo **revocado**, *cuando* intenta registrar por sí mismo una decisión sobre ese alumno, *entonces* `404` (el alumno no está en su ámbito) y no se escribe nada (`RN-FAM-UNIT-35`).
- **CA-FAM-UNIT-054** — *Dado* un alumno con `autorizo` en `redes_sociales`, *cuando* un tutor que decide registra `no_autorizo`, *entonces* la respuesta trae el estado `no_autorizo` e `ImageConsentPolicy` responde «no publicable» en la consulta siguiente (revocación con efecto inmediato, `REQ-FAM-UNIT-004`).
- **CA-FAM-UNIT-055** — Tabla de verdad de `RN-FAM-UNIT-45`, **un test por fila**. *Dado* un alumno con los representantes legales indicados y texto publicado, *cuando* se consulta el estado (y `ImageConsentPolicy`), *entonces*:

  | # | Quienes deciden y su decisión vigente | Otros tutores | Estado | Publicable |
  |---|---|---|---|---|
  | a | Nadie decide: el alumno no tiene ningún vínculo vivo con representación legal | — | `pendiente` | No |
  | b | R1 `autorizo` | — | `autorizo` | Sí |
  | c | R1 `no_autorizo` | — | `no_autorizo` | No |
  | d | R1 `autorizo`, R2 sin decisión | — | `pendiente` | No |
  | e | R1 `autorizo`, R2 `autorizo` | — | `autorizo` | Sí |
  | f | R1 `autorizo`, R2 `no_autorizo` | — | `no_autorizo` | No |
  | g | R1 `no_autorizo`, R2 sin decisión | — | `no_autorizo` | No |
  | h | R1 `autorizo` (activo), R2 **revocado** sin decisión | — | `pendiente` | No |
  | i | R1 `autorizo` (activo), R2 **revocado** con `autorizo` registrado por el centro | — | `autorizo` | Sí |
  | j | R1 `autorizo` (activo), R2 **revocado** con `no_autorizo` registrado por el centro | — | `no_autorizo` | No |
  | k | Nadie decide | Tutor T que registró `autorizo` cuando tenía representación legal y al que después se le retira (`is_legal_guardian = false`); su decisión se conserva pero **no cuenta**. Un abuelo sin representación legal no puede registrar ninguna (`CA-FAM-UNIT-052`) | `pendiente` | No |
  | l | R1 `no_autorizo` y después `autorizo` (última por `(occurred_at, id)`) | — | `autorizo` | Sí |
  | m | R1 `autorizo` | Vínculo de R2 **dado de baja** (borrado lógico) con `no_autorizo` previo: no cuenta | `autorizo` | Sí |

- **CA-FAM-UNIT-056** — *Dado* `PATCH` o `DELETE` sobre una decisión por cualquier vía (API, modelo, SQL con el rol de aplicación), *entonces* se rechaza (`RN-FAM-UNIT-42`: `AppendOnlyModel` y `REVOKE` en el motor).
- **CA-FAM-UNIT-057** — *Dado* una decisión registrada por secretaría en nombre de un tutor, *cuando* se consulta el histórico, *entonces* muestra el tutor que decidió, el usuario que la registró, el canal `presencial` y la versión del texto (`RN-FAM-UNIT-43`).
- **CA-FAM-UNIT-058** — *Dado* dos representantes legales que registran a la vez decisiones sobre el mismo alumno y nivel (test de concurrencia, dos procesos), *cuando* terminan, *entonces* **quedan las dos filas** y el estado coincide con aplicar `RN-FAM-UNIT-45` sobre ambas, sin bloqueo de fila (`RN-FAM-UNIT-44`, sustituida).
- **CA-FAM-UNIT-059** — *Dado* `ImageConsentPolicy` con una lista de alumnos donde uno es de otro centro o no existe, *entonces* «no publicable» y ese id aparece entre los que bloquean (`RN-FAM-UNIT-46`).
- **CA-FAM-UNIT-060** — *Dado* el módulo descontratado, *cuando* otro módulo consulta `ImageConsentPolicy`, *entonces* «no publicable» para todos (§9).
- **CA-FAM-UNIT-061** — *Dado* un usuario con `consentimiento_imagen.exportar`, *cuando* pide el informe, *entonces* se encola, la respuesta es `202` con el recurso de exportación, el CSV tiene el esquema cerrado de `api.md` y la solicitud queda auditada como `exported`; *y* con `q` en el cuerpo ⇒ `422` (`ADR-054 §9`).
- **CA-FAM-UNIT-062** — *Dado* un alumno con estado `no_autorizo`, *cuando* un módulo de prueba que simula un publicador consulta `ImageConsentPolicy` antes de publicar, *entonces* recibe «no publicable» con ese alumno entre los que bloquean (contrato de `INV-014`; el bloqueo real lo verifica cada consumidor, §7.3).
- **CA-FAM-UNIT-087** — *Dado* un alumno con un único representante legal R1 que decidió `autorizo` en `web` (estado `autorizo`), *cuando* secretaría da de alta un vínculo de R2 con representación legal sobre ese alumno, *entonces* en la consulta siguiente el estado de `web` es `pendiente` e `ImageConsentPolicy` responde «no publicable», **sin que se haya registrado ninguna decisión nueva** (`ADR-066 §5.2`). Igual al marcar `is_legal_guardian = true` en un vínculo existente de R2 que no lo tenía (transición `false → true`, `RN-FAM-UNIT-74`).
- **CA-FAM-UNIT-088** — *Dado* el estado de `CA-FAM-UNIT-087` (`pendiente` por R2 sin decidir), *cuando* se da de baja el vínculo de R2 (o se desmarca su `is_legal_guardian`), *entonces* el estado vuelve a `autorizo` sin ninguna decisión nueva; *y cuando* se crea de nuevo un vínculo de R2 con representación legal, *entonces* vuelve a `pendiente` (una decisión de R2 anterior a la nueva verificación tampoco contaría, `RN-FAM-UNIT-72`).
- **CA-FAM-UNIT-089** — *Dado* un nivel sin texto publicado (ninguna versión, o solo `borrador`/`retirada`), *cuando* un representante legal registra `autorizo`, *entonces* `422 fam_unit.image_consent.text_not_published`, no se escribe ninguna fila ni auditoría y el estado sigue `pendiente` (`RN-FAM-UNIT-68`).
- **CA-FAM-UNIT-090** — *Dado* un nivel sin texto publicado, *cuando* un representante legal registra `no_autorizo`, *entonces* `201` con `consent_text_version = null` y estado `no_autorizo`; *y* una inserción directa con `decision = 'autorizo'` y `consent_text_version_id` nulo la rechaza el `CHECK` del motor (`RN-FAM-UNIT-68`).
- **CA-FAM-UNIT-091** — *Dado* un alumno con R1 `autorizo` y R2 con el vínculo **revocado** sin decisión (estado `pendiente`), *cuando* un usuario con `consentimiento_imagen.actualizar` de ámbito `todos` **y** `revocacion_tutor.leer` registra `autorizo` con `on_behalf_of = R2` y canal `presencial`, *entonces* `201`, la decisión queda con `guardian = R2`, `recorded_by` = ese usuario y canal `presencial`, y el estado pasa a `autorizo` (`RN-FAM-UNIT-41`, `-35`, `-37`).
- **CA-FAM-UNIT-092** — *Dado* lo mismo que `CA-FAM-UNIT-091` pero el usuario **sin** `revocacion_tutor.leer`, *cuando* envía la misma petición, *entonces* `422 fam_unit.image_consent.guardian_not_linked` con **el mismo cuerpo** que con un tutor sin ningún vínculo con el alumno, y no se escribe nada (`RN-FAM-UNIT-40`, `-41`).
- **CA-FAM-UNIT-093** — *Dado* un alumno con una decisión de R2 (vínculo revocado) registrada por el centro y una de R1 (activo), *cuando* R1 consulta el histórico con su ámbito `unidad_familiar`, *entonces* solo ve su propia decisión, el `meta` de paginación no delata la otra, y el estado que ve es el calculado con las dos; *y cuando* lo consulta personal con ámbito `todos`, *entonces* ve las dos (`RN-FAM-UNIT-71`).
- **CA-FAM-UNIT-094** — *Dado* `ImageConsentPolicy`, *cuando* se invoca `isPublishable` o `blockingAmong` con una lista de alumnos vacía, *entonces* lanza una excepción y no devuelve `true` (`RN-FAM-UNIT-46`).
- **CA-FAM-UNIT-095** — *Dado* una unidad con un alumno sin ninguna decisión y otro con `autorizo`, *cuando* se pide `GET /image-consents?state=pendiente` y el informe `POST /image-consents/exports` con el mismo filtro, *entonces* ambos incluyen al alumno sin decisiones (con `last_decision_at` vacío) y no al otro (`ADR-066 §5.2`, `api.md §3`, `§4`).
- **CA-FAM-UNIT-096** — *Dado* R1 con `autorizo` registrado y estado `autorizo`, *cuando* se desmarca y se vuelve a marcar su `is_legal_guardian` (nueva `legal_guardian_verified_at`), *entonces* el estado es `pendiente` hasta que R1 decida de nuevo, y su decisión anterior sigue en el histórico (`RN-FAM-UNIT-72`, `OPEN-066-04`).
- **CA-FAM-UNIT-097** — *Dado* una versión `retirada` y otra `publicada` del texto de `web`, *cuando* el centro registra por `presencial` una decisión indicando la `retirada`, *entonces* `201` y la decisión guarda la `retirada`; *y cuando* indica un `borrador`, una versión del tipo de `redes_sociales` o cualquier versión por el canal `portal`, *entonces* `422 fam_unit.image_consent.text_version_invalid` y no se escribe nada (`RN-FAM-UNIT-69`).
- **CA-FAM-UNIT-098** — *Dado* un alumno con `autorizo` registrado sobre la versión 1 del texto de `web`, *cuando* se publica la versión 2, *entonces* el estado sigue `autorizo`, la versión 1 pasa a `retirada` y el histórico muestra la decisión con la versión 1 (`RN-FAM-UNIT-70`).
- **CA-FAM-UNIT-099** — *Dado* `1.14-b` desplegada, *cuando* se crea una versión con `kind = condiciones_uso`, *entonces* `422 fam_unit.terms.kind_not_available`; *y cuando* se publica una versión de `consentimiento_imagen_web`, *entonces* la publicada de `consentimiento_imagen_redes` no cambia, y una segunda publicada del mismo tipo es imposible también ante dos publicaciones simultáneas (índice único, `RN-FAM-UNIT-73`, `-60`).
- **CA-FAM-UNIT-100** — *Dado* una versión `publicada` o `retirada` referenciada por una decisión, *cuando* se intenta editar o borrar por la API, *entonces* `409 fam_unit.terms.not_draft`; *y* un borrado directo en base de datos lo impide la clave foránea `RESTRICT` (`RN-FAM-UNIT-73`, `datos.md §1.6`).
- **CA-FAM-UNIT-101** — *Dado* un tutor que registra una decisión por el canal `portal` con la petición en `de` y el texto publicado traducido al alemán, *cuando* se consulta la decisión en base de datos, *entonces* `shown_locale = 'de'`; *y* si el texto publicado **no** tiene traducción `de` y el idioma por defecto del centro es `es-ES`, *entonces* `shown_locale = 'es-ES'`, el mismo valor que `GET /image-consent-texts` devuelve en `locale` (`OPEN-FAM-UNIT-25`); *y* una decisión registrada por `presencial` tiene `shown_locale` nulo (`RN-FAM-UNIT-43`) [DERIVADA, `ADR-066 §9.5`; aceptada por el usuario el 2026-10-27].

**Criterios de la aprobación del 2026-10-27** (§17; numeración a continuación del máximo, `CA-FAM-UNIT-101`):

- **CA-FAM-UNIT-102** *(regresión de `1.14-a`)* — *Dado* un vínculo de R1 con `is_legal_guardian = true` verificado en T0 por el usuario U0, y una decisión `autorizo` de R1 en `web` posterior a T0 (estado `autorizo`), *cuando* el usuario U1 envía en T1 `PATCH /guardian-links/{id}` con `is_legal_guardian = true` y otro campo cambiado (p. ej. `access_level`), *entonces* `200`, `legal_guardian_verified_at` sigue siendo T0 y `legal_guardian_verified_by` sigue siendo U0, la fila de `audit_logs` de esa edición no incluye cambios de esos dos atributos, y el estado de `web` sigue `autorizo`; *y cuando* se envía `is_legal_guardian = false` y después `true`, *entonces* la fecha pasa a la de la segunda petición y el estado pasa a `pendiente` (`RN-FAM-UNIT-74`, `-72`, `CA-FAM-UNIT-096`). El test cita `RN-FAM-UNIT-74` y `CA-FAM-UNIT-102` y falla con el comportamiento actual de `GuardianLinkService`.
- **CA-FAM-UNIT-103** — *Dado* un tutor con `consentimiento_imagen.leer` de ámbito `unidad_familiar`, el texto de `web` con una versión `retirada` (v1), una `publicada` (v2) con traducción `fr` y un `borrador` (v3), y ninguna versión publicada de `redes_sociales`, *cuando* pide `GET /image-consent-texts` en `fr`, *entonces* `200` con dos entradas: `web` con la v2, su título y cuerpo en francés y `locale = 'fr'`; `redes_sociales` con `terms_version`, `title`, `body` y `locale` a `null`; y en ningún caso aparecen la v1 ni la v3 (`RN-FAM-UNIT-75`).
- **CA-FAM-UNIT-104** — *Dado* la v2 publicada de `web` sin traducción `de` y el idioma por defecto del centro `es-ES`, *cuando* se pide `GET /image-consent-texts` en `de`, *entonces* el título y el cuerpo vienen en `es-ES` y `locale = 'es-ES'` (caída de `ADR-062`, `RN-FAM-UNIT-75`).
- **CA-FAM-UNIT-105** — *Dado* un usuario sin `consentimiento_imagen.leer` (aunque tenga `texto_legal.leer` o `familia.leer`), *cuando* pide `GET /image-consent-texts`, *entonces* `403`; *y* un tutor con el permiso de ámbito `unidad_familiar` pero sin ningún vínculo activo recibe `200` con el mismo contenido que cualquier otro sujeto con el permiso (el ámbito no filtra, `RN-FAM-UNIT-75` [DERIVADA, aceptada por el usuario el 2026-10-27]); *y* el aislamiento entre centros lo cubre `CA-FAM-UNIT-043` (el texto del centro B nunca aparece).
- **CA-FAM-UNIT-106** — *Dado* un centro nuevo (`ProvisionTenantDefaults`) o uno existente tras `fam_unit:grant-permissions` con `1.14-b` desplegada, *cuando* se consultan los permisos del rol predefinido `secretaria`, *entonces* tiene `texto_legal.leer` con ámbito `todos` y **ninguno** de `texto_legal.crear`, `.actualizar`, `.eliminar` ni `.publicar`; *y* puede listar las versiones `retirada` de un tipo para indicarla en una decisión `presencial` (`OPEN-FAM-UNIT-22`, `RN-FAM-UNIT-69`, `permisos.md §4`).

### 12.7 Condiciones de uso (`1.14-c`)

- **CA-FAM-UNIT-070** — *Dado* una versión publicada y un tutor con vínculo activo que nunca aceptó, *cuando* pide cualquier *endpoint* que no sea de la lista exenta, *entonces* `403 urn:pge:error:terms-acceptance-required`; *y cuando* acepta, *entonces* la siguiente petición pasa.
- **CA-FAM-UNIT-071** — *Dado* una aceptación, *cuando* se consulta, *entonces* registra usuario, versión, idioma mostrado, fecha y hora, IP y agente de usuario (`REQ-FAM-UNIT-003`, `RN-FAM-UNIT-65`), y no se puede modificar ni borrar.
- **CA-FAM-UNIT-072** — *Dado* un tutor que aceptó la v1, *cuando* se publica la v2 **sin** exigir re-aceptación, *entonces* no ve el muro; *y cuando* se publica la v3 **exigiendo** re-aceptación, *entonces* sí (`RN-FAM-UNIT-62`).
- **CA-FAM-UNIT-073** — *Dado* un docente sin faceta de tutor, *cuando* hay versión publicada, *entonces* no ve el muro (`RN-FAM-UNIT-61`).
- **CA-FAM-UNIT-074** — *Dado* una versión publicada, *cuando* se intenta editar, *entonces* `409`; *y cuando* se publica otra, *entonces* la anterior pasa a `retirada` en la misma transacción (`RN-FAM-UNIT-60`).
- **CA-FAM-UNIT-075** — *Dado* un borrador sin texto en el idioma por defecto del centro, *cuando* se publica, *entonces* `422` (`RN-FAM-UNIT-65`).
- **CA-FAM-UNIT-076** *(E2E)* — *Dado* un tutor en navegador real con versión pendiente, *cuando* inicia sesión, *entonces* aterriza en el muro, no puede navegar a otra ruta, y tras aceptar llega a su inicio.
- **CA-FAM-UNIT-077** — *Dado* aceptar dos veces la misma versión, *entonces* la segunda devuelve la aceptación existente y no crea fila (`RN-FAM-UNIT-64`).

### 12.8 Transversales

- **CA-FAM-UNIT-080** — *Dado* cualquier alta, modificación o baja de unidad, tutor, pertenencia, vínculo, revocación, versión, decisión o aceptación, *entonces* existe su fila en `audit_logs` con la política de `datos.md` (`INV-003`), y **ningún** valor redactado aparece en `changes`.
- **CA-FAM-UNIT-081** — *Dado* el módulo descontratado, *cuando* se llama a cualquier ruta, *entonces* `403 urn:pge:error:module-disabled`, y la navegación no muestra ninguna entrada del módulo (`RMOD-008`, `RMOD-009`).
- **CA-FAM-UNIT-082** — *Dado* las claves de `fam_unit` en `es`, *entonces* existen en `en`, `de` y `fr` (`AR-12`, `lint:i18n`), y cada recurso declara `resource_label_key` en los cuatro idiomas (`CA-PERM-134`).
- **CA-FAM-UNIT-083** — *Dado* el código del módulo, *entonces* ninguna regla compara códigos de rol (`AR-08`) y ningún fichero fuera del módulo importa sus clases internas (`AR-01`, `AR-02`).
- **CA-FAM-UNIT-084** *(E2E, `1.14-d`)* — *Dado* secretaría en navegador real, *cuando* crea una unidad, añade un tutor, vincula un alumno de prueba y revoca el vínculo, *entonces* la ficha refleja cada paso y el estado revocado se distingue sin depender del color (WCAG 2.2 AA).
- **CA-FAM-UNIT-085** (condicionado a `OPEN-FAM-UNIT-05`) — *Dado* un usuario con `restriccion_custodia.leer` concedido desde un rol con `special_data_access = true`, *cuando* lee el detalle, *entonces* recibe los datos descifrados y se escribe un evento `read` en `audit_logs` sin valores; *y* desde un rol sin el atributo, la concesión es inerte y responde `403`.

---

## 13. Preguntas abiertas

**Resolución (2026-10-26): el usuario aprueba todas las recomendaciones de esta sección, tal cual, y la partición en `1.14-a`/`-b`/`-c`.** Quedan estas condiciones, que la aprobación no elimina:
- `OPEN-FAM-UNIT-05`: solo entra la revocación como permiso ordinario; el detalle documental de la custodia espera a un ADR de `architect` (cifrado por campo y auditoría de lectura). No se implementa antes.
- `OPEN-FAM-UNIT-12`: el registro de «muros de sesión» y la ampliación de las listas cerradas de `AR-07a` y de rutas `[]` quedan aprobados; el diseño pasa por `architect` antes de `1.14-c`.
- `OPEN-FAM-UNIT-08`: unanimidad para `autorizo`, y el revocado sigue decidiendo; **concretada por `ADR-066 §5.4` (ACEPTADA, 2026-10-27)**. Su validación jurídica es **condición previa a datos reales**.
- `OPEN-FAM-UNIT-17`, `-18` y `-20`: la recomendación es no inventar reglas jurídicas; permanecen sin regla hasta que haya criterio legal, y no bloquean `1.14-a` ni `1.14-b`. **`OPEN-FAM-UNIT-18` pasa a ser condición previa a datos reales** (`OPEN-066-05`, aprobada el 2026-10-27).
- `OPEN-066-02` (`ADR-066`): en `1.14-b` un texto nuevo no invalida los `autorizo` anteriores (`RN-FAM-UNIT-70`); la respuesta jurídica es **condición previa a datos reales**.

**Condiciones previas a cargar datos reales del consentimiento de imagen** (resumen; ninguna bloquea `1.14-b` con datos sintéticos de `REQ-SEED`): (1) validación jurídica de `OPEN-FAM-UNIT-08`; (2) criterio jurídico sobre 14 y 18 años, `OPEN-FAM-UNIT-18`; (3) respuesta a `OPEN-066-02`.

Texto original de la propuesta (cada pregunta lleva su recomendación): **Bloqueantes** (§13.1): resueltos el 2026-10-26 con la recomendación; la columna «Bloquea» indica la parte afectada por las condiciones que persisten.

### 13.1 Bloqueantes

| ID | Pregunta | Opciones | Recomendación | Bloquea |
|----|----------|----------|---------------|---------|
| `OPEN-FAM-UNIT-01` | ¿Cómo se identifica al alumno mientras `ALUM` (`1.15`) no existe? | **A**: por su persona (`people`), con el contrato de §7.4; las pantallas de alta de alumnos en una unidad quedan sin datos reales hasta `1.15`. **B**: ampliar `Core` para que este módulo cree personas de menores ya en `1.14` (invade `ALUM` y adelanta un alta de menores sin la base legal de `INV-008`). **C**: reordenar el plan para implementar `1.15` antes (imposible: `ALUM` depende de `FAM-UNIT`) | **A**. Es lo que fija `ADR-034 §1` y no inventa altas de menores | Todo `1.14-a` |
| `OPEN-FAM-UNIT-03` | Ampliación de `Core\Domain` | Interfaz de lectura de personas (por `public_id`, por ids: nombre visible, `public_id`, si está viva; **y la fecha de nacimiento** solo para las reglas de edad de `OPEN-FAM-UNIT-18`) y la resolución usuario → persona | Aprobarla, con la misma disciplina que `activeIdsAmong` (una consulta, sin memoización entre llamadas, sin exponer el modelo) | Todo `1.14-a` (`INV-007`) |
| `OPEN-FAM-UNIT-05` | Detalle de la restricción de custodia como categoría especial (C5) | **A**: diseñar ahora, con ADR de `architect`, el cifrado a nivel de campo (gestión de claves: `APP_KEY` frente a clave por centro, rotación, imposibilidad de buscar) y el mecanismo de auditoría de lectura que sustituye al cable trampa `AR-09`; tabla `custody_restrictions` con permiso `restriccion_custodia.*` especial. **B**: no guardar el detalle en la plataforma: solo la revocación (§3.5) y, fuera del sistema, el expediente en papel. **C**: guardarlo como dato ordinario (contradice `CLAUDE.md §8` según el encargo) | **A, en dos tiempos**: la revocación (lo que protege al menor) entra en `1.14-a` sin esperar; el detalle se difiere hasta que el ADR esté aceptado. Hay que aceptar además que, por `RPERM-012`, **ningún rol predefinido** podrá leerlo: el centro tendrá que darlo a un rol con `special_data_access` | Solo §3.7/§4.5 |
| `OPEN-FAM-UNIT-08` | Regla de combinación del consentimiento de imagen (dos representantes legales que deciden distinto) y efecto de la revocación sobre ello. También: ¿pueden decidir tutores sin representación legal (abuelo, apoderado)? | **A**: unanimidad de los representantes legales con vínculo vigente para `autorizo`, cualquier `no_autorizo` bloquea (falla en cerrado). **B**: la última decisión gana. **C**: basta un representante legal | **A**, y solo deciden representantes legales. La decisión del revocado: cuenta su `no_autorizo` y su `autorizo` sigue siendo necesario (pierde el acceso, no la patria potestad, `RN-FAM-UNIT-37`); si queda bloqueado, secretaría registra su decisión en papel. **Es una cuestión jurídica: conviene validarla con el DPO o asesoría del centro piloto** | `1.14-b`. **Concretada por `ADR-066 §5.4`** (`RN-FAM-UNIT-41`, `-45`); validación jurídica antes de datos reales |
| `OPEN-FAM-UNIT-12` | Muro de condiciones: cómo se engancha a la sesión y qué rutas quedan exentas | **A**: registro de «muros de sesión» en `Core` (el de MFA de `Auth` pasaría a ser uno más), con lista cerrada de rutas exentas (`/me`, `/me/terms*`, `/logout`, `GET /tenant/branding`) y ampliación aprobada de la lista cerrada de rutas `[]` de la SPA. **B**: comprobación dentro del *middleware* de MFA (acopla dos módulos). **C**: solo en la SPA (no cumple `INV-010`: la API seguiría abierta) | **A**. Conviene que lo revise `architect` porque toca la cadena de *middleware* de todo el producto. ¿El muro aplica solo a tutores (`RN-FAM-UNIT-61`) o también a alumnado? El requisito dice «tutor»; se recomienda solo tutores | `1.14-c` |

### 13.2 No bloqueantes (afectan al detalle; tienen recomendación aplicable)

| ID | Pregunta | Recomendación |
|----|----------|---------------|
| `OPEN-FAM-UNIT-02` | Padre con hijos en dos unidades (C2). **A** vínculo que cruza unidades (respeta `§16.2`); **B** tutor en varias unidades (cambia `§16.2`, exige ADR); **C** prohibido (caso real sin solución) | **A** |
| `OPEN-FAM-UNIT-04` | Qué puede hacer el tutor principal por sí mismo («gestiona la unidad, añade/elimina tutores, autoriza cambios») y qué escribe un secundario de `lectura_escritura`. El requisito no lo concreta | En `1.14`, nada de autoservicio sobre la composición de la unidad: lo gestiona el centro. Que el principal **proponga** altas que el centro valida, y qué cambios «autoriza», se especifica con `1.22` (portal) o `1.14b` (autorizados), cuando haya un caso concreto. `lectura_escritura` se guarda ya y su efecto lo definen los módulos que tengan algo que escribir (p. ej. `AUT`) |
| `OPEN-FAM-UNIT-06` | Código de la unidad: generado por el sistema o escrito por el centro | Generado (`UF-` + número secuencial por centro), inmutable, y que el importador (`1.24`/`1.25`) pueda aportar el de la plataforma de origen en un campo aparte si hace falta. Alternativa: texto libre único |
| `OPEN-FAM-UNIT-07` | Vocabularios cerrados: tipos de relación (`padre`, `madre`, `tutor_legal`, `abuelo`, `apoderado`, `otro`), categorías restringibles, motivos de revocación | Relación: la lista del requisito más `otro`. Categorías: **solo `salud`** (único ejemplo del requisito); se añaden con la especificación del módulo que las consuma. Revocación: **solo `resolucion_judicial`**; otra retirada es baja del vínculo |
| `OPEN-FAM-UNIT-09` | ¿Se versiona el **texto** del consentimiento de imagen como el de las condiciones (fuente única, C4)? `REQ-FAM-UNIT-004` no lo pide; `REQ-PRIV-002` sí («versión del texto aceptado») | Sí, reutilizando el mismo mecanismo de versiones con un tipo de documento, para que `REQ-PRIV` consuma y no duplique. Y un ADR corto «fuente única de consentimientos de imagen», análogo a `ADR-032`. **Resuelta por `ADR-066` (ACEPTADA, 2026-10-27)**: `terms_versions` con `kind` desde `1.14-b` (`RN-FAM-UNIT-68` a `-70`, `-73`) |
| `OPEN-FAM-UNIT-10` | Siembra en roles predefinidos (`permisos.md §4`) | La propuesta de `permisos.md §4`: administrador todo lo no especial; secretaría gestión diaria sin revocación ni condiciones; dirección lectura; `tutor_legal` lectura de su familia y decisión de imagen con `unidad_familiar`. Revocar, solo administrador (decisión con consecuencias legales) |
| `OPEN-FAM-UNIT-11` | Informe «periódico» de consentimientos | Bajo demanda en `1.14` (exportación en cola). Si «periódico» significa envío programado, que lo especifique `COM` (`1.19`), que es quien envía. Requiere ampliar el catálogo de tipos de `data_exports` de `Core` (precedente `1.9b`) |
| `OPEN-FAM-UNIT-13` | Pantallas del tutor (ver su unidad, decidir sobre imagen) en `1.14` o en `1.22` | API en `1.14`, pantallas en `1.22` (`REQ-FAM-PORTAL-004` las nombra). Hasta entonces el centro registra las decisiones en papel (`RN-FAM-UNIT-43`). Excepción: el muro de condiciones sí va en `1.14-c` porque bloquea el acceso |
| `OPEN-FAM-UNIT-14` | `essential` y `depends_on` | `essential: false`, `depends_on: []` (§8). Consecuencia en §9 |
| `OPEN-FAM-UNIT-15` | Partición en `1.14-a`/`-b`/`-c` | Aprobarla (§1.2). **Ampliada con `1.14-d` (pantallas de personal y `CA-FAM-UNIT-084`) por decisión del usuario del 2026-10-26** |
| `OPEN-FAM-UNIT-16` | ¿Qué pasa con la faceta de tutor cuando su usuario se da de baja en `Core`? | Nada automático en `1.14` (`ADR-048`); la ficha muestra al tutor como «usuario dado de baja» como usuario eliminado (`removed = true` y nombre vacío en `displayNamesByIds`, #446). Si se quiere baja en cascada, consumir `UserDeactivated` en una entrega posterior |
| `OPEN-FAM-UNIT-17` | ¿Una revocación judicial debe bloquear también el acceso del tutor revocado si además es **personal** del centro con ámbito sobre ese alumno? | Cuestión jurídica: no se decide. Mientras tanto, §7.1 expone `isAccessRevoked()` para que el módulo que lo necesite pueda aplicarlo. Recomendación: preguntar a la asesoría del centro piloto |
| `OPEN-FAM-UNIT-18` | Mayoría de edad (18) y edad de consentimiento propio (14, `LOPDGDD` art. 7) | No inventar reglas. Recomendación: avisar en la ficha cuando el alumno cumple 18 y dejar la decisión de retirar vínculos al centro; sobre los 14 años, decidir con la asesoría si el consentimiento de imagen pasa al alumno. Requiere la fecha de nacimiento de `OPEN-FAM-UNIT-03` |
| `OPEN-FAM-UNIT-19` | ¿El consentimiento de imagen se renueva cada curso académico? | El requisito no lo pide y `ADR-034 §4` excluye la unidad familiar del curso. No renovar en `1.14`; si el centro lo pide, una acción masiva «volver a pendiente» con su propia especificación |
| `OPEN-FAM-UNIT-21` | Restaurar una copia anterior a una revocación (o a un `no_autorizo`) devolvería el acceso al tutor revocado (o la publicabilidad de la imagen) (`operacion.md §7`) | Que `REQ-BKP` (`1.26`) trate revocaciones y retiradas de consentimiento como `REQ-BKP-005` trata las supresiones: registro que se reaplica tras toda restauración. Se anota en el plan de `1.26`; no se resuelve en `1.14` |
| `OPEN-FAM-UNIT-20` | Representación de los datos de contacto de los otros tutores ante un tutor con ámbito `unidad_familiar` (casos de violencia de género: un tutor no debe ver el domicilio o teléfono del otro si hay orden de protección) | En `1.14` el tutor ve solo **nombre y tipo de relación** de los demás tutores activos, nunca sus datos de contacto. Lo amplía `1.22` si se pide |

### 13.3 Resueltas: abiertas por la enmienda de `ADR-066` (2026-10-27)

**Resolución (2026-10-27): el usuario aprueba la recomendación de las tres, tal cual.** Se aplican en §17 (`RN-FAM-UNIT-74`, `-75`, `CA-FAM-UNIT-102` a `-106`, `api.md §2.7`, `permisos.md §2` y `§4`). No queda ninguna pregunta abierta de la enmienda. Texto original:

| ID | Pregunta | Recomendación (aprobada) | Bloqueaba |
|----|----------|---------------|---------|
| `OPEN-FAM-UNIT-22` | **Siembra de `texto_legal`.** La siembra aprobada (`OPEN-FAM-UNIT-10`) da las versiones de texto solo a `administrador_centro`. Pero secretaría registra en papel decisiones `presencial` y puede tener que indicar la versión `retirada` que firmó la familia (`RN-FAM-UNIT-69`): sin `texto_legal.leer` no puede listar las versiones para elegirla | Sembrar `texto_legal.leer` (solo lectura, `todos`) también en `secretaria`. Alternativa: dejarlo al administrador del centro con un rol propio | Solo la siembra; no el resto de `1.14-b` |
| `OPEN-FAM-UNIT-23` | **Lectura del texto vigente por el tutor.** Por el canal `portal`, la decisión registra la versión y el idioma en que se **mostró** el texto (`RN-FAM-UNIT-43`), pero ningún *endpoint* de `1.14-b` permite a un tutor con ámbito `unidad_familiar` leer el texto publicado de cada nivel (`texto_legal.leer` solo admite `todos`). `ADR-066` no lo prevé | Añadir en `1.14-b` una lectura del texto publicado de los dos niveles con `consentimiento_imagen.leer` (ámbitos `todos` y `unidad_familiar`), p. ej. `GET /image-consent-texts`, que devuelva título y cuerpo resueltos en el idioma de la petición. Alternativa: diferirlo a `1.22` (portal) y aceptar que hasta entonces el canal `portal` solo se usa por API sin interfaz | `1.22` sin falta; en `1.14-b`, solo si se quiere que el canal `portal` sea utilizable por API de forma informada |
| `OPEN-FAM-UNIT-24` | **Reverificación involuntaria** (interacción de `RN-FAM-UNIT-72` con `1.14-a`). Hoy `PATCH /guardian-links/{id}` con `is_legal_guardian = true` vuelve a fijar `legal_guardian_verified_at = now()` **aunque el vínculo ya tuviera representación legal** (`GuardianLinkService`, comprobado en el código de `1.14-a`). Con `RN-FAM-UNIT-72`, una edición rutinaria que reenvíe ese campo (p. ej. un formulario completo de `1.14-d`) dejaría **sin efecto todas las decisiones de imagen** de ese tutor, que volverían a `pendiente`. Falla en cerrado, pero sorprenderá al centro | Que `legal_guardian_verified_at` solo cambie en la transición `false → true` (reenviar `true` sobre un vínculo que ya lo tiene no reverifica). Es un cambio de comportamiento de `1.14-a` y lo debe aprobar el usuario; si se aprueba, entra en `1.14-b` con su `CA` | `RN-FAM-UNIT-72` en producción; no el resto de `1.14-b` |

**Cómo quedan**: `OPEN-FAM-UNIT-22` ⇒ `secretaria` recibe `texto_legal.leer` (`todos`) en la siembra (`permisos.md §4`, `CA-FAM-UNIT-106`). `OPEN-FAM-UNIT-23` ⇒ `GET /image-consent-texts` en `1.14-b` (`RN-FAM-UNIT-75`, `api.md §2.7`, `CA-FAM-UNIT-103` a `-105`). `OPEN-FAM-UNIT-24` ⇒ `RN-FAM-UNIT-74` y `CA-FAM-UNIT-102`, cambio de `GuardianLinkService` con test de regresión en `1.14-b`.

### 13.4 Resuelta: surgida al aplicar la aprobación (2026-10-27)

**Resolución (2026-10-27): el usuario aprueba la recomendación.** `shown_locale` registra el idioma **realmente mostrado** (`RN-FAM-UNIT-43` corregida para coincidir con `RN-FAM-UNIT-65`; `CA-FAM-UNIT-101`, `api.md §2.7`, `datos.md §1.6`). No queda ninguna pregunta abierta de `1.14-b`. Texto original:

| ID | Pregunta | Recomendación (aprobada) | Bloqueaba |
|----|----------|---------------|---------|
| `OPEN-FAM-UNIT-25` | **Idioma registrado cuando el texto cae al idioma por defecto.** `RN-FAM-UNIT-43` dice que `shown_locale` es el idioma de la petición, «mismo criterio que `RN-FAM-UNIT-65`», y `RN-FAM-UNIT-65` dice que se registra «en qué idioma se mostró». Si el texto publicado no tiene traducción al idioma de la petición, `ADR-062` lo sirve en el idioma por defecto (`CA-FAM-UNIT-104`): los dos criterios dan valores distintos, y `CA-FAM-UNIT-101` no distingue el caso | Registrar el idioma **efectivamente mostrado** tras la caída (el mismo que `GET /image-consent-texts` devuelve en `locale`), que es lo que prueba qué leyó el tutor. Mientras no se decida, `1.14-b` implementa `RN-FAM-UNIT-43` y `CA-FAM-UNIT-101` tal como están, con texto traducido en el idioma de la petición en el test | Nada de `1.14-b`; es exactitud de la evidencia antes de datos reales |

---

## 14. Diferimientos y huecos

> Antes había **dos** secciones `## 14` («Resumen: qué se difiere y adónde» y «Huecos detectados en la revisión de documentación»). Se agrupan como §14.1 y §14.2 sin cambiar el número de §15, que citan `datos.md`, `api.md`, `permisos.md`, `ADR-066` y el código (corrección del 2026-10-27, `ADR-066 §9.14`).

### 14.1 Resumen: qué se difiere y adónde

| Qué | Adónde |
|-----|--------|
| `REQ-FAM-UNIT-005` completo y `REQ-PRL-004` | `1.14b` |
| Alta de la persona del alumno | `1.15` (`ALUM`) |
| Pantallas del tutor | `1.22` (`FAM-PORTAL`) |
| Notificaciones a tutores | `1.19` (`COM`) |
| Bloqueo efectivo de publicación y galerías | Cada publicador (`COM`, `INF`, `WEB`) |
| Efecto de la restricción `salud` | `REQ-SALUD` (fase 2) |
| Registro centralizado de consentimientos | `REQ-PRIV` (fase 2), leyendo de este módulo sin copia (`ADR-066 §5.7`) |
| Interfaces `ImageConsentRecords` e `ImageConsentRecorder` | Especificación de su primer consumidor (`REQ-AUT`/`REQ-PRIV`, fase 2) |
| Decisión del propio alumno (14 años) e invalidación por texto nuevo | Cuando haya criterio jurídico (`OPEN-FAM-UNIT-18`, `OPEN-066-02`); cambio *expand* con su especificación |
| Tipo `condiciones_uso`, aceptaciones y muro | `1.14-c` |
| Detalle de la restricción de custodia | Tras el ADR de `OPEN-FAM-UNIT-05` |
| Marcado `FUTURO` en el documento | Ninguno |

### 14.2 Huecos detectados en la revisión de documentación (2026-10-26) y correcciones de numeración (2026-10-27)

No cambian el alcance aprobado; `1.14-a` los cierra al empezar, antes de escribir código, y si alguno exige decidir se pregunta al usuario. **Estado tras `1.14-a`:**

1. **Cerrado salvo `RN-FAM-UNIT-63`.** `RN-FAM-UNIT-35`, `-37` y `-38` (unidad sin principal al revocar el último vínculo, `principal_cleared`) tienen ya su `CA` (`CA-FAM-UNIT-038`, `-039` y `-029`). `RN-FAM-UNIT-63` (sin versión publicada no hay muro) es de `1.14-c`: su `CA` se añade con esa entrega.
2. **Cerrado.** `PATCH /guardians/{id}` no cambia de unidad (decisión del usuario del 2026-10-26): `RN-FAM-UNIT-14` y `CA-FAM-UNIT-009`.
3. **Sigue vigente (informativo).** Los `CA` que exigen la persona de un alumno (`CA-FAM-UNIT-010`, `-012`, `-084` y los de consentimiento) se prueban con fábricas y con `REQ-SEED` (`CLAUDE.md §11`): no hay alta de alumno por API hasta `1.15`.
4. **Cerrado (2026-10-27): identificadores duplicados** detectados por `architect` en `ADR-066 §9.14`. Se conserva siempre el identificador que ya citan tests, código o documentación mezclados en `develop` (búsqueda en `apps/api`, `CHANGELOG.md`, `SECURITY.md` y los cinco ficheros del módulo), y se mueve el duplicado de `1.14-b`, que no tiene ningún test todavía, al siguiente libre:

   | Identificador | Se queda con | Referenciado por (no se toca) | El duplicado pasa a |
   |---|---|---|---|
   | `RN-FAM-UNIT-40` | §5.3: vínculos revocados invisibles sin `revocacion_tutor.leer` | `GuardianLinksApiTest.php`, `GuardianLinkRevocationTest.php`, `FamUnitOpenApiTest.php`, `GuardianLinkService.php`, `openapi/paths/fam-unit.yaml`, `CHANGELOG.md`, `SECURITY.md`, `api.md`, `permisos.md §7` | **`RN-FAM-UNIT-67`** (§5.4: niveles y decisiones cerrados). `datos.md §1.6` actualizado |
   | `CA-FAM-UNIT-050` | §12.3: límite de revocaciones (`RN-FAM-UNIT-66`) | `RevocationRateLimitTest.php`, `CHANGELOG.md` | **`CA-FAM-UNIT-086`** (§12.6: alumno sin decisiones) |
   | `## 14` | §14.1 y §14.2 de una sola §14 | — | §15 conserva su número |

---

## 15. Decisiones de implementación de `1.14-a`

Donde la especificación aprobada deja una elección de detalle, `1.14-a` fija lo siguiente. Ninguna cambia el alcance; todas son revisables por el usuario.

| # | Decisión | Motivo |
|---|----------|--------|
| D-1 | `POST /family-units`: `code` es **opcional**. Sin él, el sistema genera `UF-` + número de seis cifras, secuencial por centro (máximo existente, vivas y borradas, + 1, bajo bloqueo consultivo del centro). Con él, se valida (1 a 40 caracteres, sin caracteres de control) y es único entre las vivas. `PATCH` admite cambiar `code` | `OPEN-FAM-UNIT-06` (generado) y `api.md §2.1`/`CA-FAM-UNIT-002` (el cliente escribe el código, `PATCH` lo cambia) se contradicen si se leen como excluyentes; esta lectura cumple las dos |
| D-2 | `GET /guardian-links?status=revocado` sin `revocacion_tutor.leer` ⇒ `403 forbidden`, e `include_deleted` sin `familia.eliminar` ⇒ `403`. **Corregido tras la revisión de seguridad (M1, 2026-10-26)**: sin `revocacion_tutor.leer`, el listado omite los vínculos revocados y el detalle de uno es `404` (`RN-FAM-UNIT-40`, `CA-FAM-UNIT-048`); con el permiso se ven con `revocation` | `api.md §3` dice «exige» el permiso sin fijar el código; el estado `revocado` revela una resolución judicial |
| D-3 | `isAccessRevoked()` **no** depende de que el módulo esté contratado; los conjuntos de `FamilyMembership` y `GuardianRecipients` sí (vacíos si no lo está, `§9`) | `§9` solo nombra los conjuntos. Una revocación judicial no debe «deshacerse» al descontratar el módulo |
| D-4 | La ampliación de `Core\Domain` (`OPEN-FAM-UNIT-03`) se entrega como `PersonDirectory` (sin fecha de nacimiento: la única regla que la usaría, `OPEN-FAM-UNIT-18`, no existe todavía y `ADR-048` pide no construir sin consumidor) | `ADR-048` |
| D-5 | `PATCH /guardians/{id}` no tiene ningún campo editable en `1.14-a`: sin `family_unit`, responde `200` con el tutor sin cambios | `RN-FAM-UNIT-14` |
| D-6 | `ImageConsentPolicy` y los permisos de `consentimiento_imagen`, `condiciones_uso` y `aceptacion_condiciones` **no** se declaran en `1.14-a`; entran con `1.14-b` y `1.14-c`. *(2026-10-27: `condiciones_uso` se renombra `texto_legal` y entra en `1.14-b`, `OPEN-066-03`; ver §16)* | `§1.2`: un permiso sin *endpoint* invita a concederlo creyendo que hace algo |
| D-7 | Códigos de error añadidos a los de `api.md §7` por necesidad de las reglas aprobadas: `fam_unit.guardian.user_not_eligible` (`RN-FAM-UNIT-10`: usuario `inactivo`), `fam_unit.link.emergency_contact_revoked` (`RN-FAM-UNIT-35`), `fam_unit.guardian.not_editable` (`RN-FAM-UNIT-14`) y `fam_unit.validation.{principal_guardian_not_found, relationship_invalid, access_level_invalid, restricted_category_invalid, reason_invalid, required, boolean_invalid, reference_invalid, filter_forbidden}` para los vocabularios cerrados (`RN-FAM-UNIT-32`), los `public_id` mal formados y los campos obligatorios | La especificación exige un `422` en esos casos sin nombrar el código |
| D-8 | Mapa cerrado de `AR-10` (`permisos.md §3.1`): `familia` es un recurso de **tres** modelos (`FamilyUnit`, `Guardian`, `FamilyUnitStudent`) con tres servicios sancionados (`FamilyUnitService`, `GuardianService`, `FamilyUnitStudentService`) y `vinculo_tutor` de uno (`GuardianLinkService`). El test pasa de `model` a `models` por recurso. Los vínculos se leen desde `familia` por la relación `Guardian::links()`, nunca por consulta estática | Los tres modelos se piden entre sí dentro de las mismas reglas de dependencia; un único servicio por modelo habría exigido dependencias circulares |
| D-9 | **Resuelta (decisión del usuario, 2026-10-26)**: las pantallas de personal de §10 (`/familias`, `/familias/:id`) y el E2E `CA-FAM-UNIT-084` pasan a una entrega propia, **`1.14-d`** (§1.2). `1.14-a` no lleva frontend; la API está completa y documentada (`INV-006`) | «No inventes pantallas» |
| D-10 | **Resuelta (decisión del usuario, 2026-10-26)**: un vínculo `revocado` no se puede dar de baja, ni directamente ni arrastrado por la baja del tutor: `409 fam_unit.link.revoked_not_deletable` (`RN-FAM-UNIT-39`, `CA-FAM-UNIT-027`). `isAccessRevoked()` sigue contando solo vínculos vivos, y ya no puede perder una revocación por una baja; solo el levantamiento explícito la retira | `ADR-032`: la exclusión del tutor revocado de la lista de autorizados de `1.14b` depende de que la revocación se conserve |
| D-11 | `FamilyUnit` añade `deleted_at` a su representación **solo** cuando la unidad está dada de baja (listado con `include_deleted`); sin él, ese listado no distinguiría las bajas | `api.md §1.1` no lo prevé |
| D-12 | Índices de `datos.md §3` **no creados** por redundantes: `guardians (tenant_id, family_unit_id)`, `family_unit_students (tenant_id, family_unit_id)` y `guardian_student_links (tenant_id, student_person_id)`, parciales sobre las vivas, ya cubiertos por el índice completo `(tenant_id, <columna>)` que `tenantForeignId()` crea en cada clave foránea. Sí están el caliente de vínculos activos por tutor y el de prefijo de código | Un índice sin consulta que lo necesite es deuda (`datos.md §3`) |
| D-13 | `student_person` en los filtros y cuerpos es el `public_id` de la **persona**; `family_unit_students` no comprueba que la persona tenga o vaya a tener faceta `student` (no existe hasta `1.15`) | `OPEN-FAM-UNIT-01` |

---

## 16. Enmienda por `ADR-066` (2026-10-27): trazabilidad de los 27 cambios de su §9

`ADR-066` (fuente única del consentimiento de imagen) fue **aceptado** por el usuario el 2026-10-27 con estas resoluciones: `OPEN-066-01` ratificada (estado calculado en origen, sin `image_consents`); `OPEN-066-02`, en `1.14-b` un texto nuevo no invalida los `autorizo` anteriores, con validación jurídica antes de datos reales; `OPEN-066-03`, el recurso de permisos `condiciones_uso` pasa a ser `texto_legal`; `OPEN-066-04`, solo cuentan las decisiones posteriores a `legal_guardian_verified_at`; `OPEN-066-05`, el criterio sobre 14 y 18 años (`OPEN-FAM-UNIT-18`) es condición previa a datos reales junto a la validación de `OPEN-FAM-UNIT-08`.

| # (`ADR-066 §9`) | Dónde queda aplicado |
|---|---|
| 1 | §1.1, §1.2, §3.9, §4.9, `RN-FAM-UNIT-73`, `CA-FAM-UNIT-099` |
| 2 | §0.1, §3.8 |
| 3 | `RN-FAM-UNIT-41` (vínculo vivo, `activo` o `revocado`; revocado solo `presencial` con `revocacion_tutor.leer`), §4.6 |
| 4 | `RN-FAM-UNIT-35` |
| 5 | `RN-FAM-UNIT-43` (versión no condicional y `shown_locale` [DERIVADA]), `CA-FAM-UNIT-101` |
| 6 | `RN-FAM-UNIT-44` (sustituida), §6, `CA-FAM-UNIT-058` |
| 7 | `RN-FAM-UNIT-45` |
| 8 | `RN-FAM-UNIT-46`, §7.3, `CA-FAM-UNIT-094` |
| 9 | `RN-FAM-UNIT-68` (`autorizo` exige texto), `-69` (versión por canal), `-70` (texto nuevo no altera estados), `-71` (histórico sin revocados con `unidad_familiar`); además `-72` (`OPEN-066-04`) |
| 10 | §7.3 (contrato y prohibiciones a consumidores) |
| 11 | §7.6 |
| 12 | §12.6: `CA-FAM-UNIT-055` (tabla de verdad por filas), `-058` (sin bloqueo), nuevos `-087` a `-101` |
| 13 | §13 (`OPEN-FAM-UNIT-08` y `-09` concretadas) |
| 14 | §14.2 punto 4 (`RN-FAM-UNIT-40` → `-67`, `CA-FAM-UNIT-050` → `-086`, dos `## 14` → §14.1/§14.2) |
| 15-20 | `datos.md §1.6`, `§1.7`, `§1.8`, `§2`, `§3`, `§4`, `§6` |
| 21-24 | `api.md §1.5`, `§1.6`, `§2.7`, `§2.8`, `§3`, `§7` |
| 25-26 | `permisos.md §1`, `§2`, `§3`, `§3.1`, `§4` |
| 27 | `operacion.md §2`, `§3` |

**Defectos además de los de `ADR-066 §9.14`, corregidos en la misma enmienda**: `RN-FAM-UNIT-41`, `RN-FAM-UNIT-35` y la regla de `on_behalf_of` de `api.md §2.7` exigían «vínculo activo», en contradicción con la recomendación aprobada de `OPEN-FAM-UNIT-08` (el revocado sigue decidiendo). Quedan alineadas con `ADR-066 §5.4`.

**[DERIVADAS] de esta enmienda, ACEPTADAS por el usuario el 2026-10-27** (ya no son rechazables; se conserva la marca [DERIVADA] como trazabilidad de origen): `shown_locale` en las decisiones (`ADR-066 §9.5`, `RN-FAM-UNIT-43`, `CA-FAM-UNIT-101`); los códigos `fam_unit.terms.kind_not_available` (`RN-FAM-UNIT-73`) y `fam_unit.terms.kind_not_editable` (`api.md §1.7`); que no cuenten las decisiones de un vínculo con `legal_guardian_verified_at` nulo (`RN-FAM-UNIT-72`); y que `last_decision_at` se calcule solo con las decisiones que cuentan (`api.md §1.5`). Preguntas nuevas: `OPEN-FAM-UNIT-22` a `-24`, **resueltas** el mismo día (§13.3, §17).

---

## 17. Aprobación de `1.14-b` (2026-10-27)

El usuario aprueba la especificación enmendada de `1.14-b`. Queda **LISTA PARA IMPLEMENTAR**, con las condiciones previas a datos reales de §13 (validación jurídica de `OPEN-FAM-UNIT-08`, criterio sobre 14 y 18 años de `OPEN-FAM-UNIT-18`/`OPEN-066-05` y respuesta a `OPEN-066-02`), que no bloquean la implementación con datos sintéticos de `REQ-SEED`.

| Decisión | Dónde queda aplicada |
|----------|----------------------|
| `OPEN-FAM-UNIT-24`: `legal_guardian_verified_at`/`_by` solo cambian en la transición `false → true` | `RN-FAM-UNIT-74` (§5.3), `RN-FAM-UNIT-72`, §4.4, `CA-FAM-UNIT-087`, `CA-FAM-UNIT-102`, `api.md §2.4`, `datos.md §1.4`, `operacion.md §6` |
| `OPEN-FAM-UNIT-23`: lectura del texto publicado de cada nivel por el tutor | `RN-FAM-UNIT-75`, §3.8, §4.6, §4.7, `CA-FAM-UNIT-103` a `-105`, `api.md §1.9`, `§2.7`, `§3`, `permisos.md §2`, `§3.1` |
| `OPEN-FAM-UNIT-22`: `texto_legal.leer` para `secretaria` | `permisos.md §4`, `operacion.md §3`, `CA-FAM-UNIT-106` |
| [DERIVADAS] de la enmienda, aceptadas | §16 (último párrafo), `RN-FAM-UNIT-43`, `-72`, `-73`, `api.md §1.5`, `§1.7`, `§7`, `datos.md §1.6` |
| Dos niveles, `web` y `redes_sociales`, sin separar por red social | §3.8, `RN-FAM-UNIT-67` |
| `OPEN-FAM-UNIT-25`: `shown_locale` = idioma realmente mostrado | `RN-FAM-UNIT-43`, `CA-FAM-UNIT-101`, §13.4, `api.md §2.7`, `datos.md §1.6` |
| [DERIVADAS] de la aprobación, aceptadas: `GET /image-consent-texts` legible por cualquier sujeto con el permiso (también un tutor sin vínculos activos) y con campo `locale`; con ámbito `unidad_familiar`, `last_decision_at` nunca refleja la decisión de un tutor revocado | `RN-FAM-UNIT-75`, `CA-FAM-UNIT-105`, `api.md §1.5`, `§1.9`, `§2.7`, `permisos.md §3.1` |

**Tareas de implementación que añade la aprobación** (además de las de §16):

1. **Cambio de comportamiento de `1.14-a` en `GuardianLinkService`** (`RN-FAM-UNIT-74`): fijar `legal_guardian_verified_at`/`_by` solo cuando `is_legal_guardian` pasa de `false` a `true` en `PATCH` (y al crear con `true`); reenviar `true` no los toca. Test de regresión `CA-FAM-UNIT-102`, que debe fallar antes del cambio. Revisar que `CA-FAM-UNIT-039` (revocar y levantar no cambian la verificación) sigue en verde, y actualizar la descripción de `PATCH /guardian-links/{id}` en `apps/api/openapi/paths/fam-unit.yaml`.
2. **`GET /image-consent-texts`** (`RN-FAM-UNIT-75`): ruta con `RequireAuthenticatedUser` → `module-enabled:fam_unit` → `permission:consentimiento_imagen.leer`, documentada en `apps/api/openapi/paths/fam-unit.yaml` con su esquema (`api.md §1.9`) y cubierta por el test de OpenAPI del módulo.
3. **Siembra de `texto_legal.leer` en `secretaria`** (`ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS` y `fam_unit:grant-permissions`, `CA-FAM-UNIT-106`).
4. **Traducciones** (`ADR-066 §9.23`, `api.md §7`), en `apps/api/lang/{es,en,de,fr}/fam_unit.php`: las claves de error nuevas de `1.14-b` — `image_consent.not_legal_guardian`, `image_consent.guardian_required`, `image_consent.guardian_not_linked`, `image_consent.text_not_published`, `image_consent.text_version_invalid`, `terms.kind_not_available`, `terms.kind_not_editable`, `terms.kind_invalid`, `terms.requires_reacceptance_not_allowed`, `terms.not_draft`, `terms.default_locale_missing` y `export.q_not_allowed` — y las etiquetas de recurso `fam_unit.permissions.resources.consentimiento_imagen` y `fam_unit.permissions.resources.texto_legal` (`CA-FAM-UNIT-082`). `GET /image-consent-texts` y `RN-FAM-UNIT-74` no añaden claves. Los **textos legales del centro** (título y cuerpo de `terms_versions`) son contenido del centro (`ADR-062`): ni se escriben en ficheros de idioma ni los toca el agente `traductor` (§11).
