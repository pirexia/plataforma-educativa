# REQ-FAM-UNIT · Unidad familiar y tutores · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-FAM-UNIT` (sección 5.3 del documento de requisitos). Este paso cubre **`REQ-FAM-UNIT-001`** (unidad familiar), **`-002`** (vinculación de tutores, custodia y restricciones de acceso por tutor), **`-003`** (condiciones de uso) y **`-004`** (consentimiento de imagen granular). **`-005`** (personas autorizadas a recoger, `ADR-032`) es del paso **`1.14b`** y queda fuera (§1.4) |
| Prioridad | MUST |
| Fase | 1 · paso **1.14** del plan (bloque D, Personas) |
| Depende de | Documento: `CORE`, `AUTH`. Real: `CORE` y `AUTH` (esenciales, ya implementados). Ninguna tabla de este módulo depende del curso académico (`ADR-034 §4`: «unidad familiar» está en la lista expresa de entidades **sin** `academic_year_id`). Propuesta: `depends_on: []` (`OPEN-FAM-UNIT-14`) |
| Esencial | Propuesta: `essential: false` (`OPEN-FAM-UNIT-14`) |
| Estado | **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). Los cinco bloqueantes de §13.1 están resueltos con su recomendación; persisten las condiciones listadas al inicio de §13. **`1.14-a` IMPLEMENTADA (2026-10-26)**: unidades, tutores, alumnado, vínculos, revocación, resolutor `unidad_familiar`, interfaces de §7.1 a §7.4 y ampliación de `Core\Domain`; decisiones de detalle en §15. **Pendiente**: `1.14-b`, `1.14-c`, el detalle de custodia (ADR) y las pantallas de personal de §10 (ver §15, D-9). |
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
| **Consentimiento de imagen** | `ImageConsentDecision` (histórico) e `ImageConsent` (estado vigente) | Decisión por alumno y por nivel (`web`, `redes_sociales`) (`REQ-FAM-UNIT-004`) |
| **Condiciones de uso** | `TermsVersion` / `TermsAcceptance` | Texto del centro, versionado, y su aceptación por cada tutor (`REQ-FAM-UNIT-003`) |

Reglas de nombre **[DERIVADA]**: el **ámbito** de permiso se llama `unidad_familiar` (`RPERM-004`, `ADR-044 §4.1`); ningún **recurso** de permiso de este módulo usa ese nombre para no confundirlos (mismo criterio que `REQ-ACAD/permisos.md §1` con `grupo`). El código de módulo propuesto es `fam_unit` y el prefijo de traducciones y errores, `fam_unit.`.

### 0.2 Contradicciones y huecos detectados

| # | Hallazgo | Fuentes | Cómo queda en esta propuesta |
|---|----------|---------|------------------------------|
| C1 | **Dependencia circular en la práctica con `REQ-ALUM`.** `REQ-FAM-UNIT` vincula tutores con alumnos, pero el alumno (`Student`) es de `REQ-ALUM` (`1.15`), que a su vez **depende** de `FAM-UNIT` (`§5.0`). No se puede depender de `ALUM` | `§5.0`, `§5.3`, `§5.6`, `§16.2` | El lado «alumno» se modela sobre **`people`** (Core, ya existe), no sobre `students`: `ADR-034 §1` ya fija que las facetas cuelgan de `people`. `ALUM` creará después la faceta `student` sobre la misma persona. Contrato en §7.4. **Consecuencia que hay que aceptar**: hasta `1.15` no hay forma por API de crear la persona de un alumno, así que las pantallas de alta de alumnos en una unidad no tienen datos reales hasta entonces. Decisión en `OPEN-FAM-UNIT-01` |
| C2 | **Cardinalidad de `§16.2` frente al caso real.** `FAMILY_UNIT ||--o{ GUARDIAN` (un tutor pertenece a una sola unidad) y `FAMILY_UNIT ||--o{ STUDENT` (un alumno a una sola unidad), pero `GUARDIAN }o--o{ STUDENT`. El caso real de un padre con hijos de dos parejas distintas, en dos unidades, no cabe si el vínculo solo puede ser dentro de la unidad | `§16.2`, `REQ-FAM-UNIT-001`/`-002` | Se respetan **literalmente** las tres cardinalidades de `§16.2` y se permite que un vínculo tutor ↔ alumno cruce unidades (opción C de `OPEN-FAM-UNIT-02`). Cambiar la cardinalidad tutor ↔ unidad exigiría ADR (`§16`: «no deben modificarse sin ADR») |
| C3 | **`INV-008` no lo puede cumplir este módulo solo.** Exige «base legal registrada y consentimiento del tutor» para **cualquier** dato personal de un menor. La base legal por campo sigue sin catálogo (`OPEN-13`, `REQ-PRIV-006`, ya señalado en `ADR-043 §8.1`), y el tratamiento ordinario de datos escolares no descansa en el consentimiento sino en otras bases legales | `INV-008`, `RSEC-GDPR-009`, `REQ-PRIV-002`, `ADR-043` | `1.14` aporta lo que es suyo: **quién es representante legal** de cada menor, con su verificación (§3.3), y el consentimiento allí donde el requisito lo pide expresamente (imagen). La lista de bases legales por campo sigue en `OPEN-13`. Se dice explícitamente que `INV-008` queda **cubierto parcialmente** (`OPEN-FAM-UNIT-08`) |
| C4 | **Tres sitios para el consentimiento de imagen.** `REQ-FAM-UNIT-004` lo define; `REQ-AUT-002` lo remite a `-004` (coherente); `REQ-PRIV-002` (fase 2) pide un «registro centralizado de todos los consentimientos: imagen…». Es el mismo riesgo de lista duplicada que `ADR-032` resolvió para la recogida | `§5.3`, `§5.13`, `§5.45` | **Fuente única en este módulo** desde `1.14`, con los campos que `REQ-PRIV-002` pedirá (versión del texto, fecha, hora, IP, canal) para que `PRIV` lo **consuma** por interfaz y no lo duplique. Recomendación en `OPEN-FAM-UNIT-09`; si se acepta, conviene un ADR corto análogo a `ADR-032` |
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
| Consentimiento de imagen | Decisiones por alumno y nivel, histórico inmutable, estado vigente, informe por unidad familiar e interfaz de comprobación para `INV-014` | `REQ-FAM-UNIT-004`, `INV-014`, `RSEC-GDPR-011` |
| Condiciones de uso | Versiones traducibles, publicación, re-aceptación, histórico de aceptaciones y muro de aceptación | `REQ-FAM-UNIT-003` |
| Pantallas de personal del centro | Listado y ficha de unidad familiar, gestión de vínculos y consentimientos, versiones de condiciones y muro de aceptación (§10) | `INV-006` |

