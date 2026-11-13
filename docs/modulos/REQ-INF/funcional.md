# REQ-INF · Primer ciclo de Educación Infantil 0-3 · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-INF` (sección 5.50 del documento de requisitos: `REQ-INF-001` a `-008`) |
| Prioridad | MUST |
| Fase | 1 · paso **1.18** del plan (bloque E, Evaluación) |
| Depende de | Documento (3.2.30): `ACAD, CALIF, ALUM, FIN, SALUD`. **Propuesta** (`OPEN-INF-02`): `depends_on: ['curso', 'acad', 'alum', 'fam_unit']`. `CALIF` (pasos `1.16`/`1.17`), `FIN` y `SALUD` (fase 2) **no están implementados**: ver §0.2 C1 |
| Esencial | `essential: false` (depende de módulos no esenciales, `ADR-045`) |
| Estado | **PROPUESTO** (2026-11-12, ola 1, agente B6). Sin aprobar. Tiene preguntas abiertas bloqueantes (§14.1) |
| Clasificación del paso: crítico | Todas las entregas. Ver §2 |
| Decisiones vinculantes de partida | `ADR-004`, `ADR-013`, `ADR-015`, `ADR-016` (excepción del 0-3 privado), `ADR-020`, `ADR-021`, `ADR-029`, `ADR-032`, `ADR-033`, `ADR-034`, `ADR-035`, `ADR-038`, `ADR-044` (enmendado por `ADR-063`), `ADR-045`, `ADR-048`, `ADR-053`, `ADR-054`, `ADR-056`, `ADR-057`, `ADR-062`, `ADR-063 §5.4`, `ADR-066`, `ADR-068`, `ADR-069 §4.2`, `ADR-076`. En estado **PROPUESTA**, no vinculantes todavía: `ADR-078` (PDF), `ADR-079` (notificaciones), `ADR-082` (firma) |

> Donde esta especificación añade algo que ni los requisitos ni un ADR fijan, se marca **[DERIVADA]** con su motivo. Donde falta información o hay contradicción, hay una pregunta abierta (§14) con opciones y recomendación; ninguna se da por resuelta. Las recomendaciones se aplican en el texto como **propuesta**, y cambian si el usuario decide otra cosa.

---

## 0. Terminología, contradicciones y huecos

### 0.1 Terminología (se usa igual en los cinco ficheros)

| Término | Entidad / tabla | Qué es |
|---------|-----------------|--------|
| **Primer ciclo** | `InfantCycleLevel` / `infant_cycle_levels` | Marca, por curso académico, de qué etapa de `acad` (`education_levels`, `stage = 'infantil'`) es el primer ciclo 0-3, con su fecha de corte de edad. `acad` no distingue ciclos (`REQ-ALUM/funcional.md §7.3`) |
| **Tramo de edad** | enumerado `0-1`, `1-2`, `2-3` | Los tres tramos de `REQ-INF-001` |
| **Grupo infantil** | `InfantGroup` / `infant_groups` | Extensión 1:1 de un grupo de `acad` (`groups`) del primer ciclo, con su tramo. El grupo sigue siendo de `acad`; `inf` no lo duplica |
| **Regla de ratio** | `InfantRatioRule` / `infant_ratio_rules` | Máximo de alumnos por grupo para un tramo y curso (`InfantRatio` del documento) |
| **Excepción de ratio** | `InfantRatioException` / `infant_ratio_exceptions` | Autorización justificada de una plaza por encima del máximo |
| **Registro diario de ratio** | `InfantRatioRecord` / `infant_ratio_records` | Foto diaria, de solo anexar, de la ratio real de cada grupo |
| **Autorización administrativa** | `InfantCycleAuthorization` / `infant_cycle_authorizations` | Autorización del centro para impartir el ciclo, con vigencia |
| **Parte diario** (agenda diaria) | `DailyLog` / `infant_daily_logs` y `DailyLogEntry` / `infant_daily_log_entries` | El registro de un alumno en un día (`REQ-INF-003`): cabecera con ánimo y observaciones, y anotaciones de comida, descanso, higiene, hitos y reposición |
| **Indicador de desarrollo** | `DevelopmentIndicator` / `infant_development_indicators` | Descriptor evaluable de un área de desarrollo para un tramo (`REQ-INF-002`; lectura sujeta a `OPEN-INF-08`) |
| **Informe de desarrollo** | `DevelopmentReport` / `infant_development_reports` (+ valoraciones y observaciones por área) | Evaluación cualitativa de un alumno en un período, con su flujo de validación y publicación |
| **Plan de adaptación** | `AdaptationPlan` / `infant_adaptation_plans` y `infant_adaptation_plan_days` | Calendario progresivo de incorporación de un alumno (`REQ-INF-004`) |

Términos heredados sin cambio: curso académico, etapa, curso de etapa, grupo (`REQ-ACAD`); alumno, matrícula (`REQ-ALUM`); unidad familiar, tutor legal, vínculo (`REQ-FAM-UNIT`). «Educador» es la persona del centro que atiende el aula: no es un rol nuevo (`OPEN-INF-10`).

Reglas de nombre [DERIVADA]: código de módulo `inf`, prefijo de traducciones y errores `inf.`, tablas con prefijo `infant_`. `CareSession` (entidad del documento) no se crea en `1.18`: pertenece a `REQ-INF-005`, diferido (§1.3).

### 0.2 Contradicciones y huecos detectados

| # | Hallazgo | Fuentes | Cómo queda (propuesta) |
|---|----------|---------|------------------------|
| C1 | **Un MUST de fase 1 depende de módulos sin implementar o de fases posteriores.** La cabecera de §5.50 declara `CALIF` (pasos `1.16`/`1.17`, sin hacer), `FIN` y `SALUD` (fase 2); el cuerpo cita además `REQ-BEC` (fase 2), `REQ-ACOG` y `REQ-COMED` (fase 3), `REQ-COM` (`1.19`) y `REQ-PRIV-006`. Mismo patrón que `REQ-ALUM` C1 | §5.50, §5.22, §5.37, `PLAN` | `OPEN-INF-01` (alcance) y `OPEN-INF-02` (`depends_on`). Nada de `REQ-INF` está marcado `FUTURO`: diferir no es descartar |
| C2 | **Categoría especial sin mecanismo.** `REQ-INF-006` (alergias, medicación, fiebre, incidencias sanitarias) es salud. `CLAUDE.md §8` exige tabla separada, cifrado, permiso propio y auditoría de lectura; no existe ADR de cifrado a nivel de campo (`REQ-ALUM` C3) y `AR-09` falla en cuanto un permiso declare `is_special_category = true` (`ADR-044 §4.4`) | `CLAUDE.md §8`, `RPERM-012`, `RPERM-015`, `AR-09` | `REQ-INF-006` no se implementa hasta que exista ese ADR (`OPEN-INF-03`). Ningún permiso de las entregas especificadas es de categoría especial |
| C3 | **El parte diario roza la salud.** Deposiciones, sueño, apetito y observaciones libres no son un diagnóstico, pero pueden revelar el estado de salud de un menor de 0 a 3 años, y el texto libre admite cualquier cosa («tiene fiebre») | `REQ-INF-003`, RGPD art. 9 | `OPEN-INF-04` (bloqueante, **pendiente de validación jurídica**). Propuesta: cuidados rutinarios con vocabularios cerrados como dato ordinario delicado de menor (auditoría de lectura incluida); todo lo sanitario, a la tabla cifrada de `REQ-INF-006` |
| C4 | **`acad` no distingue el primer ciclo.** `education_levels.stage` admite `infantil`, sin ciclo; `REQ-ALUM §7.3` ya lo dejó pendiente | `REQ-ACAD/datos.md §1.1` | `OPEN-INF-05`: la marca la pone `inf` en tabla propia |
| C5 | **El bloqueo por ratio ocurre en otro módulo.** El criterio de aceptación del documento exige impedir la matrícula al alcanzar la ratio; matricular es de `alum`, que no puede depender de `inf` (`INV-007`, `depends_on`) | §5.50 criterios, `REQ-ALUM §7.2` | `OPEN-INF-06` (bloqueante de `1.18-a`): registro de vetos de alta y de cambio de grupo en `Alum\Domain`, con la forma del de borrado (`RN-CURSO-30`) |
| C6 | **«Ratio legal» sin cifras ni definición.** El documento no dice las cifras ni si la ratio es alumnos por grupo o por educador, ni qué es la «ratio real» diaria | `REQ-INF-001` | `OPEN-INF-07`. No se fija ninguna cifra de memoria: las introduce el centro con su referencia normativa |
| C7 | **«Descriptores por tramo de edad» es ambiguo**: descriptores de cada valor de la escala, o indicadores evaluables por área | `REQ-INF-002` | `OPEN-INF-08` (bloqueante de `1.18-c`) |
| C8 | **Escalas duplicadas.** `acad` ya tiene `grading_scales` (`1.11d`); `REQ-INF-002` pide «escalas configurables por el centro» y `REQ-CALIF` (B5, en paralelo) decide su uso | `REQ-ACAD/datos.md §1.8-§1.9`, `OPEN-ACAD-18/19` | `OPEN-INF-09`: reutilizar las de `acad` por interfaz pública |
| C9 | **Firma y PDF del informe sin motor ni decisión.** «Firma digital del tutor», PDF y emisión en el idioma del destinatario dependen de `ADR-078` y `ADR-082` (PROPUESTA) y del motor de boletines de `1.17` | `REQ-INF-002`, `ADR-078`, `ADR-082` | Entrega `1.18-g`, bloqueada; `OPEN-INF-11` decide si se publica en pantalla antes de tener firma |
| C10 | **Sin conexión, *push*, canal directo.** `REQ-INF-003` pide funcionamiento sin conexión (`RMOB-COM-001`) y notificación *push*; `REQ-INF-008`, canal educador-familia. Lo primero lo decide la arquitectura de `1.13` (B2), lo segundo `ADR-079` y `1.19` | `REQ-INF-003`, `REQ-INF-008` | Diferido (§1.3); la API de `1.18-b` nace preparada para reintentos (`client_ref`, `RN-INF-27`) |
| C11 | **Nivel de consentimiento de la galería.** `ADR-066` fija dos niveles de imagen (`web`, `redes_sociales`); la galería del aula y las fotos del parte se ven solo por familias autenticadas del grupo, que no es ninguno de los dos | `ADR-066 §5.3`, `INV-014`, `REQ-INF-003`, `-008` | `OPEN-INF-12` (bloqueante de `1.18-f`) |
| C12 | **`INV-008` no lo cumple este módulo solo.** El catálogo de bases legales (`OPEN-13`, `REQ-PRIV-006`) no existe | `INV-008`, `REQ-ALUM` C6 | Cobertura parcial; condición previa a datos reales (`OPEN-INF-16`) |
| C13 | **Entradas y salidas ya tienen dueño.** `REQ-INF-005` pide hora real de entrada y salida y verificar a quien recoge; `prl` ya registra la entrega (`student_movements`, `1.14b`) y la asistencia es `1.13` | `REQ-INF-005`, `ADR-032`, `ADR-068` | Diferido a `1.18-i` para no crear un segundo registro (`OPEN-INF-13`) |

