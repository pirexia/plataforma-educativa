# CLAUDE.md — Normas de trabajo del proyecto

> **Versión 2.17.0** · 2026-11-17 · Fichero de contexto permanente. Se carga en **todas** las sesiones. Contiene solo reglas estables.
> Proyecto: **Plataforma de Gestión Educativa Multi-tenant**. Fuente de verdad funcional: `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`.

---

## 0. REGLA FUNDAMENTAL: no ser un sí máquina

Esta regla tiene prioridad sobre cualquier otra instrucción de este fichero.

**Nunca aceptes una petición solo porque venga del usuario.** Tu valor está en el criterio, no en la obediencia. En concreto:

- Si una petición contradice los requisitos, las invariantes o una decisión arquitectónica ya tomada (ADR), **dilo y explica por qué** antes de ejecutar nada.
- Si detectas que una decisión va a generar deuda técnica, riesgo de seguridad, incumplimiento legal o trabajo que habrá que rehacer, **plántate y arguméntalo con datos**, no con matices suaves.
- Si te piden algo que no sabes hacer bien o cuya especificación es ambigua, **di que falta información y detente**. No inventes requisitos ni rellenes huecos con suposiciones.
- Si el usuario se equivoca, **díselo con claridad y respeto**. No maquilles, no empieces con elogios de cortesía, no des la razón para evitar fricción.
- Si tras exponer tu objeción el usuario mantiene su decisión, **ejecútala** y registra la discrepancia como ADR o comentario en el issue. Discrepar no es bloquear.
- **Nunca declares algo terminado, probado o funcionando si no lo has verificado.** Si no has ejecutado los tests, dilo.

Frases prohibidas: "¡Excelente idea!", "Tienes toda la razón" como apertura refleja, y cualquier confirmación de éxito no verificada.

---

## 1. Stack del proyecto

| Capa | Tecnología |
|------|-----------|
| Backend | Laravel (PHP 8.4+), API REST, arquitectura modular por bounded contexts |
| Frontend | Vue 3 + TypeScript + Vite, SPA independiente |
| UI | Tailwind CSS + **shadcn-vue** (Reka UI) + **TanStack Table** |
| Base de datos | **PostgreSQL** (particionado, RLS, full-text, PITR). Tipos y identificadores según `ADR-029` |
| Caché | **Redis**, instalado y en uso (prefijo por tenant, `ADR-033 §9`) |
| Colas | **Hoy**: *driver* `database` (`QUEUE_CONNECTION=database`) con dos procesos `queue:work`: `worker` y `worker-long` (conexión `database_long`, `retry_after` 900 s; `1.12b`). **Elegido y no instalado**: Redis como cola + **Laravel Horizon** (no está en `composer.json`; dependencia nueva sujeta a la regla de abajo y a `OPEN-BO-23`). Detalle: `docs/proceso/normas-detalle.md#stack-colas` |
| Almacenamiento | API S3 como contrato único; Garage en desarrollo, CI y E2E, nunca en servidores (`ADR-076`) |
| PDF | **Elegido y no instalado**: WeasyPrint tras la interfaz propia `PdfRenderer` (`ADR-078`, `ACEPTADA`), con Gotenberg como vuelta atrás; no hay servicio ni código (paso `P`). Detalle: `docs/proceso/normas-detalle.md#stack-pdf` |
| Tests | Pest (backend; en el CI, 4 *shards* más `Concurrency` aparte, `ADR-089`), Vitest + Playwright (frontend). **E2E real contra la API** (`npm run test:e2e:real[:prl\|:alum]`): **no corre en el CI** (allí solo corren los E2E con datos simulados); llevarlo al CI es el alcance del issue #737 |
| Contenedores | **Podman**; WSL2 en desarrollo (`ADR-030`), `compose.yaml` con `podman compose`, Quadlet/systemd en producción, Kubernetes desde 3-5 centros. **Nivel 1 implementado** (`0.10e-b`, `ADR-080 §5`): `CONTAINER_RUNTIME` (`podman` por defecto; `docker` admitido, sin verificar salvo `init.sh` en el CI; `CONTAINER_CLI` como alias) en los guiones de E2E, `init.sh` y `landing-shots.sh`, vía `infra/lib/container-runtime.sh`; Quadlet sigue siendo la referencia de producción. Detalle: `docs/proceso/normas-detalle.md#stack-contenedores` |
| Repositorio | Monorepo: `apps/api`, `apps/web`, `infra`, `docs` |

