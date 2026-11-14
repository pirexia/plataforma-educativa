# ADR-049 · Segunda opinión de Codex sobre código ya implementado: solo lectura, sin autoridad de bloqueo y con prueba acotada

**Estado**: **ACEPTADA** (2026-09-14). El usuario aprobó las condiciones vinculantes de `§5`-`§9` tal como las escribió `architect`, confirmó personalmente la auditoría de los tres `.env` reales exigida por `§5.3`/`§14.2` (ninguna credencial válida fuera de esta máquina de desarrollo) y autorizó la limpieza de los `.claude/worktrees/agent-*` abandonados de `§15.1`.

**Fecha**: 2026-09-14

**Resuelve**: la comprobación formal de `CLAUDE.md §1` («prohibido introducir una dependencia nueva sin justificarla y sin comprobar mantenimiento activo, licencia y frecuencia de *releases*») aplicada a **`openai/codex-plugin-cc`**, y las cuatro cosas que esa comprobación no cubre y que nadie más iba a decidir: qué protege y qué **no** protege el *sandbox* de Codex, qué significa «envolver tras interfaz propia» para una herramienta que no es una librería, dónde encaja en el pipeline de revisión, y con qué criterio se revierte.

**Concreta**: `CLAUDE.md §1` (dependencias), `CLAUDE.md §2` (modelos y cuota), `CLAUDE.md §5` (severidades), `CLAUDE.md §9` (procedimiento de reversión probado), `CLAUDE.md §10` (definición de terminado), `RNF-MANT-007`