---

## 1. Alcance (`OPEN-INF-01`, propuesta)

### 1.1 Qué entra en `1.18` (entregas especificadas aquí)

| Entrega | Qué se entrega | Requisito |
|---------|----------------|-----------|
| `1.18-a` | Módulo `inf`; marca del primer ciclo y fecha de corte; tramo de cada grupo; reglas de ratio, veto de matrícula al superarla, excepción justificada y registro diario; autorización administrativa con vigencia. Solo API | `REQ-INF-001` |
| `1.18-b` | Parte diario: alta, anotaciones, registro masivo por grupo con excepciones, publicación a la familia, lectura por la familia e histórico. Solo API | `REQ-INF-003` (sin fotos, sin conexión, *push* ni PDF) |
| `1.18-c` | Indicadores por área y tramo; informe de desarrollo por período con observaciones, comentario global, validación de dirección, publicación controlada e histórico inmutable; informe final de ciclo. Solo API | `REQ-INF-002` (sin PDF ni firma digital) |
| `1.18-d` | Plan de adaptación por alumno y evolución diaria. Solo API | `REQ-INF-004` (sin facturación) |
| `1.18-e1`, `-e2` | Pantallas de personal y E2E contra la API real | `INV-006` |

### 1.2 Qué queda diferido, con su bloqueo (no se especifica en detalle)

| Entrega | Contenido | Bloqueada por |
|---------|-----------|---------------|
| `1.18-f` | Fotografías del parte y galería del aula con comprobación de consentimiento (`INV-014`, cuarto criterio del documento) | `OPEN-INF-12`; `S-2` de `ADR-076` (`TenantObjectStore`) |
| `1.18-g` | Informe en PDF con marca del centro, firma del tutor, emisión en el idioma del destinatario (tercer criterio del documento); exportación en PDF del histórico del parte | `ADR-078`, `ADR-082`, paso `1.17` |
| `1.18-h` | Salud y cuidados (`REQ-INF-006`): ficha reforzada, incidencias sanitarias del día | ADR de categoría especial (`OPEN-INF-03`), `REQ-SALUD` (fase 2); menús, `REQ-COMED` (fase 3) |
| `1.18-i` | Entradas, salidas y horario ampliado (`REQ-INF-005`) | `OPEN-INF-13`; `1.13`, `1.14b-d`, `REQ-ACOG` (fase 3) |
| `1.18-j` | Sin conexión del parte; aviso a la familia al publicar | ADR de sin conexión de `1.13`; `ADR-079` y `1.19` |

### 1.3 Qué NO entra, explícitamente

| Fuera de alcance | Requisito | Dónde se retoma |
|------------------|-----------|-----------------|
| Cuotas, becas, facturación, facturación proporcional de la adaptación | `REQ-INF-007`, `-004` | `REQ-FIN`, `REQ-BEC` (fase 2) |
| Marcado de plazas con fondos públicos | `REQ-INF-001` | Con su primer consumidor, `REQ-BEC-001` (`OPEN-INF-14`) |
| Promoción automática de tramo y copia de la configuración al curso siguiente | `REQ-INF-001` | `1.17b` (`OPEN-INF-15`) |
| Canal directo educador-familia | `REQ-INF-008` | `REQ-COM` (`1.19`) |
| Traspaso de información al segundo ciclo | `REQ-INF-002` | `OPEN-INF-18` |
| Pantallas de familias | — | `1.22` (`REQ-FAM-PORTAL`); la **API** sí admite `unidad_familiar` (`INV-006`) |
| Documento adjunto de la autorización administrativa o de la excepción de ratio | `REQ-INF-001` | Tras `S-2` (`OPEN-INF-19`) |
| Retención y purga efectivas | `REQ-INF-002`, `REQ-PRIV-006` | `REQ-PRIV` (`OPEN-INF-16`) |
| Datos de demostración del 0-3 | `REQ-SEED` | Ampliación de `1.15b` por bloque |

---

## 2. Clasificación del paso: **crítico** (`ADR-069 §4.2`)

**Clasificación del paso: crítico**, en todas las entregas (`1.18-a` a `-e2`). Basta cualquiera de estas:

- Migraciones que **crean tablas** con RLS (todas las de `datos.md`), dos de ellas de solo anexar (`REVOKE`).
- *Endpoints*, permisos y **resolutores de ámbito nuevos**, y entradas nuevas en el mapa cerrado de `AR-10`.
- *Endpoints* que **leen y escriben datos personales de menores** de 0 a 3 años, con contenido cercano a la salud (C3).
- Ampliación de una interfaz pública de otro módulo que condiciona una escritura (veto en `alum`, C5).
- `1.18-e1`/`-e2` son pantallas sobre esos datos y rutas con `meta.permissions`: la duda es crítico.

Revisión: Codex primero; `security-reviewer` con su columna; `db-reviewer` (hay migraciones en `-a` a `-d`); `doc-precheck` y `doc-reviewer`. La sesión comprueba la clasificación contra el diff.

---

## 3. Actores y roles implicados

| Actor (`§11.1`) | Qué hace en este módulo |
|-----------------|-------------------------|
| Administrador de Centro | Todo |
| Dirección | Configura el ciclo, aprueba excepciones de ratio, **valida** y **publica** informes, consulta todo |
| Secretaría | Consulta la configuración y el estado de ratio (matricula en `alum`) |
| Docente / Tutor de grupo (educador del aula) | Parte diario, informes y adaptación de **sus grupos** (ámbito `grupo`; nunca `todos`, `ADR-063 §5.4`) |
| Tutor legal / Familia | Por API, lee el parte **publicado**, los informes **publicados** y el plan de adaptación publicado de los alumnos con vínculo **activo** (ámbito `unidad_familiar`); pantallas en `1.22` |
| Estudiante | Nada (0 a 3 años) |

Ninguna regla compara roles (`AR-08`): se comprueban permisos, y todo funciona con roles personalizados. Cómo se vincula un educador (y un educador de apoyo) a un grupo infantil es `OPEN-INF-10`.