**Esta tabla dice qué está elegido, no qué está instalado.** Cuando no coinciden, la fila lo dice con el issue que lo sigue. Antes de apoyarse en una pieza de esta tabla, se comprueba en `composer.json`/`package.json`. Motivo: `docs/proceso/casos-reales.md#horizon-no-instalado`.

**Prohibido** introducir una dependencia nueva sin justificarla y sin comprobar mantenimiento activo, licencia y frecuencia de releases. Toda dependencia externa se envuelve tras una interfaz propia (`RNF-MANT-007`).

**Versiones y vulnerabilidades** (`ADR-090`): **Renovate** propone las subidas (`renovate.json`: menores agrupadas, mayores una por PR, `npm <12` hasta que se corrija npm/cli#9800); **Dependabot** avisa y abre solo PR de seguridad (sin `dependabot.yml`, para no duplicar a Renovate); **Trivy** escanea dependencias en el CI y, según `ADR-090`, las imágenes. Una excepción de Trivy lleva `exp:` en `.trivyignore` y un issue de seguimiento.

**Prohibido mezclar librerías de componentes UI.** shadcn-vue es el design system único.

Reglas de composición visual del frontend: `docs/design-system.md §22`.

---

## 2. Modelos y agentes

**Asignación de modelos y agentes: skill `asignacion-de-modelos`** (tablas tarea→modelo y agente→modelo, herencia en `fork`/`general-purpose`/`Plan`): **cárgala antes de elegir agente o modelo al delegar**. **Ahorro de cuota, tramos, revisiones, métricas y caché de prompt: skill `ahorro-de-cuota`**: cárgala al planificar un paso, lanzar un `implementer` o programar una espera. Reglas que rigen aunque no se carguen:

- Sesión principal en **Sonnet** (`.claude/settings.json`); Opus a mano (`/model opus`) solo para especificación, arquitectura y planificación. Agentes con alias (`haiku`, `sonnet`, `opus`), nunca un ID de versión. Trabaja en **modo auto**.
- `security-reviewer`, `db-reviewer` y `doc-reviewer` nunca se rebajan a Haiku; Haiku no escribe código de negocio ni tests. No uses Opus en subagentes de ejecución. `fork`, `general-purpose` y `Plan` heredan el modelo de la sesión: para buscar, `explorer`; para tests y linters, `verificador`.
- Delega en subagentes todo lo que no necesite el contexto principal (exploración, lectura de documentación, revisiones, suite): es un recurso escaso.
- **Un subagente no lanza subagentes.** Quien encadena `implementer` → `traductor` → `verificador` → revisores es la sesión orquestadora.
- **Revisión con Codex** (`ADR-049`/`050`/`069`, skill `revision-con-codex`): con `ADR-069` (`OPEN-069-01` confirmada por el usuario el 2026-11-02) Codex revisa **primero** y es obligatorio por el proceso en todo paso con código (no entra en §10); lo lanza la sesión solo con `review` y `adversarial-review`; `rescue` prohibido; sin autoridad de bloqueo; un hallazgo contra un ADR vigente se cierra citándolo. Si `OPEN-069-01` dejara de estar confirmada, no se lanza sobre código del proyecto y rige `ADR-050`. Detalle: `docs/proceso/normas-detalle.md#codex`.
- **Restauración de la configuración del 08/10/2026** (etiqueta `config-claude-2026-10-08`): solo si el usuario la pide; procedimiento en `docs/proceso/restauracion-configuracion.md`; `PLAN-IMPLEMENTACION.md` no se restaura entero y la etiqueta no se mueve ni se borra.

**Ahorro de cuota (norma del usuario, 2026-11-02; detalle y motivo en la skill `ahorro-de-cuota`).** El gasto de tokens es una restricción de diseño:

1. **Tramos de `implementer`**, nunca uno largo por paso: **secuenciales** (jamás en paralelo: el checkout y la pila de desarrollo son compartidos, también para `verificador` y los E2E; lo que solo escribe documentos va en un `git worktree` propio), de ~60-100 llamadas o <1 h. Cada tramo cierra con un commit `Verificado: X/X …` y deja la nota viva de §3. La ejecución final del E2E real no es un tramo: la lanza la sesión con `espera-e2e`.
2. **Prompt mínimo por tramo**: solo las secciones de la especificación que necesita y las decisiones ya aprobadas, más el briefing de `explorer` antes de cada tramo de código.
3. **Revisiones acotadas**: los revisores se lanzan **una vez**, al final del paso, sobre el diff. Codex primero (`ADR-069`); `spec-writer` clasifica el paso y la duda es crítico; `security-reviewer` en paso crítico solo con su columna del reparto, o en ordinario solo si Codex trae un Crítico o Alto o no está disponible; `db-reviewer` siempre que haya migraciones. Segunda ronda: la verifica Codex si hubo Medios o Bajos; el revisor de Claude solo reentra por un Crítico o un Alto (régimen `ADR-050`: si trae Críticos, Altos o Medios). Los Bajos se corrigen directamente. Las correcciones, en **una sola reanudación** tras **todos** los informes.
4. **Métricas**: una fila por subagente en `docs/metricas-agentes.md`; al cerrar el paso se recalculan por tipo de agente.
5. **Contexto principal pequeño y salidas cortas**: lectura, búsqueda y ejecución en subagentes; se reinicia la sesión por encima de ~50 % de contexto; tests y linters resumidos con `tail`/`grep`.
6. **Entregas de ≤3 tramos de `implementer`** (≈250 llamadas); si no cabe, se parte con sufijo de letra (`1.14b-a`..`1.14b-d`).
7. **Documentación**: `doc-precheck` (Haiku) siempre antes de `doc-reviewer`; la segunda ronda (si la primera trajo Medios) la hace `doc-precheck` sobre la lista cerrada de hallazgos, y `doc-reviewer` solo reentra si se corrigió un Crítico o un Alto (`OPEN-069-05`).
8. **Traducciones**: hasta ~15 claves nuevas no legales las traduce la sesión; con más, `traductor`. Tratamiento formal, placeholders intactos, paridad con `TranslationParityTest`. Los textos legales, de consentimiento, de privacidad y de seguridad no se traducen automáticamente **en ningún caso**.
9. **Caché de prompt**: al esperar evita ~300 s (<270 s, o 1200-1800 s si es de fondo); con contexto acumulado usa `fork`; no cargues herramientas a mitad de sesión. El cierre de §3 prima sobre el ahorro de caché.

---

## 3. Protocolo de sesión

**Al arrancar cualquier sesión, sin que te lo pidan:**
1. Lee `memory.md` (estado actual, decisiones recientes, trabajo en curso).
2. Lee `PLAN-IMPLEMENTACION.md` y localiza el paso activo.
3. Comprueba la rama actual y si hay trabajo sin commitear.
4. Resume en 5 líneas dónde estamos y qué toca. Espera confirmación antes de escribir código.

**Durante la sesión:**
- Actualiza `memory.md` tras cada hito, no solo al final: si la sesión se corta por cuota, el estado debe estar guardado.
- Commits pequeños y frecuentes. Nunca acumules horas de trabajo sin commitear.

**Al cerrar sesión (o cuando avises de que queda poca cuota):**
1. Commit y push de todo lo funcional.
2. Actualiza `memory.md`: qué se completó, qué queda a medias, siguiente paso concreto, decisiones tomadas, problemas abiertos.
3. Actualiza `PLAN-IMPLEMENTACION.md` marcando el progreso.
4. Deja el repositorio en estado compilable y con tests en verde.
5. Revisa los PR abiertos por Renovate: `gh pr list --author "app/renovate" --state open`; por cada uno anota en `memory.md` si pasa el CI, está obsoleto (rebase) o falla. Si falla por código del proyecto (p. ej. Larastan), se abre issue con la severidad de §5; los PR con etiqueta `security` se tratan como Altos. Renovate nunca se mezcla sin CI en verde (`automerge: false`).

**Este cierre no espera a que lo pidas.** En cuanto el sistema avise de que la cuota se agota (aviso «usage limit approaching» u otro equivalente), termina el paso atómico en curso (no empieces uno nuevo), aplica los cuatro puntos anteriores y programa un aviso para retomar cuando la cuota se restaure. La hora de reset **no está en tu contexto**: pregúntasela al usuario, salvo que ya te la haya dado. Mecanismo: skill `cierre-de-sesion`.

**Cierre también entre pasos del plan.** Salvo orden explícita del usuario, al terminar un paso de `PLAN-IMPLEMENTACION.md` (aprobado, implementado, revisado y mezclado) aplica el cierre completo **antes** de empezar el siguiente y déjalo dicho en `memory.md`, con la revisión de PR de Renovate del punto 5 del cierre de sesión; no encadenes varios pasos del plan en la misma sesión por defecto (higiene de contexto).

**Relanzar un subagente de ejecución tras un fallo o un corte por cuota no es licencia para decidir alcance**: no recorta, amplía, fusiona ni reinterpreta la especificación aprobada, ni para «simplificar». La sigue al pie de la letra; si lo que queda no está claro, para y repórtalo (sección 0). La sesión orquestadora contrasta lo entregado con la especificación antes de darlo por bueno. Motivo: `docs/proceso/casos-reales.md#relanzamiento-recorta-alcance`.

**Todo relanzamiento tras un corte de cuota deja constancia de lo ya verificado** (motivo: `docs/proceso/casos-reales.md#cortes-cuota-reverificacion`):

1. Al confirmar la suite en verde para un lote de commits, el último commit del lote dice el número real (`Verificado: X/X Pest en verde tras este commit`), nunca aproximado ni supuesto.
2. Mientras el paso esté en curso, `memory.md` mantiene una **única nota viva por paso** en «Trabajo en curso» (se sobrescribe) con el hash verificado, la fecha y qué queda con certeza por hacer.

Un relanzamiento que encuentra esa nota confía en los commits que cubre y solo verifica lo posterior; esto ahorra reverificación, no habilita recortar, ampliar ni reinterpretar alcance.

**Suite (`ADR-060 §4.4`).** Durante el trabajo, solo los ficheros afectados (`php -d memory_limit=-1 vendor/bin/pest <rutas>`). La suite completa (`composer test`) se ejecuta **una vez en local antes de abrir el PR** y el CI la repite. Los revisores no la relanzan salvo necesidad justificada. `Verificado` dice su alcance (`(suite completa)` o `(ficheros afectados: …)`) y un relanzamiento solo da por buena la suite completa con un `Verificado` de suite completa. Detalle: `docs/proceso/normas-detalle.md#suite`.

**E2E contra la API real (norma del usuario, 2026-11-02).** Con el paso implementado y la suite en verde, se prueba además de extremo a extremo contra la API real (Playwright o HTTP real con cookie y CSRF, datos de `REQ-SEED`). Se puede recrear el demo o la base sin pedir permiso. La lanza la sesión con la skill `espera-e2e` (sin agente; `verificador` solo para diagnosticar): un fallo de código vuelve a `implementer`, uno de entorno lo arregla la sesión. Un E2E parcial no se declara verde. El resultado (qué se probó y qué no, p. ej. el S3 real, `ADR-076 §2.3`) va a `memory.md` y al informe del paso.

**Los E2E deben existir, no solo ejecutarse (norma del usuario, 2026-11-17).** Toda implementación deja, en la misma entrega, su E2E real (flujo de usuario: Playwright contra la API real que referencie el ID del requisito; solo backend: HTTP real con cookie y CSRF sobre `REQ-SEED`), igual que exigimos tests unitarios (`INV-015`). Un E2E nuevo debe **demostrar que detecta el fallo**: se ejecuta también con la función rota y debe fallar; se anota en el informe. La especificación lo lista de antemano (`spec-writer`), el prompt de `implementer` lo incluye, y `doc-reviewer` y la sesión comprueban en el diff que existe y está registrado en el script de E2E real; sin él el paso no está terminado (`§10`). Quedan fuera documentación, refactors sin cambio de comportamiento y subidas de dependencias, salvo que alteren un comportamiento visible o de API. Si un E2E real no es viable, se dice en el informe, se cubre con el mejor sustituto y se abre issue. Detalle y motivo (#729, #737): `docs/proceso/normas-detalle.md#e2e`.

---

## 4. Git

- Ramas permanentes: **`main`** (producción), **`staging`** (lo que se prueba en el entorno de staging) y **`develop`** (integración). Flujo: `develop` → `staging` → `main` (`ADR-080 §4`).
- Todo desarrollo ocurre en ramas colgadas de `develop`:
  - `feature/REQ-XXX-descripcion-corta`
  - `fix/REQ-XXX-descripcion-corta`
  - `chore/descripcion-corta`
- Merge a `develop` al terminar, y **borrado inmediato de la subrama** local y remota.
- **Promociones** (`ADR-080 §4.3`): `staging` y `main` solo avanzan por **avance rápido** de un commit que ya está en `develop` (`staging`) o en `staging` (`main`), con `git push origin <sha de 40 hex>:refs/heads/<rama>` desde HEAD desacoplado o un *worktree* (nunca con una rama protegida como actual: el gancho lo exige y no se relaja); sin *squash*, sin `--force`, sin el botón de mezclar. `main` solo recibe lo que ya está en `staging`, con etiqueta semver y cuando el usuario lo pide.
- **Nunca commitees directamente en `main`, `staging` ni `develop`.**
- **Arreglo urgente** (`ADR-080 §4.4`), solo si `develop` contiene algo que no puede ir aún a staging: rama `fix/…` desde `staging`, PR a `staging` y luego `staging` → `develop`, ambos con *merge commit* (nunca *squash*), mezclados a mano sin borrar `staging` ni `main`; sin más promociones hasta mezclar. La reconciliación `main` → `develop` sigue la misma regla. Detalle: `docs/proceso/normas-detalle.md#git-promociones`.
- El gancho `.claude/hooks/block-protected-branch.sh` bloquea commit y `push` en `develop`, `staging` y `main`, los `push` forzados y todo destino protegido que no sea `<sha de 40 hex>:refs/heads/<rama>`. Prueba: `.claude/hooks/test-block-protected-branch.sh`.
- Formato de commit: `tipo(ámbito): descripción [REQ-XXX-NNN]`
  - Ejemplo: `feat(auth): MFA obligatorio por rol [REQ-AUTH-003]`
- Todo commit referencia al menos un ID de requisito o de issue.
- Antes de cualquier merge: tests en verde, lint limpio, documentación del módulo actualizada.
- **Pull request** (skills `pr-check` y `espera-ci`; lo hace la sesión orquestadora o `janitor`, que las lleva cargadas): antes de `gh pr create`, `pr-check` (rama, commits, `Verificado`, ficheros sensibles, documentación; la suite la ejecuta `verificador`, un subagente nunca la lanza); tras abrirlo, `espera-ci` espera el CI en segundo plano y mezcla solo si no hay `fail` ni `pending`.

### .gitignore

Eres responsable de mantener el repositorio limpio. Revisa `.gitignore` **antes de cada commit** y añade lo que corresponda. Nunca deben subirse:

- Dependencias (`vendor/`, `node_modules/`), builds y artefactos.
- Ficheros de entorno, claves, certificados, tokens, `.env` de cualquier tipo.
- Volcados de base de datos, backups, exportaciones y **cualquier fichero con datos reales de alumnos, familias o personal**.
- Ficheros de IDE, del sistema operativo, logs, caché, cobertura.
- Ficheros temporales de trabajo propios.

Si detectas que algo sensible ya está en el histórico, **para y avisa inmediatamente**: no basta con borrarlo en un commit nuevo.

---

## 5. Gestión de incidencias

Cuando encuentres un problema durante el trabajo, clasifícalo y actúa:

| Severidad | Criterio | Acción |
|-----------|----------|--------|
| **Crítica** | Fuga entre tenants, exposición de datos personales, pérdida de datos, caída total, incumplimiento legal | Crea issue en GitHub, **detén el trabajo en curso** y resuélvelo de inmediato. Documenta causa, solución y test de regresión. |
| **Alta** | Fallo funcional que impide usar un módulo, vulnerabilidad explotable, migración destructiva | Crea issue y resuélvelo en la misma sesión. Documenta en el issue. |
| **Media** | Fallo funcional con rodeo posible, deuda que crecerá, incumplimiento de invariante sin impacto inmediato | Crea issue y resuélvelo en la misma sesión si no descarrila el objetivo; si descarrila, avisa y pide decisión. |
| **Baja** | Mejora, refactor cosmético, inconsistencia menor de UI | Crea issue documentado con contexto y propuesta. **Informa al usuario y no lo resuelvas** sin que lo pida. |

Todo issue lleva: descripción, cómo reproducirlo, ficheros implicados, severidad, requisitos afectados y propuesta de solución. Al resolverlo, enlaza el commit y explica qué se hizo.

**Nunca arregles un problema en silencio.** Aunque lo resuelvas al momento, queda documentado en GitHub.

---

## 6. Documentación

Estructura **híbrida**, y es obligatoria:

```
/README.md /ARCHITECTURE.md /SYSADMIN.md /SECURITY.md
/PRIVACY.md /RUNBOOK.md /CHANGELOG.md /CONTRIBUTING.md
/docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md
/docs/modulos/REQ-XXX/{funcional,datos,api,permisos,operacion}.md
/docs/manual-usuario/{admin,direccion,secretaria,docente,familia,estudiante}.md
/docs/adr/ADR-NNN-titulo.md
/docs/cumplimiento/{README,matriz-normas,preguntas-abiertas,indice-evidencias}.md
```

**Normas de obligado cumplimiento:**

1. **Ningún módulo se cierra sin su documentación actualizada.** Forma parte de la definición de terminado, no es una tarea posterior.
2. **Ninguna fase se cierra sin revisión completa de documentación**, ejecutada por el subagente `doc-reviewer`, que verifica coherencia entre requisito, modelo de datos, API implementada, permisos, manual de usuario **y la vigencia de los documentos raíz** (regla 7).
3. Toda decisión arquitectónica relevante genera un **ADR numerado e inmutable**. Los ADR `001`-`027` son canónicos en la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`, que actúa de índice. **Los nuevos, del `028` en adelante, se escriben como fichero propio en `/docs/adr/` y se referencian desde esa sección.**
4. `SYSADMIN.md` y los manuales de usuario se actualizan **en cada fase**, no al final del proyecto.
5. La documentación de un módulo se escribe en su carpeta, nunca se acumula en un documento único: es inmanejable con 53 módulos y consume el contexto innecesariamente.
6. Si el código y la documentación se contradicen, es un issue de severidad media como mínimo.
7. **Vigencia de los documentos raíz** (`README.md`, `SECURITY.md`, `PRIVACY.md`, `ARCHITECTURE.md`, `SYSADMIN.md`, `RUNBOOK.md`, `CONTRIBUTING.md`, `CHANGELOG.md` y la cabecera/historial de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`), no solo la de módulo: en **cada** cierre de fase (no solo al final de un bloque), `doc-reviewer` contrasta su contenido contra el estado real del código y el plan — cabecera de estado, tabla de versiones cruzada entre documentos, secciones que describan como "pendiente" o "no implementado" algo que ya existe, y `CHANGELOG.md` con una entrada por cada paso cerrado. Motivo: `docs/proceso/casos-reales.md#docs-raiz-desactualizados`.

---

## 7. Invariantes del producto

Reglas transversales de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` sección 0.5. Aplican a **todo** el código sin excepción. Las críticas:

- **INV-001** Aislamiento de tenant a nivel de framework, nunca solo en el controlador.
- **INV-002** Autorización verificada en cada endpoint. Denegar por defecto.
- **INV-003** Auditoría de toda creación, modificación y borrado.
- **INV-004** Borrado lógico en entidades críticas.
- **INV-006** API primero: la UI es un cliente más.
- **INV-007** Un módulo no importa código interno de otro. Comunicación por interfaces o eventos.
- **INV-008** Datos de menores: base legal y consentimiento del tutor registrados.
- **INV-009** Ningún literal visible escrito en el código: todo por el sistema de traducción.
- **INV-010** Validación de negocio siempre en servidor.
- **INV-012** Tareas pesadas en colas, nunca en la petición HTTP.
- **INV-015** Ningún requisito está implementado sin test que lo cubra y referencie su ID.

Convenciones de esquema (`ADR-029`): `TIMESTAMPTZ` siempre, `text` en vez de `varchar(n)`, importes en enteros de céntimos, clave primaria `bigint` interna más `public_id` ULID en todo lo que se exponga en URL o API.

Idiomas obligatorios: **es-ES (por defecto), en, de, fr**, en interfaz, documentos generados y contenido del centro.

---

## 8. Seguridad: reglas no negociables

- Autenticación de la SPA por **cookie de sesión** `httpOnly`, `Secure`, `SameSite` con CSRF. **Prohibido JWT en `localStorage`**.
- Consultas parametrizadas siempre. Nunca concatenar SQL.
- Todo fichero subido: validación de tipo real, tamaño y almacenamiento fuera de la raíz web, con URL firmada de caducidad corta.
- Secretos fuera del código, en gestor de secretos.
- Cabeceras de seguridad y CSP estricta configuradas desde el primer despliegue.
- Datos de categoría especial (salud, NEAE, convivencia) en tablas separadas, cifrados, con permisos propios y auditoría de lectura.
- Cada PR pasa escaneo de dependencias y, según `ADR-090` (paso `0.10g-a`), de las imágenes construidas.

---

## 9. Despliegue

- Migraciones **expand/contract**: añadir, desplegar, y eliminar lo antiguo dos versiones después. Nunca renombrar ni borrar una columna en la misma entrega que deja de usarse.
- El esquema debe ser compatible con la versión anterior y la nueva simultáneamente.
- Aplicación stateless. Nada en disco local.
- El host solo ejecuta contenedores: **no se instala PHP, Node ni PostgreSQL en el sistema operativo**.
- SELinux permanece en `enforcing`. Los volúmenes de contenedor se montan con la etiqueta `:Z`.
- **Red y dependencias** (`ADR-028`): el frontend no hace de proxy hacia la API; la red es externa y no se destruye; `compose down` está prohibido en el servidor; entre servicios de aplicación se usa `Wants=` + `After=`, nunca `Requires=` ni `BindsTo=`; ninguna IP de contenedor escrita a mano.
- Los workers deben procesar trabajos encolados por la versión anterior.
- Cada entrega incluye su procedimiento de reversión probado.
- **Staging** (`ADR-080`) es un entorno más, entre desarrollo y producción: se despliega con Quadlet **rootless** (`install.sh <tag> --user`), nunca con `compose`, y es **destruible y sin datos reales**: solo datos sintéticos de `REQ-SEED`. No es producción: certificados de pruebas hasta que el usuario decida otra cosa, y en él no se ensayan copias ni restauración **de datos del producto** (`ADR-080 §3.3`); la copia cifrada de los **secretos** del VPS sí se hará (`ADR-090`, paso `0.10g-g`).

---

## 10. Definición de terminado

Un requisito está terminado cuando:

- [ ] Cumple sus criterios de aceptación
- [ ] Cumple las invariantes de la sección 7
- [ ] Tiene tests que referencian su ID y están en verde
- [ ] Tiene E2E real que cubre su flujo (o prueba HTTP real si es solo backend), registrado en el script de E2E y demostrado que falla cuando la función se rompe (§3)
- [ ] El endpoint está documentado en OpenAPI
- [ ] Pasa lint, análisis estático y escaneo de dependencias (e imágenes, `ADR-090`)
- [ ] Textos traducidos a los cuatro idiomas
- [ ] Accesible según WCAG 2.2 AA
- [ ] Documentación del módulo actualizada
- [ ] `memory.md` actualizado

---

## 11. Qué NO hacer

- No implementes nada marcado como `FUTURO`.
- No resuelvas por tu cuenta una decisión abierta: pregunta.
- No inventes requisitos que no estén en el documento.
- No cambies una decisión de un ADR sin escribir un ADR nuevo que lo sustituya.
- No refactorices código ajeno al objetivo de la sesión sin avisar.
- **Nunca escribas el NIF/DNI ni la dirección física del usuario en Git**: ni en ficheros, ni en mensajes de commit, PR, *issues* o comentarios, ni en el historial (repositorio público; riesgo físico para su familia). Tampoco se publican en la landing (`ADR-073`).
- No uses datos reales de alumnos, familias o personal en desarrollo, **bajo ningún concepto** (`ADR-030`). Ni una exportación del centro, ni una copia de producción para depurar. Para eso está `REQ-SEED`.
- **Staging tampoco admite datos reales**, ni siquiera de prueba «temporal» de un centro: la regla de arriba rige igual allí que en desarrollo (`ADR-080`).
- Los datos de prueba siguen la convención de `REQ-SEED-005`: dominios `@example.com`, documentos de identidad con dígito de control inválido, centros con nombre explícitamente ficticio, y nunca fotografías de personas reales.
- No declares terminado lo que no has ejecutado y verificado.
