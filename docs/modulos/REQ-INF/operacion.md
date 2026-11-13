# REQ-INF · Operación

> **Estado**: **PROPUESTO** (2026-11-13, ola 1, agente B6), junto con `funcional.md`. Sin aprobar. Todas las cifras de configuración de §1 son **[DERIVADA]**, revisables, y ninguna procede de una norma: no se ha inventado ningún valor legal (ratio, plazos de retención).

## 1. Variables de entorno y configuración

**Ninguna variable nueva en `.env`.** Configuración del módulo en `config/inf.php` (valores por despliegue, no por centro):

| Clave | Valor propuesto | Para qué | Origen |
|-------|-----------------|----------|--------|
| `authorization_expiry_warning_days` | 60 | Umbral de `proxima_a_caducar` de la autorización administrativa (`RN-INF-10`) | [DERIVADA]: el documento dice «alertas» sin plazo |
| `read_rate_limit` | 600 lecturas auditadas por hora, por centro y usuario | Cupo de `RN-INF-30` (`api.md`, reglas comunes) | [DERIVADA]: el doble del de la foto de alumno (`alum.photo.read_rate_limit`, 300), porque un educador abre el día de su grupo muchas veces; revisar con la medición del piloto |
| `text.detail_max` | 500 caracteres | `detail` de una anotación | [DERIVADA] |
| `text.day_notes_max`, `text.evolution_max`, `text.notes_max` | 2.000 | Observaciones del día, evolución de adaptación, notas | [DERIVADA] |
| `text.observation_max`, `text.global_comment_max` | 4.000 | Observación por área y comentario global del informe | [DERIVADA] |
| `text.justification_max` | 1.000 | Justificación y anulación de excepciones de ratio | [DERIVADA] |
| `bulk.max_entries` | 10 | Anotaciones por registro masivo (`api.md §2`) | [DERIVADA] |

Las longitudes se repiten como `CHECK` en base de datos (`datos.md`); si se cambian aquí, se cambia el `CHECK` con una migración (*expand*: ampliar antes de que la aplicación acepte más).

Sin ficheros, sin S3 y sin correo en `1.18-a` a `-e2`.

## 2. Servicios externos de los que depende