---

## 4. Flujos principales

### 4.1 Configurar el ciclo (`1.18-a`)

1. Dirección marca una etapa de `acad` del curso académico como primer ciclo e indica la fecha de corte de edad. El servidor comprueba, por la interfaz pública de `acad`, que la etapa está viva, es `infantil` y de régimen `privado` (`RN-INF-01`).
2. Asigna a cada grupo de esa etapa su tramo (`0-1`, `1-2`, `2-3`).
3. Introduce, por tramo, el máximo de alumnos por grupo y la referencia normativa de la que sale.
4. Registra la autorización administrativa del centro con sus fechas de vigencia.

### 4.2 Matrícula con control de ratio (`1.18-a`, en `alum`)

1. Secretaría matricula en `alum` (flujo de `REQ-ALUM §4.2`) o se aprueba un cambio de grupo.
2. `alum` consulta su registro de vetos; el veto de `inf`, dentro de la misma transacción y con el grupo bloqueado, cuenta las matrículas activas del grupo destino y las compara con el máximo de su tramo más las excepciones sin consumir.
3. Si no cabe: `409` con el código de `inf` y sin efecto. Si cabe gracias a una excepción, la excepción queda consumida por esa matrícula.
4. Para una excepción, quien tiene `ciclo_infantil.aprobar` la registra antes, con justificación obligatoria.

### 4.3 Registro diario de ratio (`1.18-a`)

Una tarea diaria en cola anota, por cada grupo infantil del curso activo, las matrículas activas, el máximo vigente y si se supera. Solo anexa.

### 4.4 Parte diario (`1.18-b`)

1. El educador abre el día de su grupo: el servidor devuelve un parte por alumno con matrícula activa en el grupo ese día (existente o vacío).
2. Añade anotaciones a un alumno, o **en bloque** a todo el grupo indicando a quién excluir.
3. Publica el parte de un alumno o el de todo el grupo. Desde ese instante la familia lo ve.
4. Puede seguir anotando tras publicar (la merienda llega después de la siesta): lo nuevo es visible al momento.
5. La familia consulta el parte del día y el histórico de sus hijos.

### 4.5 Informe de desarrollo (`1.18-c`)

1. Dirección mantiene los indicadores por área y tramo.
2. El educador crea el informe de un alumno para un período, valora cada indicador con un valor de la escala, escribe la observación de cada área y el comentario global. Estado `borrador`.
3. Lo envía a validación (`pendiente_validacion`). Dirección lo **valida** (`validado`) o lo **devuelve** con motivo (`borrador`).
4. Quien tiene `informe_desarrollo.publicar` lo publica (`publicado`). Solo entonces lo ve la familia.
5. Un informe publicado no se edita: una **rectificación** crea una versión nueva que repite el flujo y, al publicarse, sustituye a la anterior, que se conserva.

### 4.6 Plan de adaptación (`1.18-d`)

1. El educador crea el plan de un alumno con sus días (fecha, hora de entrada y de salida previstas).
2. Lo publica: la familia lo ve.
3. Cada día anota la evolución.

---

## 5. Reglas de negocio

Todas se validan en servidor (`INV-010`).

### 5.1 Ciclo, grupos y ratio (`1.18-a`)

- **RN-INF-01** Solo se marca como primer ciclo una etapa viva del curso con `stage = 'infantil'` y `regime = 'privado'` (`REQ-INF-001`, `ADR-016`, `ADR-020`); otra cosa, `422`. Un centro puede marcar más de una etapa por curso [DERIVADA: no hay motivo para prohibirlo].
- **RN-INF-02** La fecha de corte es obligatoria y pertenece al curso académico.
- **RN-INF-03** Solo un grupo de una etapa marcada admite tramo; un grupo tiene un tramo como mucho.
- **RN-INF-04** Regla de ratio: una por tramo y curso; `max_students_per_group` entero ≥ 1; referencia normativa obligatoria. No hay valores por defecto (`OPEN-INF-07`).
- **RN-INF-05** Un grupo infantil sin tramo o un tramo sin regla **no** bloquean la matrícula; el estado de ratio lo señala como «sin control» [DERIVADA: fallar en cerrado aquí impediría matricular antes de configurar].
- **RN-INF-06** Con regla vigente, ninguna alta de matrícula con grupo, asignación de grupo ni cambio de grupo deja al grupo con más matrículas activas que `max + excepciones vigentes` (criterio 1 del documento). La comprobación es transaccional y serializada por grupo.
- **RN-INF-07** Una excepción de ratio exige justificación no vacía, autoriza **una** plaza en **un** grupo, es de solo anexar y se consume con la matrícula que la usa. No se edita ni se borra; se **anula** con otro registro si no se ha consumido.
- **RN-INF-08** Bajar el máximo por debajo de la ocupación real se admite, no expulsa a nadie y deja el grupo «excedido» en el estado de ratio.
- **RN-INF-09** El registro diario de ratio es de solo anexar, uno por grupo y fecha; la tarea es idempotente.
- **RN-INF-10** Autorización administrativa: referencia, organismo, fecha de inicio y fecha de fin opcional. Estado **calculado**, no guardado: `vigente`, `proxima_a_caducar` (umbral configurable por despliegue, `operacion.md`) o `caducada`. El aviso activo espera a `1.19`; hasta entonces el estado se ve en la API y en el bloque del panel.
- **RN-INF-11** Una autorización caducada **no bloquea** ninguna operación: avisa [DERIVADA; el documento dice «alertas»].

### 5.2 Parte diario (`1.18-b`)

- **RN-INF-20** Un parte por alumno (matrícula) y fecha. Solo para alumnos con matrícula activa ese día en un grupo infantil.
- **RN-INF-21** La fecha no puede ser futura. El día se calcula en la zona horaria del centro.
- **RN-INF-22** Anotaciones con **vocabulario cerrado** por tipo (`datos.md §1.7`): comida (servicio, cantidad, detalle), descanso (minutos, calidad), higiene (clase, hora), hito, reposición (artículo). El ánimo, en la cabecera. Los valores se muestran por el sistema de traducción (`INV-009`).
- **RN-INF-23** Los textos libres (detalle, observaciones del día) son contenido del educador: no se traducen automáticamente, tienen longitud máxima y la interfaz advierte que **no deben contener información sanitaria** (C3, `OPEN-INF-04`).
- **RN-INF-24** Estados `borrador` y `publicado`. La familia nunca ve un parte en `borrador`, ni en listado ni en detalle (`404`).
- **RN-INF-25** Publicar exige `agenda_infantil.publicar`. Publicar el grupo publica todos los partes en borrador del grupo y día en una operación.
- **RN-INF-26** Registro masivo: aplica las mismas anotaciones a todos los alumnos del grupo en la fecha salvo los excluidos; crea el parte donde no exista; todo o nada.
- **RN-INF-27** Toda anotación admite `client_ref` (identificador generado por el cliente), único por centro: un reenvío no duplica. El registro masivo exige `Idempotency-Key`.
- **RN-INF-28** Un parte publicado sigue admitiendo anotaciones y correcciones; cada cambio se audita (`INV-003`). No hay ventana de cierre en `1.18` (`OPEN-INF-20`).
- **RN-INF-29** Despublicar no existe. Un parte creado por error se elimina con `agenda_infantil.eliminar` (borrado lógico) y deja de verlo la familia al momento.
- **RN-INF-30** Toda lectura de detalle, de listado y de histórico escribe una fila `read` **antes** de devolver, sin valores, con cupo (`RPERM-015` por analogía, skill `auditoria`); si la fila falla, no sale ningún dato.
- **RN-INF-31** El parte conserva el grupo que el alumno tenía ese día: un cambio de grupo posterior no mueve partes.

### 5.3 Informe de desarrollo (`1.18-c`)

