# Normas de `CLAUDE.md`: texto íntegro desplazado por el recorte

> **Numeración**: los puntos de «Ahorro de cuota» que citan otros documentos (`puntos 1 y 7`, `punto 9`) son los de la skill `ahorro-de-cuota`, que conserva la numeración original; `CLAUDE.md §2` los resume con otra.
>
> Movido desde `CLAUDE.md` 2.15.0 el 2026-11-17 (recorte a 2.16.0, issue #426). `CLAUDE.md` conserva la regla y remite aquí por ancla. Si este texto y `CLAUDE.md` se contradicen, prevalece `CLAUDE.md`: es un fallo de documentación (`CLAUDE.md §6`, regla 6).

## stack-colas

**Hoy**: *driver* `database` de Laravel — `QUEUE_CONNECTION=database`, tablas `jobs` y `failed_jobs`, consumidas por `php artisan queue:work` en **dos procesos** (perfil `worker` de `compose.yaml`): `worker` (conexión `database`, `infra/quadlet/worker.container`; issue #128, resuelto) y `worker-long` (cola `long`, conexión `database_long`, `retry_after` 900 s, `infra/quadlet/worker-long.container`; `1.12b`, ratificado por el usuario el 2026-10-21 porque el `retry_after` es de la conexión con la que se lee la cola). **Elegido y no instalado**: Redis como cola + **Laravel Horizon**. Horizon **no está en `composer.json`** y no hay `config/horizon.php`; adoptarlo es una dependencia nueva sujeta a la regla de dependencias de `CLAUDE.md §1` y a `OPEN-BO-23` (`docs/modulos/REQ-BO/funcional.md §14`).

## stack-pdf

Servicio contenerizado de renderizado HTML→PDF. **Elegido y no instalado**: WeasyPrint tras la interfaz propia `PdfRenderer` (`ADR-078`, `ACEPTADA` el 2026-11-14; decisión del usuario), con Gotenberg como vuelta atrás; no hay servicio ni código (paso `P` del plan).

## stack-contenedores

**Podman**. Desarrollo en **WSL2** con perfil reducido (`ADR-030`); `compose.yaml` estándar con `podman compose`; Quadlet/systemd en producción. Kubernetes a partir de 3-5 centros. **Nivel 1 implementado el 2026-11-17** (`0.10e-b`, `ADR-080 §5`): `CONTAINER_RUNTIME` (`podman` por defecto; `docker` admitido, sin verificar salvo `init.sh` en el CI; alias de respaldo `CONTAINER_CLI`, que se retirará en una entrega posterior) resuelta y validada con lista cerrada por `infra/lib/container-runtime.sh` en los guiones de E2E, `landing-shots.sh`, `infra/garage/init.sh` y `curso-rollover.spec.ts`; Quadlet es la referencia de producción. Fuera de alcance por decisión del usuario: el gancho `format-on-edit.sh`, `.mcp.json`, los permisos de `.claude/settings.json` y `compose.prodlike.yaml`. `ADR-091` (`PROPUESTA`) propone que el CI ejecute `docker` para el E2E real.

## codex

**Revisión con Codex (`ADR-049`/`ADR-050`/`ADR-069`)**: plugin `openai/codex-plugin-cc`, protocolo en la skill `revision-con-codex`. Con `ADR-069` Codex revisa **primero** y es obligatorio por el proceso en todo paso con código (no entra en §10); lo lanza la sesión por `Bash` solo con `review` y `adversarial-review`; `rescue` prohibido; sin autoridad de bloqueo; un hallazgo contra un ADR vigente se cierra citándolo. **`OPEN-069-01` confirmada por el usuario (2026-11-02; entrenamiento desactivado en su cuenta de OpenAI): régimen de `ADR-069` activo.** Si dejara de estarlo, no se lanza Codex sobre código del proyecto y rige `ADR-050` (opcional): el interruptor está en la skill. Condiciones de retirada de `ADR-050 §5.3` siempre armadas. Motivo: `docs/proceso/casos-reales.md#codex-prueba-cerrada`.

## restauracion

**Restauración de la configuración del 08/10/2026** (etiqueta `config-claude-2026-10-08`, commit `0ecd3a2`): si el usuario la pide, procedimiento en `docs/proceso/restauracion-configuracion.md`. `PLAN-IMPLEMENTACION.md` no se restaura entero; la etiqueta no se mueve ni se borra.

## relanzamiento

**Relanzar un subagente de ejecución (`implementer` u otro) tras un fallo o un corte por cuota no es licencia para decidir alcance.** No recorta, amplía, fusiona ni reinterpreta lo que la especificación aprobada dice, ni siquiera para «simplificar» algo redundante o acelerar el cierre. Sigue la especificación al pie de la letra, como si empezara de cero; si lo que queda no está claro o parece contradecirla, para y repórtalo (sección 0). Quien relanza (la sesión orquestadora) contrasta lo entregado contra la especificación antes de darlo por bueno. Motivo: `docs/proceso/casos-reales.md#relanzamiento-recorta-alcance`.

**Todo relanzamiento tras un corte de cuota deja constancia de lo ya verificado** (cualquier subagente de ejecución, todo el proyecto). Motivo: `docs/proceso/casos-reales.md#cortes-cuota-reverificacion`.

1. Al confirmar la suite en verde para un lote de commits, el mensaje del último commit del lote lo dice con el número real (`Verificado: X/X Pest en verde tras este commit`), nunca aproximado ni supuesto: se ejecuta, no se estima.
2. Mientras el paso esté en curso, `memory.md` mantiene una **única nota viva por paso** en «Trabajo en curso» (se sobrescribe, no se acumula) con el hash hasta el que está verificado, la fecha y qué queda con certeza por hacer.

Un relanzamiento que encuentra esa nota confía en los commits que cubre sin reejecutar la suite, y solo verifica lo no commiteado o posterior a la nota. Esto **no** afloja la norma anterior: ahorra reverificación, no habilita recortar, ampliar ni reinterpretar alcance.

## suite

**Cuándo se ejecuta la suite (`ADR-060 §4.4`).** Durante el trabajo, solo los ficheros o directorios afectados (`php -d memory_limit=-1 vendor/bin/pest <rutas>`). La suite completa (`composer test`: Unit y Feature en paralelo, una base `plataforma_test_N` por proceso, y `Concurrency` en serie) se ejecuta **una vez en local antes de abrir el PR**, y CI la repite en cada *push*. Los revisores no la relanzan salvo necesidad justificada en su informe. El mensaje `Verificado` dice su alcance, `(suite completa)` o `(ficheros afectados: …)`, y un relanzamiento solo da por buena la suite completa con un `Verificado` de suite completa. Las bases por proceso se crean con el script de `SYSADMIN.md`; `composer test:serie` queda como vuelta atrás.

## e2e

**E2E contra la API real (norma del usuario, 2026-11-02).** Con el paso implementado y su suite en verde, se prueba además **de extremo a extremo contra la API real** en ejecución (Playwright o HTTP real con cookie de sesión y CSRF, datos de `REQ-SEED`), no solo con cliente de pruebas ni API simulada. Se permite **recrear el demo o la base** cuantas veces haga falta, sin pedir permiso. **Quién lo ejecuta (2026-11-06; 2026-11-17):** la sesión orquestadora con la skill `espera-e2e` (script en segundo plano, resumen corto, sin agente: un agente cuesta decenas de miles de tokens por ejecución); `verificador` (Haiku) solo si hay que diagnosticar. Devuelve el resultado literal; si falla por código, la sesión reencarga a `implementer` solo el fallo; si falla por entorno (código 2, pila caída, S3), lo arregla la sesión. Un E2E parcial no se declara verde. El resultado (qué se probó y qué no, p. ej. el S3 real del proveedor, `ADR-076 §2.3`) se registra en `memory.md` y en el informe del paso.

**Los E2E deben existir, no solo ejecutarse (norma del usuario, 2026-11-17).** Toda implementación deja, en la misma entrega, el E2E real que asegura su funcionamiento, igual que exigimos tests unitarios y de integración (`INV-015`). Motivo: la subida a `vue-router` 5 (PR #729) cambió el comportamiento de scroll y los 8 E2E reales pasaron en verde sin ejercitarlo; solo un spec escrito a propósito lo encontró (issue #737).

- **Qué se exige.** Funcionalidad con flujo de usuario (pantalla, formulario, tabla, acción): al menos un E2E real (Playwright contra la API real) que referencie el ID del requisito o criterio y ejercite lo que el usuario hace (menús, botones, tablas con orden/filtro/paginación y estado en URL, formularios con validación de servidor, scroll, idioma, según aplique). Funcionalidad solo de backend: una prueba HTTP real con cookie de sesión y CSRF sobre datos de `REQ-SEED`.
- **Fuera de la obligación:** documentación, refactors sin cambio de comportamiento y subidas de dependencias. Si una subida de dependencia o un refactor **altera un comportamiento visible o de API**, sí exige su E2E antes de mezclar.
- **Un E2E nuevo debe demostrar que detecta el fallo**: se ejecuta también con la funcionalidad rota (o el cambio comentado) y debe fallar; se anota en el informe. Un E2E que solo pasa no prueba nada.
- **La especificación lo lista de antemano** (`spec-writer`: qué E2E cubre cada criterio de aceptación) y los prompts de `implementer` lo incluyen; `doc-reviewer` y la sesión comprueban en el diff que el spec existe y está registrado en el script de E2E real. Un paso sin su E2E no se da por terminado (`§10`).
- **Excepción justificada:** si un E2E real no es viable (p. ej. depende de un servicio externo que `ADR-076 §2.3` deja fuera), se dice en el informe, se cubre con el mejor sustituto (HTTP real parcial, mock del límite) y se abre issue de seguimiento.

## git-promociones

**Promociones** (`ADR-080 §4.3`): `staging` y `main` solo avanzan por **avance rápido** de un commit que ya está en `develop` (`staging`) o en `staging` (`main`), con `git push origin <sha de 40 hex>:refs/heads/<rama>`, **sin *squash*, sin `--force` y sin el botón de mezclar** de GitHub. La promoción se hace desde **HEAD desacoplado** (o un *worktree*), nunca con una rama protegida como actual: el gancho lo exige y no se relaja. `main` solo recibe lo que ya está en `staging`, etiquetado con versión semántica, y solo cuando el usuario lo pide.

**Arreglo urgente** (`ADR-080 §4.4`), solo si `develop` contiene algo que no puede ir aún a staging: rama `fix/…` desde `staging`, PR a `staging` con *merge commit* (nunca *squash*) y, en la misma sesión, PR `staging` → `develop` también con *merge commit*; hasta que se mezcle no hay más promociones. La reconciliación `main` → `develop` sigue la misma regla. Estos PR se mezclan a mano, sin borrar `staging` ni `main` (la rama `fix/…` de origen sí se borra como siempre).

**Gancho** `.claude/hooks/block-protected-branch.sh`: bloquea el commit en las tres ramas protegidas, el `push` desde ellas, cualquier `push` forzado (`--force`, `-f`, `--force-with-lease`, refspec con `+`) con destino protegido y todo destino `develop`, `staging` o `main` que no sea la forma `<sha de 40 hex>:refs/heads/<rama>`. Se prueba con `.claude/hooks/test-block-protected-branch.sh`.
