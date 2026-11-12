# Ola 1 de arquitectura y especificación (2026-11-12 a 2026-11-16)

> **Guion de ejecución para la sesión orquestadora.** Es un documento de proceso, no un requisito. Lo escribió la sesión del 2026-11-12 para que una sesión nueva lo ejecute con el contexto limpio. Fuente de las decisiones del usuario: la conversación del 2026-11-12 (resumida abajo).

## 1. Qué es y por qué ahora

El usuario dispone de un **reset gratuito del límite semanal** de Anthropic y de la **promoción de Fable 5.2 hasta el lunes 2026-11-16 a las 02:00** (hora del usuario). Quiere aprovecharlos con las tareas que más rinden en un modelo caro: **arquitectura y especificación, sin escribir código**. Las decisiones caras de revertir (modelo de datos, ADR transversales) y las especificaciones que se pueden adelantar son el mejor uso.

**Decisiones del usuario (2026-11-12):**

1. Alcance: **«todo lo posible», ~14 agentes** en paralelo.
2. Modelo: **Fable 5.2** (`model: "fable"` en cada llamada a `Agent`) en `architect`, `spec-writer` y en los revisores críticos. Es una **excepción temporal** al ahorro de cuota de `CLAUDE.md §2`: se anota en `memory.md` y **no** se edita la skill `asignacion-de-modelos` hasta que el usuario decida si la excepción queda.
3. La ola se orquesta desde una **sesión nueva** con este guion.
4. «No te preocupes por mí, iré contestando, **asegúrate de siempre darme una opción recomendada**»: toda pregunta lleva una opción **primera y marcada «(Recomendada)»** con su motivo (memoria `feedback_siempre_opcion_recomendada`).

**Frase de arranque** (la dice el usuario): «Lanza la ola 1 del guion `docs/proceso/ola1-arquitectura-2026-11.md`».

## 2. Restricciones que no se negocian

