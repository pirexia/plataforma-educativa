# CLAUDE.md — Normas de trabajo del proyecto

> **Versión 2.1.2** · 2026-08-18 · Fichero de contexto permanente. Se carga en **todas** las sesiones. Contiene solo reglas estables.
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
| Caché y colas | Redis + Laravel Horizon |
| Almacenamiento | Compatible S3 (MinIO en desarrollo) |
| PDF | Servicio contenerizado de renderizado HTML→PDF |
| Tests | Pest (backend), Vitest + Playwright (frontend) |
| Contenedores | **Podman**. Desarrollo en **WSL2** con perfil reducido (`ADR-030`); `compose.yaml` estándar con `podman compose`; Quadlet/systemd en producción. Kubernetes a partir de 3-5 centros |
| Repositorio | Monorepo: `apps/api`, `apps/web`, `infra`, `docs` |

**Prohibido** introducir una dependencia nueva sin justificarla y sin comprobar mantenimiento activo, licencia y frecuencia de releases. Toda dependencia externa se envuelve tras una interfaz propia (`RNF-MANT-007`).

**Prohibido mezclar librerías de componentes UI.** shadcn-vue es el design system único.

---

## 2. Modelos y agentes

| Tarea | Modelo |
|-------|--------|
| Especificaciones, arquitectura, planificación, decisiones de diseño, revisión crítica | **Opus** |
| Implementación, refactor, tests, revisión de código, documentación | **Sonnet** |
| Tareas mecánicas: formateo, renombrados, búsquedas, listados, commits rutinarios | **Haiku** |

- Trabaja en **modo auto**.
- Cada subagente declara su modelo en su propia definición. No uses Opus en subagentes de ejecución.
- **Cuota**: el plan es Pro con límite de 5 horas. Opus la consume rápido. Reserva Opus para sesiones de spec y plan; no lo uses para picar código.
- Delega en subagentes todo lo que no necesite el contexto principal: exploración de código, lectura de documentación, revisiones. El contexto principal es un recurso escaso.

---

## 3. Protocolo de sesión

**Al arrancar cualquier sesión, sin que te lo pidan:**
1. Lee `memory.md` (estado actual, decisiones recientes, trabajo en curso).
2. Lee `PLAN-IMPLEMENTACION.md` y localiza el paso activo.
3. Comprueba la rama actual y si hay trabajo sin commitear.
4. Resume en 5 líneas dónde estamos y qué toca. Espera confirmación antes de escribir código.

**Durante la sesión:**
- Actualiza `memory.md` tras cada hito, no solo al final. Si la sesión se corta por límite de cuota, el estado debe estar guardado.
- Commits pequeños y frecuentes. Nunca acumules horas de trabajo sin commitear.

**Al cerrar sesión (o cuando avises de que queda poca cuota):**
1. Commit y push de todo lo funcional.
2. Actualiza `memory.md`: qué se completó, qué queda a medias, siguiente paso concreto, decisiones tomadas, problemas abiertos.
3. Actualiza `PLAN-IMPLEMENTACION.md` marcando el progreso.
4. Deja el repositorio en estado compilable y con tests en verde.

**Este cierre no espera a que lo pidas.** En cuanto el propio sistema avise de que la cuota se agota (aviso de "usage limit approaching" u otro equivalente), ejecútalo sin que haga falta que lo digas tú: termina el paso atómico en curso (no empieces uno nuevo), aplica los cuatro puntos anteriores, y programa un aviso para retomar cuando la cuota se restaure. La hora de reset **no está en tu contexto** (la app cliente la muestra en su propia interfaz, no como texto de sistema): pregúntasela al usuario, salvo que ya te la haya dado en la conversación. Mecanismo concreto en el skill `cierre-de-sesion`.

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
2. **Ninguna fase se cierra sin revisión completa de documentación**, ejecutada por el subagente `doc-reviewer`, que verifica coherencia entre requisito, modelo de datos, API implementada, permisos y manual de usuario.
3. Toda decisión arquitectónica relevante genera un **ADR numerado e inmutable**. Los ADR `001`-`027` son canónicos en la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`, que actúa de índice. **Los nuevos, del `028` en adelante, se escriben como fichero propio en `/docs/adr/` y se referencian desde esa sección.**
4. `SYSADMIN.md` y los manuales de usuario se actualizan **en cada fase**, no al final del proyecto.
5. La documentación de un módulo se escribe en su carpeta, nunca se acumula en un documento único: es inmanejable con 52 módulos y consume el contexto innecesariamente.
6. Si el código y la documentación se contradicen, es un issue de severidad media como mínimo.

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