- **RN-INF-40** La evaluación es cualitativa: ningún campo numérico de calificación (`REQ-INF-002`).
- **RN-INF-41** Áreas: las tres de `REQ-INF-002`, como enumerado fijo (`armonia`, `entorno`, `comunicacion`), con nombre por el sistema de traducción.
- **RN-INF-42** Indicador: área, tramo, texto traducible (`ADR-062`) y posición; por curso académico (`OPEN-INF-08`).
- **RN-INF-43** Las valoraciones usan valores de **una** escala de `acad` elegida para el ciclo en ese curso (`OPEN-INF-09`); un valor de otra escala, `422`.
- **RN-INF-44** Un informe por matrícula, período y clase (`periodo` o `final_ciclo`), más sus versiones. Solo valora indicadores del tramo del grupo del alumno.
- **RN-INF-45** Transiciones: `borrador → pendiente_validacion → validado → publicado`; `pendiente_validacion → borrador` (devolución con motivo). Cualquier otra, `409`.
- **RN-INF-46** Solo se edita en `borrador`. Enviar a validación exige observación en las tres áreas [DERIVADA: «observaciones cualitativas del tutor por área»].
- **RN-INF-47** Quien valida no puede ser quien lo redactó (`OPEN-INF-21`, propuesta; precedente `RN-ALUM-44`).
- **RN-INF-48** La familia solo alcanza informes `publicado` vigentes (no sustituidos): un informe no publicado no aparece ni en listado ni en detalle ni en recuentos (criterio 2 del documento).
- **RN-INF-49** Un informe `publicado` es inmutable: ni edición ni borrado, por ningún permiso. La rectificación crea la versión `n+1` enlazada; al publicarse, la anterior queda `sustituido` y se conserva (`REQ-INF-002`, «histórico íntegro e inmutable»).
- **RN-INF-50** Los textos libres del informe son contenido del educador: no se traducen automáticamente (la emisión en el idioma del destinatario de `ADR-021` alcanza a la plantilla, las áreas, los indicadores traducibles y los valores de escala; `1.18-g`).
- **RN-INF-51** Lectura auditada como `RN-INF-30`.
- **RN-INF-52** Sin firma digital en `1.18-c`: el informe registra quién lo redactó, quién lo validó y quién lo publicó, y cuándo (`OPEN-INF-11`).

### 5.4 Adaptación (`1.18-d`)

- **RN-INF-60** Un plan vivo por matrícula. Días dentro del curso académico, sin fechas repetidas, con hora de salida posterior a la de entrada.
- **RN-INF-61** Estados `borrador` y `publicado`; la familia solo ve el publicado, con la evolución diaria ya anotada.
- **RN-INF-62** La evolución de un día solo se anota en fecha no futura. Texto libre con la advertencia de `RN-INF-23`.
- **RN-INF-63** Lectura auditada como `RN-INF-30`.

### 5.5 Transversales

- **RN-INF-70** Curso cerrado: toda lectura invoca `AcademicYearReadAccess` (`RN-CURSO-33`); la escritura la bloquea el disparador (`ADR-057`). Qué ocurre con la familia y el histórico de cursos cerrados es `OPEN-INF-17`.
- **RN-INF-71** El alcance de la familia se resuelve por vínculos **activos** (`FamilyMembership`): un tutor con el acceso revocado a un alumno no alcanza nada de él (`REQ-FAM-UNIT-002`).
- **RN-INF-72** Ningún módulo de `inf` da `todos` a `docente` como sustituto de la asignación (`ADR-063 §5.4`).
- **RN-INF-73** Ningún aviso que se construya después (`1.18-j`, `ADR-079`) lleva datos del parte ni del informe en el cuerpo ni como adjunto: solo un enlace que exige sesión.
- **RN-INF-74** Escrituras por instancia sobre modelos `Auditable` (`AR-15`).

---

## 6. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Dos matrículas simultáneas a la última plaza | Una `201`, otra `409 inf.ratio_exceeded` (test de `Concurrency`) |
| Excepción registrada y nunca consumida | Sigue vigente hasta que se anula; se ve en el estado de ratio |
| Etapa marcada que `acad` quiere borrar | `409` por veto de borrado de estructura (`RN-ACAD-21`) |
| Grupo infantil que `acad` quiere borrar con partes, informes o planes | `409` por el mismo registro de vetos |
| Matrícula que `alum` quiere anular con partes, informes o planes | `409` por el registro de vetos de borrado de matrícula (`REQ-ALUM §7.2`) |
| Alumno dado de baja a mitad de curso | Sus partes e informes se conservan; no se crean partes posteriores a la fecha de baja |
| Educador sin asignación al grupo | Conjunto vacío: listado vacío, detalle `404` |
| Docente que además es tutor legal de un alumno de otro grupo | Unión de ámbitos (`ADR-044`): como familia ve lo publicado de su hijo, nada más |
| Tutor con vínculo revocado | `404` |
| Parte duplicado (mismo alumno y fecha) | `422 inf.validation.daily_log_exists` |
| Reenvío de una anotación con el mismo `client_ref` | Devuelve la ya creada, sin duplicar |
| Informe: valorar un indicador de otro tramo | `422` |
| Informe: publicar sin validar | `409 inf.report_invalid_transition` |
| Rectificar un informe ya sustituido | `409` |
| Período de `acad` borrado con informes | `409` por veto |
| Curso cerrado | Escritura `409 academic-year-closed`; lectura `404` sin `curso_historico.leer` |
| Módulo `inf` no contratado | `403 urn:pge:error:module-disabled` (`RMOD-009`) |
| Cupo de lecturas agotado | `429` con `Retry-After` |

---

## 7. Interacción con otros módulos

Toda interfaz vive en `Domain` (nunca `Domain\Models`); `inf` no lee tablas de otro módulo (`INV-007`, `AR-01`, `AR-02`). Las claves foráneas en base de datos hacia `groups`, `education_levels`, `enrollments`, `academic_periods` y `people` se permiten; la lectura va por interfaz.

### 7.1 Lo que consume

| De | Qué | Estado |
|----|-----|--------|
| `curso` | `AcademicYearContext`, `AcademicYearDirectory`, `AcademicYearReadAccess` | Existen |
| `acad` | `GroupDirectory`; `TeachingMembership::groupIdsFor()` | Construidas en `1.15-b` |
| `acad` | Lectura de etapa (`stage`, `regime`), de períodos y de escalas con sus valores | **Sin verificar** si hay interfaz pública; si falta, se especifica y construye en `acad` con `inf` como primer consumidor (`ADR-048`) y aprobación del usuario (`OPEN-INF-09`) |
| `acad` | Registro de vetos de borrado de estructura (`RN-ACAD-21`) | Creado en `1.15-b` |
| `alum` | `StudentEnrollmentDirectory`, `EnrollmentRoster`, registro de vetos de borrado de matrícula (`REQ-ALUM §7.2`) | Especificados; se construyen con su primer consumidor. **Sin verificar** si `1.13` o `1.14b-d2` los construyen antes; si no, los construye `1.18-b` dentro de `alum` |
| `alum` | **Registro de vetos de alta y de cambio de grupo** | **No existe ni está especificado**: ampliación de `Alum\Domain` (`OPEN-INF-06`) |
| `fam_unit` | `FamilyMembership` (alumnos alcanzados por vínculos activos) | Existe |
| `fam_unit` | `ImageConsentPolicy`, `ImageConsentLevel` | Existen; se usan en `1.18-f`, no antes |
| `Core` | `PersonDirectory` (nombre y fecha de nacimiento para la vista del día) | Existe |
| núcleo | `TenantModel`, `Auditable`, `AuditRecorder::recordRead`, `ScopedQuery`, `RequireIdempotencyKey` | Existen |

### 7.2 Lo que expone (`Inf\Domain`)

Se especifica; se construye con su primer consumidor (`ADR-048`). Falla en cerrado.

| Interfaz | Operación | Primer consumidor |
|----------|-----------|-------------------|
| `InfantCycleDirectory` | `isFirstCycleLevel(int educationLevelId)`, `ageBandOfGroup(int groupId)` | `prl` (salida autónoma, que necesita reconocer el primer ciclo, `REQ-ALUM §7.3`), `REQ-BEC` |

### 7.3 Eventos

Ni emite ni consume en `1.18` (`ADR-048`). Candidatos para `1.19`/`1.18-j`: `DailyLogPublished`, `DevelopmentReportPublished`, `AdaptationPlanPublished`, `InfantCycleAuthorizationExpiring`. El aviso sale por el motor de `ADR-079` (PROPUESTA) con enlace autenticado (`RN-INF-73`).

### 7.4 Apertura del curso siguiente

`inf` **no** participa en el *rollover* en `1.18` (`OPEN-INF-15`): la marca de ciclo, los tramos, las reglas de ratio y los indicadores se configuran en cada curso hasta `1.17b`.

---

## 8. Descriptor del módulo (`ADR-045`, `ADR-034 §5`)

| Campo | Valor |
|-------|-------|
| `code` | `inf` |
| `name_key` | `modules.inf` (cuatro idiomas) |
| `phase` | `1` |
| `depends_on` | `['curso', 'acad', 'alum', 'fam_unit']` (`OPEN-INF-02`) |
| `essential` | `false` |
| `feature_flags` | Ninguno |

Todas las rutas con `module-enabled:inf` antes de `permission:` (`AR-07b`).