### 1.2 Entregas propuestas [DERIVADA]

El paso es grande y mezcla tres riesgos distintos. Precedente: `1.11-a`/`1.11-b`, `1.12-a`/`1.12-b`. Partición propuesta (`OPEN-FAM-UNIT-15`):

| Entrega | Contenido | Por qué junto |
|---------|-----------|---------------|
| **`1.14-a`** | Módulo, unidades, tutores, alumnado de la unidad, vínculos, revocación, resolutor e interfaces de §7.1-§7.4 | Es la base de todo lo demás y de `1.14b` |
| **`1.14-b`** | Consentimiento de imagen (§4.6-§4.8) e interfaz `INV-014` | Riesgo propio (dato de menores con efecto de publicación) y no lo necesita `1.14-a` |
| **`1.14-c`** | Condiciones de uso y muro de aceptación (§4.9-§4.10) | Toca la sesión (C6) y depende de `OPEN-FAM-UNIT-12` |
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
| Registro centralizado de consentimientos, RAT, derechos ARCO | `REQ-PRIV-001`/`-002`/`-003` | Fase 2 | `REQ-PRIV`, consumiendo §7.3 (C4) |
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

- **Dos niveles independientes** (`REQ-FAM-UNIT-004`): `web` (portal web del centro: web pública, galerías, publicaciones internas) y `redes_sociales` (Facebook, Instagram, X, LinkedIn, *newsletters*, prensa).
- **Estado por alumno y nivel**: `autorizo`, `no_autorizo` o `pendiente`.
- Cada **decisión** de un tutor es una fila **inmutable** (`§16.3`, regla 6: consentimientos *append-only*) con el texto del consentimiento en vigor, fecha y hora, IP, canal y autor. El **estado vigente** se deriva de las decisiones según una regla de combinación que el requisito no fija (`OPEN-FAM-UNIT-08`).
- Revocar el consentimiento es registrar `no_autorizo`: efecto inmediato (`REQ-FAM-UNIT-004`).
- Sin ninguna decisión, el estado es `pendiente`, que **bloquea** igual que `no_autorizo` (`REQ-FAM-UNIT-004`, `INV-014`).

### 3.9 Condiciones de uso

- Texto del centro (`REQ-FAM-UNIT-003`: «personalizables por el Administrador de Centro»), contenido traducible según `ADR-062` (capa 3 de `REQ-CORE-006`).
- **Versionado**: `borrador` → `publicada` → `retirada`. Como mucho una versión `publicada` a la vez.
- Al publicar una versión nueva, el centro decide si **exige re-aceptación** (`REQ-FAM-UNIT-003`: «posibilidad de requerir re-aceptación»).
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
2. Lo modifica con `PATCH /guardian-links/{id}`.
3. **Revocación**: `POST /guardian-links/{id}/revocation`. Efecto inmediato (§3.5). **Levantamiento**: `DELETE /guardian-links/{id}/revocation`.
4. Baja del vínculo (no judicial): `DELETE /guardian-links/{id}` (lógica).

### 4.5 Registrar la documentación de una custodia (condicionado a `OPEN-FAM-UNIT-05`)

`PUT /guardian-links/{id}/custody-restriction` sobre un vínculo revocado. Cada lectura (`GET`) registra un evento `read` en `audit_logs` (`RPERM-015`).

### 4.6 Registrar una decisión de imagen

**Por el propio tutor** (API; pantalla en `1.22`):

1. `POST /image-consent-decisions` con alumno, nivel y decisión. Permiso `consentimiento_imagen.actualizar` con ámbito `unidad_familiar` (o `todos`).
2. Validación de negocio, además del permiso: el sujeto tiene vínculo **activo** con el alumno **y** con representación legal (`RN-FAM-UNIT-41`).
3. Se escribe la decisión (inmutable), se recalcula el estado vigente en la misma transacción y se audita.

**Por el centro, en nombre del tutor** (papel entregado en secretaría): igual, con `on_behalf_of` (el tutor que decidió) y canal `presencial`; el autor es el usuario del centro (`RN-FAM-UNIT-43`).

### 4.7 Consultar consentimientos

`GET /image-consents` (estado vigente; filtros por unidad, alumno, nivel y estado) y `GET /image-consent-decisions` (histórico de un alumno).

### 4.8 Informe de consentimientos por unidad familiar

`POST /image-consents/exports`: CSV en cola por la primitiva `data_exports` de `Core` (`INV-012`), con enlace caducable, auditado como `exported`. «Informe periódico» (`REQ-FAM-UNIT-004`): si además debe generarse solo cada cierto tiempo, `OPEN-FAM-UNIT-11`.

### 4.9 Publicar una versión de las condiciones de uso

