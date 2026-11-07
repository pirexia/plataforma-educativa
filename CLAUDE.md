# CLAUDE.md — Normas de trabajo del proyecto

> **Versión 2.11.1** · 2026-11-07 · Fichero de contexto permanente. Se carga en **todas** las sesiones. Contiene solo reglas estables.
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
| Colas | **Hoy**: *driver* `database` de Laravel — `QUEUE_CONNECTION=database`, tablas `jobs` y `failed_jobs`, consumidas por `php artisan queue:work` en **dos procesos** (perfil `worker` de `compose.yaml`): `worker` (conexión `database`, `infra/quadlet/worker.container`; issue #128, resuelto) y `worker-long` (cola `long`, conexión `database_long`, `retry_after` 900 s, `infra/quadlet/worker-long.container`; `1.12b`, ratificado por el usuario el 2026-10-21 porque el `retry_after` es de la conexión con la que se lee la cola). **Elegido y no instalado**: Redis como cola + **Laravel Horizon**. Horizon **no está en `composer.json`** y no hay `config/horizon.php`; adoptarlo es una dependencia nueva sujeta a la regla de abajo y a `OPEN-BO-23` (`docs/modulos/REQ-BO/funcional.md §14`) |
| Almacenamiento | Compatible S3 (MinIO en desarrollo) |
| PDF | Servicio contenerizado de renderizado HTML→PDF |
| Tests | Pest (backend), Vitest + Playwright (frontend) |
| Contenedores | **Podman**. Desarrollo en **WSL2** con perfil reducido (`ADR-030`); `compose.yaml` estándar con `podman compose`; Quadlet/systemd en producción. Kubernetes a partir de 3-5 centros |
| Repositorio | Monorepo: `apps/api`, `apps/web`, `infra`, `docs` |

**Esta tabla dice qué está elegido, no qué está instalado.** Cuando no coinciden, la fila lo dice con el issue que lo sigue. Antes de apoyarse en una pieza de esta tabla, se comprueba en `composer.json`/`package.json`. Motivo: `docs/proceso/casos-reales.md#horizon-no-instalado`.

**Prohibido** introducir una dependencia nueva sin justificarla y sin comprobar mantenimiento activo, licencia y frecuencia de releases. Toda dependencia externa se envuelve tras una interfaz propia (`RNF-MANT-007`).

**Prohibido mezclar librerías de componentes UI.** shadcn-vue es el design system único.

Reglas de composición visual del frontend: `docs/design-system.md §22`.

---

## 2. Modelos y agentes

**La asignación de modelos y agentes está en la skill `asignacion-de-modelos`** (tablas tarea→modelo y agente→modelo, herencia de modelo en `fork`/`general-purpose`/`Plan`, criterios para tareas nuevas): **cárgala antes de elegir agente o modelo al delegar**. Reglas que rigen aunque no se cargue:

- Sesión principal en **Sonnet** (`.claude/settings.json`); Opus a mano (`/model opus`) solo para especificación, arquitectura y planificación. Agentes con alias (`haiku`, `sonnet`, `opus`), nunca un ID de versión. Trabaja en **modo auto**.
- `security-reviewer`, `db-reviewer` y `doc-reviewer` nunca se rebajan a Haiku; Haiku no escribe código de negocio ni tests. No uses Opus en subagentes de ejecución. `fork`, `general-purpose` y `Plan` heredan el modelo de la sesión: para buscar, `explorer`; para tests y linters, `verificador`.
- Delega en subagentes todo lo que no necesite el contexto principal (exploración, lectura de documentación, revisiones, suite): es un recurso escaso.
- **Un subagente no lanza subagentes.** Quien encadena `implementer` → `traductor` → `verificador` → revisores es la sesión orquestadora.
- **Revisión con Codex (`ADR-049`/`ADR-050`/`ADR-069`)**: plugin `openai/codex-plugin-cc`, protocolo en la skill `revision-con-codex`. Con `ADR-069` Codex revisa **primero** y es obligatorio por el proceso en todo paso con código (no entra en §10); lo lanza la sesión por `Bash` solo con `review` y `adversarial-review`; `rescue` prohibido; sin autoridad de bloqueo; un hallazgo contra un ADR vigente se cierra citándolo. **`OPEN-069-01` confirmada por el usuario (2026-11-02; entrenamiento desactivado en su cuenta de OpenAI): régimen de `ADR-069` activo.** Si dejara de estarlo, no se lanza Codex sobre código del proyecto y rige `ADR-050` (opcional): el interruptor está en la skill. Condiciones de retirada de `ADR-050 §5.3` siempre armadas. Motivo: `docs/proceso/casos-reales.md#codex-prueba-cerrada`.

**Restauración de la configuración del 08/10/2026** (etiqueta `config-claude-2026-10-08`, commit `0ecd3a2`): si el usuario la pide, procedimiento en `docs/proceso/restauracion-configuracion.md`. `PLAN-IMPLEMENTACION.md` no se restaura entero; la etiqueta no se mueve ni se borra.

**Caché de prompt** (vigencia ~5 min; motivo: `docs/proceso/casos-reales.md#cache-prompt`):
- Al esperar (`ScheduleWakeup` u otra pausa) evita ~300 s: usa <270 s para mantener la caché caliente, o 1200-1800 s si es de fondo; nunca un valor redondo intermedio sin motivo.
- Si la tarea necesita el contexto acumulado y el modelo de la sesión es el adecuado, usa `fork` (comparte caché) en vez de un subagente nuevo (arranca en frío).
- No cargues herramientas adicionales a mitad de sesión (`ToolSearch`, skills que añaden herramientas) más de lo necesario: invalidan el prefijo cacheado. Decide de una vez qué hará falta.
- El cierre de la sección 3 prima sobre el ahorro de caché.

**Ahorro de cuota (norma del usuario, 2026-11-02).** El gasto de tokens es una restricción de diseño del proceso (motivo: `docs/proceso/casos-reales.md#cuota-semanal`).

1. **Tramos de `implementer`.** Nunca uno largo por paso: tramos **secuenciales** (jamás en paralelo en la misma rama: el contenedor monta el checkout principal) cortados en costuras naturales — datos y permisos / servicios y *endpoints* / UI / E2E real —, de ~60-100 llamadas o <1 h (`docs/proceso/casos-reales.md#coste-tramos`). `implementer` escribe y depura los *specs* de E2E; **la ejecución final del E2E real no es un tramo de `implementer`**: la lanza `verificador` (Haiku) y solo un fallo de código vuelve a `implementer` (sección 3). Cada tramo cierra con un commit `Verificado: X/X …` y deja la nota viva de la sección 3.
2. **Prompt mínimo por tramo.** Solo las secciones de la especificación que el tramo necesita (fichero y sección) y las decisiones ya aprobadas para que no se reabran; nunca la especificación entera. **Antes de cada tramo de código, `explorer` entrega un briefing** (ficheros a imitar, puntos de contacto, lo que no existe) que la sesión pega en el prompt, para que `implementer` no gaste su arranque explorando. Es experimental hasta 5 tramos medidos (punto 4): se anota en `docs/metricas-agentes.md` como fila `explorer · briefing` y se compara el gasto del `implementer` con y sin él.
3. **Revisiones acotadas.** Los revisores se lanzan **una vez**, al final del paso, sobre el diff y con las decisiones aprobadas en el prompt. Orden con `ADR-069` (`OPEN-069-01` confirmada): Codex primero; en paso **crítico** después `security-reviewer` solo con su columna del reparto; en paso **ordinario** `security-reviewer` solo si Codex trae un Crítico o Alto aceptado o no está disponible. `spec-writer` clasifica el paso y la sesión lo comprueba contra el diff; la duda es crítico. `db-reviewer` siempre que haya migraciones. **Segunda ronda**: la verifica Codex si hubo Medios o Bajos; el revisor de Claude solo reentra por un Crítico o un Alto (la de documentación, punto 8). Si `OPEN-069-01` dejara de estar confirmada (régimen de `ADR-050`): segunda ronda si la primera trae Críticos, Altos o Medios. Los Bajos se corrigen directamente. Las correcciones se reúnen en **una sola reanudación** tras **todos** los informes.
4. **Métricas.** Al terminar cada subagente se anota una fila (`subagent_tokens`, `tool_uses`, `duration_ms`; en reanudaciones, el delta) en `docs/metricas-agentes.md`. Al cerrar cada paso se recalculan media, mediana y tokens por llamada **por tipo de agente** y se ajusta el número de tramos del siguiente. Con <5 observaciones por tipo son orientativas; `subagent_tokens` no incluye lecturas de caché.
5. **Contexto principal pequeño.** Lectura, búsqueda y ejecución en subagentes. Se reinicia la sesión por encima de ~50 % de contexto o antes de una pausa larga (la caché caduca tras ~1 h), no por sistema. No releva el cierre de la sección 3.
6. **Salidas cortas.** Tests, linters y comandos con salida resumida o a fichero (`tail`, `grep`); ficheros grandes con `offset`/`limit` tras localizar con `grep`.
7. **Tamaño de entrega.** Al planificar (`spec-writer`/`architect`/plan), cada entrega se dimensiona para caber en **≤3 tramos de `implementer`** (≈250 llamadas; cifra a recalibrar con `docs/metricas-agentes.md`). Si no cabe, se parte en entregas con sufijo de letra, como `1.14b-a`..`1.14b-d`.
8. **Documentación.** `doc-precheck` (Haiku) se ejecuta **siempre** antes de `doc-reviewer`. La segunda ronda de documentación (que hay si la primera trajo Medios, punto 3) la hace `doc-precheck` sobre la lista cerrada de hallazgos de la primera; `doc-reviewer` solo vuelve a entrar si se corrigió un Crítico o un Alto (`OPEN-069-05`, aprobada el 2026-11-02).
9. **Traducciones pequeñas.** Hasta ~15 claves nuevas no legales las traduce la sesión orquestadora directamente (lanzar `traductor` cuesta más en arranque y relectura que la propia traducción); con más, va `traductor`. Tratamiento formal («Sie», «vous»), placeholders intactos y paridad de claves comprobada con `TranslationParityTest`. Los textos legales, de consentimiento, de privacidad y de seguridad (p. ej. `prl.protocol.minimal`) no se traducen automáticamente en ningún caso (aprobada por el usuario el 2026-11-02).

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

**Este cierre no espera a que lo pidas.** En cuanto el sistema avise de que la cuota se agota (aviso «usage limit approaching» u otro equivalente), termina el paso atómico en curso (no empieces uno nuevo), aplica los cuatro puntos anteriores y programa un aviso para retomar cuando la cuota se restaure. La hora de reset **no está en tu contexto**: pregúntasela al usuario, salvo que ya te la haya dado. Mecanismo: skill `cierre-de-sesion`.

**Cierre también entre pasos del plan.** Salvo orden explícita del usuario, al terminar un paso de `PLAN-IMPLEMENTACION.md` (aprobado, implementado, revisado y mezclado) aplica el cierre completo **antes** de empezar el siguiente y déjalo dicho en `memory.md`; no encadenes varios pasos del plan en la misma sesión por defecto (higiene de contexto).

**Relanzar un subagente de ejecución (`implementer` u otro) tras un fallo o un corte por cuota no es licencia para decidir alcance.** No recorta, amplía, fusiona ni reinterpreta lo que la especificación aprobada dice, ni siquiera para «simplificar» algo redundante o acelerar el cierre. Sigue la especificación al pie de la letra, como si empezara de cero; si lo que queda no está claro o parece contradecirla, para y repórtalo (sección 0). Quien relanza (la sesión orquestadora) contrasta lo entregado contra la especificación antes de darlo por bueno. Motivo: `docs/proceso/casos-reales.md#relanzamiento-recorta-alcance`.

**Todo relanzamiento tras un corte de cuota deja constancia de lo ya verificado** (cualquier subagente de ejecución, todo el proyecto). Motivo: `docs/proceso/casos-reales.md#cortes-cuota-reverificacion`.

1. Al confirmar la suite en verde para un lote de commits, el mensaje del último commit del lote lo dice con el número real (`Verificado: X/X Pest en verde tras este commit`), nunca aproximado ni supuesto: se ejecuta, no se estima.
2. Mientras el paso esté en curso, `memory.md` mantiene una **única nota viva por paso** en «Trabajo en curso» (se sobrescribe, no se acumula) con el hash hasta el que está verificado, la fecha y qué queda con certeza por hacer.

Un relanzamiento que encuentra esa nota confía en los commits que cubre sin reejecutar la suite, y solo verifica lo no commiteado o posterior a la nota. Esto **no** afloja la norma anterior: ahorra reverificación, no habilita recortar, ampliar ni reinterpretar alcance.

**Cuándo se ejecuta la suite (`ADR-060 §4.4`).** Durante el trabajo, solo los ficheros o directorios afectados (`php -d memory_limit=-1 vendor/bin/pest <rutas>`). La suite completa (`composer test`: Unit y Feature en paralelo, una base `plataforma_test_N` por proceso, y `Concurrency` en serie) se ejecuta **una vez en local antes de abrir el PR**, y CI la repite en cada *push*. Los revisores no la relanzan salvo necesidad justificada en su informe. El mensaje `Verificado` dice su alcance, `(suite completa)` o `(ficheros afectados: …)`, y un relanzamiento solo da por buena la suite completa con un `Verificado` de suite completa. Las bases por proceso se crean con el script de `SYSADMIN.md`; `composer test:serie` queda como vuelta atrás.

**E2E contra la API real (norma del usuario, 2026-11-02).** Con el paso implementado y su suite en verde, se prueba además **de extremo a extremo contra la API real** en ejecución (Playwright o HTTP real con cookie de sesión y CSRF, datos de `REQ-SEED`), no solo con cliente de pruebas ni API simulada. Se permite **recrear el demo o la base** cuantas veces haga falta, sin pedir permiso. **Quién lo ejecuta (2026-11-06):** `verificador` (Haiku) lanza `npm run test:e2e:real[:prl]` y devuelve el resultado literal; si falla por código, la sesión reencarga a `implementer` solo el fallo; si falla por entorno (código 2, pila caída, S3), lo arregla la sesión. Un E2E parcial no se declara verde. El resultado (qué se probó y qué no, p. ej. S3/MinIO) se registra en `memory.md` y en el informe del paso.

---

## 4. Git

- Ramas permanentes: **`main`** (producción) y **`develop`** (integración).
- Todo desarrollo ocurre en ramas colgadas de `develop`:
  - `feature/REQ-XXX-descripcion-corta`
  - `fix/REQ-XXX-descripcion-corta`
  - `chore/descripcion-corta`
- Merge a `develop` al terminar, y **borrado inmediato de la subrama** local y remota.
- `main` solo recibe merges desde `develop` en release, etiquetados con versión semántica.
- **Nunca commitees directamente en `main` ni en `develop`.**
- Formato de commit: `tipo(ámbito): descripción [REQ-XXX-NNN]`
  - Ejemplo: `feat(auth): MFA obligatorio por rol [REQ-AUTH-003]`
- Todo commit referencia al menos un ID de requisito o de issue.
- Antes de cualquier merge: tests en verde, lint limpio, documentación del módulo actualizada.
- **Pull request** (skills `pr-check` y `espera-ci`; lo hace la sesión orquestadora o `janitor`, que las lleva cargadas): antes de `gh pr create`, `pr-check` (rama, commits, `Verificado`, ficheros sensibles, documentación; la suite la ejecuta `verificador`, un subagente nunca la lanza); tras abrirlo, `espera-ci` espera el CI en segundo plano y mezcla solo si no hay `fail` ni `pending`. Un hook (`.claude/hooks/block-protected-branch.sh`) bloquea además commit y push en `develop` y `main`.

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
- Cada PR pasa escaneo de dependencias.

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

---

## 10. Definición de terminado

Un requisito está terminado cuando:

- [ ] Cumple sus criterios de aceptación
- [ ] Cumple las invariantes de la sección 7
- [ ] Tiene tests que referencian su ID y están en verde
- [ ] El endpoint está documentado en OpenAPI
- [ ] Pasa lint, análisis estático y escaneo de dependencias
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
- No uses datos reales de alumnos, familias o personal en desarrollo, **bajo ningún concepto** (`ADR-030`). Ni una exportación del centro, ni una copia de producción para depurar. Para eso está `REQ-SEED`.
- Los datos de prueba siguen la convención de `REQ-SEED-005`: dominios `@example.com`, documentos de identidad con dígito de control inválido, centros con nombre explícitamente ficticio, y nunca fotografías de personas reales.
- No declares terminado lo que no has ejecutado y verificado.