## 9. Comportamiento con el módulo desactivado

- API: `403 urn:pge:error:module-disabled` en todas las rutas (`RMOD-009`).
- Interfaz: desaparecen las entradas de navegación, las rutas y el bloque del panel (`RMOD-008`).
- El veto de ratio **no actúa** con `inf` desactivado: `alum` matricula sin control de ratio (el registro de vetos ignora módulos no contratados; falla en abierto a propósito, porque un módulo no contratado no puede bloquear a otro) [DERIVADA].
- La tarea diaria de ratio no se ejecuta para ese centro. Los datos se conservan.

## 10. Interfaz: *shell* del frontend (`ADR-053`) — `1.18-e1`/`-e2`

Módulo web `apps/web/src/modules/inf/` (`AR-11`). Composición según `docs/design-system.md §22`; tablas con `src/data-table`.

| Ruta | Pantalla | `meta.permissions` (anyOf) | Entrega |
|------|----------|----------------------------|---------|
| `/infantil/ciclo` | Configuración: etapas, tramos, ratio, excepciones, autorización | `ciclo_infantil.leer` | `-e1` |
| `/infantil/agenda` | Día del grupo: rejilla alumno × anotación, registro masivo, publicar | `agenda_infantil.leer` | `-e1` |
| `/infantil/agenda/:id` | Parte de un alumno e histórico | `agenda_infantil.leer` | `-e1` |
| `/infantil/adaptacion` | Planes de adaptación | `adaptacion_infantil.leer` | `-e1` |
| `/infantil/indicadores` | Indicadores por área y tramo | `informe_desarrollo.leer` | `-e2` |
| `/infantil/informes` | Informes por grupo y período, bandeja de validación | `informe_desarrollo.leer` | `-e2` |
| `/infantil/informes/:id` | Redacción, validación, publicación, versiones | `informe_desarrollo.leer` | `-e2` |