| Servicio | Para qué | Si no responde |
|----------|----------|----------------|
| PostgreSQL | Todo | Caída del módulo, como el resto |
| *Worker* de colas (`worker`, conexión `database`) | Tarea diaria de ratio (§4) | La tarea se retrasa; nada de la API depende de ella (el estado de ratio se calcula en directo) |
| Planificador de Laravel (`schedule:run`) | Lanzar la tarea diaria | **No desplegado** (#453): ver §4 y `OPEN-INF-26` |
| Interfaces de otros módulos (`curso`, `acad`, `alum`, `fam_unit`, `Core`) | Ámbitos, matrículas, nombres, escalas | Están en el mismo proceso: no hay degradación posible, fallan con la petición (fallo en cerrado) |

No hay llamadas a servicios externos al despliegue: ni cortacircuitos ni tiempos de espera propios (`RARQ-ARC-005` no aplica hasta `1.18-f`/`-g`/`-j`).

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

Orden por entrega, con la pila parada solo lo que exige el procedimiento general de `SYSADMIN.md`:

1. **Migraciones** (`php artisan migrate --database=pgsql_owner`), todas aditivas (*expand*) y en `apps/api/app/Modules/Inf/Database/migrations/`:
   - `1.18-a`: `infant_cycle_levels`, `infant_groups`, `infant_ratio_rules`, `infant_ratio_exceptions` (solo anexar), `infant_ratio_records` (solo anexar), `infant_cycle_authorizations`.
   - `1.18-b`: `infant_daily_logs`, `infant_daily_log_entries`.
   - `1.18-c`: `infant_development_indicators`, `infant_development_reports`, `infant_development_report_ratings`, `infant_development_report_area_notes`; y `infant_cycle_levels.development_scale_id` (columna nula, *expand*).
   - `1.18-d`: `infant_adaptation_plans`, `infant_adaptation_plan_days`.
   - Las FK compuestas hacia `education_levels`, `groups`, `academic_periods`, `enrollments` y `people` exigen en el destino un único `(tenant_id, id)` o `(tenant_id, academic_year_id, id)`. **Sin verificar** que existan todos (`groups` y `enrollments` sí, por precedente de `REQ-ALUM/operacion.md §3`); si falta alguno, la migración de `inf` va precedida de un índice único `CONCURRENTLY` + `USING INDEX` en el módulo dueño, con su revisión de `db-reviewer`.
   - Ninguna toca una tabla existente salvo esos posibles índices.
2. **`php artisan platform:sync-registry`**: añade el módulo `inf` (`depends_on ['curso','acad','alum','fam_unit']`, `OPEN-INF-02`) y sus permisos por entrega: 3 en `-a` (`ciclo_infantil.*`), 5 en `-b`, 6 en `-c`, 5 en `-d` (`permisos.md §2`). **Aborta** si `depends_on` cita un código inexistente: con la cabecera literal del documento (`ACAD, CALIF, ALUM, FIN, SALUD`) abortaría, porque `calif`, `fin` y `salud` no existen (`OPEN-INF-02`). Ningún permiso con `is_special_category = true` (`AR-09`).
3. **`php artisan inf:grant-permissions`** para los centros existentes (siembra de `permisos.md §4`), idempotente; se ejecuta tras cada entrega que añade permisos. No concede nada si falta un permiso del catálogo (precedente `alum:grant-permissions`, #387).
4. **Contratar `inf`** por centro desde el *backoffice* (`REQ-BO-002`); exige `curso`, `acad`, `alum` y `fam_unit` contratados.

**Reversión** (`CLAUDE.md §9`; se prueba antes del PR): código anterior sin rutas de `inf`; las tablas nuevas pueden quedar (nadie las lee) y se retiran con `down()` en orden inverso solo si no hay datos que conservar. El veto de ratio de `alum` vuelve a no existir: la versión anterior matricula sin control, que es el comportamiento previo. Descontratar `inf` no borra nada (`RMOD-003`).

**Compatibilidad entre versiones**: el registro de vetos de alta y cambio de grupo de `alum` (`OPEN-INF-06`) se despliega en `1.18-a`; una instancia de la versión anterior no lo consulta, así que durante el despliegue progresivo (si lo hubiera) una matrícula podría pasar sin veto. Con un único contenedor de API (situación actual) no aplica; se anota para cuando haya varias réplicas.

**Workers**: la tarea diaria es un *job* nuevo; un *worker* de la versión anterior no lo recibe porque solo lo encola el comando nuevo. Desplegar API y *workers* juntos.

## 4. Tareas programadas y jobs

| Elemento | Cola | Notas |
|----------|------|-------|
| Comando `inf:record-daily-ratios` [nombre DERIVADA] | — | Recorre los centros **con `inf` contratado** y encola un *job* por centro. Programado una vez al día después de medianoche en la zona del centro [DERIVADA: «foto» del día que empieza]. Acepta `--date` para recuperar un día perdido (solo fechas pasadas o la de hoy) |
| *Job* `RecordDailyRatios` (por centro) | `default` (conexión `database`) | Con contexto de tenant (`ADR-033`, skill `aislamiento-tenant`); `tries = 3`; idempotente por el único `(tenant_id, group_id, record_date)` (`RN-INF-09`, `CA-INF-012`): un reintento o una segunda ejecución no duplican (inserción que ignora el conflicto, sin `UPDATE`: la tabla es de solo anexar). Cuenta matrículas por la interfaz de `alum`. `default` y no `long`: decenas de grupos por centro |

**Planificador no desplegado** (#453): hasta que exista, la tarea no corre sola en ningún servidor (`OPEN-INF-26`, recomendación A). Mientras tanto se puede lanzar a mano con `podman exec plataforma-api php artisan inf:record-daily-ratios` (recupera días con `--date`). La opción C (temporizador de systemd que ejecute el comando en el contenedor) es compatible con «el host solo ejecuta contenedores» (`CLAUDE.md §9`), pero adelanta una decisión de #453 y no se toma aquí.

Sin otras tareas. Los avisos (autorización próxima a caducar, publicación de parte o informe) esperan a `1.18-j` y al motor de `ADR-079`.

## 5. Métricas y alertas

- Sin métricas propias en `1.18-a` a `-d`.
- La tarea diaria registra en el *log* estructurado una línea por centro con número de grupos registrados y excedidos (sin datos personales). Un día sin filas en `infant_ratio_records` para un centro con `inf` contratado indica que la tarea no corrió (§6).
- Rendimiento: si `infant_daily_log_entries` crece por encima de la estimación de `datos.md §4` (≈220.000 filas por centro y curso, **sin medir**), revisar con la medición de `REQ-SEED` (`1.15b`) antes de particionar. `academic_year_write_guard` añade coste a la inserción en masa (cifras de `CA-ALUM-060`); el registro masivo del parte inserta del orden de 10 × 20 filas por petición, muy por debajo.

## 6. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué hacer |
|---------|----------------|-----------|
| `409 inf.conflict.ratio_exceeded` al matricular | Grupo completo con su regla de ratio | Esperado; registrar una excepción justificada (`ciclo_infantil.aprobar`) o elegir otro grupo |
| Se matricula por encima de la ratio | Grupo sin tramo o tramo sin regla («sin control», `RN-INF-05`), o `inf` no contratado (`funcional.md §9`) | Revisar `GET /infant-ratio-status` |
| El educador «no ve a sus alumnos» en el día | No está vinculado al grupo en `acad` (`OPEN-INF-10`), o el grupo no tiene tramo, o la fecha es de otro curso | Revisar la tutoría del grupo en `acad` y `GET /infant-groups` |
| La familia no ve el parte, el informe o el plan | No publicado; vínculo revocado; curso cerrado sin `curso_historico.leer` (`OPEN-INF-17`) | Esperado salvo que esté publicado y el vínculo activo |
| `429` al consultar partes | Cupo de lecturas auditadas (`inf.read_rate_limit`) | Esperar el `Retry-After`; subir el cupo solo si el uso legítimo lo exige |
| `500` en una lectura de parte con la base de datos sana | Falla la escritura de la fila `read` (`RN-INF-30`): por diseño no sale ningún dato | Revisar `audit_logs` (permisos, particiones) |
| `409 acad.conflict.has_dependents` al borrar un grupo, etapa o período | Datos de `inf` lo usan (`CA-INF-015`, `-054`) | Esperado |
| `422 scale_not_configured` al crear un informe | El ciclo no tiene escala elegida para el curso | `PATCH /infant-cycle-levels/{id}` con `development_scale` |
| Faltan días en `infant_ratio_records` | Planificador no desplegado (#453) o *worker* parado | Lanzar `inf:record-daily-ratios --date=…` por cada día perdido |
| `platform:sync-registry` aborta al desplegar `inf` | `depends_on` con un código inexistente | `OPEN-INF-02`; comprobar que `alum` y `fam_unit` están desplegados |

## 7. Impacto en copias de seguridad y restauración

Tablas de tenant ordinarias: entran en PITR y en la exportación lógica por tenant (`ADR-022`). Sin objetos en S3 ni secretos propios. Las dos tablas de solo anexar se restauran como el resto. Lo suprimido por ejercicio de derechos no debe reaparecer al restaurar (`REQ-BKP-005`); `inf` no añade mecanismo propio y depende del de `REQ-PRIV`/`REQ-BKP` (`OPEN-INF-16`). Purga de tenant: las catorce tablas llevan `tenant_id` (`ADR-077`, PROPUESTA).

## 8. E2E real (`1.18-e1`, `-e2`)

**Cómo se ejecutará** (a crear por `implementer` en `-e1`, siguiendo `REQ-ALUM/operacion.md §9`): `npm run test:e2e:real:inf` con un guion propio que prepara dos centros sintéticos con `curso`, `acad`, `alum`, `fam_unit` e `inf` contratados mediante `apps/api/tests/Support/e2e-real-tenant.php setup <slug> inf` (ampliación de ese ayudante, sin datos reales: `REQ-SEED-005`, dominios `@example.com`), una etapa de infantil `privado` marcada como primer ciclo, un grupo por tramo con regla de ratio y unos pocos alumnos ficticios. Lo lanza `verificador` (`CLAUDE.md §3`).

**Usuarios de prueba**: dirección, secretaría, dos docentes con ámbito `grupo` (uno vinculado al grupo, otro no), un tutor legal con vínculo activo y otro con el vínculo revocado. Contraseñas por variables `E2E_*`, fuera del repositorio.

**Qué prueba**: `CA-INF-081` (registro masivo y publicación en tableta), `-082` (informe hasta publicación y lectura por la familia), `-083` (rechazo por ratio en la pantalla de `alum`), aislamiento entre los dos centros. **Qué no prueba**: pantallas de familia (`1.22`), fotos (`1.18-f`), PDF (`1.18-g`), sin conexión (`1.18-j`), la tarea diaria lanzada por el planificador (#453).

## 9. Documentación que el paso actualiza al cerrar (no la escribe esta especificación)

`SYSADMIN.md` (comandos `inf:grant-permissions` e `inf:record-daily-ratios`, `config/inf.php`, orden de despliegue), `docs/manual-usuario/direccion.md`, `docente.md` y `secretaria.md` (pantallas de `funcional.md §10` y el rechazo por ratio), `PRIVACY.md` (tratamientos de parte diario, informe y adaptación de menores de 0-3, con la base legal **pendiente de validación jurídica**, `OPEN-INF-16`), `CHANGELOG.md` (una entrada por entrega), `ARCHITECTURE.md` (módulo `inf`, entradas de `AR-10`), y las notas de `REQ-ALUM` y `REQ-ACAD` sobre las interfaces y vetos que `inf` consume o amplía.