**Se apoya en**: `ADR-030` (el entorno de desarrollo es WSL2 en equipo personal y **no puede alojar datos reales bajo ningún concepto**), `ADR-037` (los secretos de producción no salen de un `.env` de desarrollo), `ADR-041` y `ADR-042` (precedente de comprobación de dependencia externa con datos y no de memoria), el issue [#150](https://github.com/pirexia/plataforma-educativa/issues/150) (un agente no actúa sobre trabajo ajeno a su encargo)

**Afecta a**: el **proceso de revisión** de todos los pasos siguientes de `PLAN-IMPLEMENTACION.md`, y a `.claude/settings.json`, que está versionado: cualquiera que clone el repositorio hereda esta configuración. **No afecta a ninguna línea de código entregado**: el plugin no entra en `composer.json` ni en `package.json`, no se despliega, no se ejecuta en producción y ningún artefacto del producto depende de él.

**No sustituye a ningún ADR anterior.** No toca `ADR-025`, `ADR-030` ni `ADR-037`. No cambia ninguna invariante ni ningún requisito de las secciones 1-17.

---

## 1 · Contexto

### 1.1 · La petición, y por qué llega ahora

El usuario quiere instalar `openai/codex-plugin-cc` para que Codex dé una **segunda opinión sobre código ya implementado por Claude Code**. El motivo declarado no es la calidad por sí sola: es que Codex corre contra la cuota de OpenAI y no contra el límite de 5 horas del plan Pro que `CLAUDE.md §2` administra como recurso escaso. En un proyecto de 53 módulos desarrollado en solitario, mover trabajo de revisión a una cuota distinta es un argumento serio, no un capricho.

Llega ahora porque el cierre de `1.6b` (PR [#204](https://github.com/pirexia/plataforma-educativa/pull/204)) dejó seis incidencias documentadas, varias de ellas encontradas **solo al ejecutar la suite por primera vez** tras tres cortes de cuota. Es el momento natural para preguntarse si un revisor más, y gratis en términos de cuota propia, habría adelantado alguna.

### 1.2 · Comprobación de `CLAUDE.md §1`, con datos verificados y no de memoria

Verificado el **2026-09-14** contra la API de GitHub (`api.github.com/repos/openai/codex-plugin-cc`), no recordado:

| Criterio | Dato |
|---|---|
| **Licencia** | **Apache-2.0** |
| **Propietario** | `openai`, cuenta oficial. Repositorio **no archivado** |
| ***Releases*** | **7**: `v1.0.0` (2026-03-30) … `v1.0.6` (**2026-07-08**) |
| **Último *push*** | **2026-07-08** — **68 días sin un solo *commit*** a día de hoy |
| **Estrellas** | 33.144 |
| ***Issues* abiertas** | **499** |
| **Requisitos de ejecución** | CLI local `@openai/codex` (`npm i -g`) + `codex login`. Node 18.18+. Usa la autenticación y la cuota que ese CLI ya tenga configuradas |

**Lo que estos datos dicen, sin adornarlos.** Siete *releases* en los primeros cien días y después dos meses de silencio no es un proyecto muerto, pero tampoco es el mantenimiento sostenido que `ADR-041` exigió a `pragmarx/google2fa`. Y **499 *issues* abiertas contra 33.000 estrellas describen una cola desatendida**: si aparece un fallo, la probabilidad de que alguien lo arregle pronto es baja.

Para una dependencia de producción eso sería motivo de cautela seria. Aquí **no lo es, y el motivo es el radio de daño, no la indulgencia**: esta pieza no se despliega, no toca datos, no entra en ningún artefacto entregado y se desinstala con dos órdenes (`§9`). El criterio de `CLAUDE.md §1` se aplica igual, pero se calibra contra lo que puede romper. Lo que no se puede hacer es fingir que los datos son mejores de lo que son, y por eso quedan escritos.

### 1.3 · Superficie del plugin, verificada en esta sesión

- **Comandos de solo lectura**: `/codex:review` (revisión estándar), `/codex:adversarial-review` (cuestiona decisiones de diseño), `/codex:status`, `/codex:result`, `/codex:cancel`, `/codex:setup`.
- **Comandos que escriben**: `/codex:rescue` (delega tareas a Codex, que **sí escribe en el árbol de trabajo**, con un subagente propio `codex-rescue`) y `/codex:transfer` (crea un hilo persistente de Codex desde la sesión).
- **Tres *hooks***: `SessionStart` y `SessionEnd` (contabilidad local) y **`Stop`**, que es la **puerta de revisión automática** y está verificado como **no-op por defecto**: solo se arma si se ejecuta `/codex:setup --enable-review-gate`. El propio README advierte de que, armada, «*can create extended Claude/Codex loops and rapidly consume usage limits*».
- **Configuración** vía `.codex/config.toml` (proyecto) o `~/.codex/config.toml` (usuario): modelo, esfuerzo de razonamiento, *sandbox* y modo de aprobación.

### 1.4 · Estado real del árbol de trabajo, que es lo que cambia la decisión

Verificado hoy sobre `develop` limpio. **Hay material sensible dentro del directorio que Codex tomaría como espacio de trabajo**:

- `/.env` — contiene, por su `.env.example`: `POSTGRES_PASSWORD`, `TENANCY_OWNER_PASSWORD`, `TENANCY_APP_PASSWORD`, `TENANCY_PLATFORM_PASSWORD`, `MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD`.
- `apps/api/.env` — `APP_KEY` (la clave con la que se cifran los `client_secret` por tenant de `ADR-043`), `DB_PASSWORD`, `DB_OWNER_PASSWORD`, `DB_PLATFORM_PASSWORD`, `REDIS_PASSWORD`, `MAIL_PASSWORD`, `AWS_SECRET_ACCESS_KEY`.
- `apps/web/.env`.
- `apps/api/storage/framework/testing/saml-fake-idp/{key,cert}.pem` — material de prueba de `1.4c`.
- **Copias completas de todo lo anterior en dos `.claude/worktrees/agent-*` abandonados**, que siguen en disco.

Todo ello está en `.gitignore` y **ninguna de esas rutas aparece jamás en un `git diff`**. Y precisamente por eso la lista `deny` de `.claude/settings.json` (`Read(./.env)`, `Read(./.env.*)`, `Read(./**/secrets/**)`, `Read(./**/*.pem)`, `Read(./**/*.key)`) existe: impide que esos contenidos entren en el contexto de una sesión de Claude Code.

**Esa lista no le aplica a Codex en absoluto.** Codex tiene su propio mecanismo, y no es equivalente. `§5.3` es la parte de este ADR que más importa.

### 1.5 · Por qué esto merece ADR y no un párrafo de configuración

Tres motivos, ninguno de estilo:

1. **Cambia el pipeline de revisión**, que es arquitectura de proceso escrita en `CLAUDE.md §4`, `§6` y `§10`, y que hoy descansa en tres revisores obligatorios. Añadir un cuarto opinante —sobre todo uno que **no ha leído ni un solo ADR de este proyecto**— sin decir por escrito qué autoridad tiene es la forma más rápida de que dentro de tres meses alguien cierre un hallazgo real porque «Codex dijo que estaba bien», o abra un issue contra `ADR-047` porque Codex propuso lo que ese ADR descartó.
2. **Introduce una salida de código fuente hacia un tercero** bajo una política de datos que, en la capa elegida, permite entrenar con el contenido. Eso tiene peso legal y de propiedad intelectual, y una decisión con ese peso no vive en un fichero de configuración sin motivo escrito.
3. **Queda versionado en `.claude/settings.json`**: el ámbito de instalación es `project`, luego esto no es una preferencia personal, es una decisión que hereda cualquiera que clone el repositorio.

---

## 2 · Decisiones del usuario que este ADR ratifica y **no** reabre

Tomadas por el usuario el 2026-09-14, antes de este ADR:

1. **Credencial**: capa gratuita de la suscripción ChatGPT, explícitamente **para medir si aporta valor** antes de decidir si merece la pena estudiar la política de datos con más cuidado. Se le advirtió de que, salvo desactivación expresa en su cuenta de OpenAI, el contenido puede usarse para entrenar modelos, y lo aceptó **para esta fase de prueba**. Queda ratificado, con el límite de `§5.6`: uso acotado, nunca producción, y termina en el instante en que exista un dato real cerca del árbol.
2. **Alcance de solo lectura**: permitidos `/codex:review`, `/codex:adversarial-review`, `/codex:status`, `/codex:result`, `/codex:cancel`. **Prohibidos `/codex:rescue` y `/codex:transfer`**, por el mismo riesgo que motivó el issue #150 —un agente externo escribiendo en el árbol con menos visibilidad que un subagente propio— agravado porque aquí ni siquiera hay *worktree* aislado como salvaguarda. **Puerta de revisión (`Stop`) desactivada.**
3. **Ámbito de instalación**: `project`.

Las tres son correctas y este ADR las asume como punto de partida. Lo que sigue es lo que faltaba decidir.

---

## 3 · Qué **no** decide este ADR

1. **No decide instalar.** Decide **con qué condiciones** puede instalarse, y `§14` enumera lo que se somete al usuario.
2. **No modifica `CLAUDE.md`, ni `.claude/settings.json`, ni crea la *skill* de uso, ni instala nada.** Eso es trabajo de la sesión orquestadora, posterior a la aceptación.
3. **No convierte a Codex en parte de la definición de terminado** (`CLAUDE.md §10`), ni hoy ni si la prueba sale bien: cambiar `§10` exigiría un ADR nuevo.
4. **No decide nada sobre revisión asistida en CI** ni sobre ninguna otra integración con terceros.
5. **No arregla nada de lo que encuentra fuera de su alcance.** `§15` lo reporta.

---

## 4 · Opciones reales

| # | Opción | En qué consiste |
|---|---|---|
| **A** | **No instalar** | Seguir con `db-reviewer`, `security-reviewer` y `doc-reviewer` |
| **B** | **Instalar con alcance completo** | Todos los comandos, incluidos `rescue` y `transfer`, y puerta de revisión armada |
| **C** | **Instalar acotado a solo lectura, sin puerta, con protocolo escrito y criterio de reversión** | La decisión de este ADR |
| **D** | **Usar el CLI de Codex a mano, fuera del repositorio**, copiando el *diff* a un directorio aparte | Sin plugin, sin *hooks*, sin nada versionado |

| | A · no instalar | B · completo | C · acotado | D · manual fuera del repo |
|---|---|---|---|---|
| **Coste de implementación en solitario** | Cero | Bajo de instalar, alto de vigilar: cada ejecución puede haber escrito | Bajo: instalar, un `.codex/config.toml`, una *skill* de protocolo | **Alto y recurrente**: copiar el *diff*, ejecutar, traer el resultado, a mano y en cada paso |
| **Mantenimiento a 3 años** | Nulo | La puerta automática convierte cada cierre en una negociación entre dos agentes | Un fichero de configuración y una *skill*. Se desinstala en dos órdenes | Un procedimiento manual que se deja de seguir a la tercera vez |
| **Impacto en las invariantes** | Ninguno | `INV-015`, `CLAUDE.md §10` y el pipeline de `§4` quedan supeditados al veredicto de un tercero que no conoce los ADR. Escritura de un agente externo sin aislamiento | Ninguno mientras el veredicto no tenga autoridad (`§7`) y no escriba (`§5.1`) | Ninguno |
| **Reversibilidad** | Trivial | Media: hay que revisar qué escribió | **Alta**: desinstalar y borrar dos ficheros (`§9`). Nada que auditar, porque nada escribió | Alta |
| **Exposición de código a un tercero** | Ninguna | Máxima: la puerta automática lo dispara sin que nadie lo pida | Acotada a ejecuciones explícitas | Acotada, pero la copia manual del árbol invita a errores peores que el plugin |

**B** se descarta por sí sola: la puerta de revisión convierte el veredicto de un revisor que no ha leído `ADR-033`, `ADR-044`, `ADR-045`, `ADR-046`, `ADR-047` ni `ADR-048` en condición de cierre, y el propio README advierte del bucle de consumo. **D** parece la más prudente y es la peor: un procedimiento manual repetitivo en un proyecto en solitario no se sigue, y la copia del árbol a un directorio aparte es justamente la operación en la que un `.env` acaba donde no debe.

---

## 5 · Decisión

**Opción C**, con las condiciones siguientes. Todas son vinculantes; ninguna es una recomendación.

### 5.1 · Alcance permitido, y cómo se hace cumplir

Permitidos: `/codex:review`, `/codex:adversarial-review`, `/codex:status`, `/codex:result`, `/codex:cancel`, `/codex:setup` (solo para comprobar instalación y estado).

Prohibidos: **`/codex:rescue`** y **`/codex:transfer`**.

**Corrección del 2026-09-14, verificada contra el código fuente del plugin y no solo contra su documentación**: esta sección afirmaba que el *sandbox* de solo lectura de `§5.2` impedía que `rescue` escribiera aunque se invocara por error. **Es falso, y queda corregido aquí en vez de mantenerse escrito mal.** `codex-companion.mjs:491` fija `sandbox: request.write ? "workspace-write" : "read-only"` **por invocación**, ignorando la configuración global de `§5.2`; y el propio agente `codex-rescue` (`agents/codex-rescue.md:34`) está instruido para añadir `--write` **por defecto**, salvo que se le pida expresamente lo contrario. Es decir: `/codex:rescue` invocado sin más **pasa a modo de escritura real**, con independencia de `sandbox_mode` en `.codex/config.toml`. **No existe barrera técnica que sustituya a la norma.** La única protección real es no invocarlo — la misma situación de cualquier subagente sin *worktree* aislado, sin la salvaguarda añadida que aquí se creyó tener. `§14` recoge esta corrección para el usuario.

Prohibido además que Codex abra *issues*, escriba *commits* o toque `docs/`. Su salida llega a las manos de la sesión orquestadora y de nadie más.

### 5.2 · Configuración de Codex: qué se fija y por qué

En `.codex/config.toml`, **versionado en git** (es una protección, no una preferencia):

```toml
sandbox_mode    = "read-only"
approval_policy = "never"
```

- **`sandbox_mode = "read-only"`** cierra **escritura** en disco y **red** desde los comandos que Codex ejecuta. En Linux lo impone el sistema operativo (Landlock + seccomp), no el modelo: aunque el modelo decida escribir, no puede.
- **`approval_policy = "never"`** cierra la **puerta de escalada**. La alternativa (`"untrusted"`, que pregunta antes de cada comando) parece más segura y es peor en la práctica: convierte la protección en una decisión del operador repetida decenas de veces, y una protección que depende de que el humano diga que no cuarenta veces seguidas acaba fallando a la cuadragésima. Con `never`, la escalada no se pide porque no existe. **Fallo en cerrado.**
- **`[sandbox_workspace_write]` no aplica** en modo solo lectura y **no se escribe**: dejarlo escrito invita a que alguien cambie `sandbox_mode` sin darse cuenta de que ya hay una sección que le da permisos.
- **El modelo y el esfuerzo de razonamiento se dejan en el valor por defecto del plugin y no se fijan aquí.** Fijar un nombre de modelo en un documento inmutable garantiza que el documento estará equivocado en seis meses. Lo que este ADR fija es lo que tiene consecuencias de seguridad; lo demás es configuración viva.

**Antes de la primera ejecución hay que comprobar la configuración *efectiva*, no el fichero.** Que Codex lea el `.codex/config.toml` de proyecto es lo que dice su documentación; que lo lea **esta** versión instalada en **esta** máquina es un hecho que se verifica, no se supone. Si la configuración efectiva no sale en solo lectura, el plugin no se usa hasta que salga.

**Verificado el 2026-09-14, y `§12.3` acertó**: el `.codex/config.toml` de proyecto **no se aplicaba** (`codex doctor` mostraba `approval policy: OnRequest`, no `Never`) — es un *bug* conocido y abierto de `openai/codex` (issue [#30001](https://github.com/openai/codex/issues/30001), "Repo-local .codex/config.toml sandbox_mode is ignored"). Réplica necesaria en `~/.codex/config.toml` (nivel de usuario, fuera del repositorio, no versionado): mismas dos claves. Con la réplica, `codex doctor` muestra `approval policy: Never`, y una comprobación de comportamiento real (`codex exec` pidiendo crear un fichero) confirma `sandbox: read-only` y el bloqueo efectivo (`Blocked by the sandbox: /tmp is mounted read-only`). **El fichero de proyecto se mantiene igualmente** (documentación e intención, y por si la versión instalada en otra máquina no tiene el *bug*), pero la protección real depende hoy de la réplica de usuario — anótese en cualquier máquina nueva donde se instale este proyecto.

### 5.3 · La lista `deny` de Claude Code **no tiene equivalente** en Codex, y hay que decirlo así

Esta es la pregunta que se me encargó y la respuesta honesta es incómoda: **el *sandbox* de Codex restringe escritura y red, no lectura.** No existe en su configuración ninguna clave que prohíba leer una ruta concreta. `Read(./.env)` **no se puede replicar**. Cualquier cosa que un comando pueda leer dentro del espacio de trabajo, Codex la puede leer y enviar.

Dado eso, la protección real es de tres capas, y la tercera no la da la herramienta:

**Capa 1 — la lista `deny` de `.claude/settings.json` se mantiene y gana una función nueva.** El *hook* `SessionStart` del plugin entrega a Codex la **ruta del transcript de la sesión**. Es decir: lo que sale hacia OpenAI **no está acotado al *diff***, sino a lo que la sesión de Claude Code tenga en su transcript. La lista `deny` es precisamente lo que impide que el contenido de un `.env` entre en ese transcript. **Deja de ser una protección local para pasar a ser el filtro de lo que sale de la máquina**, y por eso no se toca, no se relaja y no se le añaden excepciones.

**Capa 2 — el *sandbox* de `§5.2`**, que cierra escritura y red de los comandos. Necesaria, insuficiente para lo que aquí importa.

**Capa 3 — quitar el material del alcance, porque la herramienta no lo va a hacer.** Condición previa a la primera ejecución, verificable y asignada a la sesión orquestadora:

1. **Auditar los tres `.env` reales** (`/.env`, `apps/api/.env`, `apps/web/.env`) y confirmar que **ningún valor es una credencial válida fuera de esta máquina de desarrollo**: ni SMTP real, ni S3 real, ni `client_secret` de Google de `ADR-042`, ni nada que apunte a un servicio de terceros. Si alguno lo es, **se rota o se saca del árbol antes de instalar**, no después.
2. **Limpiar los `.claude/worktrees/agent-*` abandonados**, que hoy multiplican por tres las copias en disco de esos mismos secretos y de las claves de prueba de SAML (tarea de `janitor`, `§15`).
3. Dejado eso, la exposición residual son secretos de un entorno que **por `ADR-030` no puede contener datos reales bajo ningún concepto** y cuyas credenciales **por `ADR-037` no se reutilizan en el alojamiento**. Es una exposición real, acotada y proporcionada a una prueba de dos pasos. No es cero, y este ADR no finge que lo sea.

### 5.4 · La puerta de revisión (`Stop`) permanece desactivada, y eso es normativo

No se arma en ningún caso, y no se arma «para probar». Dos motivos independientes, cada uno suficiente:

1. **Consumo.** El propio README advierte del bucle Claude↔Codex y del agotamiento rápido de cuota. El motivo entero de instalar esto era aliviar una cuota, no inaugurar un mecanismo que quema dos.
2. **Autoridad.** Armada, convierte el veredicto de un tercero que no ha leído los ADR de este proyecto en condición de cierre. `CLAUDE.md §10` enumera lo que hace falta para dar algo por terminado, y el visto bueno de un revisor externo no está ahí. Añadirlo exige un ADR que sustituya a este, no una opción de `/codex:setup`.

Si alguna vez se encuentra armada, se desarma (`/codex:setup --disable-review-gate`) y se anota como incumplimiento de proceso, con el precedente del issue [#188](https://github.com/pirexia/plataforma-educativa/issues/188).

### 5.5 · Ámbito `project`: qué queda versionado y qué no

Se ratifica `project`. Queda versionado: la entrada del *marketplace* y del plugin en `.claude/settings.json`, el `.codex/config.toml` de `§5.2` y la *skill* de protocolo de `§6`. **No** se versiona nada relacionado con la credencial: la autenticación vive en el CLI de Codex del usuario (`codex login`), fuera del repositorio, y `CLAUDE.md §8` sigue aplicando sin matices.

**Comprobar antes de la primera ejecución si el plugin escribe estado de trabajos dentro del repositorio.** Si lo hace, esa ruta entra en `.gitignore` **antes**, no después de que aparezca en un `git status`.

### 5.6 · La prueba caduca sola cuando aparezca un dato real

Cláusula de fallo en cerrado que no cuesta nada: **en el momento en que exista un dato personal real en esta máquina o en este repositorio —cierre de `OPEN-11`, llegada del centro piloto, cualquier exportación—, la prueba termina y el plugin se desinstala**, con independencia de lo bien que esté saliendo. Reanudarla exigiría un ADR nuevo y una revisión seria de la política de datos con la capa de pago o con la desactivación de entrenamiento. Los datos de prueba de `REQ-SEED-005` son ficticios y no cuentan a estos efectos.

---

## 6 · Qué significa «envolver tras interfaz propia» aquí (`RNF-MANT-007`), zanjado

`CLAUDE.md §1` y `RNF-MANT-007` exigen envolver toda dependencia externa tras una interfaz propia. Aplicado literalmente a esto, sale un absurdo: una interfaz PHP o TypeScript para una herramienta que **ningún código del producto invoca**, que se llama con un *slash command* desde una sesión interactiva y que jamás se despliega.

**Queda decidido, para que no se discuta después:** la interfaz propia de una herramienta de este tipo es **el protocolo de uso escrito**, no código. Concretamente, una *skill* del proyecto que fije:

1. **Cuándo se invoca**: después de que `implementer` termine, con la suite en verde y el trabajo commiteado (`§7`).
2. **Qué se le da**: un rango de *diff* con nombre, acotado al paso, y el contexto mínimo necesario. Nunca el árbol entero «a ver qué encuentra».
3. **Qué no se le da nunca**: `.env`, `storage/`, volcados, nada bajo `secrets/`, y ningún fichero que la lista `deny` de `.claude/settings.json` proteja.
4. **Cómo se clasifica el resultado**: el triaje de `§7.2`.
5. **Qué comandos están prohibidos** (`§5.1`).

Esa *skill* es el envoltorio, y cumple la misma función que `MfaVerifier` cumple para `google2fa` en `ADR-041`: que el día que esto se sustituya o se retire, haya **un solo sitio** que cambiar y un procedimiento que ya sabe cuál era el contrato. **`RNF-MANT-007` queda satisfecho por ahí y no exige código.**

**Regla general derivada, aplicable a cualquier herramienta futura de este tipo:** una dependencia de *herramienta de desarrollo* —la que no entra en ningún manifiesto del producto ni se despliega— se envuelve en un **protocolo documentado**, no en una interfaz de código; y se somete igualmente a la comprobación de licencia, mantenimiento y *releases* de `CLAUDE.md §1`, calibrada contra su radio de daño.

---

## 7 · Sitio exacto en el pipeline de revisión. Esto es norma, no sugerencia

### 7.1 · Cuándo

**Después** de que `implementer` haya terminado su trabajo, con la suite ejecutada y en verde y los cambios commiteados. **Antes o en paralelo** a `db-reviewer`, `security-reviewer` y `doc-reviewer`. **Nunca en lugar de ninguno de los tres.**

Que el trabajo esté commiteado no es formalismo: garantiza que la entrada de Codex es un *diff* con nombre y que cualquier alteración del árbol sería visible de inmediato, aunque `§5.2` ya la impida.

**Los tres revisores siguen siendo obligatorios en todos los casos en que hoy lo son** (`CLAUDE.md §4`, `§6.2`, `§6.7`). Ni uno solo de ellos se salta porque Codex haya mirado antes. Si en algún momento se plantea lo contrario, la respuesta ya está escrita aquí y es que no.

### 7.2 · Qué autoridad tiene el veredicto: ninguna de bloqueo

**Un hallazgo de Codex es un candidato, no un veredicto.** La sesión orquestadora lo contrasta contra los requisitos, los ADR y la especificación del paso, y entonces:

- **Aceptado** → entra por la tabla de severidad de `CLAUDE.md §5` **exactamente igual que cualquier otro hallazgo**, con su issue, su reproducción, sus ficheros y su propuesta. A partir de ahí es un issue del proyecto, no «un issue de Codex».
- **Rechazado** → se anota **en una línea, con el motivo**, en la nota de cierre del paso. Esto no es burocracia: sin ese registro, el mismo hallazgo rechazado vuelve en cada ejecución y nadie recuerda por qué se descartó la vez anterior.

**Y el caso que importa de verdad:** Codex **no ha leído ni un solo ADR de este proyecto**. Un hallazgo suyo que contradiga un ADR vigente **no es un hallazgo, es ruido**, y se cierra citando el ADR. Se puede anticipar qué va a proponer: mover `ProvisionTenantDefaults` a `Infrastructure` con sufijo `Eloquent*` (`ADR-048 §4.4` lo descartó por escrito), renombrar `affected_tenant_id` a `tenant_id` (`ADR-047` lo prohíbe y rompería el *build*), sustituir el contrato síncrono por un evento (`ADR-048 §3.1`), o añadir listas de exenciones a los tests de esquema (`ADR-047` lo prohíbe expresamente). **Ninguna de esas propuestas se acepta, se discute ni se «evalúa con mente abierta»: se cierran citando el ADR.** Si el usuario quisiera reabrir una de esas decisiones, el camino es un ADR nuevo (`CLAUDE.md §11`), y desde luego no la sugerencia de una herramienta que no sabe que la decisión existe.

`CLAUDE.md §0` sigue mandando en los dos sentidos: Claude Code tampoco acepta un hallazgo de Codex porque venga de Codex.

---

## 8 · Criterio de éxito y de fracaso de la prueba

Sin un umbral escrito de antemano, la evaluación se resuelve por la impresión del día. Este es concreto y verificable.

### 8.1 · Ejecución de calibrado, sobre respuestas conocidas

Se ejecuta `/codex:review` y `/codex:adversarial-review` sobre el *diff* de **PR [#204](https://github.com/pirexia/plataforma-educativa/pull/204)** (`1.6b`), **sin darle la lista de incidencias**. Los hallazgos reales de ese cierre ya están documentados con su severidad:

| Issue | Hallazgo | ¿Encontrable solo desde el *diff*? |
|---|---|---|
| [#200](https://github.com/pirexia/plataforma-educativa/issues/200) | Falta índice parcial en `tenants(status, grace_period_ends_at)` | **Sí** |
| [#201](https://github.com/pirexia/plataforma-educativa/issues/201) | `CHECK` sobre `admin_action_logs`/`dual_authorizations` sin `NOT VALID` + `VALIDATE CONSTRAINT` | **Sí** |
| [#202](https://github.com/pirexia/plataforma-educativa/issues/202) | Un tenant `eliminado` puede cambiar de nombre y de *slug* | **Sí** |
| [#203](https://github.com/pirexia/plataforma-educativa/issues/203) | `PurgeUnlockTokens` sin `TenantContext::runFor()` | **Sí** |
| [#196](https://github.com/pirexia/plataforma-educativa/issues/196) | Bugs expuestos al ejecutar la suite | **No** — requiere ejecutarla |
| [#199](https://github.com/pirexia/plataforma-educativa/issues/199) | Agotamiento de conexiones en la suite completa | **No** — requiere ejecutarla |

Los dos últimos quedan **fuera del baremo por construcción**: en modo solo lectura y sin red, Codex no puede levantar la base de datos ni ejecutar Pest. Medirle por lo que no puede hacer sería tramposo. El baremo son los **cuatro** primeros.

### 8.2 · Umbral

La prueba se considera **superada** si, en el calibrado y en los **dos pasos siguientes** que se cierren tras la instalación, se cumplen las tres a la vez:

1. **Cobertura**: reproduce **al menos 2 de los 4** hallazgos encontrables desde el *diff* de `1.6b`.
2. **Precisión**: como máximo **un hallazgo descartado por cada hallazgo aceptado** en el triaje de `§7.2`. Cuentan como descartados los erróneos, los ya decididos por un ADR y los fuera de alcance. Un revisor que obliga a leer diez cosas para encontrar una **cuesta cuota de la que sí es escasa**: la atención del usuario.
3. **Aportación diferencial**: al menos **un hallazgo aceptado** en los dos pasos de prueba que **ninguno** de los tres revisores existentes hubiera producido. Si todo lo que encuentra ya lo encontraban `db-reviewer`, `security-reviewer` y `doc-reviewer`, no aporta valor: aporta una segunda copia del mismo valor, pagada con exposición de código a un tercero.

El resultado de cada ejecución —hallazgos propuestos, aceptados, descartados y el motivo— se anota en `memory.md` en el cierre del paso correspondiente. **Sin ese registro no hay evaluación posible**, por el mismo motivo que `CLAUDE.md §3` obliga a dejar constancia de lo verificado.

### 8.3 · Fracaso

Se revierte por `§9`, sin discusión, si ocurre cualquiera de estas:

1. No se cumple alguno de los tres umbrales de `§8.2` al cerrar el segundo paso de prueba.
2. Se detecta **una sola vez** que el plugin ha escrito en el árbol de trabajo, o que se ha ejecutado `/codex:rescue`, `/codex:transfer` o la puerta de revisión.
3. Aparece un dato real en el ámbito de `§5.6`.
4. El proyecto queda archivado, cambia de licencia, o aparece un aviso de seguridad sin corregir.

**El resultado por defecto es revertir.** Si al cerrar el segundo paso de prueba nadie ha escrito la evaluación, se desinstala: una herramienta de la que no se sabe si aporta no aporta.

---

## 9 · Procedimiento de reversión

`CLAUDE.md §9` exige que toda entrega incluya su procedimiento de reversión **probado**. Esto no es infraestructura de despliegue, pero el principio de higiene es el mismo y aquí sale barato:

1. `/codex:setup --disable-review-gate` — primero, por si estuviera armada, aunque no deba estarlo.
2. Desinstalar el plugin y retirar el *marketplace* (`/plugin` en sesión, o `claude plugin uninstall` / `claude plugin marketplace remove` desde el CLI; **la forma exacta se comprueba contra la versión instalada, no se copia de aquí de memoria**).
3. Comprobar que las entradas correspondientes han desaparecido de `.claude/settings.json` y **que la lista `permissions` ha quedado exactamente como estaba**. Se verifica con `git diff`, no a ojo.
4. Borrar `.codex/config.toml` del repositorio.
5. Borrar la *skill* de protocolo de `§6` y el párrafo que `CLAUDE.md` hubiera ganado.
6. Borrar cualquier estado local de trabajos que el plugin haya dejado (ruta identificada en el paso previo de `§5.5`), y la entrada de `.gitignore` que se le hubiera añadido.
7. Opcional y aparte: `codex logout` y desinstalar el CLI global, que **no** son del repositorio y no forman parte de esta reversión.

**La reversión se da por probada cuando se ejecuta y `git status` queda limpio salvo por el *commit* que la registra.** Se ejecuta de verdad si la prueba fracasa; no se simula.

Que la reversión sea esto —seis órdenes y ningún dato que migrar— es el argumento central de `§10`.

---

## 10 · Motivo

Tres frases:

1. **La asimetría entre coste y riesgo es enorme, y está del lado bueno.** El coste de equivocarse es desinstalar un plugin que no escribió nada, en un entorno que por `ADR-030` no puede contener datos reales. El beneficio posible es un revisor más sobre una cuota que no es la escasa. `CLAUDE.md §0` obliga a decir que no cuando la complejidad no es proporcional al beneficio; aquí la complejidad añadida es un fichero de configuración, una *skill* y un umbral de evaluación, y se retira en una tarde.
2. **Lo que había que decidir de verdad no era instalar o no, sino la autoridad.** Un revisor que no conoce los ADR de un proyecto con 48 decisiones escritas va a proponer, con confianza y buena prosa, exactamente lo que varias de ellas descartaron. `§7.2` cierra esa puerta antes de que se abra, y es la parte de este ADR que seguirá valiendo aunque la herramienta se sustituya por otra.
3. **La protección real no la da la herramienta, y había que descubrirlo antes y no después.** El *sandbox* de Codex no restringe lectura: la lista `deny` de Claude Code no se puede replicar. Lo que protege los secretos es que no entren en el transcript y que no haya credenciales de valor en el árbol, y eso se verifica una vez, a mano, antes de la primera ejecución (`§5.3`).

---

## 11 · Consecuencias

**Buenas:**

- Una revisión adicional por paso sin consumir la cuota de 5 horas del plan Pro.
- Una revisión **adversarial** —`/codex:adversarial-review` cuestiona decisiones de diseño— que hoy no existe en el pipeline: los tres revisores actuales comprueban listas, no discuten el diseño.
- Queda escrito, y por tanto zanjado, qué significa `RNF-MANT-007` para una herramienta de desarrollo (`§6`), que volverá a hacer falta.
- La lista `deny` de `.claude/settings.json` queda documentada como **filtro de salida** y no solo como higiene local, lo que sube su categoría y la protege de futuras relajaciones.

**Costes, sin adornos:**

- **Sale código fuente hacia un tercero**, bajo una capa gratuita en la que, salvo desactivación, el contenido puede usarse para entrenar. El usuario lo aceptó para esta fase (`§2.1`) y `§5.6` le pone caducidad.
- **Existe exposición residual de secretos de desarrollo** que la herramienta no puede impedir (`§5.3`). Se acota, no se elimina.
- **Lo que sale no está acotado al *diff***, sino al transcript de la sesión (`§5.3` capa 1). Es el punto que más conviene verificar en el código antes de la primera ejecución (`§14`).
- El triaje de `§7.2` es **trabajo nuevo** en cada paso. Si el ruido es alto, cuesta más de lo que ahorra, y por eso `§8.2` lo mide en vez de suponerlo.
- El proyecto lleva **68 días sin *commits* y tiene 499 *issues* abiertas** (`§1.2`). Se acepta por el radio de daño, no porque el dato sea bueno.

---

## 12 · Riesgo residual

1. **La autoridad se cuela por la costumbre.** Nadie va a armar la puerta de revisión; lo que sí puede pasar, tras diez pasos, es que un hallazgo de Codex se acepte sin contrastarlo contra el ADR que lo contradice, porque venía bien argumentado. La contención es `§7.2` y la obligación de anotar los descartes con su motivo.
2. **La configuración de `§5.2` se puede cambiar en un momento de prisa**, y `sandbox_mode = "workspace-write"` está a una palabra de distancia. Contención: el fichero está versionado, luego el cambio aparece en `git diff` y en la revisión.
3. **Materializado y corregido el 2026-09-14**: `.codex/config.toml` de proyecto no lo leía la versión instalada del CLI (*bug* conocido, issue #30001 de `openai/codex`), dejando la protección en el papel. Contención aplicada: réplica en `~/.codex/config.toml`, verificada por comportamiento (`§5.2`). Riesgo residual: una actualización del CLI podría corregir el *bug* y hacer que las dos copias diverjan sin que nadie lo note — revisar ambos ficheros si `codex --version` cambia.
4. **La cola de 499 *issues*** significa que, si aparece un fallo del plugin, la solución es desinstalar, no esperar. `§9` está escrito para que eso sea barato.
5. **`--help` no es seguro con `codex-companion.mjs`, verificado en vivo el 2026-09-14.** El *parser* de argumentos de `adversarial-review`/`review`/`task` no distingue `--help` de texto de enfoque: pedir ayuda **ejecuta una revisión real** contra el estado actual del árbol, sin confirmación. Ocurrió sin querer durante la propia diligencia de esta sesión (una llamada a `--help` para inspeccionar opciones disparó una revisión completa del *diff* de trabajo en curso, ninguno de cuyos ficheros era sensible por casualidad, no por diseño). **Norma nueva**: nunca invocar estos comandos con `--help` ni con ninguna sintaxis exploratoria; usar solo la sintaxis validada de la *skill* `revision-con-codex`. Toda invocación de Codex, sin excepción, ya es una salida real de contenido hacia un tercero.

---

## 13 · Alternativas descartadas y por qué

| Alternativa | Por qué no |
|---|---|
| **No instalar** (opción A) | Renuncia a una revisión adicional sobre otra cuota, con un coste de reversión que es de una tarde. La prudencia no está en negarse, está en acotar y medir |
| **Instalar con alcance completo y puerta de revisión** (opción B) | Entrega un veto de cierre a un revisor que no ha leído ningún ADR del proyecto, contra `CLAUDE.md §10`, y arma un bucle que el propio README señala como devorador de cuota |
| **Usar el CLI a mano fuera del repositorio** (opción D) | Procedimiento manual repetitivo que no se sigue, y la copia del árbol a otro directorio es la operación que más fácilmente acaba moviendo un `.env` donde no debe |
| **Permitir `/codex:rescue` «solo en ramas de prueba»** | La salvaguarda que hace tolerable a un subagente propio es el aislamiento, y aquí no hay *worktree* aislado. Es el escenario exacto del issue #150, con menos visibilidad |
| **`approval_policy = "untrusted"` en vez de `"never"`** | Convierte la protección en una decisión humana repetida decenas de veces. La que falla es la número cuarenta (`§5.2`) |
| **Replicar la lista `deny` en Codex** | **No es posible**: su *sandbox* restringe escritura y red, no lectura, y no hay clave de exclusión por ruta (`§5.3`). Escribirlo como si existiera sería la peor salida de todas: protección de papel |
| **Escribir un envoltorio de código (`CodexReviewer`) para cumplir `RNF-MANT-007`** | Una interfaz en un lenguaje que nadie invoca, para una herramienta que ningún artefacto del producto usa. Complejidad sin beneficio proporcional; el envoltorio correcto es el protocolo (`§6`) |
| **Añadir el visto bueno de Codex a `CLAUDE.md §10`** | Supeditaría la definición de terminado a un tercero. Exigiría un ADR que sustituya a este, y no está sobre la mesa (`§3.3`) |
| **Sustituir alguno de los tres revisores por Codex** | Los tres conocen los ADR, las invariantes y las *skills* del proyecto; Codex no conoce ninguno. No es la misma función (`§7.1`) |
| **Fijar modelo y esfuerzo de razonamiento en este ADR** | Un ADR es inmutable y los nombres de modelo caducan en meses. Se fija lo que tiene consecuencias de seguridad y nada más (`§5.2`) |
| **Evaluar «por sensación» al cabo de unos pasos** | Sin umbral escrito de antemano, el resultado lo decide el día que se pregunte. `§8.2` lo fija antes de empezar |

---

## 14 · Qué se le pide exactamente al usuario

El mecanismo, el alcance y las condiciones (`§5`-`§9`) son decisión de `architect` y están tomadas. Lo que se somete al usuario es:

1. **Aceptar este ADR** con sus condiciones vinculantes, en particular `§5.3` (lo que la herramienta **no** puede proteger y la auditoría previa de los `.env`), `§5.6` (la prueba caduca sola al aparecer un dato real) y `§8` (el umbral y que **el resultado por defecto es revertir**).
2. **Encargar dos verificaciones previas a la primera ejecución**, ambas condición y no recomendación:
   - **Leer el código del *hook* `SessionStart`** y confirmar qué hace exactamente con la ruta del transcript. De ahí depende si lo que sale es el *diff* o la conversación entera (`§5.3`, capa 1).
   - **Auditar los tres `.env`** en busca de credenciales válidas fuera de esta máquina, y limpiar los `.claude/worktrees/agent-*` abandonados.
3. **Confirmar que la evaluación de `§8` se escribe en `memory.md`** en cada uno de los dos pasos de prueba. Sin ese registro, la decisión de mantener o revertir no tendrá con qué tomarse.

Lo que **no** se le pide: cambiar `CLAUDE.md §10`, relajar la lista `deny`, ni aprobar `rescue`, `transfer` o la puerta de revisión. Nada de eso entra.

---

## 15 · Hallazgos fuera del alcance de este ADR, reportados y no corregidos

Se reportan y **no se tocan** (`CLAUDE.md §5`, issue #150):

1. **Resuelto al aceptar este ADR (2026-09-14)**: los dos `.claude/worktrees/agent-*` abandonados (`agent-ae81c1cb6632f998d/`, `agent-a0f374e5af72d1187/`), con copias completas del árbol incluidos los tres `.env` y las claves de prueba `saml-fake-idp/{key,cert}.pem`, se borraron con autorización explícita del usuario tras comprobar que no contenían trabajo único (`diff -rq` contra el estado actual, mismo procedimiento que el precedente de `1.6`).
2. **`SECURITY.md` y `PRIVACY.md` no contemplan la salida de código fuente hacia una herramienta de terceros** en el entorno de desarrollo (severidad **Baja**). Si este ADR se acepta, `doc-reviewer` debería contrastarlo en el siguiente cierre de fase, por la regla 7 de `CLAUDE.md §6`. **No los toca este ADR**: están fuera de su ámbito de escritura.
3. **`CLAUDE.md §1` no distingue entre dependencia del producto y herramienta de desarrollo** (severidad **Baja**). `§6` de este ADR establece la distinción y la regla derivada, pero el texto de `CLAUDE.md` sigue exigiendo literalmente «interfaz propia» a las dos. Corresponde a la sesión orquestadora decidir si lo refleja al implementar este ADR; **no lo edita `architect`**.
4. **El plugin ya está instalado en el momento de escribirse este ADR** (severidad **Media**, como incumplimiento de proceso). Verificado sobre `.claude/settings.json`, que al arrancar la sesión estaba limpio y ahora contiene `"enabledPlugins": {"codex@openai-codex": true}` y la entrada `extraKnownMarketplaces` de `openai/codex-plugin-cc`. Es decir: **la instalación se ha adelantado a la aceptación de la decisión que la autoriza**, y en particular a las dos verificaciones que `§14.2` pone como condición previa —leer el *hook* `SessionStart` y auditar los tres `.env`—, ninguna de las cuales consta hecha. `architect` **no lo revierte** (`CLAUDE.md §5`, issue #150: no se actúa sobre trabajo ajeno al encargo, y este ADR no escribe fuera de `docs/adr/` y de la sección 18). Lo que corresponde, y queda dicho aquí para que no se pierda: **no ejecutar ningún comando `/codex:*` hasta que las dos verificaciones estén hechas**; si el ADR no se acepta, aplicar `§9` completo. El mismo fichero ha quedado además con sus claves reordenadas (`$comment` desplazado, `ask` movido detrás de `deny`) por la escritura automática del instalador: **el contenido de `permissions` se conserva íntegro y la lista `deny` está intacta**, comprobado con `git diff`, pero conviene saber que ese fichero lo reescribe la herramienta y no sobrevive al formato original. Precedente del mismo tipo: issue [#188](https://github.com/pirexia/plataforma-educativa/issues/188).