1. El administrador crea una versión en `borrador` (`POST /terms-versions`) con título y texto traducibles.
2. La publica (`POST /terms-versions/{id}/publish`) indicando si exige re-aceptación. La versión publicada anterior pasa a `retirada` en la misma transacción.

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
| `RN-FAM-UNIT-35` | Un vínculo `revocado` no cuenta para ningún ámbito `unidad_familiar`, ni como destinatario (§7.2), ni como contacto de emergencia, ni puede registrar decisiones de imagen. Marcar como contacto de emergencia un vínculo `revocado` ⇒ `422 fam_unit.link.emergency_contact_revoked` (la base de datos lo impide además con un `CHECK`). |
| `RN-FAM-UNIT-36` | Un sujeto que solo tiene ámbito `unidad_familiar` **nunca** ve vínculos revocados, ni motivo, ni fechas de revocación, ni la existencia de una restricción de custodia, aunque sean de su propia unidad. [DERIVADA] Evita que un tutor revocado o un tercero infiera una resolución judicial. |
| `RN-FAM-UNIT-37` | Revocar no cambia la representación legal del vínculo. Perder el acceso no es perder la patria potestad; qué efecto tiene sobre el consentimiento de imagen: `OPEN-FAM-UNIT-08`. |
| `RN-FAM-UNIT-38` | El tutor principal de una unidad no puede tener **todos** sus vínculos con alumnos de esa unidad revocados: revocar el último ⇒ la unidad queda sin principal en la misma transacción y la respuesta lo indica. [DERIVADA] |