- **Ningún agente escribe código** de aplicación, migraciones, tests ni configuración de despliegue. Solo documentación bajo `docs/`.
- **`CLAUDE.md §11`**: no inventar requisitos. Cada requisito sale de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (cita sección) o se marca **propuesta** y se lista como `OPEN-*`. Nada marcado `FUTURO` se especifica. No se resuelve por cuenta propia una decisión abierta: se formula como `OPEN-*` con **recomendación**.
- **Los ADR salen en estado `PROPUESTA`**, nunca `ACEPTADA`: los acepta el usuario. Un ADR nuevo no cambia uno vigente sin sustituirlo explícitamente.
- **Datos personales y de menores** (`ADR-030`, `REQ-SEED-005`): ningún dato real, ni NIF ni domicilio del usuario (`CLAUDE.md §11`).
- **Un subagente no lanza subagentes** (`CLAUDE.md §2`): quien encadena es la sesión orquestadora.
- **No se tocan los ficheros compartidos** desde las ramas de los agentes: `PLAN-IMPLEMENTACION.md`, `memory.md`, `CHANGELOG.md`, `README.md`, `ARCHITECTURE.md`, `SYSADMIN.md`, `RUNBOOK.md`, `CLAUDE.md`, `.claude/**` e índice de ADR de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`. Cada agente **propone** esos cambios en una sección «Cambios propuestos en ficheros compartidos» de su propio documento; los aplica la sesión orquestadora al integrar.
- **Concurrencia máxima: 7 agentes simultáneos.** Más arriesga agotar la ventana de 5 horas con agentes a medias. Si aparece el aviso de cuota («usage limit approaching»), se aplica el cierre de `CLAUDE.md §3` (skill `cierre-de-sesion`) y se pide al usuario la hora de reset.
- `spec-writer` **no tiene `Bash`**: no puede hacer `git commit`. Escribe los ficheros en su *worktree*; **commitea la sesión orquestadora** con `git -C <worktree>` (o `janitor`). `architect` sí tiene `Bash`.

## 3. Arranque de la sesión nueva

1. Protocolo de `CLAUDE.md §3`: leer `memory.md`, `PLAN-IMPLEMENTACION.md`, este guion; comprobar rama y estado; `git fetch`. Resumir en 5 líneas. **La frase de arranque del usuario sustituye a la confirmación previa.**
2. Cargar la skill `asignacion-de-modelos` (la excepción de Fable se anota en el informe, no se edita).
3. Comprobar que `docs/adr/` termina en `ADR-076` y que los números reservados (`§4`) están libres.
4. **No hay rama de integración única**: cada entrega va en **su propia rama** (tabla de `§5`, convenciones de `CLAUDE.md §4`) y en **su propio PR** hacia `develop`. Los cambios de ficheros compartidos van en una rama aparte (`§8`).
5. Lanzar los lotes en el orden de `§6`.

## 4. Números de ADR reservados

| ADR | Tema | Agente |
|---|---|---|
| `ADR-077` | Purga física de tenant | A1 |
| `ADR-078` | Motor de renderizado PDF | A2 |
| `ADR-079` | Notificaciones y comunicaciones salientes | A3 |
| `ADR-080` | Staging, rama `staging` y runtime de contenedores agnóstico | A4 |
| `ADR-081` | Pasarela de pago y SEPA | A5 |
| `ADR-082` | Firma digital y apoderado o firmante | A6 |
| `ADR-083` | Proveedor de almacenamiento S3 en producción (con el expediente de cumplimiento) | A7 |

Si un agente necesita un ADR adicional, **no toma número**: lo propone en su informe y la orquestadora le asigna el siguiente libre (`ADR-084` en adelante).

## 5. Reparto de agentes

Todos con `model: "fable"`, `isolation: "worktree"` y *worktree* propio (el `Agent` lo crea). Tipos: `architect` (A) y `spec-writer` (B). **Ramas organizadas como siempre** (`CLAUDE.md §4`: `feature/`, `fix/`, `chore/`, colgadas de `develop`), una por entrega; la lista está en la tabla «Ramas y commits» de más abajo y se le indica a cada agente en su prompt (la rama se crea al empezar, antes de escribir).

### Lote 1: decisiones transversales (7 × `architect`)

| Id | Entrega | Fuentes mínimas que debe leer | Lo que debe decidir o proponer |
|---|---|---|---|
| A1 | `docs/adr/ADR-077-purga-fisica-de-tenant.md` | issue #371; `ADR-033`, `ADR-034`, `ADR-036`, `ADR-048`, `ADR-076` (prefijo de centro en S3); REQUISITOS `REQ-PRIV` §5.45; RGPD art. 5.1.e y 17 | Qué se purga y en qué orden (PostgreSQL con RLS y particiones, objetos S3 por prefijo, Redis por prefijo de tenant, colas y trabajos, ficheros, auditoría), periodo de gracia y reversibilidad, qué sobrevive (auditoría de plataforma, copias de seguridad y su caducidad), verificación de borrado, rol de plataforma y doble confirmación, plazos legales, test de regresión necesario, encaje con `REQ-BKP`. Obligatorio antes de datos reales y de `1.24` |
| A2 | `docs/adr/ADR-078-motor-de-renderizado-pdf.md` | `CLAUDE.md §1` (servicio contenerizado HTML→PDF); `PLAN` `1.17`/`1.17b`; `OPEN-ACAD-38`; `OPEN-ALUM-15`; `REQ-CORE/funcional.md` ~l.264; `ADR-076`; `ADR-082` (borrador) | Motor y empaquetado (opciones reales: Chromium sin cabeza tipo Gotenberg, WeasyPrint, Typst, otros), **verificado con fuentes actuales**: mantenimiento activo, licencia y frecuencia de releases (`CLAUDE.md §1`); interfaz propia (`RNF-MANT-007`); volumen (boletines de 1.200 alumnos), cola (`INV-012`), seguridad (SSRF, recursos externos), accesibilidad del PDF y archivado (PDF/A), cuatro idiomas, tipografías, firma (PAdES) |
| A3 | `docs/adr/ADR-079-notificaciones-y-comunicaciones.md` | REQUISITOS `REQ-COM` §5.12 y los módulos que envían avisos; `OPEN-09`; `ADR-068` y `PRL` (avisos de `1.14b-d3`/`d4`); `AUTH/operacion.md` (sin SMS, l.440); `colas-y-workers` | Correo transaccional (proveedor UE, DKIM/SPF/DMARC; cierra o reformula `OPEN-09`), SMS, *push*/Web Push, aviso en la aplicación; plantillas por idioma; preferencias y base legal/consentimiento (RGPD, LSSI); urgencia y prioridad (recogida de menores), reintentos, trazabilidad de entrega, límites, datos personales en el cuerpo del mensaje; coste |
| A4 | `docs/adr/ADR-080-staging-rama-y-runtime-de-contenedores.md` | memoria del usuario `project_staging_barato_y_rama_staging.md` y `project_runtime_contenedores_agnostico_pendiente.md` (en `~/.claude/projects/-home-amatias-proyectos-plataforma-educativa/memory/`); `PLAN` `0.10e`; `ADR-028`, `ADR-030`, `ADR-076 §2.3`; skill `contenedores-y-red`; `OPEN-11` | Entorno de staging barato (qué prueba que desarrollo no: TLS, versionado y cifrado del S3 real, Traefik real, Quadlet), rama `staging` y flujo de promoción, nivel 1 de runtime agnóstico (`CONTAINER_RUNTIME`; Quadlet como referencia), criterios de salida, coste mensual, qué necesita decidir el usuario (VPS) |
| A5 | `docs/adr/ADR-081-pasarela-de-pago-y-sepa.md` | REQUISITOS `FIN`, `BEC`, `TRAN`, matrícula de `ALUM` (líneas ~743, 890, 891, 1436, 1557, 1992); `ADR-029` (importes en céntimos); `ADR-031` | Pasarela y proveedores (UE), remesas SEPA (mandatos, `pain.008`, devoluciones), PSD2/SCA, alcance PCI-DSS (SAQ-A), idempotencia, conciliación, impagos, datos de tarjeta nunca en la plataforma; qué requiere decisión de negocio (comisiones, proveedor) como `OPEN-*` |
| A6 | `docs/adr/ADR-082-firma-digital-y-apoderado.md` | REQUISITOS `REQ-DOC-002`, `SEC`, `AUT`; issue #82; `ADR-068` y vínculos de `REQ-FAM-UNIT`; `ADR-078` (borrador); eIDAS | Nivel de firma (simple/avanzada/cualificada) por caso de uso, proveedor o firma propia, Cl@ve, sellado de tiempo, evidencias y custodia, PDF firmado (PAdES), tutores legales y menores, firmante/apoderado (#82), validez y caducidad, auditoría |
| A7 | `docs/cumplimiento/` (matriz, README con índice) **y** `docs/adr/ADR-083-proveedor-s3-en-produccion.md` | `ADR-076 §10`; `ADR-076 §2.3`; `memory.md` (decisión del usuario sobre el expediente); RGPD, LOPDGDD, LOE DA 23.ª, ENS, NIS2, Data Act, ISO 27001/9001 del proveedor, guías de la AEPD | Matriz de normas con **fuente primaria verificada y fecha**, qué exige a la plataforma frente al proveedor, evidencias a archivar (**fuera de Git**; solo su índice, repositorio público), marcas **«pendiente de validación jurídica»**; **nunca declara «cumple»**. Define y numera los `OPEN-STO-01..12` que `ADR-076` cita y no existen. `ADR-083`: proveedor de producción (la recomendación previa de `architect` fue OVHcloud 3-AZ o Scaleway, **sin verificar**: contrastar certificaciones, precios y DPA con fuentes primarias) |

### Lote 2: especificaciones (7 × `spec-writer`)

Siguen `docs/modulos/_PLANTILLA/`, cada entrega dimensionada para **≤3 tramos de `implementer`** (`CLAUDE.md §2.7`; partir con sufijo de letra si no cabe), con clasificación **crítico/ordinario** (la duda es crítico), ámbito de permisos, auditoría (`INV-003`), datos de menores (`INV-008`), cuatro idiomas (`INV-009`), criterios de aceptación con ID (`INV-015`), y `OPEN-*` con recomendación. Referencia de tamaño: `REQ-ALUM` (527 líneas) y `REQ-CURSO` (946).

| Id | Paso | Entrega (decide y justifica la ubicación siguiendo cómo se documentó `1.11`/`1.12b`) | Fuentes mínimas | Notas |
|---|---|---|---|---|
| B1 | `S-2` | `docs/modulos/REQ-CORE/almacen-objetos.md` (nuevo) | `ADR-076 §3.2-§3.3`, #612, `PrivateObjectStore`, `TenantStorage` | `TenantObjectStore` en `App\Support\Files`, migración de las **13 llamadas directas** restantes a `Storage` (`git grep -n "Storage::disk(config" -- apps/api/app` da la lista exacta), test de arquitectura que prohíbe `Storage` fuera de ese espacio (`INV-001`), purga con comprobación de prefijo (#612). **Crítico** |
| B2 | `1.13` | asistencia, `REQ-ACAD-003` | REQUISITOS; `ADR-063 §5.4`; `REQ-ACAD funcional §18.4.2` y `§18.7`; `groupIdsFor` ya construida; `PRL` (alumno no recogido, `1.14b-d4`) | Operación más frecuente del sistema: **móvil y sin conexión** (es una decisión de arquitectura: sincronización, conflictos, caducidad de lo cacheado; propónla como sección o ADR aparte con número solicitado a la orquestadora). Confirma la asignación docente por curso académico (`ADR-063 §5.4`; el plan citaba `ADR-048`, que no trata de docencia) |
| B3 | `1.15a` | capa visual, módulo de interfaz | `ADR-064` (§6 abierto), `docs/design-system.md §22` (`RN-DS-25` a `-29`), #431, `RUX-BRAND-005` | Pantalla de acceso «Panel», tokens, radio de 2 px; texto de bienvenida personalizable; qué toca lo que `RN-DS-02` exige como ADR propio. Va antes de `1.15b` |
| B4 | `1.15b` | `docs/modulos/REQ-SEED/` | REQUISITOS `REQ-SEED` §5.53, `ADR-030`, convención `REQ-SEED-005`, `seed/README.md`, `apps/api/tests/Support/e2e-real-tenant.php` | Tres centros ficticios (300-1.200 alumnos), plantilla completa, semilla reproducible, **bloqueo en producción sin excepción**, ampliable por bloque; medir `STATE_SQL` con miles de alumnos |
| B5 | `1.16` y `1.17` | `docs/modulos/REQ-CALIF/` | REQUISITOS `REQ-CALIF` §5.5; `ADR-063 §5.4`; `OPEN-ACAD-18/19` (escalas); `REQ-CURSO-003`; `ADR-078` (borrador de A2) | Calificaciones y boletines; los boletines dependen del motor de PDF: marca la dependencia y no la decidas |
| B6 | `1.18` | `docs/modulos/REQ-INF/` (0-3) | REQUISITOS §5.50 (75 líneas); `ACAD`, `CALIF`, `ALUM`, `FIN` | Infantil primer ciclo: partes diarios, informes; **datos de salud = categoría especial** (tablas separadas, cifradas, auditoría de lectura) |
| B7 | `1.19` | `docs/modulos/REQ-COM/` | REQUISITOS §5.12; `ADR-079` (borrador de A3); avisos de `1.14b-d3`/`d4` | Mensajería, circulares, confirmación de lectura, consentimiento, permisos por rol |

### Ramas y commits

| Id | Rama | Prefijo del commit |
|---|---|---|
| A1 | `chore/adr-077-purga-fisica-de-tenant` | `docs(adr): … [ADR-077]` |
| A2 | `chore/adr-078-motor-de-renderizado-pdf` | `docs(adr): … [ADR-078]` |
| A3 | `chore/adr-079-notificaciones-y-comunicaciones` | `docs(adr): … [ADR-079]` |
| A4 | `chore/adr-080-staging-y-runtime-de-contenedores` | `docs(adr): … [ADR-080]` |
| A5 | `chore/adr-081-pasarela-de-pago-y-sepa` | `docs(adr): … [ADR-081]` |
| A6 | `chore/adr-082-firma-digital-y-apoderado` | `docs(adr): … [ADR-082]` |
| A7 | `chore/adr-083-cumplimiento-y-proveedor-s3` | `docs(cumplimiento): … [ADR-083]` |
| B1 | `feature/REQ-CORE-almacen-de-objetos-spec` | `docs(core): … [ADR-076]` |
| B2 | `feature/REQ-ACAD-003-asistencia-spec` | `docs(acad): … [REQ-ACAD-003]` |
| B3 | `feature/ADR-064-capa-visual-spec` | `docs(ui): … [ADR-064]` |
| B4 | `feature/REQ-SEED-001-generador-de-datos-spec` | `docs(seed): … [REQ-SEED-001]` |
| B5 | `feature/REQ-CALIF-calificaciones-y-boletines-spec` | `docs(calif): … [REQ-CALIF-001]` |
| B6 | `feature/REQ-INF-0-3-spec` | `docs(inf): … [REQ-INF-001]` |
| B7 | `feature/REQ-COM-comunicaciones-spec` | `docs(com): … [REQ-COM-001]` |
| Lote 0 | `chore/doc-incoherencias-2026-11` | `docs: … [#<issue>]` (abrir antes un issue Bajo con las seis incoherencias, `CLAUDE.md §5`) |
| Compartidos (`§8`) | `chore/ola1-ficheros-compartidos` | `docs(proceso): … [ADR-077]` y siguientes |

Si un ID de requisito (`REQ-XXX-NNN`) no existe en `REQUISITOS`, el commit cita el ADR o el issue (`CLAUDE.md §4`: todo commit referencia al menos un ID). Los revisores del lote 3 no tienen rama: sus correcciones se aplican **en la rama de la entrega** reanudando al agente original. Si una entrega necesita partirse, se abren más ramas con el mismo prefijo y un sufijo de letra (`-a`, `-b`).

### Lote 3: revisión crítica adversarial (4 × `architect`, `model: "fable"`), cuando acaben los lotes 1 y 2

Solo lectura. Cada uno revisa un grupo y devuelve hallazgos con severidad y corrección: contradicciones con ADR vigentes e invariantes (`INV-001` a `INV-015`), requisitos inventados (`CLAUDE.md §11`), faltas de permisos, auditoría, aislamiento de tenant o datos de menores, viabilidad real (¿cabe en ≤3 tramos?), fuentes no verificadas, opciones descartadas sin motivo.

| Id | Revisa |
|---|---|
| C1 | `ADR-077`, `ADR-082`, `ADR-083` y `docs/cumplimiento/` (legal y seguridad) |
| C2 | `ADR-078`, `ADR-079`, `ADR-080`, `ADR-081` (técnicos) |
| C3 | B1, B2, B3, B4 |
| C4 | B5, B6, B7 |

### Lote 0 (barato, `janitor`, Haiku, puede ir en cualquier momento): incoherencias detectadas

1. `PLAN-IMPLEMENTACION.md` marca `1.11` `[ ]` y `1.14` `[~]`; `memory.md` dice que `1.14` está completa y `1.11-b` mezclada. Contrastar y corregir (la sesión revisa antes de aplicar).
2. `REQ-ACAD/funcional.md` l.1351 dice `TeachingMembership` «especificada, no construida»; l.1167 y `memory.md` dicen que `groupIdsFor` está construida.
3. `OPEN-08` colisiona: `ADR-066` l.179 lo usa como cuestión jurídica; `REQUISITOS` l.3297 lo define como dominio y DNS.
4. `ADR-076 §10` cita `OPEN-STO-01..12`, que no existen (los define A7).
5. `REQ-PERM/README.md` dice «pendiente de merge»; su `funcional.md` dice implementada.
6. El plan asigna la asignación docente a `ADR-048`; ese ADR trata del aprovisionamiento de tenants (`ADR-063 §5.4` es el correcto).

## 6. Orden y concurrencia

1. **Ahora**: lanzar **A1 a A7** (lote 1) en paralelo (7 agentes) y, en la misma tanda, el **lote 0** (`janitor`, barato).
2. Cuando **al menos 4 de los 7** del lote 1 hayan informado, lanzar **B1 a B7** (lote 2). B5 y B7 pueden empezar sin esperar a A2 y A3: leen sus borradores si ya existen y, si no, marcan la dependencia.
3. Cuando los lotes 1 y 2 hayan informado, lanzar **C1 a C4** (lote 3). Aplicar correcciones en **una sola reanudación** por entrega (`SendMessage` al agente original, no un agente nuevo: `CLAUDE.md §2`, lección 5).
4. `doc-precheck` (Haiku) sobre **cada rama** antes de abrir su PR.

Prompt de cada agente: la **orquestadora lo compone** con (a) los datos de su fila, (b) las restricciones de `§2` y (c) las reglas comunes de `§7`. Se pega tal cual, sin la especificación entera del proyecto.

## 7. Reglas comunes que reciben todos los agentes

- Eres un agente de **arquitectura/especificación**: **no escribes código**; solo documentos bajo `docs/` en **tu** *worktree* y rama.
- **Crea el esqueleto del documento al principio y guarda cada sección según la completas**: si la sesión se corta por cuota, lo hecho sobrevive. Commitea de forma incremental si tienes `Bash`; si no, deja los ficheros guardados y dilo en el informe.
- Lee antes lo que dice tu fila y `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` en lo que te toque. No cargues documentos enteros que no necesitas (`offset`/`limit` tras localizar con `grep`).
- **Decisiones técnicas con fuentes actuales** (`WebSearch`, documentación oficial): anota fuente y fecha de consulta; lo no verificado se marca **«sin verificar»**. Nunca fijes versiones, precios o certificaciones de memoria.
- Ofrece **al menos 3 opciones reales** en cada decisión, con ventajas, riesgos, coste de revertir y **una recomendación razonada**. Descarta con motivo.
- **Preguntas abiertas**: `OPEN-<TEMA>-NN` con **recomendación y su impacto**; separa las que **bloquean la implementación** de las que no, y marca quién decide (usuario, negocio, legal, técnico).
- Cumple las invariantes de `CLAUDE.md §7` y la seguridad de `§8`; idiomas es-ES, en, de, fr; textos legales y de consentimiento **no se traducen automáticamente**.
- **Tu informe final: máximo 15 líneas**: ruta(s) y tamaño de lo escrito, nº de `OPEN-*` (cuántas bloquean), **las 3 decisiones que más necesitas del usuario con tu opción recomendada**, riesgos y lo que no pudiste comprobar. El detalle va en el fichero, no en el informe.
- No toques los ficheros compartidos de `§2`: propón sus cambios en una sección de tu documento.

## 8. Integración (al terminar los lotes 1 a 3)

1. Por cada rama de la tabla «Ramas y commits» con trabajo: commit (si el agente no pudo, `git -C <worktree>`), `push` y **PR propio** hacia `develop` (skills `pr-check` y `espera-ci`; son solo documentación, pero el CI corre completo: las esperas de varios PR se pueden lanzar a la vez en segundo plano). No hay solapes de ficheros por diseño; si los hay, para y resuélvelo con el usuario. Tras cada merge, borrar la rama remota y local y el *worktree* (`git worktree remove`).
2. Aplicar los «Cambios propuestos en ficheros compartidos» en **una rama aparte** (`chore/ola1-ficheros-compartidos`, un PR) cuando estén mezcladas las entregas: índice de ADR en `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`§18`), `PLAN-IMPLEMENTACION.md` (nuevos pasos, pasos que cambian de etiqueta o de orden), `README.md` (tabla de versiones), `CHANGELOG.md` (una entrada para la ola), `memory.md` (nota viva y la excepción de Fable).
3. **Consolidar las decisiones para el usuario** en una tabla («Decisión | Opciones | **Recomendada** | Impacto | Bloquea»), ordenadas por lo que desbloquean. Preguntarlas con `AskUserQuestion` en **lotes de hasta 4, recomendada primera y marcada**. Lo que el usuario decida se refleja en los ADR (`PROPUESTA` → `ACEPTADA`) y en las especificaciones (`OPEN-*` → decididas).
4. `doc-precheck` y, si hay Críticos o Altos corregidos, `doc-reviewer`; el PR de ficheros compartidos y `espera-ci`.
5. Métricas en `docs/metricas-agentes.md` (una fila por agente) y recálculo por tipo de agente; el modelo `fable` va en su propia fila de notas.

## 9. Ola 2 (no incluida en esta tanda)

Depende de lo que el usuario decida en la ola 1 y puede lanzarse **antes del lunes 02:00** si responde deprisa (la promoción de Fable caduca):

- `1.14b-d3` y `-d4` (tras `1.13` y `1.19`), `1.17b` (promoción y paquete de cierre; firma `ADR-082`, motor `ADR-078`, `REQ-FIN`).
- `1.20` AGENDA, `1.21` PROF, `1.22` FAM-PORTAL, `1.23` EST; `1.24` y `1.25` ONB (importador y perfil GQdalya, sin los ficheros de `0.11`); `1.26` BKP (`0.10d`, `OPEN-10`); `REQ-PRIV` (tras `ADR-077`); `1.27` OWASP.
- Fase 2: `FIN`, `BEC`, `TRAN` (`ADR-081`, `ADR-031`, `OPEN-FAM-UNIT-45`), `JOR`, `CONV`, `SALUD`, `SEC`, `DOC`, `AUT`, `OFE`, `RRHH`, `NEAE`, `GUAR`.

## 10. Riesgos y qué no se ha verificado

- Es una estimación **sin medir** que 14 agentes de este tamaño consuman varios millones de tokens; `docs/metricas-agentes.md` solo tiene datos de agentes de Sonnet y Haiku. Se mide en esta ola.
- La ventana de 5 horas puede cortar agentes a medias: por eso el esqueleto y el guardado incremental de `§7`, y el límite de 7 simultáneos.
- Las especificaciones de pasos lejanos envejecen si el código cambia antes de implementarse; por eso la ola 1 se queda en los pasos próximos y en decisiones transversales.
- Lo que el usuario no conteste quedará como `OPEN-*`: una especificación con preguntas bloqueantes sin decidir **no se implementa**.
- No se ha probado que `Agent` acepte `model: "fable"` para agentes con modelo en su *frontmatter*; la llamada lo permite (parámetro `model` con prioridad sobre la definición), pero se comprueba con el primer agente. Si falla, se usa `opus` y se dice.