- Navegación: una entrada «Infantil 0-3» con sus hijas; visibles si el *guard* dejaría montar la ruta.
- Bloque del panel: estado de ratio y de la autorización (`ciclo_infantil.leer`). Depende del mecanismo de bloques (#261): si no existe al llegar a `-e1`, se omite y se anota.
- La pantalla del día está pensada para tableta y móvil «en pocos toques» (`REQ-INF-003`, `RUX-RESP-001`): valores cerrados como botones, sin teclado salvo el detalle. Si la rejilla alumno × anotación no cabe en `src/data-table`, es una rejilla de edición nueva y exige aprobar la ampliación de la lista cerrada de `RN-CORE-53` (`OPEN-INF-22`).
- Ninguna lectura auditada se dispara sola por montaje o sondeo: el histórico se pide por acción del usuario (skill `frontend-spa`).
- Las pantallas de familia no entran (`1.22`).

---

## 11. Datos personales, menores e idiomas

### 11.1 Menores de 0 a 3 años (`INV-008`)

- Todos los interesados son menores sin capacidad alguna de actuar: todo acceso de familia pasa por un **vínculo de tutela activo** comprobado en cada petición (`RN-INF-71`), no por ser «una familia».
- Base legal de cada tratamiento (parte diario, informe, adaptación): **pendiente de validación jurídica**; depende del catálogo de `OPEN-13`/`REQ-PRIV-006`. La especificación no la fija (`OPEN-INF-16`). Es condición previa a datos reales, no a implementar.
- Fotografías: nunca sin consentimiento de imagen vigente de **todos** los identificables (`INV-014`); no entran hasta `1.18-f`.
- Minimización: `inf` no copia nombre, fecha de nacimiento ni documento; referencia a la persona y a la matrícula.

### 11.2 Salud (categoría especial)

- **Ninguna tabla de `1.18-a` a `-d` es de salud** según la propuesta de `OPEN-INF-04`; si el usuario o el asesor jurídico deciden que el parte entero es categoría especial, `1.18-b` y `-d` quedan bloqueadas hasta el ADR de `OPEN-INF-03`.
- `REQ-INF-006` (`1.18-h`) exige, sin excepción: tabla separada, cifrado a nivel de campo, permiso propio `is_special_category = true` no incluido en ningún rol por defecto (`RPERM-012`), auditoría de lectura (`RPERM-015`) y valores fuera de `audit_logs` (`ADR-035`). Su diseño es del ADR pendiente y de `REQ-SALUD`: aquí no se adelanta.
- Las incidencias sanitarias del día «vinculadas a la agenda» irán en esa tabla cifrada con referencia al parte, nunca como un tipo más de anotación.

### 11.3 Auditoría

- Escritura: automática por `Auditable` en todos los modelos; política de valores en `datos.md §5` (textos libres y referencias a personas, sin valor).
- Lectura: `recordRead` antes de devolver, con `purpose` y recuentos, sin nombres (`RN-INF-30`, `-51`, `-63`).
- Excepciones de ratio y registro diario: tablas de solo anexar.

### 11.4 Idiomas (`INV-009`, `ADR-021`)

- Interfaz, vocabularios cerrados, áreas, estados y errores: es-ES, en, de, fr, con paridad (`AR-12`, `TranslationParityTest`).
- Contenido del centro traducible: texto de los indicadores (`ADR-062`).
- Texto libre del educador: en el idioma en que se escribe (`RN-INF-23`, `-50`).
- Documentos en el idioma del destinatario: `1.18-g`.
- La advertencia de `RN-INF-23` es un texto de privacidad: **no se traduce automáticamente**; lo valida el usuario en los cuatro idiomas.

---

## 12. Criterios de aceptación

Cada uno tiene test que cita su ID (`INV-015`). «Familia» es un usuario con el permiso de lectura en ámbito `unidad_familiar` y vínculo activo con el alumno; «educador», uno con ámbito `grupo` asignado al grupo.

### 12.1 `1.18-a` Ciclo, tramos y ratio

- **CA-INF-001** *Dado* un centro con `inf` contratado, *cuando* se ejecuta `platform:sync-registry`, *entonces* existen el módulo `inf` y sus permisos con `applicable_scopes` explícito y ninguno con `is_special_category = true`.
- **CA-INF-002** *Dada* una etapa `infantil` y `privado`, *cuando* dirección la marca como primer ciclo con fecha de corte, *entonces* `200` y queda auditado. *Dada* una etapa `primaria` o `concertado`, *entonces* `422` (`RN-INF-01`).
- **CA-INF-003** *Dado* un grupo de una etapa no marcada, *cuando* se le asigna tramo, *entonces* `422` (`RN-INF-03`).
- **CA-INF-004** *Dada* una regla de ratio sin referencia normativa o con máximo 0, *cuando* se guarda, *entonces* `422` (`RN-INF-04`).
- **CA-INF-005** (criterio 1 del documento) *Dado* un grupo de 1-2 años con tantas matrículas activas como su máximo, *cuando* secretaría matricula a otro alumno en ese grupo, *entonces* `409 inf.ratio_exceeded` y no se crea la matrícula.
- **CA-INF-006** *Dado* ese mismo grupo, *cuando* se aprueba un cambio de grupo hacia él o se asigna ese grupo a una matrícula sin grupo, *entonces* `409 inf.ratio_exceeded` (`RN-INF-06`).
- **CA-INF-007** *Dado* ese grupo y una excepción registrada con justificación, *cuando* se matricula un alumno, *entonces* `201`, la excepción queda consumida por esa matrícula y la siguiente matrícula vuelve a recibir `409`.
- **CA-INF-008** *Dada* una excepción sin justificación, *cuando* se registra, *entonces* `422`; *dado* un usuario sin `ciclo_infantil.aprobar`, *entonces* `403`.
- **CA-INF-009** *Dadas* dos altas simultáneas a la última plaza, *cuando* se resuelven, *entonces* una `201` y otra `409` (test de `Concurrency`).
- **CA-INF-010** *Dada* una excepción o un registro diario de ratio, *cuando* se intenta `UPDATE` o `DELETE` con el rol de aplicación, *entonces* el motor lo rechaza.
- **CA-INF-011** *Dado* un grupo infantil sin tramo o sin regla, *cuando* se matricula, *entonces* `201` y el estado de ratio lo muestra «sin control» (`RN-INF-05`).
- **CA-INF-012** *Dada* la tarea diaria ejecutada dos veces el mismo día, *entonces* hay un registro por grupo y fecha, con ocupación, máximo y marca de excedido (`RN-INF-09`), y solo de centros con `inf` contratado.
- **CA-INF-013** *Dada* una autorización con fecha de fin dentro del umbral, *cuando* se consulta, *entonces* su estado es `proxima_a_caducar`; pasada la fecha, `caducada`; ninguna operación queda bloqueada (`RN-INF-10`, `-11`).
- **CA-INF-014** *Dado* `inf` no contratado, *cuando* se matricula en un grupo lleno, *entonces* `alum` responde `201` (§9) y toda ruta de `inf` responde `403 module-disabled`.
- **CA-INF-015** *Dada* una etapa marcada o un grupo con tramo, *cuando* `acad` intenta borrarlos, *entonces* `409`.

### 12.2 `1.18-b` Parte diario

- **CA-INF-020** *Dado* un educador de un grupo, *cuando* pide el día de su grupo, *entonces* recibe un parte (existente o vacío) por cada alumno con matrícula activa ese día y ninguno de otro grupo.
- **CA-INF-021** *Dado* ese educador, *cuando* pide por `public_id` un parte de otro grupo, *entonces* `404`, igual que si no existiera; *cuando* intenta crear o anotar en otro grupo, *entonces* `404`.
- **CA-INF-022** *Dado* un parte en `borrador`, *cuando* la familia pide el listado o el detalle, *entonces* no aparece (`404` en detalle) (`RN-INF-24`).
- **CA-INF-023** *Dado* un parte publicado, *cuando* la familia lo pide, *entonces* lo recibe; *cuando* lo pide un tutor con el vínculo revocado u otra familia, *entonces* `404`.
- **CA-INF-024** *Dada* una familia, *cuando* intenta crear, anotar, publicar o eliminar, *entonces* `403`.
- **CA-INF-025** *Dado* un registro masivo con dos alumnos excluidos, *cuando* se envía, *entonces* todos los demás alumnos del grupo tienen la anotación y los excluidos no; si una anotación es inválida, no se crea ninguna (`RN-INF-26`).
- **CA-INF-026** *Dado* un registro masivo repetido con la misma `Idempotency-Key`, o una anotación repetida con el mismo `client_ref`, *entonces* no hay duplicados (`RN-INF-27`).
- **CA-INF-027** *Dada* una anotación con un valor fuera de su vocabulario, con fecha futura, o de un alumno sin matrícula activa ese día, *entonces* `422` (`RN-INF-20` a `-22`).
- **CA-INF-028** *Dado* un parte publicado, *cuando* el educador añade una anotación, *entonces* la familia la ve en la siguiente lectura y el cambio queda auditado sin el texto libre (`RN-INF-28`).
- **CA-INF-029** *Dada* cualquier lectura de parte (detalle, día de grupo, histórico), *entonces* existe una fila `read` con actor y finalidad y sin nombres ni textos; *si* la escritura de esa fila falla, *entonces* la respuesta no lleva datos; superado el cupo, `429` (`RN-INF-30`).
- **CA-INF-030** *Dado* un alumno que cambia de grupo, *entonces* sus partes anteriores siguen en el grupo antiguo: el educador nuevo no los alcanza y el antiguo sí (`RN-INF-31`).
- **CA-INF-031** *Dado* un parte eliminado, *cuando* la familia lo pide, *entonces* `404` (`RN-INF-29`).
- **CA-INF-032** *Dada* una matrícula con partes, *cuando* `alum` intenta anularla, *entonces* `409`.

### 12.3 `1.18-c` Informe de desarrollo

- **CA-INF-040** *Dado* un informe, *entonces* ninguna respuesta de la API contiene una calificación numérica (`RN-INF-40`).
- **CA-INF-041** *Dado* un indicador con texto en es-ES y de, *cuando* lo pide un usuario en alemán, *entonces* lo recibe en alemán; en francés, con el idioma de respaldo (`ADR-062`).
- **CA-INF-042** *Dado* un informe en `borrador`, *cuando* se valora un indicador de otro tramo o con un valor de otra escala, *entonces* `422` (`RN-INF-43`, `-44`).
- **CA-INF-043** *Dado* un informe sin observación en alguna de las tres áreas, *cuando* se envía a validación, *entonces* `422` (`RN-INF-46`).
- **CA-INF-044** *Dado* un informe en `pendiente_validacion`, *cuando* dirección lo valida, *entonces* pasa a `validado`; *cuando* lo devuelve sin motivo, `422`; con motivo, vuelve a `borrador`.
- **CA-INF-045** *Dado* quien redactó el informe, *cuando* intenta validarlo, *entonces* `403` (`RN-INF-47`).
- **CA-INF-046** *Dado* un informe en `borrador` o `pendiente_validacion`, *cuando* se intenta publicar, *entonces* `409 inf.report_invalid_transition` (`RN-INF-45`).
- **CA-INF-047** (criterio 2 del documento) *Dado* un informe sin publicar, *cuando* la familia pide el listado, el detalle o cualquier recuento, *entonces* no ve ningún dato de evaluación de ese período.
- **CA-INF-048** *Dado* un informe publicado, *cuando* la familia del alumno lo pide, *entonces* lo recibe; otra familia o un tutor revocado, `404`.
- **CA-INF-049** *Dado* un informe publicado, *cuando* cualquier usuario, incluido el administrador del centro, intenta editarlo o borrarlo, *entonces* `409` y no cambia (`RN-INF-49`).
- **CA-INF-050** *Dada* una rectificación publicada, *entonces* la familia ve la versión nueva, la anterior queda `sustituido` y el personal con permiso ve ambas.
- **CA-INF-051** *Dado* un educador, *cuando* pide el listado de informes, *entonces* solo ve los de sus grupos; por `public_id` uno de otro grupo, `404`.
- **CA-INF-052** *Dada* cualquier lectura de informe, *entonces* fila `read` antes de devolver y sin valores (`RN-INF-51`).
- **CA-INF-053** *Dado* un informe publicado, *entonces* la respuesta identifica quién lo redactó, validó y publicó, y cuándo (`RN-INF-52`).
- **CA-INF-054** *Dado* un período con informes, *cuando* `acad` intenta borrarlo, *entonces* `409`.

### 12.4 `1.18-d` Adaptación

- **CA-INF-060** *Dado* un plan con dos días en la misma fecha, con salida anterior a la entrada o fuera del curso, *cuando* se guarda, *entonces* `422` (`RN-INF-60`).
- **CA-INF-061** *Dado* un plan en `borrador`, *cuando* la familia lo pide, *entonces* `404`; publicado, lo recibe con la evolución anotada (`RN-INF-61`).
- **CA-INF-062** *Dado* un día futuro, *cuando* se anota su evolución, *entonces* `422` (`RN-INF-62`).
- **CA-INF-063** *Dado* un educador, *entonces* solo alcanza planes de sus grupos, en listado y en detalle (`404` fuera).
- **CA-INF-064** *Dada* una segunda alta de plan vivo para la misma matrícula, *entonces* `422`.

### 12.5 Transversales (todas las entregas)

- **CA-INF-070** **Aislamiento entre tenants** (`INV-001`): con dos centros con datos equivalentes, ningún *endpoint* de `inf` devuelve, modifica ni cuenta datos del otro, en listado y en detalle; el veto de ratio y la tarea diaria solo cuentan matrículas del propio centro.
- **CA-INF-071** **Curso cerrado** (`RN-CURSO-33`): sin `curso_historico.leer`, `404` en listado **y** en detalle de partes, informes, planes y configuración de un curso `cerrado`/`archivado`; con él, los datos. La escritura, `409 academic-year-closed`.
- **CA-INF-072** Todo *endpoint* sin el permiso responde `403`; sin sesión, `401`; ninguna ruta carece de `permission:` ni de `module-enabled:inf` (`AR-07a`, `AR-07b`).
- **CA-INF-073** `docente` y `tutor_grupo` no reciben ningún permiso de `inf` con ámbito `todos` en la siembra (`RN-INF-72`).
- **CA-INF-074** Toda creación, cambio y borrado deja fila de auditoría sin textos libres ni identificadores personales (`INV-003`, `ADR-035`).
- **CA-INF-075** Paridad de claves en los cuatro idiomas (`AR-12`) y ningún literal en la interfaz (`lint:i18n`).
- **CA-INF-076** `pest --group=arch` en verde, con `inf` en el mapa de `AR-10` y sin ampliar ninguna otra lista de excepciones.

### 12.6 `1.18-e1`/`-e2` Pantallas

- **CA-INF-080** *Dado* un usuario sin ningún permiso de `inf`, *entonces* no ve la entrada «Infantil 0-3» ni puede montar sus rutas.
- **CA-INF-081** *Dado* un educador en una tableta (768 px), *cuando* registra una comida para todo el grupo menos un alumno y publica, *entonces* lo consigue sin teclado y la API real lo refleja (E2E real).
- **CA-INF-082** *Dado* un informe, *cuando* el educador lo redacta y envía, dirección lo valida y publica, *entonces* la familia lo obtiene por la API real y antes no (E2E real, criterio 2 del documento).
- **CA-INF-083** *Dado* un grupo lleno, *cuando* secretaría matricula desde la pantalla de `alum`, *entonces* ve el motivo traducido del rechazo por ratio (E2E real, criterio 1).
- **CA-INF-084** Las pantallas cumplen WCAG 2.2 AA (foco, nombres accesibles de los botones de valor, contraste) y no disparan lecturas auditadas por montaje.

Criterios 3 (PDF en alemán) y 4 (bloqueo de foto sin consentimiento) del documento: pertenecen a `1.18-g` y `1.18-f`; **no** se dan por cubiertos en estas entregas.

---

## 13. Entregas y tramos (`CLAUDE.md §2.7`)

Cada entrega cabe en ≤3 tramos secuenciales de `implementer`; cada una con su revisión y cierre. Orden forzado: `-a` antes que todas; `-b`, `-c`, `-d` independientes entre sí; `-e1` tras `-b` y `-d`; `-e2` tras `-c`.

| Entrega | Tramo 1 | Tramo 2 | Tramo 3 |
|---------|---------|---------|---------|
| `1.18-a` | Módulo (`make:module`), cinco tablas, permisos `ciclo_infantil.*`, siembra | Servicios y *endpoints* de configuración, estado de ratio, autorización | Registro de vetos en `alum` y veto de `inf`, excepciones, tarea diaria, test de `Concurrency` |
| `1.18-b` | Dos tablas, permisos `agenda_infantil.*`, resolutores `grupo` y `unidad_familiar`, contratos de `alum` si faltan | *Endpoints* de personal: día del grupo, parte, anotaciones, masivo, publicación | Lectura de familia, histórico, auditoría de lectura con cupo, vetos de borrado |
| `1.18-c` | Cuatro tablas, permisos `informe_desarrollo.*`, resolutores, interfaces de `acad` si faltan | Indicadores; informe en borrador y valoraciones | Flujo (envío, validación, devolución, publicación, rectificación), familia, auditoría de lectura |
| `1.18-d` | Dos tablas, permisos, resolutores | *Endpoints*, publicación, evolución, familia | — |
| `1.18-e1` | Módulo web, configuración del ciclo y ratio | Día del grupo, parte, masivo | Adaptación; *specs* de E2E real (`CA-INF-081`, `-083`) |
| `1.18-e2` | Indicadores | Informes: redacción, bandeja, validación, publicación, versiones | *Specs* de E2E real (`CA-INF-082`) |

La ejecución final del E2E real la lanza `verificador`. Si un tramo 3 de `-a` no cabe (el veto toca `alum`), se parte en `1.18-a1` (configuración) y `1.18-a2` (veto y tarea), sin cambiar el alcance.

---

## 14. Preguntas abiertas

Ninguna se resuelve aquí. La opción **A es siempre la recomendada**. «Decide» indica quién.

### 14.1 Bloquean la implementación

| ID | Pregunta | Opciones | Recomendación e impacto | Bloquea | Decide |
|----|----------|----------|-------------------------|---------|--------|
| `OPEN-INF-01` | Alcance de `1.18` con módulos dependientes sin hacer (C1) | **A (Recomendada)**: lo de §1.1, diferir §1.2 y §1.3. B: esperar a `CALIF`, `FIN` y `SALUD` y hacer `REQ-INF` entero en fase 2. C: solo `-a` y `-b` ahora | A entrega el uso diario del aula y el informe sin esperar a fase 2; deja dos criterios del documento (PDF en alemán, foto) sin cubrir hasta `-f`/`-g`. B retrasa un MUST de fase 1 | Todo | Usuario |
| `OPEN-INF-02` | `depends_on` real | **A (Recomendada)**: `curso, acad, alum, fam_unit`, y corregir §5.50. B: mantener `ACAD, CALIF, ALUM, FIN, SALUD` | Con B el módulo no puede registrarse: `platform:sync-registry` aborta con códigos inexistentes | Todo | Usuario |
| `OPEN-INF-04` | ¿El parte diario (comida, sueño, pañal, deposiciones, ánimo) es dato de salud? (C3) **Pendiente de validación jurídica** | **A (Recomendada)**: cuidados rutinarios con vocabulario cerrado = dato ordinario delicado de menor, con auditoría de lectura; lo sanitario, solo en la tabla cifrada de `-h`; advertencia en textos libres. B: todo el parte es categoría especial. C: A, pero sin ningún texto libre | A permite `-b` y `-d` ahora con riesgo residual en el texto libre. B bloquea `-b` y `-d` hasta el ADR de `OPEN-INF-03` y obliga a permiso especial fuera de los roles por defecto (los educadores lo recibirían uno a uno). C reduce riesgo y utilidad | `-b`, `-d` | Asesor jurídico y usuario |
| `OPEN-INF-05` | Cómo se reconoce el primer ciclo (C4) | **A (Recomendada)**: tabla propia `infant_cycle_levels` en `inf`. B: columna de ciclo en `education_levels` (cambia `acad`). C: valor nuevo de `stage` | A no toca `acad` ni su `CHECK`; exige `InfantCycleDirectory` para quien pregunte. B y C migran una tabla aprobada y afectan a `prl` y `alum` | `-a` | Usuario |
| `OPEN-INF-06` | Bloqueo de matrícula por ratio desde `inf` (C5) | **A (Recomendada)**: registro de vetos de alta, asignación y cambio de grupo en `Alum\Domain`, forma de `RN-CURSO-30`. B: `inf` solo avisa después (no bloquea). C: la ratio se implementa dentro de `alum` | A cumple el criterio 1 del documento y amplía una interfaz aprobada de `alum` (necesita aprobación expresa). B incumple el criterio. C mete conocimiento de Infantil en `alum` | `-a` (tramo 3) | Usuario |
| `OPEN-INF-07` | Qué mide la ratio y qué cifras (C6) | **A (Recomendada)**: máximo de alumnos **por grupo y tramo**, sin valor por defecto, con referencia normativa escrita por el centro; «ratio real» = matrículas activas del día. B: además alumnos por educador presente. C: valores por defecto de la norma autonómica | A es comprobable hoy. B necesita asistencia (`1.13`) y presencia de personal (`REQ-JOR`). C exige verificar la norma vigente con fuente primaria: **no se ha hecho** | `-a` | Negocio y asesor jurídico |
| `OPEN-INF-08` | Qué son los «descriptores por tramo» (C7) | **A (Recomendada)**: indicadores evaluables por área y tramo, cada uno valorado con la escala. B: solo un texto que describe cada valor de la escala por tramo, y una valoración por área. C: ambos | Cambia las tablas de `-c`. A da informes más ricos y más trabajo al educador | `-c` | Negocio (centro piloto) |
| `OPEN-INF-09` | Escala del informe (C8) e interfaces de `acad` | **A (Recomendada)**: reutilizar `grading_scales` de `acad` por interfaz pública (construirla si falta, con aprobación). B: escala propia en `inf`. C: esperar a lo que decida `REQ-CALIF` (B5) | A evita dos conceptos de escala; depende de que `REQ-CALIF` no cambie su forma. B duplica | `-c` | Usuario, con B5 |
| `OPEN-INF-10` | Cómo se vincula el educador (y el de apoyo) a un grupo infantil, sin asignaturas | **A (Recomendada)**: por la tutoría de grupo de `acad`, que ya entra en `groupIdsFor` (`RN-ACAD-98`), admitiendo varios por grupo. B: asignación propia en `inf`. C: rol con `todos` | **Sin verificar** si `acad` admite varios tutores por grupo; si no, A exige enmendar `acad`. B crea una segunda fuente de pertenencia. C lo prohíbe `ADR-063 §5.4` | `-b`, `-c`, `-d` | Usuario, tras comprobar `acad` |
| `OPEN-INF-11` | ¿Se publica el informe a la familia sin firma digital ni PDF? (C9) | **A (Recomendada)**: sí, en pantalla, con autoría, validación y publicación registradas; firma y PDF en `-g`. B: `-c` llega hasta `validado` y la publicación espera a `-g`. C: firma simple propia ahora | A da valor antes; el documento pide «las mismas garantías que un boletín», que A no alcanza hasta `-g`. B deja `-c` sin su criterio 2 completo. C adelanta `ADR-082` | `-c` (publicación) | Usuario y asesor jurídico |
| `OPEN-INF-22` | Rejilla del día (alumno × anotación) frente a `RN-CORE-53` | **A (Recomendada)**: aprobar una rejilla de edición nueva en la lista cerrada. B: tarjetas por alumno sin rejilla | A es lo que pide «pocos toques»; amplía una lista que solo debería reducirse | `-e1` | Usuario |

### 14.2 Bloquean solo entregas diferidas

| ID | Pregunta | Recomendación e impacto | Bloquea | Decide |
|----|----------|-------------------------|---------|--------|
| `OPEN-INF-03` | No existe ADR del mecanismo de categoría especial (cifrado a nivel de campo, custodia de clave, `AR-09`) (C2) | **A (Recomendada)**: encargar a `architect` un ADR transversal (número a asignar por la orquestadora), que sirve también a custodia, `REQ-SALUD` y `REQ-NEAE`. B: resolverlo dentro de `REQ-SALUD`. Sin él, ningún dato de salud entra en el sistema | `-h` (y `-b`/`-d` si `OPEN-INF-04` = B) | Usuario |
| `OPEN-INF-12` | Nivel de consentimiento para fotos del parte y galería del aula (C11) | **A (Recomendada)**: exigir el nivel `web` de `ADR-066` (el menos amplio de los existentes) hasta que un ADR decida si hace falta un tercer nivel «interno». B: tercer nivel nuevo (ADR que enmiende `ADR-066`). C: sin consentimiento por ser acceso restringido. C contradice `INV-014` | `-f` | Usuario y asesor jurídico |

### 14.3 No bloquean

| ID | Pregunta | Recomendación e impacto | Decide |
|----|----------|-------------------------|--------|
| `OPEN-INF-13` | Entradas y salidas (`REQ-INF-005`) frente a `prl` y asistencia (C13) | **A (Recomendada)**: no crear registro propio; `-i` consume `prl` y `1.13` y solo añade la alerta fuera de horario. B: `CareSession` propia | Usuario |
| `OPEN-INF-14` | Marcado de plazas con fondos públicos | **A (Recomendada)**: con `REQ-BEC-001`, su primer consumidor. B: columna mínima ya en `-a` | Usuario |
| `OPEN-INF-15` | Promoción automática de tramo y copia de configuración al curso siguiente | **A (Recomendada)**: en `1.17b`, con `inf` como participante del *rollover*; hasta entonces, configuración manual por curso y tramo fijo durante el curso. B: participante ya en `-a` (un tramo más) | Usuario |
| `OPEN-INF-16` | Base legal y plazos de retención de partes, informes y planes (C12) | **A (Recomendada)**: no fijar plazos aquí; decidirlos con `REQ-PRIV-006` y asesor jurídico **antes de datos reales**. El informe no tiene custodia de la administración (`REQ-INF-002`): su plazo es política del centro. No se inventa ninguna cifra | Asesor jurídico |
| `OPEN-INF-17` | Familia e histórico de cursos cerrados (`RN-INF-70`) | **A (Recomendada)**: seguir lo que `REQ-ALUM`/`1.22` decidan para `unidad_familiar`; hasta entonces, regla general (`404` sin `curso_historico.leer`). Impacto: la familia no vería informes de cursos pasados | Usuario, en `1.22` |
| `OPEN-INF-18` | Traspaso al segundo ciclo | **A (Recomendada)**: diferir; cuando exista, lectura del informe final por el tutor del grupo de destino, auditada | Usuario |
| `OPEN-INF-19` | Documento adjunto de la autorización y de la excepción de ratio | **A (Recomendada)**: tras `S-2`; hasta entonces «documentada» = justificación escrita y auditada | Usuario |
| `OPEN-INF-20` | Ventana de cierre del parte | **A (Recomendada)**: sin cierre en `1.18`; revisar con el piloto | Negocio |
| `OPEN-INF-21` | ¿Quien valida puede ser quien redactó? | **A (Recomendada)**: no (`RN-INF-47`). B: sí, en centros pequeños | Usuario |
| `OPEN-INF-23` | Siembra en roles predefinidos (`permisos.md §4`) | **A (Recomendada)**: la tabla propuesta; sin rol nuevo de «educador» | Usuario |
| `OPEN-INF-24` | Vocabularios del parte: fijos o configurables por centro | **A (Recomendada)**: fijos y traducidos en `1.18` (un valor configurable por el centro no se traduce solo a la familia alemana). B: configurables con `ADR-062`. Cambiar después es migración | Usuario |
| `OPEN-INF-25` | ¿Se audita también la lectura de la familia de su propio hijo? | **A (Recomendada)**: sí, una fila por petición, con cupo. B: solo la del personal | Usuario |

---

## 15. Cambios propuestos en ficheros compartidos

No se han tocado. Los aplica la sesión orquestadora si el usuario aprueba.

| Fichero | Cambio propuesto |
|---------|------------------|
| `PLAN-IMPLEMENTACION.md` | `1.18`: marcar ⚠️ *paso crítico*, especificación PROPUESTA, y partirlo en `1.18-a`, `-b`, `-c`, `-d`, `-e1`, `-e2`; añadir como diferidas `1.18-f` (fotos; `S-2`, `OPEN-INF-12`), `-g` (PDF y firma; `1.17`, `ADR-078`, `ADR-082`), `-h` (salud; ADR de categoría especial, `REQ-SALUD`), `-i` (entradas y salidas), `-j` (sin conexión y avisos; `1.13`, `1.19`). En `1.17b`: obligación heredada de `OPEN-INF-15`. En `1.15b`: ampliar el centro concertado con 0-3 privado cuando exista `-a`. En `1.19`: obligación heredada de los avisos de `inf` (`RN-INF-73`). En `1.22`: pantallas de familia de parte, informe y adaptación, y `OPEN-INF-17` |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | §5.50, línea «Depende de», y la fila del índice de módulos, según `OPEN-INF-02`; historial de versiones. Si `OPEN-INF-05` = A, nota en §16 de que el ciclo es de `inf` |
| Índice de ADR (§18) | ADR nuevo solicitado, sin número: «Mecanismo de datos de categoría especial» (`OPEN-INF-03`) |
| `docs/modulos/REQ-ALUM/` | Si `OPEN-INF-06` = A: especificar en `funcional.md §7.2` el registro de vetos de alta, asignación y cambio de grupo, y el error que devuelve; lo redacta `spec-writer` con aprobación, no esta entrega |
| `docs/modulos/REQ-ACAD/` | Si `OPEN-INF-09`/`-10` = A y faltan: interfaces públicas de lectura de etapa, períodos y escalas; varios tutores por grupo |
| `ARCHITECTURE.md` | Al implementar: módulo `inf` en el mapa de módulos y entradas de `AR-10` |
| `CHANGELOG.md` | Una línea en la entrada de la ola 1: especificación de `REQ-INF` (PROPUESTA) |
| `memory.md` | Nota: `1.18` especificado, 25 `OPEN-INF`, 11 bloquean; ADR de categoría especial pendiente |
| `docs/modulos/REQ-CORE/` | Si `OPEN-INF-22` = A: ampliar la lista cerrada de rejillas de `RN-CORE-53` |

---

## 16. Lo que no se pudo comprobar

Esta sesión no tuvo `Bash`, `Grep` ni `Glob`; se leyó por tramos y con la búsqueda de código de GitHub sobre `develop`.

- `ADR-079` y `ADR-082` (borradores de A3 y A6) **no estaban en este *worktree***: la coordinación con ellos se limita a lo que dice el encargo (enlace autenticado, nunca adjunto; firma por `ADR-082`).
- La especificación de `REQ-CALIF` (B5) y la arquitectura sin conexión de `1.13` (B2), en curso en paralelo, no se han leído.
- No se ha leído el texto de `REQ-SALUD`, `REQ-BEC-001`, `REQ-PRL-004` ni `RMOB-COM-001` del documento de requisitos; su fase se toma de `REQ-ALUM/funcional.md` C1 y del encargo.
- No se ha verificado en el código: qué interfaces públicas expone `Acad\Domain` para etapas, períodos y escalas; si `acad` admite varios tutores por grupo; si `StudentEnrollmentDirectory` y `EnrollmentRoster` están ya construidas; la firma exacta del registro de vetos de `RN-CURSO-30`; el estado del mecanismo de bloques del panel (#261).
- Ninguna cifra de ratio ni plazo de retención procede de una fuente verificada: por eso no aparece ninguna.
- No se ha medido si cada entrega cabe en tres tramos: es una estimación por analogía con `1.15-a`..`-d`.

---

**¿Se aprueba esta especificación (y con qué respuestas a §14.1) antes de pasar a implementación?**