### 5.4 Consentimiento de imagen

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-40` | Niveles cerrados: `web`, `redes_sociales`. Decisiones: `autorizo`, `no_autorizo`. `pendiente` es la **ausencia** de decisión vigente, no un valor que se registra. |
| `RN-FAM-UNIT-41` | Solo cuenta una decisión de un tutor con vínculo **activo** y **representación legal** sobre ese alumno. Lo comprueba el servidor además del permiso: `422 fam_unit.image_consent.not_legal_guardian`. (Quién más puede decidir: `OPEN-FAM-UNIT-08`.) |
| `RN-FAM-UNIT-42` | Las decisiones son **inmutables** (`§16.3`, regla 6): tabla de solo anexar, `REVOKE UPDATE, DELETE` en el motor y modelo `AppendOnlyModel` (`ADR-034 §6`). Corregir es registrar otra decisión. |
| `RN-FAM-UNIT-43` | Cada decisión registra: alumno, nivel, decisión, tutor que decide, usuario que la registra (el propio tutor o el del centro), canal (`portal` o `presencial`), versión del texto del consentimiento mostrado (si se aprueba `OPEN-FAM-UNIT-09`), fecha y hora, IP y agente de usuario. |
| `RN-FAM-UNIT-44` | El estado vigente se recalcula en la **misma transacción** que la decisión y bajo bloqueo de fila del par (alumno, nivel), para que dos decisiones simultáneas no dejen un estado incoherente. |
| `RN-FAM-UNIT-45` | Regla de combinación de varias decisiones (dos progenitores): **pendiente de `OPEN-FAM-UNIT-08`**. Recomendación, que falla en cerrado: `no_autorizo` si algún representante legal con vínculo vigente decidió `no_autorizo`; `autorizo` solo si **todos** ellos decidieron `autorizo`; `pendiente` en cualquier otro caso. |
| `RN-FAM-UNIT-46` | `pendiente` y `no_autorizo` **bloquean** la publicación en ese nivel (`INV-014`). La interfaz de §7.3 responde «no publicable» también si el módulo no está contratado, si el alumno no existe o si no pertenece al centro (fallo en cerrado). |
| `RN-FAM-UNIT-47` | Los dos niveles son independientes: una decisión sobre `web` no altera `redes_sociales`. |

### 5.5 Condiciones de uso

| ID | Regla |
|----|-------|
| `RN-FAM-UNIT-60` | Como mucho una versión `publicada`. Publicar retira la anterior en la misma transacción. Una versión `publicada` o `retirada` no se edita: `409`. |
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
| Alumno de 14 años o más y el consentimiento de imagen | `REQ-FAM-UNIT-004` asigna la decisión al tutor; la `LOPDGDD` (art. 7) permite al mayor de 14 consentir por sí mismo. No se inventa: `OPEN-FAM-UNIT-18` |
| Dos peticiones simultáneas de decisión de imagen sobre el mismo alumno y nivel | Bloqueo de fila (`RN-FAM-UNIT-44`); el estado final es el que dicta la regla de combinación sobre las dos decisiones |
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

### 7.3 `ImageConsentPolicy` (lectura, `INV-014`)

| Consulta | Devuelve |
|----------|----------|
| ¿Es publicable la imagen de estos alumnos en este nivel? | Booleano: `true` solo si **todos** los alumnos tienen estado `autorizo` en ese nivel |
| ¿Cuáles de estos alumnos bloquean la publicación en este nivel? | Ids de persona de los que no están en `autorizo` |

Reglas para el consumidor, que se copian en su especificación:

1. Se consulta **en el momento de publicar y en el momento de servir** la imagen, no solo al subirla: así una revocación deja de mostrar la imagen «inmediatamente» (segundo criterio de aceptación de `REQ-FAM-UNIT-004`). Un evento no basta.
2. Falla en cerrado: alumno desconocido, de otro centro o módulo no contratado ⇒ no publicable.
3. Una foto de grupo es publicable solo si **todos** los alumnos identificables lo son (`REQ-INF-008`).

### 7.4 Contrato con `REQ-ALUM` (`1.15`)

`ALUM` depende de `FAM-UNIT`; este módulo **no** depende de `ALUM`. El contrato:

1. El alumno se identifica **por su persona** (`people`). `ALUM` crea la persona y la faceta `student` (`ADR-034 §1`); este módulo nunca crea personas.
2. `ALUM` registra la pertenencia del alumno a una unidad mediante una interfaz de escritura de este módulo, `FamilyUnitStudents` (añadir / quitar una persona de alumno en una unidad), con las mismas reglas de §5.2, en la transacción del alta del alumno si así lo especifica `1.15`.
3. En la API de este módulo, el alumno se nombra `student_person` (`public_id` de la **persona**). Si `1.15` expone un `public_id` propio de `students`, la API de este módulo **añadirá** el campo (`ADR-038 §7`, cambio compatible), nunca lo sustituirá en la misma versión. Decisión en `OPEN-FAM-UNIT-01`.
4. Los nombres de alumnos y tutores se leen de `Core` (interfaz de personas, `OPEN-FAM-UNIT-03`), nunca de `students`.

### 7.5 Muro de sesión (`REQ-FAM-UNIT-003`)

Este módulo aporta una comprobación «¿tiene el usuario U una aceptación pendiente?» y un código de error. Cómo se engancha a la sesión (registro de muros en `Core`/`Auth`, igual que el de MFA) es `OPEN-FAM-UNIT-12`: no se decide aquí porque toca código de otros dos módulos.

### 7.6 Eventos de dominio

**Ninguno en `1.14`** (`ADR-048`: sin consumidor no se emite). Los primeros consumidores previsibles los declararán con su especificación: `1.14b` (`GuardianLinkRevoked` para avisar a conserjería, si lo pide) y `REQ-PRIV` (cambios de consentimiento). **Consume**: ninguno (ver `RN-FAM-UNIT-13` y `OPEN-FAM-UNIT-16`).

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

`apps/web/src/modules/fam-unit/` con `shell.ts`, `api/`, `types/`, `locales/{es,en,de,fr}.json` (`AR-11`). Pantallas de **personal del centro** (las del tutor son `1.22`, `OPEN-FAM-UNIT-13`):

| Ruta | `meta.layout` | `meta.permissions` (anyOf) | Contenido |
|------|---------------|----------------------------|-----------|
| `/familias` | aplicación | `familia.leer` | Listado con la tabla de datos de `ADR-054` (código, tutor principal, nº de tutores, nº de alumnos) |
| `/familias/:id` | aplicación | `familia.leer` | Ficha: tutores, alumnos, vínculos (con revocación si se tiene `revocacion_tutor.leer`) y consentimientos (si se tiene `consentimiento_imagen.leer`) |
| `/familias/consentimientos` | aplicación | `consentimiento_imagen.leer` | Estado vigente filtrable, histórico por alumno, informe |
| `/configuracion/condiciones-de-uso` | aplicación | `condiciones_uso.leer` | Versiones, edición del borrador con el campo traducible de `ADR-062 §5.7`, publicación |
| `/condiciones-de-uso` | desnudo | `[]` | Muro de aceptación. **Amplía la lista cerrada** de rutas de autoservicio por identidad (`src/navigation/modules.spec.ts`); requiere aprobación (`OPEN-FAM-UNIT-12`) |

Navegación: entrada «Familias» (visible si y solo si se puede montar `/familias`) y entrada de configuración «Condiciones de uso». Bloques del panel: ninguno en `1.14`.

Accesibilidad (WCAG 2.2 AA): los estados `autorizo`/`no_autorizo`/`pendiente` y `activo`/`revocado` nunca se transmiten solo por color; el muro de condiciones es navegable por teclado, con el texto completo accesible y el botón de aceptar deshabilitado solo con explicación textual.

## 11. Internacionalización (`INV-009`, `ADR-021`)

- Claves de interfaz en `apps/web/src/modules/fam-unit/locales/{es,en,de,fr}.json` y de servidor en `apps/api/lang/{es,en,de,fr}/fam_unit.php`: nombres de recurso (`resource_label_key`), códigos de error, tipos de relación, niveles y estados de consentimiento, estados de vínculo, categorías restringidas, estados de versión.
- **Ningún literal** en código. Los enumerados viajan sin traducir en la API (`ADR-038 §3.2`) y se traducen en la SPA.
- Contenido del centro traducible: título y texto de las condiciones de uso (`ADR-062`). Si se aprueba `OPEN-FAM-UNIT-09`, también el texto de cada nivel de consentimiento de imagen.
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

### 12.6 Consentimiento de imagen (`1.14-b`)

- **CA-FAM-UNIT-050** — *Dado* un alumno sin decisiones, *cuando* se consulta su estado en `web` y `redes_sociales`, *entonces* ambos son `pendiente` y `ImageConsentPolicy` responde «no publicable» en los dos.
- **CA-FAM-UNIT-051** — *Dado* un tutor con vínculo activo y representación legal, *cuando* registra `autorizo` en `web`, *entonces* `201`, la decisión es inmutable y `redes_sociales` no cambia (`RN-FAM-UNIT-47`).
- **CA-FAM-UNIT-052** — *Dado* un tutor con vínculo activo **sin** representación legal, *cuando* intenta registrar una decisión, *entonces* `422 fam_unit.image_consent.not_legal_guardian` (`RN-FAM-UNIT-41`).
- **CA-FAM-UNIT-053** — *Dado* un tutor con vínculo **revocado**, *cuando* intenta registrar una decisión sobre ese alumno, *entonces* `404` (el alumno no está en su ámbito) y no se escribe nada.
- **CA-FAM-UNIT-054** — *Dado* un alumno con `autorizo` en `redes_sociales`, *cuando* un tutor registra `no_autorizo`, *entonces* en la misma transacción el estado pasa a `no_autorizo` e `ImageConsentPolicy` responde «no publicable» en la consulta siguiente (revocación con efecto inmediato, `REQ-FAM-UNIT-004`).
- **CA-FAM-UNIT-055** — *Dado* la regla de combinación aprobada en `OPEN-FAM-UNIT-08`, *cuando* dos representantes legales deciden distinto, *entonces* el estado vigente es el que dicta la regla (un caso por cada combinación de la tabla de verdad).
- **CA-FAM-UNIT-056** — *Dado* `PATCH` o `DELETE` sobre una decisión por cualquier vía (API, modelo, SQL con el rol de aplicación), *entonces* se rechaza (`RN-FAM-UNIT-42`: `AppendOnlyModel` y `REVOKE` en el motor).
- **CA-FAM-UNIT-057** — *Dado* una decisión registrada por secretaría en nombre de un tutor, *cuando* se consulta el histórico, *entonces* muestra el tutor que decidió, el usuario que la registró y el canal `presencial` (`RN-FAM-UNIT-43`).
- **CA-FAM-UNIT-058** — *Dado* dos decisiones simultáneas sobre el mismo alumno y nivel, *cuando* terminan, *entonces* el estado vigente coincide con aplicar la regla sobre las dos (test de concurrencia, `RN-FAM-UNIT-44`).
- **CA-FAM-UNIT-059** — *Dado* `ImageConsentPolicy` con una lista de alumnos donde uno es de otro centro o no existe, *entonces* «no publicable» y ese id aparece entre los que bloquean (`RN-FAM-UNIT-46`).
- **CA-FAM-UNIT-060** — *Dado* el módulo descontratado, *cuando* otro módulo consulta `ImageConsentPolicy`, *entonces* «no publicable» para todos (§9).
- **CA-FAM-UNIT-061** — *Dado* un usuario con `consentimiento_imagen.exportar`, *cuando* pide el informe, *entonces* se encola, la respuesta es `202` con el recurso de exportación, el CSV tiene el esquema cerrado de `api.md` y la solicitud queda auditada como `exported`; *y* con `q` en el cuerpo ⇒ `422` (`ADR-054 §9`).
- **CA-FAM-UNIT-062** — *Dado* un alumno con estado `no_autorizo`, *cuando* un módulo de prueba que simula un publicador consulta `ImageConsentPolicy` antes de publicar, *entonces* recibe «no publicable» con ese alumno entre los que bloquean (contrato de `INV-014`; el bloqueo real lo verifica cada consumidor, §7.3).

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
- **CA-FAM-UNIT-084** *(E2E)* — *Dado* secretaría en navegador real, *cuando* crea una unidad, añade un tutor, vincula un alumno de prueba y revoca el vínculo, *entonces* la ficha refleja cada paso y el estado revocado se distingue sin depender del color (WCAG 2.2 AA).
- **CA-FAM-UNIT-085** (condicionado a `OPEN-FAM-UNIT-05`) — *Dado* un usuario con `restriccion_custodia.leer` concedido desde un rol con `special_data_access = true`, *cuando* lee el detalle, *entonces* recibe los datos descifrados y se escribe un evento `read` en `audit_logs` sin valores; *y* desde un rol sin el atributo, la concesión es inerte y responde `403`.

---

## 13. Preguntas abiertas

**Resolución (2026-10-26): el usuario aprueba todas las recomendaciones de esta sección, tal cual, y la partición en `1.14-a`/`-b`/`-c`.** Quedan estas condiciones, que la aprobación no elimina:
- `OPEN-FAM-UNIT-05`: solo entra la revocación como permiso ordinario; el detalle documental de la custodia espera a un ADR de `architect` (cifrado por campo y auditoría de lectura). No se implementa antes.
- `OPEN-FAM-UNIT-12`: el registro de «muros de sesión» y la ampliación de las listas cerradas de `AR-07a` y de rutas `[]` quedan aprobados; el diseño pasa por `architect` antes de `1.14-c`.
- `OPEN-FAM-UNIT-08`: unanimidad para `autorizo`; conviene validarla con asesoría jurídica antes de datos reales.
- `OPEN-FAM-UNIT-17`, `-18` y `-20`: la recomendación es no inventar reglas jurídicas; permanecen sin regla hasta que haya criterio legal, y no bloquean `1.14-a`.

Texto original de la propuesta (cada pregunta lleva su recomendación): **Bloqueantes** (§13.1): resueltos el 2026-10-26 con la recomendación; la columna «Bloquea» indica la parte afectada por las condiciones que persisten.

### 13.1 Bloqueantes

| ID | Pregunta | Opciones | Recomendación | Bloquea |
|----|----------|----------|---------------|---------|
| `OPEN-FAM-UNIT-01` | ¿Cómo se identifica al alumno mientras `ALUM` (`1.15`) no existe? | **A**: por su persona (`people`), con el contrato de §7.4; las pantallas de alta de alumnos en una unidad quedan sin datos reales hasta `1.15`. **B**: ampliar `Core` para que este módulo cree personas de menores ya en `1.14` (invade `ALUM` y adelanta un alta de menores sin la base legal de `INV-008`). **C**: reordenar el plan para implementar `1.15` antes (imposible: `ALUM` depende de `FAM-UNIT`) | **A**. Es lo que fija `ADR-034 §1` y no inventa altas de menores | Todo `1.14-a` |
| `OPEN-FAM-UNIT-03` | Ampliación de `Core\Domain` | Interfaz de lectura de personas (por `public_id`, por ids: nombre visible, `public_id`, si está viva; **y la fecha de nacimiento** solo para las reglas de edad de `OPEN-FAM-UNIT-18`) y la resolución usuario → persona | Aprobarla, con la misma disciplina que `activeIdsAmong` (una consulta, sin memoización entre llamadas, sin exponer el modelo) | Todo `1.14-a` (`INV-007`) |
| `OPEN-FAM-UNIT-05` | Detalle de la restricción de custodia como categoría especial (C5) | **A**: diseñar ahora, con ADR de `architect`, el cifrado a nivel de campo (gestión de claves: `APP_KEY` frente a clave por centro, rotación, imposibilidad de buscar) y el mecanismo de auditoría de lectura que sustituye al cable trampa `AR-09`; tabla `custody_restrictions` con permiso `restriccion_custodia.*` especial. **B**: no guardar el detalle en la plataforma: solo la revocación (§3.5) y, fuera del sistema, el expediente en papel. **C**: guardarlo como dato ordinario (contradice `CLAUDE.md §8` según el encargo) | **A, en dos tiempos**: la revocación (lo que protege al menor) entra en `1.14-a` sin esperar; el detalle se difiere hasta que el ADR esté aceptado. Hay que aceptar además que, por `RPERM-012`, **ningún rol predefinido** podrá leerlo: el centro tendrá que darlo a un rol con `special_data_access` | Solo §3.7/§4.5 |
| `OPEN-FAM-UNIT-08` | Regla de combinación del consentimiento de imagen (dos representantes legales que deciden distinto) y efecto de la revocación sobre ello. También: ¿pueden decidir tutores sin representación legal (abuelo, apoderado)? | **A**: unanimidad de los representantes legales con vínculo vigente para `autorizo`, cualquier `no_autorizo` bloquea (falla en cerrado). **B**: la última decisión gana. **C**: basta un representante legal | **A**, y solo deciden representantes legales. La decisión del revocado: cuenta su `no_autorizo` y su `autorizo` sigue siendo necesario (pierde el acceso, no la patria potestad, `RN-FAM-UNIT-37`); si queda bloqueado, secretaría registra su decisión en papel. **Es una cuestión jurídica: conviene validarla con el DPO o asesoría del centro piloto** | `1.14-b` |
| `OPEN-FAM-UNIT-12` | Muro de condiciones: cómo se engancha a la sesión y qué rutas quedan exentas | **A**: registro de «muros de sesión» en `Core` (el de MFA de `Auth` pasaría a ser uno más), con lista cerrada de rutas exentas (`/me`, `/me/terms*`, `/logout`, `GET /tenant/branding`) y ampliación aprobada de la lista cerrada de rutas `[]` de la SPA. **B**: comprobación dentro del *middleware* de MFA (acopla dos módulos). **C**: solo en la SPA (no cumple `INV-010`: la API seguiría abierta) | **A**. Conviene que lo revise `architect` porque toca la cadena de *middleware* de todo el producto. ¿El muro aplica solo a tutores (`RN-FAM-UNIT-61`) o también a alumnado? El requisito dice «tutor»; se recomienda solo tutores | `1.14-c` |

### 13.2 No bloqueantes (afectan al detalle; tienen recomendación aplicable)

| ID | Pregunta | Recomendación |
|----|----------|---------------|
| `OPEN-FAM-UNIT-02` | Padre con hijos en dos unidades (C2). **A** vínculo que cruza unidades (respeta `§16.2`); **B** tutor en varias unidades (cambia `§16.2`, exige ADR); **C** prohibido (caso real sin solución) | **A** |
| `OPEN-FAM-UNIT-04` | Qué puede hacer el tutor principal por sí mismo («gestiona la unidad, añade/elimina tutores, autoriza cambios») y qué escribe un secundario de `lectura_escritura`. El requisito no lo concreta | En `1.14`, nada de autoservicio sobre la composición de la unidad: lo gestiona el centro. Que el principal **proponga** altas que el centro valida, y qué cambios «autoriza», se especifica con `1.22` (portal) o `1.14b` (autorizados), cuando haya un caso concreto. `lectura_escritura` se guarda ya y su efecto lo definen los módulos que tengan algo que escribir (p. ej. `AUT`) |
| `OPEN-FAM-UNIT-06` | Código de la unidad: generado por el sistema o escrito por el centro | Generado (`UF-` + número secuencial por centro), inmutable, y que el importador (`1.24`/`1.25`) pueda aportar el de la plataforma de origen en un campo aparte si hace falta. Alternativa: texto libre único |
| `OPEN-FAM-UNIT-07` | Vocabularios cerrados: tipos de relación (`padre`, `madre`, `tutor_legal`, `abuelo`, `apoderado`, `otro`), categorías restringibles, motivos de revocación | Relación: la lista del requisito más `otro`. Categorías: **solo `salud`** (único ejemplo del requisito); se añaden con la especificación del módulo que las consuma. Revocación: **solo `resolucion_judicial`**; otra retirada es baja del vínculo |
| `OPEN-FAM-UNIT-09` | ¿Se versiona el **texto** del consentimiento de imagen como el de las condiciones (fuente única, C4)? `REQ-FAM-UNIT-004` no lo pide; `REQ-PRIV-002` sí («versión del texto aceptado») | Sí, reutilizando el mismo mecanismo de versiones con un tipo de documento, para que `REQ-PRIV` consuma y no duplique. Y un ADR corto «fuente única de consentimientos de imagen», análogo a `ADR-032` |
| `OPEN-FAM-UNIT-10` | Siembra en roles predefinidos (`permisos.md §4`) | La propuesta de `permisos.md §4`: administrador todo lo no especial; secretaría gestión diaria sin revocación ni condiciones; dirección lectura; `tutor_legal` lectura de su familia y decisión de imagen con `unidad_familiar`. Revocar, solo administrador (decisión con consecuencias legales) |
| `OPEN-FAM-UNIT-11` | Informe «periódico» de consentimientos | Bajo demanda en `1.14` (exportación en cola). Si «periódico» significa envío programado, que lo especifique `COM` (`1.19`), que es quien envía. Requiere ampliar el catálogo de tipos de `data_exports` de `Core` (precedente `1.9b`) |
| `OPEN-FAM-UNIT-13` | Pantallas del tutor (ver su unidad, decidir sobre imagen) en `1.14` o en `1.22` | API en `1.14`, pantallas en `1.22` (`REQ-FAM-PORTAL-004` las nombra). Hasta entonces el centro registra las decisiones en papel (`RN-FAM-UNIT-43`). Excepción: el muro de condiciones sí va en `1.14-c` porque bloquea el acceso |
| `OPEN-FAM-UNIT-14` | `essential` y `depends_on` | `essential: false`, `depends_on: []` (§8). Consecuencia en §9 |
| `OPEN-FAM-UNIT-15` | Partición en `1.14-a`/`-b`/`-c` | Aprobarla (§1.2) |
| `OPEN-FAM-UNIT-16` | ¿Qué pasa con la faceta de tutor cuando su usuario se da de baja en `Core`? | Nada automático en `1.14` (`ADR-048`); la ficha muestra al tutor como «usuario dado de baja» como usuario eliminado (`removed = true` y nombre vacío en `displayNamesByIds`, #446). Si se quiere baja en cascada, consumir `UserDeactivated` en una entrega posterior |
| `OPEN-FAM-UNIT-17` | ¿Una revocación judicial debe bloquear también el acceso del tutor revocado si además es **personal** del centro con ámbito sobre ese alumno? | Cuestión jurídica: no se decide. Mientras tanto, §7.1 expone `isAccessRevoked()` para que el módulo que lo necesite pueda aplicarlo. Recomendación: preguntar a la asesoría del centro piloto |
| `OPEN-FAM-UNIT-18` | Mayoría de edad (18) y edad de consentimiento propio (14, `LOPDGDD` art. 7) | No inventar reglas. Recomendación: avisar en la ficha cuando el alumno cumple 18 y dejar la decisión de retirar vínculos al centro; sobre los 14 años, decidir con la asesoría si el consentimiento de imagen pasa al alumno. Requiere la fecha de nacimiento de `OPEN-FAM-UNIT-03` |
| `OPEN-FAM-UNIT-19` | ¿El consentimiento de imagen se renueva cada curso académico? | El requisito no lo pide y `ADR-034 §4` excluye la unidad familiar del curso. No renovar en `1.14`; si el centro lo pide, una acción masiva «volver a pendiente» con su propia especificación |
| `OPEN-FAM-UNIT-21` | Restaurar una copia anterior a una revocación (o a un `no_autorizo`) devolvería el acceso al tutor revocado (o la publicabilidad de la imagen) (`operacion.md §7`) | Que `REQ-BKP` (`1.26`) trate revocaciones y retiradas de consentimiento como `REQ-BKP-005` trata las supresiones: registro que se reaplica tras toda restauración. Se anota en el plan de `1.26`; no se resuelve en `1.14` |
| `OPEN-FAM-UNIT-20` | Representación de los datos de contacto de los otros tutores ante un tutor con ámbito `unidad_familiar` (casos de violencia de género: un tutor no debe ver el domicilio o teléfono del otro si hay orden de protección) | En `1.14` el tutor ve solo **nombre y tipo de relación** de los demás tutores activos, nunca sus datos de contacto. Lo amplía `1.22` si se pide |

---

## 14. Resumen: qué se difiere y adónde

| Qué | Adónde |
|-----|--------|
| `REQ-FAM-UNIT-005` completo y `REQ-PRL-004` | `1.14b` |
| Alta de la persona del alumno | `1.15` (`ALUM`) |
| Pantallas del tutor | `1.22` (`FAM-PORTAL`) |
| Notificaciones a tutores | `1.19` (`COM`) |
| Bloqueo efectivo de publicación y galerías | Cada publicador (`COM`, `INF`, `WEB`) |
| Efecto de la restricción `salud` | `REQ-SALUD` (fase 2) |
| Registro centralizado de consentimientos | `REQ-PRIV` (fase 2), consumiendo §7.3 |
| Detalle de la restricción de custodia | Tras el ADR de `OPEN-FAM-UNIT-05` |
| Marcado `FUTURO` en el documento | Ninguno |

---

## 14. Huecos detectados en la revisión de documentación (2026-10-26)

No cambian el alcance aprobado; `1.14-a` los cierra al empezar, antes de escribir código, y si alguno exige decidir se pregunta al usuario. **Estado tras `1.14-a`:**

1. **Cerrado salvo `RN-FAM-UNIT-63`.** `RN-FAM-UNIT-35`, `-37` y `-38` (unidad sin principal al revocar el último vínculo, `principal_cleared`) tienen ya su `CA` (`CA-FAM-UNIT-038`, `-039` y `-029`). `RN-FAM-UNIT-63` (sin versión publicada no hay muro) es de `1.14-c`: su `CA` se añade con esa entrega.
2. **Cerrado.** `PATCH /guardians/{id}` no cambia de unidad (decisión del usuario del 2026-10-26): `RN-FAM-UNIT-14` y `CA-FAM-UNIT-009`.
3. **Sigue vigente (informativo).** Los `CA` que exigen la persona de un alumno (`CA-FAM-UNIT-010`, `-012`, `-084` y los de consentimiento) se prueban con fábricas y con `REQ-SEED` (`CLAUDE.md §11`): no hay alta de alumno por API hasta `1.15`.

---

## 15. Decisiones de implementación de `1.14-a`

Donde la especificación aprobada deja una elección de detalle, `1.14-a` fija lo siguiente. Ninguna cambia el alcance; todas son revisables por el usuario.

| # | Decisión | Motivo |
|---|----------|--------|
| D-1 | `POST /family-units`: `code` es **opcional**. Sin él, el sistema genera `UF-` + número de seis cifras, secuencial por centro (máximo existente, vivas y borradas, + 1, bajo bloqueo consultivo del centro). Con él, se valida (1 a 40 caracteres, sin caracteres de control) y es único entre las vivas. `PATCH` admite cambiar `code` | `OPEN-FAM-UNIT-06` (generado) y `api.md §2.1`/`CA-FAM-UNIT-002` (el cliente escribe el código, `PATCH` lo cambia) se contradicen si se leen como excluyentes; esta lectura cumple las dos |
| D-2 | `GET /guardian-links?status=revocado` sin `revocacion_tutor.leer` ⇒ `403 forbidden`; sin el filtro, un sujeto con ámbito `todos` ve los vínculos revocados con `status = revocado` y **sin** el campo `revocation` (literal de `api.md §1.4`). `include_deleted` sin `familia.eliminar` ⇒ `403` | `api.md §3` dice «exige» el permiso sin fijar el código de error |
| D-3 | `isAccessRevoked()` **no** depende de que el módulo esté contratado; los conjuntos de `FamilyMembership` y `GuardianRecipients` sí (vacíos si no lo está, `§9`) | `§9` solo nombra los conjuntos. Una revocación judicial no debe «deshacerse» al descontratar el módulo |
| D-4 | La ampliación de `Core\Domain` (`OPEN-FAM-UNIT-03`) se entrega como `PersonDirectory` (sin fecha de nacimiento: la única regla que la usaría, `OPEN-FAM-UNIT-18`, no existe todavía y `ADR-048` pide no construir sin consumidor) | `ADR-048` |
| D-5 | `PATCH /guardians/{id}` no tiene ningún campo editable en `1.14-a`: sin `family_unit`, responde `200` con el tutor sin cambios | `RN-FAM-UNIT-14` |
| D-6 | `ImageConsentPolicy` y los permisos de `consentimiento_imagen`, `condiciones_uso` y `aceptacion_condiciones` **no** se declaran en `1.14-a`; entran con `1.14-b` y `1.14-c` | `§1.2`: un permiso sin *endpoint* invita a concederlo creyendo que hace algo |
| D-7 | Códigos de error añadidos a los de `api.md §7` por necesidad de las reglas aprobadas: `fam_unit.guardian.user_not_eligible` (`RN-FAM-UNIT-10`: usuario `inactivo`), `fam_unit.link.emergency_contact_revoked` (`RN-FAM-UNIT-35`), `fam_unit.guardian.not_editable` (`RN-FAM-UNIT-14`) y `fam_unit.validation.{principal_guardian_not_found, relationship_invalid, access_level_invalid, restricted_category_invalid, reason_invalid, required, boolean_invalid, reference_invalid, filter_forbidden}` para los vocabularios cerrados (`RN-FAM-UNIT-32`), los `public_id` mal formados y los campos obligatorios | La especificación exige un `422` en esos casos sin nombrar el código |
| D-8 | Mapa cerrado de `AR-10` (`permisos.md §3.1`): `familia` es un recurso de **tres** modelos (`FamilyUnit`, `Guardian`, `FamilyUnitStudent`) con tres servicios sancionados (`FamilyUnitService`, `GuardianService`, `FamilyUnitStudentService`) y `vinculo_tutor` de uno (`GuardianLinkService`). El test pasa de `model` a `models` por recurso. Los vínculos se leen desde `familia` por la relación `Guardian::links()`, nunca por consulta estática | Los tres modelos se piden entre sí dentro de las mismas reglas de dependencia; un único servicio por modelo habría exigido dependencias circulares |
| D-9 | **Frontend: nada en `1.14-a`.** Las pantallas de personal de §10 (`/familias`, `/familias/:id`) no están asignadas por §1.2 a ninguna de las tres entregas ni a una cuarta; tampoco el `CA-FAM-UNIT-084` (E2E). No se ha construido nada hasta que el usuario las sitúe | «No inventes pantallas»; `INV-006`: la API está completa y documentada |
| D-10 | `isAccessRevoked()` cuenta solo los vínculos **vivos** `revocado`. Dar de baja (lógica) un vínculo revocado, o al tutor que lo tenía (`RN-FAM-UNIT-12`), hace que deje de constar. **Riesgo para `1.14b`** (`ADR-032`): la exclusión del tutor revocado de la lista de autorizados depende de que el vínculo revocado se conserve; decidir antes de `1.14b` si basta con la nota de `operacion.md §6` o hay que bloquear esas bajas | La especificación dice «existe un vínculo revocado» sin cualificar; se ha leído «vivo» por coherencia con el resto de interfaces |
| D-11 | `FamilyUnit` añade `deleted_at` a su representación **solo** cuando la unidad está dada de baja (listado con `include_deleted`); sin él, ese listado no distinguiría las bajas | `api.md §1.1` no lo prevé |
| D-12 | Índices de `datos.md §3` **no creados** por redundantes: `guardians (tenant_id, family_unit_id)`, `family_unit_students (tenant_id, family_unit_id)` y `guardian_student_links (tenant_id, student_person_id)`, parciales sobre las vivas, ya cubiertos por el índice completo `(tenant_id, <columna>)` que `tenantForeignId()` crea en cada clave foránea. Sí están el caliente de vínculos activos por tutor y el de prefijo de código | Un índice sin consulta que lo necesite es deuda (`datos.md §3`) |
| D-13 | `student_person` en los filtros y cuerpos es el `public_id` de la **persona**; `family_unit_students` no comprueba que la persona tenga o vaya a tener faceta `student` (no existe hasta `1.15`) | `OPEN-FAM-UNIT-01` |
