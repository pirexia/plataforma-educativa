# REQ-ACAD · Gestión académica: estructura académica · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-ACAD` (sección 5.4 del documento de requisitos). §0-§17 cubren **`REQ-ACAD-001`** (estructura académica). **§18 cubre `REQ-ACAD-002`** (horarios y asignación docente, paso `1.12`; **APROBADA el 2026-10-17; `1.12-a` implementada el 2026-10-18 y `1.12-b` el 2026-10-19**, estado en §19 y §20). `-003` (asistencia, `1.13`), `-004` (tutorías) y `-005` (exámenes) quedan fuera. **§21 cubre el participante de `acad` en `REQ-CURSO-002`** (apertura del curso siguiente, paso `1.12b`; **APROBADA el 2026-10-20**, no implementada) |
| Prioridad | MUST |
| Fase | 1 · pasos **1.11** del plan, sub-paso nuevo **`1.11d`** (períodos lectivos y escalas, `OPEN-ACAD-01` = A, aprobada) y **`1.12`** (`1.12-a` y `1.12-b`, horarios y reservas) |
| Depende de | Documento: `CORE`. Real: `CORE` (esencial) y `CURSO` (esencial; toda tabla de este módulo lleva `academic_year_id`). Aprobado: `depends_on: ['curso']` (`OPEN-ACAD-10`) |
| Esencial | Aprobado: **`essential: false`** (`OPEN-ACAD-10`) |
| Estado | **`1.12b` (participante de `acad` en la apertura del curso siguiente, §21) APROBADA el 2026-10-20 por el usuario, sin preguntas abiertas (sub-preguntas `OPEN-CURSO-29-a` a `-d` resueltas el mismo día, `REQ-CURSO/funcional.md §15.4.2`); no implementada.** **`1.12` APROBADA el 2026-10-17; `1.12-a` implementada el 2026-10-18 (§19) y `1.12-b` el 2026-10-19 (§20).** **APROBADA** (2026-10-10). El usuario aprobó el 2026-10-10 las recomendaciones de `OPEN-ACAD-01` a `-23` (§15). `OPEN-ACAD-05` y `-08` se resuelven por **`ADR-062`** y **`ADR-063`**, ambos ACEPTADOS el 2026-10-10, con dos decisiones del usuario ya tomadas sobre ellos (`OPEN-062-01`: se exige al escribir el idioma por defecto del centro; `OPEN-063-01`: se fija ya el significado de `clase`). Los dos ADR están aceptados. **Implementada en `1.11-b`** (2026-10-12): estado y desviaciones aprobadas en §16. **Ampliada el 2026-10-16 con la especificación ejecutable de `1.11d`** (períodos lectivos y escalas: §4.8, §4.9, §5.7-§5.10, §6.1, §7.2, §7.4, §10.5, §12, §13.11-§13.18, §14, §15.2, §17): **APROBADA por el usuario el 2026-10-16** (`OPEN-ACAD-24` a `-31`, todas con la recomendación); lo aprobado de `1.11` no cambia salvo la ampliación de `RN-ACAD-20` que fija `RN-ACAD-61`. **Ajustada el mismo día** por las decisiones del usuario sobre `OPEN-CURSO-25` (B), `-26` (A) y `-27` (A) de `REQ-CURSO`: **`RN-ACAD-54` y `RN-ACAD-57` cambian** (las fechas del curso se leen con la lectura con bloqueo compartido de `curso`, en orden curso → etapa; cambio aceptado expresamente por el usuario), `RN-ACAD-62` fija la entrada de `acad` en `errors.date_change[]` y se añade `CA-ACAD-102`. **Revisión de documentación del 2026-10-17**: la SPA envía siempre `academic_year` con `education_level` al listar los períodos de una etapa (§4.8.1, decisión M-5) y se añaden `CA-ACAD-103` a `-106`. **`1.11d-b` (escalas de calificación) implementada el 2026-10-17** (rama `feature/REQ-ACAD-1-11d-b-escalas`): estado, cobertura y decisiones de implementación en §17 y §17.4 |
| Decisiones vinculantes de partida | `ADR-020` (régimen por etapa), `ADR-021` (idiomas, también del contenido del centro), `ADR-029`, `ADR-033`, `ADR-034 §4`/`§6`/`§7`, `ADR-035`, `ADR-038`, `ADR-044` (enmendado en `§3` por `ADR-063`), `ADR-045`, `ADR-048 §4.9`, `ADR-051`, `ADR-053`, `ADR-054`, `ADR-055`, `ADR-056`, **`ADR-057`** (bloqueo de escritura por disparador, `AR-13`), `ADR-058`, `ADR-059`, **`ADR-062`** (contenido del centro traducible, `AR-16`), **`ADR-063`** (ámbitos `grupo`/`clase`/`departamento`); contrato de `REQ-CURSO` (`RN-CURSO-20..33`, `funcional.md §3.2`, `§7.1`, `§10.2`) |

> Donde esta especificación añade algo que ni los requisitos ni un ADR fijan, se marca **[DERIVADA]** y se argumenta.

---

## 0. Terminología, contradicciones y huecos

### 0.1 Terminología (se fija aquí y se usa en los cinco ficheros; aprobada, `OPEN-ACAD-02` = A)

El castellano usa «curso» para dos cosas distintas, y el documento de requisitos usa las dos sin distinguirlas (`§5.4` lista `AcademicYear` y `Course` como entidades del mismo módulo).

| Término en estos documentos | Entidad / tabla | Qué es | Ejemplo | Dueño |
|---|---|---|---|---|
| **Curso académico** (nunca «curso» a secas) | `AcademicYear` / `academic_years` | El año escolar | `2026-2027` | `REQ-CURSO` |
| **Etapa** o **nivel educativo** | `EducationLevel` / `education_levels` | Una etapa de enseñanza tal como la organiza el centro | «Educación Secundaria Obligatoria», «Infantil primer ciclo» | `REQ-ACAD` |
| **Curso de etapa** (en interfaz, «Curso») | `Course` / `courses` | Un curso dentro de una etapa | «2º ESO» | `REQ-ACAD` |
| **Grupo** | `Group` / `groups` | Unidad de alumnado de un curso de etapa | «2º ESO A» | `REQ-ACAD` |
| **Subgrupo** | `Subgroup` / `subgroups` | División de un grupo | «Desdoble 1 de 2º ESO A» | `REQ-ACAD` |
| **Asignatura** (o **materia**) | `Subject` / `subjects` | Asignatura de un curso de etapa | «Matemáticas de 2º ESO» | `REQ-ACAD` |
| **Asignación de asignatura a grupo** | `GroupSubject` / `group_subjects` | Qué asignaturas de su curso cursa un grupo. Es también el par grupo × asignatura que `ADR-063 §5.3` llama «clase» como **ámbito** | — | `REQ-ACAD` |
| **Período lectivo** | `AcademicPeriod` / `academic_periods` (`1.11d`) | Trimestre, cuatrimestre o semestre | «Primer trimestre» | `REQ-ACAD` |
| **Escala de calificación** | `GradingScale` / `grading_scales` (`1.11d`) | Escala con la que se califica | «Numérica 0-10» | `REQ-ACAD` |

Reglas de nombre:

- `Course` se conserva como nombre de entidad porque `§16.1`/`§16.2` lo fijan como entidad núcleo «que no debe modificarse sin ADR». La ambigüedad se resuelve en el **texto**, no renombrando la entidad.
- Ningún recurso de permiso, código de error ni clave de traducción de este módulo usa la palabra `curso` a secas: el código de módulo `curso` ya es de `REQ-CURSO`. Este módulo usa `estructura_academica` y el prefijo `acad.`.
- En la interfaz, el año escolar se llama siempre «Curso académico» y el curso de etapa «Curso».
- «Clase» como **ámbito de permiso** tiene el significado de `ADR-063 §5.3` (pares grupo × asignatura que el usuario imparte). En el texto de `REQ-ACAD-001`, «grupos/clases» sigue significando grupos.

### 0.2 Contradicciones y huecos, y cómo quedan

| # | Hallazgo | Fuentes | Resolución |
|---|----------|---------|------------|
| C1 | **Reparto de entidades entre `ACAD` y `CALIF`** (períodos, escalas, competencias; `§5.5` declara `EvaluationPeriod` y `Competency` de `CALIF`; `REQ-CORE-002` habla de «períodos de evaluación») | `§5.4`, `§5.5`, `REQ-CORE-002` | **Resuelta** por `OPEN-ACAD-17`/`-18`/`-19` (aprobadas): períodos lectivos y escalas en `acad` (`1.11d`), `CALIF` define sus períodos de evaluación referenciando los lectivos, competencias en `1.16` |
| C2 | **«Grupos/clases» como sinónimos frente a dos ámbitos distintos**; `departamento` sin entidad | `REQ-ACAD-001`, `RPERM-004`, `ADR-044 §3` | **Resuelta por `ADR-063`** (ACEPTADA): `clase` = pares (grupo, asignatura) que el usuario imparte (`§5.3`, `OPEN-063-01` = fijarlo); `departamento` queda en el vocabulario sin dueño ni resolutor (`§5.5`) |
| C3 | **Ámbitos sin la relación que los resuelve** (`REQ-ACAD-001` no pide asignar profesorado ni tutor) | `REQ-ACAD-001`, `§11.1`, `ADR-044 §3` | **Resuelta por `ADR-063`**: `1.11` no registra resolutores (`§5.1`); `acad` aportará la asignación docente y una interfaz de pertenencia, y cada módulo dueño de un recurso registra su resolutor (`§5.2`); la asignación nace en el paso candidato `1.12`, especificada y aprobada antes de aprobar la especificación de `1.13` o `1.16` (`§5.4`) |
| C4 | **Lista de niveles frente a régimen por etapa** (Infantil como un solo nivel) | `REQ-ACAD-001`, `ADR-020`, `REQ-INF-001` | **Resuelta** por `OPEN-ACAD-04` = A: el centro crea sus etapas con un `stage` cerrado de seis valores y `regime` propio; parte Infantil en dos |
| C5 | **¿Las etapas dependen del curso académico?** | `§16.1`, `ADR-034 §4`, `REQ-CURSO-002` | **Resuelta** por `OPEN-ACAD-03` = B: por curso académico |
| C6 | **El selector de curso no puede listar cursos para la mayoría de roles** (`GET /academic-years` exige `curso_academico.leer`) | `REQ-CURSO/funcional.md §10.2`, `REQ-CURSO/permisos.md §4` | **Resuelta** por `OPEN-ACAD-11` = A, issue [#409](https://github.com/pirexia/plataforma-educativa/issues/409): *endpoint* de autoservicio `GET /academic-years/selectable` en `curso`, con ampliación aprobada de la lista cerrada de `AR-07a` |
| C7 | **`AcademicYearDirectory` no resuelve un curso por su identificador interno** | código de `Curso\Domain` | **Resuelta** por `OPEN-ACAD-12` = A: se amplía en `1.11` |
| C8 | **`tenantForeignId()` no crea el índice que su documentación promete** | `TenantMigration.php`, `ADR-034 §7` | **Se corrige en `1.11`**, issue [#407](https://github.com/pirexia/plataforma-educativa/issues/407): el ayudante crea el índice `(tenant_id, <columna>)`, con test (`CA-ACAD-038`). No es retroactivo: las tablas ya migradas no lo ganan por esto (§1.2) |

---

## 1. Alcance

### 1.1 División aprobada (`OPEN-ACAD-01` = A)

| Sub-paso | Contenido |
|----------|-----------|
| **`1.11`** (este) | Etapas, cursos de etapa, grupos, subgrupos, asignaturas y asignación de asignaturas a grupos (puntos 1-3 del requisito) + **selector visual de curso académico** (diferido desde `1.10`) + obligaciones heredadas (`RN-CURSO-33`, `CA-057-09`, no vacuidad de `AR-13`) + la **infraestructura compartida** de `ADR-062` (§1.2) + los hallazgos laterales con solución en el paso (#387, #378 B3, #407, #408, #409) |
| **`1.11d`** (nuevo) | Períodos lectivos y escalas de calificación (puntos 4-5), §4.8-§4.9. Especificación ejecutable escrita el 2026-10-16 y **APROBADA por el usuario el 2026-10-16** (`OPEN-ACAD-24` a `-31` resueltas con las recomendaciones): reglas `RN-ACAD-50` a `-89` (§5.7-§5.10), criterios `CA-ACAD-063` a `-106` (§13.11-§13.18; `-103` a `-106` añadidos el 2026-10-17 en la revisión de documentación), preguntas `OPEN-ACAD-24` a `-31` (§15.2), recurso de permiso `configuracion_academica`, tres tablas (`academic_periods`, `grading_scales`, `grading_scale_values`) y diez *endpoints*. Partición en entregas: `OPEN-ACAD-31`. Completada el 2026-10-16 con las decisiones del usuario sobre `OPEN-CURSO-25` a `-27` (`REQ-CURSO`), que reescriben `RN-ACAD-54`, `-57` y `-62` |
| **`1.16`** (`REQ-CALIF`) | Evaluación por competencias LOMLOE (punto 6) |

### 1.2 Qué entra en `1.11`

**En el módulo `acad`:**

| Pieza | Qué se entrega | Requisito |
|-------|----------------|-----------|
| Módulo `acad` | `apps/api/app/Modules/Acad/` con `AcadServiceProvider` (descriptor, catálogo de permisos, migraciones, *morph map*), `Http/routes.php` requerido desde `routes/api-v1.php`, `lang/{es,en,de,fr}/acad.php` y `modules.acad` | `RMOD-001`, `AR-03`, `AR-12` |
| Etapas | Alta, edición, consulta y borrado lógico **por curso académico**, con tipo de etapa y régimen | `REQ-ACAD-001` punto 1, `ADR-020` |
| Cursos de etapa | Ídem, colgados de una etapa | Punto 2 |
| Grupos y subgrupos | Ídem, el grupo de un curso de etapa y el subgrupo de un grupo | Punto 2 |
| Asignaturas y su asignación | Asignaturas de un curso de etapa y conjunto de asignaturas que cursa cada grupo | Punto 3 |
| Contrato de curso | Disparador `academic_year_write_guard` en cada tabla; `AcademicYearReadAccess` en cada lectura | `RN-CURSO-20..27`, `-33` |
| Selector de curso académico | Barra superior de las pantallas con datos por curso, estado en `src/academic-year/` y en la URL (`OPEN-CURSO-17` = A + X) | `REQ-CURSO-001` punto 3 |
| Pantallas | Estructura académica (§10) | `INV-006` |
| Obligaciones heredadas | `CA-057-09` con tabla real (#382), no vacuidad de `AR-13`, `RN-CURSO-33` por recurso | Plan `1.11` |

**Fuera del módulo `acad`, trabajo compartido que nace en `1.11`** (primer consumidor: `acad`; precedente `ADR-057`/`AR-13` en `1.10`):

| Pieza | Dónde | Qué | Origen | Criterios |
|-------|-------|-----|--------|-----------|
| Ayudante de columna traducible | `App\Support\Tenancy\TenantMigration` | Método que añade `<campo>_translations jsonb NOT NULL` con el `CHECK` de `ADR-062 §5.1` (nombre final en implementación; nadie escribe el `CHECK` a mano) | `ADR-062 §5.1` | `CA-ACAD-014` |
| Índice en `tenantForeignId()` | `App\Support\Tenancy\TenantMigration` | Crea también el índice `(tenant_id, <columna>)`. No retroactivo: las migraciones ya mezcladas no cambian (mismo criterio que el parámetro `$constraintName` del propio ayudante). Las tablas existentes sin índice se inventarían en el cierre y se tratan en issue aparte si alguna lo necesita | #407 | `CA-ACAD-038` |
| Validación y resolución | `App\Support\I18n` | Regla de validación compartida (alta y `PATCH` por fusión, contra `TenantSettingsReader`) y resolutor puro (idioma pedido → `default_locale` → `es-ES` → primera clave en orden `es-ES`, `en`, `de`, `fr`); códigos de error comunes `translations.*` | `ADR-062 §5.2`, `§5.3` | `CA-ACAD-009` a `-012` |
| Desglose en auditoría | Constructor de `changes` (`ADR-035 §4`) | Una entrada por idioma que cambia (`name_translations.es-ES`), con los cinco pasos y el tope de 256 caracteres aplicados a cada entrada | `ADR-062 §5.5` | `CA-ACAD-013` |
| Regla `AR-16` | `tests/Feature/Architecture/` | Toda columna `*_translations` es `jsonb NOT NULL` con el `CHECK`; excepciones vacías; casos fijos y no vacuidad | `ADR-062 §5.8` | `CA-ACAD-015` |
| Componente de campo traducible | `apps/web` (sistema de diseño, sobre shadcn-vue) | Una entrada por idioma activo, el por defecto primero y obligatorio, `lang` en cada entrada; marca `lang` en la presentación cuando el texto sale de la caída | `ADR-062 §5.7` | `CA-ACAD-016` |
| Ampliación de `AcademicYearDirectory` | `Curso\Domain` | Búsqueda por identificador interno, una y varias, memoizada por petición | `OPEN-ACAD-12` | `CA-ACAD-042` |
| `GET /academic-years/selectable` | Módulo `curso` | Autoservicio: `activo` y `planificacion`, y con `curso_historico.leer` también `cerrado`/`archivado`; amplía en una ruta la lista cerrada de `AR-07a` (aprobado) | `OPEN-ACAD-11`, #409 | `CA-ACAD-043` |
| Comando de siembra y corrección del de `curso` | Módulo `Core` | `acad:grant-structure-permissions` comprueba `retired_at IS NULL`; `curso:grant-year-permissions` gana la misma comprobación | `OPEN-ACAD-14`, #387 B-5 | `CA-ACAD-036`, `-041` |
| Reglas de arquitectura | `tests/Feature/Architecture/` | `AR-07a` valida que el código de `permission:` existe en el catálogo declarado (#378 B3); regla `arch()` «solo migraciones y *fixtures* usan `TenantMigration`», exenta por ruta; `AR-14` amplía su escaneo a `database/`, `routes/` y `bootstrap/` (#387 B-4) | `OPEN-ACAD-14`, `-15` | `CA-ACAD-039`, `-040` |
| `README` de módulos web | `apps/web/src/modules/README.md` | Lista los módulos reales (`auth`, `core`, `curso`, `acad`) | #408 | `CA-ACAD-052` |

### 1.3 Qué NO entra, explícitamente

| Fuera de alcance | Requisito | Por qué | Dónde se retoma |
|------------------|-----------|---------|-----------------|
| Períodos lectivos y escalas de calificación | `REQ-ACAD-001` puntos 4-5 | División aprobada | `1.11d` (§4.8, §4.9, §5.7-§5.10) |
| **En `1.11d`**: períodos de evaluación (final, extraordinaria), qué escala usa cada asignatura o etapa, la calificación en sí | `§5.5`, `REQ-CALIF-001` | C1, `OPEN-ACAD-17`/`-18` | `1.16` (`CALIF`) |
| **En `1.11d`**: escala adaptada por alumno | `REQ-NEAE-003` (fase 2) | Necesita alumnado y `CALIF` | `REQ-NEAE` (fase 2), sobre las escalas de `1.11d` |
| **En `1.11d`**: copia de períodos y escalas al curso siguiente | `REQ-CURSO-002` («criterios de evaluación») | *Rollover* | `1.12b` (§12; especificado en §21, APROBADA 2026-10-20) |
| **En `1.11d`**: interfaces públicas `Acad\Domain` de períodos y escalas | — | Sin consumidor (`ADR-048`) | `1.16` (§7.2) |
| Evaluación por competencias LOMLOE | Punto 6 | C1 | `1.16` (`OPEN-ACAD-19`) |
| Asignación de profesorado a grupos/asignaturas y de tutor a un grupo | Ninguno lo pide en `REQ-ACAD-001` | C3 | **`ADR-063 §5.4`**: candidato `1.12`; especificada y aprobada antes de aprobar la especificación de `1.13` o `1.16` |
| Resolutores de ámbito `grupo`, `clase`, `departamento` | `RPERM-004` | C2, C3 | **`ADR-063 §5.2`/`§5.4`**: `acad` aporta la pertenencia; cada módulo dueño del recurso registra su resolutor; `departamento` sin dueño (`§5.5`) |
| Asignación de alumnado a grupos, listas de clase | `REQ-ALUM-003` | Necesita `Student`/`Enrollment` | `1.15` |
| Capacidad y ratio de un grupo | `§4.2.1`, `REQ-INF-001` | Lo controla la matrícula | `ALUM`/`INF` (`OPEN-ACAD-20`) |
| Unidades concertadas y no concertadas | `§4.1` | Afecta a facturación | Con `REQ-FIN` si se pide (`OPEN-ACAD-21`) |
| Copia de la estructura al curso siguiente | `REQ-CURSO-002` | *Rollover* | `1.12b` |
| Importación de estructura | `REQ-ONB-002`/`-003` | Importador genérico | `1.24`/`1.25` |
| Exportación de la estructura | Ningún requisito | — | No se declara `exportar` |
| Índice en tablas ya existentes que usaron `tenantForeignId()` | #407 | El arreglo no es retroactivo | Inventario en el cierre; issue aparte si procede |
| Eventos de dominio | — | Ningún consumidor (`ADR-048`) | Con su primer consumidor |

### 1.4 Dependencias

- **`REQ-CURSO` está implementado** (1.10) y la reapertura está implementada en `1.11c`, que no afecta: la reapertura cambia `academic_years.status` y el disparador deja de bloquear (`RN-CURSO-46`).
- **Cambios en el módulo `curso`** aprobados para este paso: §1.2 (búsqueda por identificador interno, `GET /academic-years/selectable`, comprobación de `retired_at` en su comando de siembra). La sesión principal actualiza `docs/modulos/REQ-CURSO/` (no es carpeta de esta especificación).
- **`ADR-062` y `ADR-063` ACEPTADOS** el 2026-10-10 (ratificados por el usuario, `OPEN-062-02` y `OPEN-063-02` = sí). La implementación se divide en dos entregas: `1.11-a` (infraestructura compartida de `§1.2`) y `1.11-b` (módulo `acad`).
- **Primera tabla real con `academic_year_id`** del producto: `academic_year_reopenings` (`1.11c`, implementada).

---

## 2. Actores y roles implicados

| Actor | Qué hace en este módulo |
|-------|--------------------------|
| **Administrador de Centro** | Crea, edita y borra la estructura (siembra aprobada, `OPEN-ACAD-09`) |
| **Dirección / Jefatura**, **Secretaría**, **Docente**, **Tutor de grupo**, **Orientador** | Consultan la estructura |
| **Familias, alumnado** | Nada en `1.11` |
| **Roles personalizados** | Ciudadanos de primera: ninguna regla compara códigos de rol (`RN-PERM-46`) |
| **Módulos consumidores** (`ALUM`, `CALIF`, horarios, `CURSO` en el *rollover*) | Referencian grupos, cursos de etapa y asignaturas por clave foránea y leen por interfaces de `Acad\Domain` (§7) |
| **`soporte_plataforma`**, Super Administrador | Sin permisos de este módulo |

---

## 3. Conceptos

### 3.1 Jerarquía

```
Curso académico (REQ-CURSO)
└── Etapa (education_levels)            tipo de etapa + régimen jurídico
    └── Curso de etapa (courses)        «2º ESO»
        ├── Grupo (groups)              «2º ESO A»
        │   ├── Subgrupo (subgroups)
        │   └── Asignaturas que cursa (group_subjects) ⊆ asignaturas del curso de etapa
        └── Asignatura (subjects)       «Matemáticas de 2º ESO»
```

Todas las entidades del árbol pertenecen a **un único** curso académico, el de su padre (`RN-CURSO-27`), impuesto por clave foránea compuesta (`datos.md §2`).

### 3.2 Curso académico de referencia (`REQ-CURSO/funcional.md §3.2`, `ADR-057 §5.8`)

- **Listados**: parámetro `academic_year` (`public_id`). Omitido ⇒ curso activo; sin activo ⇒ `404 curso.no_active_year`.
- **Alta de una etapa** (raíz): `academic_year` explícito en el cuerpo (`RN-CURSO-22`).
- **Alta de un hijo**: el curso académico es el del padre; el cuerpo **no** admite `academic_year` (`RN-ACAD-05`).
- **Escritura sobre una entidad existente**: el curso académico es el de la fila, inmutable (`RN-ACAD-06`).

### 3.3 Tipo de etapa y régimen (`ADR-020`, `OPEN-ACAD-04` = A)

- **Tipo de etapa** (`stage`): vocabulario **cerrado** con los seis valores de `REQ-ACAD-001`: `infantil`, `primaria`, `eso`, `bachillerato`, `fp`, `universidad`.
- **Etapa del centro**: la crea el centro, con nombre, tipo y **régimen** (`publico`, `privado`, `concertado`). El centro objetivo crea dos etapas de tipo `infantil` («primer ciclo», `privado`; «segundo ciclo», `concertado`).
- El régimen se **consulta**; ninguna regla de `1.11` depende de él.

### 3.4 Contenido del centro traducible (`ADR-021`, **`ADR-062`**)

Los nombres de etapas, cursos de etapa y asignaturas son contenido del centro y acabarán en documentos emitidos en el idioma del destinatario (`1.17`). Se guardan según `ADR-062`: una columna `name_translations jsonb` con claves del vocabulario cerrado `es-ES`, `en`, `de`, `fr`, validada en servidor contra el idioma por defecto y los idiomas activos del centro, y resuelta en servidor. Los nombres de grupo y subgrupo, y todo `code`, **no** son traducibles (`ADR-062 §2`, decisión de esta especificación). Resumen del contrato en el Anexo A.

---

## 4. Flujos principales

### 4.1 Consultar la estructura de un curso académico

1. El usuario con `estructura_academica.leer` abre «Estructura académica». La pantalla toma el curso académico de la URL (`?academic_year=`) o, si no lo hay, el activo.
2. `GET /education-levels?academic_year=<id>`. El servidor resuelve el curso por `AcademicYearDirectory` (404 si no existe o es de otro centro), invoca `AcademicYearReadAccess::assertCanRead` (404 si es `cerrado`/`archivado` sin `curso_historico.leer`) y devuelve las etapas.
3. El usuario baja por el árbol; cada lectura repite el paso 2 con el curso académico **de la fila** (detalle, resuelto por identificador interno, `OPEN-ACAD-12`) o del filtro (listado).
4. Con un curso de solo lectura seleccionado, aviso persistente y ninguna acción de escritura (§10.2). El servidor decide igualmente.

### 4.2 Alta de una etapa

1. Con `estructura_academica.crear`, «Nueva etapa»: código, nombre (componente de campo traducible: una entrada por idioma activo, el por defecto primero y obligatorio), tipo de etapa, régimen, posición.
2. `POST /education-levels` con `academic_year` explícito.
3. El servidor valida (`RN-ACAD-01..05`, `-10..-12`) y crea. En un curso de solo lectura, el disparador lo rechaza (`409 academic-year-closed`).
4. Auditoría `created` (política `Full`; las traducciones, desglosadas por idioma, `ADR-062 §5.5`).

### 4.3 Alta de un curso de etapa, grupo, subgrupo o asignatura

Igual que §4.2, **sin** `academic_year` (`RN-ACAD-05`). El padre llega de dos formas, con respuesta distinta si no es válido:

- **En el cuerpo** (`education_level` para un curso de etapa, `course` para un grupo o una asignatura): padre inexistente, borrado o de otro centro ⇒ `422 acad.validation.parent_not_found` en su campo.
- **En la ruta** (subgrupo, `POST /groups/{public_id}/subgroups`): grupo inexistente, borrado o de otro centro ⇒ **`404`**, como cualquier recurso de la ruta (`ADR-038 §6.4`). Grupo de un curso cerrado sin `curso_historico.leer` ⇒ también `404` (`RN-ACAD-08`); con él, `409 academic-year-closed` al escribir.

### 4.4 Edición

`PATCH` con el subconjunto de campos editables (`api.md`). `name_translations` se **fusiona** por idioma (`ADR-062 §5.2`). Nunca cambia el padre ni el curso académico (`RN-ACAD-06`). En un curso de solo lectura, `409 academic-year-closed`.

### 4.5 Borrado

Borrado lógico (`INV-004`), solo sin hijos vivos (`RN-ACAD-20`). Sin restauración por API en `1.11` **[DERIVADA]**.

### 4.6 Asignar asignaturas a un grupo

1. Desde la ficha de un grupo: lista de las asignaturas de su curso de etapa con casillas.
2. `PUT /groups/{public_id}/subjects` con la lista completa de `public_id`.
3. El servidor comprueba que son asignaturas vivas del mismo curso de etapa (`RN-ACAD-30`), calcula altas y bajas y las aplica por instancia en una transacción (`AR-15`).
4. Responde el conjunto resultante.

### 4.7 Elegir curso académico en el selector

1. En pantallas con `meta.academicYearScoped: true` (§10.2), el selector muestra el curso de la URL o el activo.
2. Las opciones salen de `GET /academic-years/selectable` (#409): `activo` y `planificacion` y, con `curso_historico.leer`, `cerrado`/`archivado`.
3. Al elegir otro, la pantalla navega a la misma ruta con `?academic_year=<public_id>`. Nada se guarda en sesión ni en `localStorage`.

> **Sub-paso `1.11d`** (§4.8 y §4.9). La forma funcional aprobada en `1.11` (`OPEN-ACAD-17` = A + Y, `OPEN-ACAD-18` = A) se conserva en el primer párrafo de cada apartado; lo que sigue es la **especificación ejecutable aprobada el 2026-10-16**, con reglas `RN-ACAD-50` a `-89` (§5.7-§5.10) y criterios `CA-ACAD-063` a `-106` (§13.11-§13.18). Donde una regla depende de una pregunta nueva (§15.2), el texto aplica la opción decidida por el usuario y la cita.

### 4.8 Períodos lectivos — sub-paso `1.11d` (`OPEN-ACAD-17` = A + Y, aprobada)

*Forma aprobada*: con `configuracion_academica.crear`, **por curso académico y etapa**, el centro define sus períodos: tipo (`trimestre`, `cuatrimestre`, `semestre`), posición, nombre traducible (`ADR-062`), inicio y fin. Fechas dentro de las del curso académico; sin solape entre períodos de la misma etapa; **todos los períodos de una etapa del mismo tipo**. `acad` es dueño de los períodos lectivos; `CALIF` define sus períodos de evaluación (incluidas final y extraordinaria) referenciándolos. Se bloquean con el curso académico como cualquier dato por curso.

**4.8.1 Consultar los períodos de una etapa.** Con `configuracion_academica.leer` y `estructura_academica.leer`, en la ficha de una etapa (`acad-level-detail`, §10.5; `OPEN-ACAD-29`), sección «Períodos lectivos»: la SPA pide **siempre** `GET /academic-periods?academic_year=<public_id del curso académico de la etapa>&education_level=<public_id de la etapa>`: envía el `academic_year` de la propia etapa (el de su representación, `api.md §1.1`), nunca lo omite. Así la sección muestra los períodos de la etapa aunque su curso académico no sea el activo (una etapa de un curso en `planificacion`, o de uno `cerrado` para quien tiene `curso_historico.leer`); si lo omitiera, la API filtraría por el curso activo y la combinación con una etapa de otro curso daría una colección vacía (`api.md §11.5`) **[decisión del usuario, 2026-10-17, revisión de documentación M-5]**. El servidor invoca `AcademicYearReadAccess` sobre ese curso (`RN-ACAD-52`) y devuelve los períodos vivos ordenados por `starts_on`. La API (`api.md §11.5`) no cambia.

**4.8.2 Alta.** Con `configuracion_academica.crear`, «Nuevo período»: etapa (fija, la de la ficha), tipo, nombre (campo traducible), inicio, fin y posición opcional. `POST /academic-periods` con `education_level` en el cuerpo y **sin** `academic_year` (`RN-ACAD-51`). En una transacción, el servicio lee las fechas del curso académico de la etapa con la **lectura con bloqueo compartido** de `curso` (`FOR SHARE` sobre la fila del curso, `REQ-CURSO RN-CURSO-55`), **después** bloquea la fila de la etapa (`FOR UPDATE`; orden curso → etapa, `RN-ACAD-54`) y valida, por este orden: padre vivo y legible, tipo, fechas, dentro del curso académico, mismo tipo que los demás períodos vivos de la etapa y sin solape (`RN-ACAD-55` a `-59`). Inserta; auditoría `created` (`Full`, traducciones desglosadas). Curso de solo lectura ⇒ el disparador responde `409 academic-year-closed`.

**4.8.3 Edición.** `PATCH /academic-periods/{public_id}` con el subconjunto de `period_type`, `name_translations` (fusión por idioma), `starts_on`, `ends_on`, `position`. Las reglas `RN-ACAD-55` a `-59` se evalúan sobre el **estado resultante**, excluyendo el propio período, con los mismos bloqueos y en el mismo orden que el alta (curso en `FOR SHARE`, después etapa en `FOR UPDATE`). `education_level` o `academic_year` en el cuerpo ⇒ `422 acad.validation.parent_immutable`.

**4.8.4 Borrado.** `DELETE /academic-periods/{public_id}` ⇒ borrado lógico, sin dependientes dentro de `acad` en `1.11d` (`RN-ACAD-61`). Una etapa con períodos vivos ya no se puede borrar (`RN-ACAD-61`, ampliación de `RN-ACAD-20`).

**4.8.5 Cambiar el esquema de una etapa** (p. ej. de trimestres a semestres): se borran los períodos existentes y se crean los nuevos; un `PATCH` de `period_type` solo prospera si el período es el único vivo de su etapa (`RN-ACAD-59`). **[DERIVADA]**: es la consecuencia directa de «todos del mismo tipo» sin una operación de sustitución en bloque, que ningún requisito pide.

### 4.9 Escalas de calificación — sub-paso `1.11d` (`OPEN-ACAD-18` = A, aprobada)

*Forma aprobada*: con `configuracion_academica.crear`, **por curso académico**, el centro define escalas: `numerica` (mínimo, máximo, decimales; «0-10» es una instancia), `cualitativa` (valores ordenados con código y etiqueta traducible) o `mixta` (numérica con tramos etiquetados). «Personalizable» = el centro crea las suyas. **Cada escala declara qué es aprobado** (umbral numérico o valores cualitativos marcados como aprobado), porque `REQ-CURSO-003` y `REQ-CALIF-003` cuentan «suspensos». Qué escala usa cada asignatura o etapa lo decide `1.16` (`CALIF`), su único consumidor. Al cerrarse el curso académico, el disparador congela sus escalas.

**4.9.1 Consultar.** Con `configuracion_academica.leer`, «Escalas de calificación» (`acad-grading-scales`, §10.5): `GET /grading-scales?academic_year=<public_id>` (omitido ⇒ activo). Cada escala viaja con sus valores o tramos dentro (`api.md §11.2`). Ficha de una escala: `GET /grading-scales/{public_id}`.

**4.9.2 Alta.** Con `configuracion_academica.crear`, «Nueva escala»: código, nombre (traducible), tipo y, según el tipo:

| Tipo | Campos | Qué es aprobado |
|------|--------|-----------------|
| `numerica` | `min_value`, `max_value`, `decimals` (0, 1 o 2), `passing_min_value` | Una nota ≥ `passing_min_value` (`RN-ACAD-74`) |
| `cualitativa` | `values`: lista ordenada de `{code, label_translations, is_passing}` | Los valores con `is_passing: true`; al menos uno (`RN-ACAD-82`) |
| `mixta` | Los cuatro de `numerica` + `values`: lista ordenada de tramos `{code, label_translations, range_min, range_max}` | El umbral numérico, como en `numerica`; cada tramo lo hereda (`RN-ACAD-87`) |

`POST /grading-scales` con `academic_year` explícito (`RN-ACAD-51`), la escala y su lista `values` en el mismo cuerpo; todo en una transacción (`RN-ACAD-80`, `OPEN-ACAD-27`). Auditoría `created` de la escala y de cada valor.

**4.9.3 Edición.** `PATCH /grading-scales/{public_id}`: `code`, `name_translations` (fusión), los campos numéricos y `values`. Si llega `values`, es el **conjunto completo** resultante: un elemento con `public_id` actualiza ese valor (y su `label_translations` se fusiona por idioma), uno sin `public_id` crea un valor nuevo y un valor existente que no aparece se borra (lógico) (`RN-ACAD-88`). Todas las reglas se evalúan sobre el estado resultante (campos numéricos nuevos con tramos nuevos o existentes). `scale_type` no se edita (`RN-ACAD-70`).

**4.9.4 Borrado.** `DELETE /grading-scales/{public_id}` ⇒ borrado lógico de la escala **y de sus valores vivos**, por instancia y en una transacción (`RN-ACAD-76`). Sin consumidores en `1.11d`; la obligación de vetos para `CALIF` está en `RN-ACAD-77`.

**4.9.5 Curso cerrado.** Toda escritura sobre períodos, escalas o valores de un curso `cerrado`/`archivado` la rechaza el disparador (`409 academic-year-closed`), y su lectura exige `curso_historico.leer` (`RN-ACAD-52`).

---

## 5. Reglas de negocio

*Numeración por bloques de diez (precedente `REQ-CURSO`): `RN-ACAD-14`, `-18`, `-19`, `-22` a `-29` y `-33` a `-39` son **huecos intencionados**, reservados sin uso; no se renumera. `RN-ACAD-13` es regla común aunque su número caiga en el bloque de etapas: se añadió con `ADR-062` y no se movió para no cambiar referencias. Las reglas de `1.11d` son `RN-ACAD-50` a `-89` (§5.7-§5.10); `RN-ACAD-43` a `-49`, `-64` a `-69` y `-78`/`-79` son huecos intencionados.*

### 5.1 Comunes a todas las entidades del árbol

| ID | Regla |
|----|-------|
| `RN-ACAD-01` | `code` obligatorio, recortado, no vacío. Único entre los **hermanos vivos**: etapas del mismo curso académico, cursos de etapa del mismo curso académico, grupos y asignaturas del mismo curso de etapa, subgrupos del mismo grupo. Conflicto ⇒ `422 acad.validation.code_taken`; la carrera sobre el índice único se traduce al mismo `422`, nunca `500` |
| `RN-ACAD-02` | `code` es contenido del centro, texto libre, no se traduce y **no es identificador de ruta** (`ADR-051`: no cumple C1). Límite de longitud **[DERIVADA]**: el criterio de `RN-CURSO-01`, fijado en implementación |
| `RN-ACAD-03` | El nombre de etapa, curso de etapa y asignatura es contenido traducible (`ADR-062`): `name_translations` con claves del vocabulario `es-ES`, `en`, `de`, `fr`. **Alta**: obligatorio el idioma por defecto **del centro** (`default_locale`, `OPEN-062-01`; no `es-ES` siempre), toda clave en los idiomas activos, valores recortados no vacíos. **`PATCH`**: fusión por idioma (`ADR-038 §9.2` regla 4): una clave cambia solo ese idioma, `null` lo quita, `""` es `422`; quitar el idioma por defecto actual es `422`; las claves no mencionadas no se tocan aunque su idioma ya no esté activo. Errores con los **códigos comunes** de `ADR-062 §5.2` punto 4: `translations.default_locale_required`, `translations.locale_not_active`, `translations.empty_value` (prefijo final según el issue #60). Longitud máxima por valor **[DERIVADA]**: el mismo criterio de textos cortos de `RN-ACAD-02`. El nombre de grupo y subgrupo es texto simple obligatorio (`acad.validation.name_required`) |
| `RN-ACAD-04` | `position` entero ≥ 1, opcional (por defecto, la última entre hermanos + 1); no único (empate ⇒ por `code`) **[DERIVADA]** |
| `RN-ACAD-05` | El curso académico de una entidad hija **es el de su padre**. El alta de un hijo no admite `academic_year` (`422 acad.validation.academic_year_not_allowed`) |
| `RN-ACAD-06` | El padre y el curso académico son **inmutables**. `PATCH` con `academic_year`, `education_level`, `course` o `group` ⇒ `422 acad.validation.parent_immutable` **[DERIVADA]** |
| `RN-ACAD-07` | Toda escritura sobre una entidad de un curso `cerrado`/`archivado` la rechaza el disparador y se responde `409 urn:pge:error:academic-year-closed` (`RN-CURSO-20/21`) |
| `RN-ACAD-08` | Toda lectura (listado, detalle y listados anidados) invoca `AcademicYearReadAccess::assertCanRead`. Curso `cerrado`/`archivado` sin `curso_historico.leer` ⇒ **`404`** con el mismo cuerpo que un recurso inexistente (`RN-CURSO-25`, `RN-CURSO-33`) |
| `RN-ACAD-09` | Toda entidad del módulo es del centro entero y no contiene datos personales: su lectura no tiene ámbito restringido (`permisos.md §3.1`, `ADR-063 §5.1`) |
| `RN-ACAD-13` | Ningún módulo resuelve por su cuenta un campo traducible: la API y los documentos usan el resolutor común (`ADR-062 §5.3`). **En etapa, curso de etapa y asignatura** (las entidades con `name_translations`), `name` en la respuesta es de solo lectura; si llega en el cuerpo de una escritura se ignora, no es `422` (`ADR-062 §5.4` punto 2). **En grupo y subgrupo**, `name` es texto simple, no traducible, obligatorio y escribible en `POST`/`PATCH` (`RN-ACAD-03`) |

### 5.2 Etapas

| ID | Regla |
|----|-------|
| `RN-ACAD-10` | `stage` ∈ vocabulario cerrado de §3.3 (`422 acad.validation.stage_invalid`). Varias etapas del mismo curso académico pueden compartir `stage` |
| `RN-ACAD-11` | `regime` ∈ {`publico`, `privado`, `concertado`}, obligatorio (`422 acad.validation.regime_invalid`). Editable mientras el curso académico admita escritura; el de un curso cerrado lo congela el disparador |
| `RN-ACAD-12` | `stage` y `regime` viajan sin traducir (`ADR-038 §3.2`); la SPA traduce la etiqueta |

### 5.3 Cursos de etapa, grupos, subgrupos y asignaturas

| ID | Regla |
|----|-------|
| `RN-ACAD-15` | Un curso de etapa pertenece a una etapa del mismo curso académico (FK compuesta) |
| `RN-ACAD-16` | Un grupo pertenece a un curso de etapa; un subgrupo a **un solo** grupo (`OPEN-ACAD-06` = A). No existe relación subgrupo-asignatura en `1.11` |
| `RN-ACAD-17` | Una asignatura pertenece a **un** curso de etapa (`§16.2`) |

### 5.4 Borrado

| ID | Regla |
|----|-------|
| `RN-ACAD-20` | (Ampliada por `RN-ACAD-61`: desde `1.11d`, también etapa con períodos lectivos vivos.) No se borra una entidad con hijos vivos: etapa con cursos de etapa (o, desde `1.11d`, con períodos lectivos); curso de etapa con grupos o asignaturas; grupo con subgrupos o asignaturas asignadas; asignatura asignada a algún grupo ⇒ `409 acad.conflict.has_dependents` con `params.dependents` **[DERIVADA]** |
| `RN-ACAD-21` | Las referencias de otros módulos no existen en `1.11`. **Obligación para la especificación de `1.15`** (`OPEN-ACAD-13` = A): antes de crear su FK a `groups`, añade en `Acad\Domain` un registro de vetos de borrado con la forma de `RN-CURSO-30` |

### 5.5 Asignación de asignaturas a grupos

| ID | Regla |
|----|-------|
| `RN-ACAD-30` | Un grupo solo cursa asignaturas de su curso de etapa (FK compuesta con `course_id`). Asignatura ajena, borrada o de otro centro ⇒ `422 acad.validation.subject_not_in_course` |
| `RN-ACAD-31` | Asignación **explícita** (`OPEN-ACAD-07` = A) |
| `RN-ACAD-32` | `PUT /groups/{id}/subjects` sustituye el conjunto completo; idempotente; altas y bajas por instancia (`AR-15`); un `PUT` sin cambios no escribe |

### 5.6 Selector de curso académico

| ID | Regla |
|----|-------|
| `RN-ACAD-40` | El curso académico seleccionado vive solo en la URL. Sin parámetro, el activo; sin activo, estado «sin curso activo» con el selector abierto, no un error |
| `RN-ACAD-41` | Opciones del selector: las que devuelve `GET /academic-years/selectable` (#409). Aunque la interfaz ofreciera un curso cerrado a quien no tiene `curso_historico.leer`, el servidor responde `404` (`RN-ACAD-08`) |
| `RN-ACAD-42` | Con un curso de solo lectura seleccionado: aviso persistente y ninguna acción de escritura visible |

### 5.7 Comunes a períodos y escalas (`1.11d`)

| ID | Regla |
|----|-------|
| `RN-ACAD-50` | Períodos lectivos, escalas y sus valores son el recurso de permiso **`configuracion_academica`** (`leer`, `crear`, `actualizar`, `eliminar`; `permisos.md §2`), distinto de `estructura_academica`: tener uno no da el otro. Son datos del centro entero sin datos personales: lectura sin ámbito restringido (como `RN-ACAD-09`). Escribir los valores de una escala (`values` en `POST`/`PATCH` de la escala) exige el permiso de la operación sobre la escala (`crear` o `actualizar`), no `crear`+`eliminar` **[DERIVADA]**, precedente `PUT /groups/{id}/subjects` |
| `RN-ACAD-51` | **Curso académico** (`REQ-CURSO/funcional.md §3.2`): un período es hijo de una etapa y su curso es el de ella; su alta lleva `education_level` en el cuerpo y **no** admite `academic_year` (`422 acad.validation.academic_year_not_allowed`, como `RN-ACAD-05`). Una escala es raíz: su alta exige `academic_year` explícito (`422 acad.validation.academic_year_required`/`academic_year_not_found`, como la etapa). Un valor de escala hereda el curso de su escala. Padre, curso académico y escala de un valor son **inmutables** (`422 acad.validation.parent_immutable`, como `RN-ACAD-06`) |
| `RN-ACAD-52` | `RN-ACAD-07` y `RN-ACAD-08` se aplican tal cual a las tres tablas nuevas: escritura en curso `cerrado`/`archivado` ⇒ `409 academic-year-closed` (disparador); toda lectura —listado y detalle— invoca `AcademicYearReadAccess` ⇒ `404` sin `curso_historico.leer`. Un padre del **cuerpo** (`education_level`) de un curso ilegible responde `422 acad.validation.parent_not_found`, y un `academic_year` del cuerpo ilegible `422 acad.validation.academic_year_not_found`, indistinguibles de uno inexistente (desviación S-3 de §16) |
| `RN-ACAD-53` | **Textos**: el nombre de período y de escala (`name_translations`) y la etiqueta de un valor (`label_translations`) son contenido traducible con las reglas de `RN-ACAD-03` y `RN-ACAD-13` (`ADR-062`): obligatorio el idioma por defecto del centro en el alta, `PATCH` por fusión, `name`/`label` resueltos y de solo lectura, tope `StructureLimits::MAX_TEXT_LENGTH` por idioma. El `code` de escala y de valor sigue `RN-ACAD-01`/`-02` (obligatorio, recortado, texto libre no traducible, caracteres no guardables ⇒ `422 acad.validation.invalid_characters`). Los períodos **no** tienen `code`: la forma aprobada (§4.8) no lo incluye y no se añade **[DERIVADA: no inventar]** |
| `RN-ACAD-54` | **Serialización** **[DERIVADA]; reescrita el 2026-10-16 por `OPEN-CURSO-25` = B (decisión del usuario, que acepta el cambio de esta regla ya aprobada)**. (1) **Alta y edición de un período**, dentro de una transacción y en este orden: (a) resuelve la etapa y su `academic_year_id` (inmutable, `RN-ACAD-06`, así que leerlo sin bloqueo es seguro) y comprueba que es legible (`RN-ACAD-52`); (b) lee el curso académico con **`AcademicYearSharedLock::lockForShareById()`** de `Curso\Domain` (`FOR SHARE` sobre la fila del curso, retenido hasta el final de la transacción; `REQ-CURSO RN-CURSO-55` a `-58`); (c) toma `FOR UPDATE` sobre la fila de su **etapa** y comprueba que sigue viva; (d) lee los períodos hermanos y valida. Orden **curso → etapa**, el de `RN-CURSO-32`/`-58`: ninguna transacción de `acad` bloquea una fila propia antes de pedir el curso en esta operación. (2) **Borrado de un período**: `FOR UPDATE` sobre la etapa, sin la lectura bloqueante del curso (el borrado no depende de las fechas del curso) **[DERIVADA]**; su disparador pide `FOR SHARE` sobre el curso después, como cualquier escritura, y no forma ciclo (`RN-CURSO-58`). El borrado de una etapa ya toma `FOR UPDATE` sobre su fila (código de `1.11-b`). (3) Toda edición o borrado de una escala toma `FOR UPDATE` sobre la fila de la escala antes de leer sus valores (las escalas no dependen de las fechas del curso). Así las reglas entre filas (`RN-ACAD-57`, `-58`, `-59`, `-83`, `-85`, `-86`) no se rompen por carrera aunque el motor no las imponga (`datos.md §8.3`), tampoco frente a un `PATCH` de fechas del curso simultáneo (`REQ-CURSO CA-CURSO-120`). La violación de un índice único por carrera se traduce al `422` de su regla, nunca `500` |

### 5.8 Períodos lectivos

| ID | Regla |
|----|-------|
| `RN-ACAD-55` | `period_type` ∈ {`trimestre`, `cuatrimestre`, `semestre`}, obligatorio (`422 acad.validation.period_type_invalid`); viaja sin traducir (`ADR-038 §3.2`), la SPA traduce la etiqueta con rama por defecto (`ADR-038 §7.3`) |
| `RN-ACAD-56` | `starts_on` y `ends_on` son fechas `AAAA-MM-DD` obligatorias (`422 acad.validation.date_invalid`), con `ends_on > starts_on` (`422 acad.validation.period_ends_before_start`; `CHECK` en el motor). Mismo criterio que `academic_years` (`RN-CURSO-02`); la forma aprobada (`datos.md §1.7`) fija `>` y no `>=` |
| `RN-ACAD-57` | El período cae **dentro** de su curso académico: `starts_on ≥ academic_year.starts_on` y `ends_on ≤ academic_year.ends_on`, bordes incluidos (`422 acad.validation.period_outside_academic_year`, `params.academic_year_starts_on`/`ends_on`). **Reescrita el 2026-10-16 por `OPEN-CURSO-25` = B** (decisión del usuario, que acepta el cambio de esta regla ya aprobada): las fechas del curso se toman del `AcademicYearSummary` (`$startsOn`/`$endsOn`) que devuelve **`AcademicYearSharedLock::lockForShareById()`** en el paso (b) de `RN-ACAD-54`, leído bajo `FOR SHARE` y dentro de la transacción de la escritura; **no** de `AcademicYearDirectory` (memoizado por petición y sin bloqueo, que podía validar contra fechas que un `PATCH` concurrente estaba cambiando) y nunca de `academic_years` (`AR-01`). Mientras la transacción del período retiene ese bloqueo, ningún `PATCH` de fechas del curso puede confirmar, y al revés (`REQ-CURSO RN-CURSO-58`): la regla se mantiene también bajo concurrencia. Junto con `RN-ACAD-62` (que protege los períodos ya confirmados frente a un cambio de fechas), la regla es invariante de los períodos vivos mientras `acad` esté contratado; un cambio de fechas hecho con `acad` descontratado puede dejar períodos fuera, que incumplirán la regla hasta su siguiente edición (`REQ-CURSO RN-CURSO-52`, decisión del usuario `OPEN-CURSO-26` = A) |
| `RN-ACAD-58` | **Sin solape** entre períodos **vivos** de la misma etapa. Los intervalos son **cerrados** (los dos días cuentan): compartir un día es solape; terminar el 22 y empezar el 23 no lo es **[DERIVADA]**: una fecha con dos períodos haría ambigua la asignación de una calificación o una falta. Violación ⇒ `422 acad.validation.period_overlap` con `params.period` (`public_id` del período con el que solapa). Períodos de etapas distintas pueden solaparse |
| `RN-ACAD-59` | **Mismo tipo**: todos los períodos vivos de una etapa tienen el mismo `period_type`. Un alta o un `PATCH` de `period_type` que lo rompa ⇒ `422 acad.validation.period_type_mismatch` con `params.period_type` (el de los demás). Garantía: validación de servicio bajo el bloqueo de `RN-ACAD-54`, **no** del motor (`datos.md §8.3`) |
| `RN-ACAD-60` | `position` sigue `RN-ACAD-04` entre los períodos vivos de la etapa (opcional, ≥ 1, por defecto la última + 1, no única). Orden por defecto de los listados: `starts_on`, desempate por `position` e `id` **[DERIVADA]**: el orden natural de un período es el cronológico. No se exige que `position` y fechas sean coherentes |
| `RN-ACAD-61` | Un período se borra lógicamente (`INV-004`) sin comprobar dependientes: en `1.11d` nada lo referencia. **Amplía `RN-ACAD-20`**: una **etapa** con períodos vivos no se borra ⇒ `409 acad.conflict.has_dependents` con `params.dependents` que incluye `academic_periods`. Compatible con lo implementado: hasta `1.11d` ninguna etapa tiene períodos |
| `RN-ACAD-62` | **Cambio de fechas del curso académico** (`REQ-CURSO`, solo en `planificacion`, `RN-CURSO-06`): por `OPEN-ACAD-24` = B (decidida), `curso` rechaza un `PATCH` de fechas que deje fuera algún período vivo, mediante una validación que `acad` registra en el punto de extensión de `Curso\Domain` especificado en `REQ-CURSO/funcional.md §5.6` (`RN-CURSO-49` a `-54`, `-60`). **Validación de `acad`**: una clase de `acad` (en `Acad\Infrastructure`) que implementa `AcademicYearDateChangeCheck`, con `moduleCode()` = `acad`, registrada en `AcadServiceProvider::boot()`. Recibe el `AcademicYearDateChange` y, con **una** consulta de solo lectura y sin bloqueos de fila (`RN-CURSO-51`) sobre los períodos **vivos** de ese curso académico (todas sus etapas; índice `(tenant_id, academic_year_id)`), cuenta los que quedarían fuera de las fechas resultantes (`starts_on` anterior al nuevo inicio o `ends_on` posterior al nuevo fin; bordes incluidos, como `RN-ACAD-57`) y obtiene el menor `starts_on` y el mayor `ends_on` de todos los períodos vivos del curso. Si ninguno queda fuera, devuelve `null`; si no, un `AcademicYearDateChangeFailure` con: **`code`** = **`acad.conflict.periods_outside_academic_year`**; **`message`** = la clave del mismo nombre de `lang/{es,en,de,fr}/acad.php` (§10.5.5) traducida al idioma resuelto de la petición (`ADR-038 §6.3`), con los dos parámetros de fecha; **`params`** = `{"count": <entero ≥ 1, períodos vivos que quedarían fuera>, "periods_starts_on": "AAAA-MM-DD", "periods_ends_on": "AAAA-MM-DD"}`, donde las dos fechas son el menor inicio y el mayor fin de **todos** los períodos vivos del curso (las fechas que el curso debe incluir para que ninguno quede fuera). **Sin** `public_id`, nombre, etapa ni ningún otro atributo de los períodos: quien hace el `PATCH` (`curso_academico.actualizar`) puede no tener `configuracion_academica.leer` ni `estructura_academica.leer` (`REQ-CURSO RN-CURSO-53`, `permisos.md §5`), y los `params` no llevan valores de atributos personales (`ADR-035 §5`; los períodos no contienen ninguno, `RN-ACAD-50`). La entrada sale en `errors.date_change[]` del `409 curso.conflict.date_change_checks_failed` (`REQ-CURSO/api.md`). Un período **borrado** no cuenta. Con `acad` no contratado en el centro no se ejecuta (`RN-CURSO-52`). Criterios: `CA-ACAD-102` y `REQ-CURSO CA-CURSO-113` a `-115`, `-120` |
| `RN-ACAD-63` | **Obligación para la especificación de `1.16`** (`CALIF`), no regla de `1.11d`: antes de que `CALIF` cree una FK a `academic_periods`, `acad` ofrece en `Acad\Domain` un registro de vetos de borrado y de edición de fechas/tipo de un período referenciado (forma de `RN-ACAD-21`/`RN-CURSO-30`) |

### 5.9 Escalas de calificación

| ID | Regla |
|----|-------|
| `RN-ACAD-70` | `scale_type` ∈ {`numerica`, `cualitativa`, `mixta`}, obligatorio en el alta (`422 acad.validation.scale_type_invalid`), viaja sin traducir, e **inmutable**: en un `PATCH` ⇒ `422 acad.validation.scale_type_immutable` **[DERIVADA]**: cambiarlo invalidaría todos sus valores; el motor lo refuerza (FK de `grading_scale_values` con `scale_type`, `datos.md §1.9`). Para cambiar de tipo se borra y se crea otra |
| `RN-ACAD-71` | `code` único entre las escalas **vivas** del mismo curso académico (`422 acad.validation.code_taken`, también por carrera); el mismo código en otro curso académico o en una escala borrada se admite. Nombre traducible (`RN-ACAD-53`) |
| `RN-ACAD-72` | En `numerica` y `mixta`, `min_value`, `max_value`, `decimals` y `passing_min_value` son **obligatorios** (`422 acad.validation.scale_field_required` en el campo que falte); en `cualitativa` están **prohibidos** (`422 acad.validation.not_applicable_to_scale_type`) **[DERIVADA]**: son claves conocidas del recurso enviadas donde no aplican, mismo criterio que `curso.validation.status_not_editable` (no se ignoran en silencio) |
| `RN-ACAD-73` | Valores numéricos: decimal con punto, a lo sumo dos decimales y dentro de `numeric(5,2)` (−999,99 a 999,99) (`422 acad.validation.decimal_invalid`); `decimals` entero 0, 1 o 2 (`422 acad.validation.decimals_invalid`); `min_value < max_value` (`422 acad.validation.range_invalid`); `min_value`, `max_value`, `passing_min_value` y los límites de los tramos con **no más decimales que `decimals`** (`422 acad.validation.decimal_precision_exceeded`: con `decimals = 0`, «5,5» no vale). No se imponen límites de negocio al rango (ni «0-10» ni no negativos): «personalizable» y ningún requisito los fija. Todo se valida en servidor antes de llegar al `CHECK` (`INV-010`) |
| `RN-ACAD-74` | **Aprobado** (`REQ-CURSO-003`, `REQ-CALIF-003`): en `numerica` y `mixta`, `min_value ≤ passing_min_value ≤ max_value` (`422 acad.validation.passing_out_of_range`) y es aprobada toda nota `≥ passing_min_value`; en `cualitativa`, los valores con `is_passing: true` (`RN-ACAD-82`). No se exige que exista un valor o tramo de suspenso **[DERIVADA: no inventar]** |
| `RN-ACAD-75` | **Representación de los decimales** en la API (`OPEN-ACAD-26` = A, decidida): la respuesta los da como **cadena** con exactamente `decimals` cifras decimales y punto (`"5.00"`, `"5"`); la entrada admite cadena o número JSON con la forma de `RN-ACAD-73`. Motivo: un `float` en JSON no representa `numeric` exacto (`ADR-038 §3.2` ya lo prohíbe para importes) |
| `RN-ACAD-76` | Borrar una escala la borra **con sus valores vivos**: los valores son parte de la escala, no dependientes con identidad propia fuera de ella; por instancia (`AR-15`), en una transacción, con una entrada `deleted` por escala y por valor. Sin `has_dependents` en `1.11d` |
| `RN-ACAD-77` | **Obligación para la especificación de `1.16`**, no regla de `1.11d`: antes de que `CALIF` referencie escalas o valores, `acad` ofrece un registro de vetos de borrado y de edición de una escala en uso (precedente `RN-ACAD-21`); qué cambios de una escala en uso se admiten (umbral, etiquetas, tramos) lo decide esa especificación |

### 5.10 Valores cualitativos y tramos

| ID | Regla |
|----|-------|
| `RN-ACAD-80` | Los valores de una escala (`grading_scale_values`) **solo se escriben dentro de la escala**: lista `values` en `POST /grading-scales` y en `PATCH /grading-scales/{id}` (`OPEN-ACAD-27` = A, decidida). No hay *endpoint* propio de valores. Toda la escritura (escala + valores) es una transacción: o se aplica entera o nada |
| `RN-ACAD-81` | En `numerica`, `values` está **prohibido** (`422 acad.validation.not_applicable_to_scale_type`); en `cualitativa` y `mixta` es obligatorio en el alta y no vacío (`422 acad.validation.values_required`) |
| `RN-ACAD-82` | **Cualitativa**: cada valor lleva `code`, `label_translations` e `is_passing` (booleano obligatorio, `422 acad.validation.is_passing_required`); al menos uno con `is_passing: true` (`422 acad.validation.passing_value_required`, en `values`); `range_min`/`range_max` prohibidos (`not_applicable_to_scale_type`) |
| `RN-ACAD-83` | `code` de valor único entre los valores **vivos** de su escala (`422 acad.validation.code_taken` en `values.<n>.code`). `position` **no se envía**: la fija el servidor por el orden de la lista (1, 2, …) **[DERIVADA]**: es la forma de reordenar sin estados intermedios con posiciones repetidas |
| `RN-ACAD-84` | **Mixta**: cada tramo lleva `code`, `label_translations`, `range_min` y `range_max`, con `range_min ≤ range_max` (un tramo de un solo valor, p. ej. «10», es válido) y ambos dentro de `[min_value, max_value]` (`422 acad.validation.band_range_invalid`); `is_passing` prohibido (`not_applicable_to_scale_type`) |
| `RN-ACAD-85` | **Tramos contiguos que cubren la escala** (`OPEN-ACAD-25` = A, decidida): ordenados por `range_min` en el orden de la lista, el primero empieza en `min_value`, el último termina en `max_value`, y cada uno empieza exactamente un paso (`10^−decimals`) después de donde termina el anterior: sin huecos ni solapes (`422 acad.validation.bands_not_contiguous`). Así toda nota válida tiene un único tramo |
| `RN-ACAD-86` | **El umbral cae en una frontera** (`OPEN-ACAD-25` = A, decidida): algún tramo tiene `range_min = passing_min_value` (`422 acad.validation.passing_threshold_inside_band`): ningún tramo mezcla notas aprobadas y suspensas |
| `RN-ACAD-87` | En `mixta`, `is_passing` de un tramo **no se guarda**: se deriva (`range_min ≥ passing_min_value`) y la representación lo incluye calculado **[DERIVADA]**: una sola fuente de verdad del aprobado, la de `RN-ACAD-74` |
| `RN-ACAD-88` | **Sincronización del conjunto** en `PATCH` con `values`: elemento con `public_id` de un valor vivo **de esa escala** ⇒ se actualiza (sus `label_translations` se fusionan por idioma); `public_id` de otra escala, de otro centro, borrado o inexistente ⇒ `422 acad.validation.value_not_in_scale`, nada cambia; elemento sin `public_id` ⇒ alta; valor vivo ausente ⇒ borrado lógico. Orden de aplicación: borrados, actualizaciones, altas **[DERIVADA]**. `public_id` repetido en la lista ⇒ `422 value_not_in_scale`. Un intercambio de `code` entre dos valores supervivientes en un mismo `PATCH` lo rechaza el índice único (`422 code_taken`): limitación conocida, se hace en dos `PATCH`. Un `PATCH` sin cambios efectivos no escribe ni audita (como `RN-ACAD-32`). Un `PATCH` que cambia campos numéricos sin `values` valida los tramos existentes contra los nuevos campos (`RN-ACAD-84` a `-86`) |
| `RN-ACAD-89` | Tope de **100** elementos en `values` (`422 acad.validation.values_too_many`) **[DERIVADA]**: límite técnico frente a cuerpos desmesurados, mismo orden que `per_page` máximo (`ADR-038 §4.3`); ninguna escala real se le acerca |

---

## 6. Casos límite y errores

| Caso | Comportamiento |
|------|----------------|
| Centro sin curso activo y listado sin `academic_year` | `404 curso.no_active_year`; la pantalla muestra el estado de `RN-ACAD-40` |
| `academic_year` de otro centro o inexistente en un listado | `404` |
| `academic_year` de otro centro o inexistente en el cuerpo del alta de una etapa | `422 acad.validation.academic_year_not_found` |
| Padre del **cuerpo** (`education_level`, `course`) borrado, inexistente o de otro centro | `422 acad.validation.parent_not_found` |
| Padre de la **ruta** (`POST /groups/{public_id}/subgroups`, y también `GET`/`PUT /groups/{public_id}/subjects` y `GET /groups/{public_id}/subgroups`) borrado, inexistente o de otro centro | `404` |
| Alta en un curso académico `cerrado` | `409 academic-year-closed` |
| Alta en un curso académico en `planificacion` | Permitida |
| `PATCH` sin cambios sobre una entidad de curso cerrado | `200` sin escritura (no hay escritura que bloquear) **[DERIVADA]**; con cambios, `409` |
| Detalle de una entidad de curso cerrado sin `curso_historico.leer` | `404` |
| Dos altas simultáneas con el mismo código | Una `201`, otra `422 code_taken` |
| Borrado concurrente del padre mientras se crea un hijo | El servicio comprueba el padre vivo con `FOR SHARE` y el borrado comprueba hijos vivos con `FOR UPDATE` **[DERIVADA]**; una de las dos pierde con `409`/`422` |
| `PUT /groups/{id}/subjects` con `public_id` repetidos | Conjunto |
| Centro con `default_locale = en` y sin `es-ES` activo | Alta con solo `en` válida (`OPEN-062-01`); `es-ES` solo actúa como caída de lectura |
| Centro que cambia `default_locale` | Las filas sin el nuevo idioma se leen por caída (`ADR-062 §5.3`) y no fallan; la siguiente edición del campo exige añadirlo |
| Centro que retira un idioma activo | Las traducciones guardadas se conservan; un `PATCH` que no las menciona no las toca |
| `name` en el cuerpo de una escritura | Se ignora (`RN-ACAD-13`) |
| Issue #60 | Si sigue abierto, los códigos `acad.*`/`translations.*` saldrían con prefijo `core.`; comprobarlo en implementación |

### 6.1 Casos límite de `1.11d`

| Caso | Comportamiento |
|------|----------------|
| Período que termina el día anterior a que empiece otro de la misma etapa | `201` (contiguos, `RN-ACAD-58`) |
| Período que termina el mismo día en que empieza otro | `422 period_overlap` |
| Período que empieza el mismo día que el curso académico o termina el mismo día que él | `201` (bordes incluidos, `RN-ACAD-57`) |
| Dos altas simultáneas de períodos solapados (o de tipos distintos) en la misma etapa | La segunda espera el `FOR UPDATE` de la etapa y, al releer, falla con `422` (`RN-ACAD-54`); nunca se confirman las dos |
| Alta de período mientras se borra su etapa | Serializadas por el `FOR UPDATE` de la etapa: o el borrado ve el período vivo (`409 has_dependents`) o el alta ve la etapa borrada (`422 parent_not_found`) |
| Período solapado con uno **borrado** de la misma etapa | `201`: solo cuentan los vivos |
| Cuatro trimestres, o un semestre de un día de diferencia con el curso | Se admite: ningún requisito fija cuántos períodos tiene cada tipo ni que cubran el curso (`OPEN-ACAD-30`) |
| `PATCH` que cambia `period_type` del único período vivo de una etapa | `200` |
| Curso académico en `planificacion` cuyo `PATCH` de fechas dejaría fuera un período vivo | `OPEN-ACAD-24` = B (decidida): `409 curso.conflict.date_change_checks_failed` desde `curso`, con la entrada `acad.conflict.periods_outside_academic_year` de `RN-ACAD-62` en `errors.date_change[]`; nada cambia (`CA-ACAD-102`). Con `acad` descontratado no se valida (`REQ-CURSO RN-CURSO-52`) |
| `PATCH` de fechas del curso académico y alta (o edición) de un período del mismo curso, simultáneos | Serializados por la fila del curso: el período lee las fechas con la lectura con bloqueo compartido antes de bloquear su etapa (`RN-ACAD-54`, `-57`). Si el período bloquea primero, confirma y el `PATCH` responde `409` si lo dejaría fuera; si el `PATCH` bloquea primero, el período espera, lee las fechas nuevas y responde `422 period_outside_academic_year` si queda fuera. Nunca un período fuera de las fechas confirmadas (`REQ-CURSO CA-CURSO-120`) |
| Alta de período en una etapa de un curso `cerrado` legible (con `curso_historico.leer`) | La lectura con bloqueo compartido devuelve el curso (no decide la escritura) y el disparador rechaza la inserción: `409 academic-year-closed` (`RN-ACAD-52`, `REQ-CURSO RN-CURSO-57`) |
| Escala `numerica` con `decimals = 0` y `passing_min_value = "4.5"` | `422 decimal_precision_exceeded` |
| Escala `mixta` 0-10 con un decimal y tramos 0-4.9, 5-6.9, 7-8.9, 9-10, umbral 5 | `201` |
| Ídem con tramos 0-4.9, 5.1-10 | `422 bands_not_contiguous` (falta 5.0) |
| Ídem con umbral 5 y tramos 0-5.9, 6-10 | `422 passing_threshold_inside_band` |
| `PATCH` de una escala `mixta` que sube `max_value` sin enviar `values` | `422 bands_not_contiguous`: el último tramo ya no termina en `max_value` |
| `PATCH` con `values` que reordena los valores de una cualitativa | `200`; `position` se reescribe por el orden; auditoría `updated` de los valores cuya posición cambia |
| `PATCH` con el `public_id` de un valor de **otra** escala del mismo centro | `422 value_not_in_scale`; nada cambia |
| Valor numérico como número JSON `5.1` y como cadena `"5.1"` | Ambos admitidos (`RN-ACAD-75`); respuesta `"5.1"` |
| Valor numérico `"5,1"` (coma), `"1e1"` o `"1000"` | `422 decimal_invalid` |
| Escala con `values` de 101 elementos | `422 values_too_many` |
| Usuario con `configuracion_academica.leer` sin `estructura_academica.leer` | La API de períodos responde (sus *endpoints* solo exigen el primero); la interfaz no muestra la sección de períodos porque vive en la ficha de etapa, que exige el segundo (`OPEN-ACAD-29`) |

---

## 7. Interacción con otros módulos

### 7.1 Lo que consume

| De | Qué | Cómo |
|----|-----|------|
| `curso` | `AcademicYearContext`, `AcademicYearDirectory` (ampliado), `AcademicYearReadAccess`, `AcademicYearWriteGuard` (opcional), `AcademicYearStatus`; desde `1.11d-a`, además `AcademicYearSharedLock` y el registro de validaciones del cambio de fechas (§7.4) | Interfaces de `Curso\Domain` |
| `curso` (base de datos) | FK compuesta a `academic_years`, disparador | `TenantMigration` |
| núcleo | `TenantModel`, `Auditable`, `RequirePermission`, `module-enabled:`, `TenantSettingsReader`, `App\Support\I18n` (validación y resolución de `ADR-062`) | Núcleo y `Core\Domain` |

### 7.2 Interfaces públicas (`Acad\Domain`, nunca `Domain\Models`)

**`1.11` no expone ninguna**: ninguna tiene consumidor fuera del módulo todavía (`ADR-048`). Quedan escritas:

| Interfaz futura | Para qué | Primer consumidor previsto |
|-----------------|----------|----------------------------|
| **Pertenencia docente** (`ADR-063 §5.2` punto 1): para un usuario, el conjunto de grupos y de pares (grupo, asignatura) que le corresponden en un curso académico, listo para un `whereIn` | Que cada módulo dueño de un recurso registre su resolutor de `grupo`/`clase` | El módulo de `1.13` o `1.16`, el que llegue antes; la especifica el paso de la asignación docente (candidato `1.12`, `ADR-063 §5.4`) |
| `GroupDirectory` | Resolver un `public_id` de grupo a su resumen (id interno, curso académico, curso de etapa, etapa con su régimen) | `ALUM` (`1.15`) |
| `SubjectDirectory` | Ídem para asignaturas | `CALIF` (`1.16`), horarios (`1.12`) |
| Registro de vetos de borrado | `RN-ACAD-21` | `ALUM` (`1.15`) |
| Copia de estructura al curso siguiente | *Rollover* | `1.12b` |
| **`AcademicPeriodDirectory`** (`1.11d`, escrita, no construida) | Para un `public_id` o identificador interno de período: su resumen (id interno, curso académico, etapa, tipo, fechas) y los períodos vivos de una etapa ordenados por `starts_on`; y «qué período contiene esta fecha en esta etapa» | `CALIF` (`1.16`: períodos de evaluación); asistencia (`1.13`: informes «por período», `REQ-ACAD-003`) |
| **`GradingScaleDirectory`** (`1.11d`, escrita, no construida) | Para una escala: tipo, rango, decimales, umbral y valores/tramos con su `is_passing` (derivado en la mixta, `RN-ACAD-87`), como objetos de valor inmutables; y la operación pura «¿esta calificación es aprobado en esta escala?», para que `CALIF` y `REQ-CURSO-003` cuenten suspensos con una sola definición | `CALIF` (`1.16`), `REQ-CURSO-003` (`1.17b`) |
| Registros de vetos de períodos y escalas | `RN-ACAD-63`, `RN-ACAD-77` | `CALIF` (`1.16`) |

**`1.11d` tampoco construye ninguna de estas interfaces**: sin consumidor no se construye (`ADR-048`, mismo criterio que `1.11`). Con `1.11d-b` implementada, `GradingScaleDirectory` (y `AcademicPeriodDirectory`) siguen **«escritas, no construidas»**: no existe ningún fichero `Acad\Domain\GradingScaleDirectory` ni consumidor que lo pida. Quedan escritas para que `1.16` no tenga que adivinarlas; la especificación de `1.16` las fija y `acad` las implementa en ese paso. `CALIF` no importará ningún modelo de `Acad\Domain\Models` (`INV-007`, `AR-01`); la clave foránea a `academic_periods`/`grading_scale_values` sí está permitida.

### 7.3 Eventos

Ni emite ni consume en `1.11` ni en `1.11d`.

### 7.4 Lo que `1.11d` consume además

| De | Qué | Cómo |
|----|-----|------|
| `curso` (`OPEN-CURSO-25` = B, decisión del usuario) | Fechas del curso académico para `RN-ACAD-57`, leídas **con bloqueo compartido** dentro de la transacción del alta o la edición del período, antes del `FOR UPDATE` de la etapa (`RN-ACAD-54`) | `AcademicYearSharedLock::lockForShareById(int $id): ?AcademicYearSummary` (`REQ-CURSO/funcional.md §5.6.1`, `RN-CURSO-55` a `-59`); del resumen, `$startsOn`/`$endsOn`. `REQ-CURSO/funcional.md §7.1` ya describe el resumen con sus fechas (desfase documental corregido) |
| `curso` (`OPEN-ACAD-24` = B, decidida) | Punto de extensión para validar un cambio de fechas del curso | `AcademicYearDateChangeCheck`, `AcademicYearDateChange`, `AcademicYearDateChangeFailure` y `AcademicYearDateChangeRegistry` (`REQ-CURSO/funcional.md §5.6`, `RN-CURSO-49` a `-54`); la validación de `acad` y su entrada de error, en `RN-ACAD-62` |

---

## 8. Descriptor del módulo (`ADR-045`, `ADR-034 §5`)

| Campo | Valor aprobado | Nota |
|-------|----------------|------|
| `code` | `acad` | |
| `name_key` | `modules.acad` | es «Gestión académica», en «Academic management», de «Schulverwaltung», fr «Gestion académique» (a revisar) |
| `phase` | `'1'` | |
| `depends_on` | `['curso']` | `OPEN-ACAD-10` |
| `essential` | `false` | `OPEN-ACAD-10` |
| `feature_flags` | Ninguno | |

## 9. Comportamiento con el módulo desactivado

Toda ruta lleva `module-enabled:acad` **antes** de `permission:` (`AR-07b`; primer módulo no esencial con rutas de `api/v1`) y responde `403 urn:pge:error:module-disabled` (`RMOD-009`); pantallas y entrada de menú desaparecen (`RMOD-008`); el selector de curso académico sigue existiendo para los demás módulos. Los datos se conservan (`RMOD-003`). Los futuros `alum`, `calif`… declararán `depends_on: ['acad']`.

---

## 10. Interfaz: *shell* del frontend (`ADR-053`)

Módulo web `apps/web/src/modules/acad/` con `shell.ts`, `api/index.ts`, `types/index.ts`, `locales/{es,en,de,fr}.json`, registrado en `src/navigation/modules.ts` y `src/i18n/index.ts` (`AR-11`). `apps/web/src/modules/README.md` se corrige para listar `auth`, `core`, `curso` y `acad` (#408).

### 10.1 Rutas

| Nombre | Ruta | `meta.permissions` | Menú | Contenido |
|--------|------|--------------------|------|-----------|
| `acad-structure` | `/academico/estructura` | `['estructura_academica.leer']` | Sí, sección `administracion` (`OPEN-ACAD-23` = A) | Etapas del curso académico seleccionado (`src/data-table`, modo `page`): código, nombre, tipo de etapa, régimen, posición. «Nueva etapa» con `estructura_academica.crear`. `meta.academicYearScoped: true` |
| `acad-level-detail` | `/academico/etapas/:publicId` | `['estructura_academica.leer']` | No | Ficha de la etapa y sus cursos de etapa |
| `acad-course-detail` | `/academico/cursos/:publicId` | `['estructura_academica.leer']` | No | Ficha del curso de etapa: grupos y asignaturas |
| `acad-group-detail` | `/academico/grupos/:publicId` | `['estructura_academica.leer']` | No | Ficha del grupo: subgrupos y asignaturas que cursa (§4.6) |
| `acad-subject-detail` | `/academico/asignaturas/:publicId` | `['estructura_academica.leer']` | No | Ficha de la asignatura y grupos que la cursan |

Altas y ediciones en diálogo sobre la ficha del padre **[DERIVADA]**; borrados con `ConfirmDialog`. Los nombres traducibles se editan con el componente común de campo traducible (`ADR-062 §5.7`) y se presentan con `name`, marcando `lang` cuando el texto sale de la caída. Las fichas muestran el selector fijo en el curso de la entidad **[DERIVADA]**. Ninguna ruta usa `permissions: []`. Ningún literal de código de rol y **ninguna comparación `.code ===`**: se compara por `public_id` (`OPEN-ACAD-16` = A, #376).

### 10.2 Selector de curso académico (`OPEN-CURSO-17` = A + X; `OPEN-ACAD-11` = A)

- Vive en `apps/web/src/academic-year/` (estado, componente y *composable*). Lee de `@/modules/curso/api` (superficie pública), que gana la llamada a `GET /academic-years/selectable`.
- Se muestra en la barra superior del *shell* solo en rutas con **`meta.academicYearScoped: true`** (aprobada; amplía el contenido de `meta`, no las tres listas de `ADR-053`).
- Comprobar en implementación que `src/navigation/architecture.spec.ts` y `AR-11` admiten que una carpeta de aplicación importe `@/modules/curso/api`; si no, la ampliación de lista cerrada se pide antes de escribirla.

### 10.3 Bloques del panel

Ninguno.

### 10.4 Textos y accesibilidad

Literales en `lang/{es,en,de,fr}/acad.php` y `locales/{es,en,de,fr}.json`; `stage`/`regime` se traducen en el cliente con rama por defecto (`ADR-038 §7.3`). WCAG 2.2 AA: selector etiquetado que anuncia el cambio; solo lectura no transmitido solo por color; **3.1.2 idioma de las partes** en textos resueltos por caída (`ADR-062 §5.4` punto 1) y en cada entrada del componente de traducción.

### 10.5 Interfaz de `1.11d`: períodos lectivos y escalas

Mismo módulo web (`apps/web/src/modules/acad/`); `shell.ts`, `api/index.ts`, `types/index.ts` y los cuatro `locales` ganan lo que sigue. No hay pantalla nueva fuera del módulo ni cambio en `src/navigation/sections.ts`. Reglas visuales de `docs/design-system.md §22` (espacio antes que adornos, separación por espacio o `border-border`, jerarquía por peso y tono, iconos de `@lucide/vue`, estados de carga y vacío en todo listado). **No** se toca la capa visual de `ADR-064`/`1.15a` (radio, sombras, acceso).

#### 10.5.1 Rutas

| Nombre | Ruta | `meta.permissions` | Menú | Contenido |
|--------|------|--------------------|------|-----------|
| *(sin ruta nueva)* `acad-level-detail` | `/academico/etapas/:publicId` | `['estructura_academica.leer']` (sin cambio) | — | Gana la sección **«Períodos lectivos»** (`OPEN-ACAD-29` = A, decidida), visible solo con `configuracion_academica.leer`: tabla (`src/data-table`, sin paginar en cliente: una etapa tiene pocos períodos; la API se pide con `per_page=100` y **siempre** con `academic_year` igual al curso académico de la etapa junto a `education_level`, §4.8.1) con nombre, tipo, inicio, fin y posición, orden por inicio; «Nuevo período», «Editar» y «Borrar» en diálogo, con `configuracion_academica.crear`/`actualizar`/`eliminar` y curso que admita escritura |
| `acad-grading-scales` | `/academico/escalas` | `['configuracion_academica.leer']` | Sí, sección `administracion`, tras «Estructura académica» | Escalas del curso académico seleccionado (`src/data-table`, modo `page`): código, nombre, tipo, rango (o número de valores en la cualitativa), aprobado. Filtro por tipo. «Nueva escala» con `configuracion_academica.crear`. `meta.academicYearScoped: true` |
| `acad-grading-scale-new` | `/academico/escalas/nueva` | `['configuracion_academica.crear']` | No | Formulario de alta (§10.5.2). `meta.academicYearScoped: true` (el curso del selector es el de la escala nueva y va explícito en el cuerpo) |
| `acad-grading-scale-detail` | `/academico/escalas/:publicId` | `['configuracion_academica.leer']` | No | Ficha: datos, valores o tramos en orden con su etiqueta y si son aprobado (texto, no solo color), «Editar» y «Borrar» (`ConfirmDialog`) con su permiso y curso escribible. Selector fijo en el curso de la escala |
| `acad-grading-scale-edit` | `/academico/escalas/:publicId/editar` | `['configuracion_academica.actualizar']` | No | Mismo formulario. Con el curso de solo lectura, estado propio sin formulario (la API daría `409`) |

**[DERIVADA]** La escala se edita en página propia y no en diálogo, a diferencia de `1.11`: el editor de valores o tramos es una lista con varias columnas que no cabe en un diálogo a 320 px sin desbordar (`RUX-RESP`, WCAG 1.4.10); precedente de página propia: `curso-academic-year-new`/`-edit`. Ninguna ruta usa `permissions: []`. Ninguna comparación `.code ===` (`OPEN-ACAD-16`). Icono de menú: uno de `@lucide/vue` acorde (p. ej. `ListOrdered`), comprobado en implementación.

#### 10.5.2 Formularios

- **Período** (diálogo): tipo (`Select` con las tres etiquetas traducidas), nombre (componente común de campo traducible, `ADR-062 §5.7`), inicio y fin (campo de fecha con etiqueta y formato anunciado, como `REQ-CURSO §10.5`), posición opcional. Muestra las fechas del curso académico como ayuda («entre el 1 sep. 2026 y el 31 ago. 2027»). Los `422` de §6.1 se pintan junto a su campo, según el contrato de campo por error que fijó la implementación de `1.11d-a` y la especificación aprobada no recogía (`api.md §11.7`: `period_overlap`, `starts_on`; `period_type_mismatch`, `period_type`; `period_ends_before_start`, `ends_on`; `period_outside_academic_year`, `starts_on` y/o `ends_on`; `date_invalid`, el campo de la fecha; `parent_not_found`, `education_level`, que el diálogo no pinta y muestra junto a las acciones); `period_overlap` nombra el período con el que solapa: la SPA resuelve su `name` desde `params.period` en la tabla ya cargada y lo muestra con la clave completa `acad.periods.overlapWith` (§10.5.5), sin concatenar fragmentos; si el período no está en la tabla cargada, muestra el `message` de la API.
- **Escala** (página): código, nombre traducible, tipo (`RadioGroup`; deshabilitado en edición, con la explicación de `RN-ACAD-70`), y según el tipo:
  - `numerica`/`mixta`: mínimo, máximo, decimales (`Select` 0/1/2) y nota mínima de aprobado, como campos de texto con `inputmode="decimal"` (la SPA envía cadena, `RN-ACAD-75`);
  - `cualitativa`: lista de valores con código, etiqueta traducible y casilla «Es aprobado»;
  - `mixta`: lista de tramos con código, etiqueta traducible, desde y hasta; columna de solo lectura «Aprobado» calculada con el umbral (`RN-ACAD-87`).
  - Editor de lista: «Añadir», «Quitar» y **«Subir»/«Bajar» con botón** para reordenar (WCAG 2.5.7: sin arrastrar como única vía), cada fila un `fieldset` con `legend` («Valor 2»), foco al nuevo elemento al añadir y a la fila siguiente al quitar, anuncio en región viva al reordenar. Los errores `values.<n>.<campo>` se pintan en su fila; los de la lista (`values_required`, `passing_value_required`, `bands_not_contiguous`, `passing_threshold_inside_band`, `values_too_many`) sobre la lista con `aria-describedby`.
- La validación de cliente es solo ayuda (`INV-010`): la decisión es del servidor.

#### 10.5.3 Estados

Carga y vacío en las dos tablas («Esta etapa no tiene períodos lectivos», «Este curso académico no tiene escalas»); sin curso activo, el estado de `RN-ACAD-40`; curso de solo lectura: aviso persistente y ninguna acción de escritura (`RN-ACAD-42`); `409 academic-year-closed`, `409 has_dependents` (ahora con `academic_periods` al borrar una etapa) y `404` con su mensaje.

#### 10.5.4 Accesibilidad y *reflow*

WCAG 2.2 AA: cada campo con `<label>`; los grupos de campos de un valor en `fieldset`; «aprobado» con texto y no solo con color o icono; idioma de las partes (`lang`) en `name`/`label` resueltos por caída; objetivos táctiles de 44 px (botones de fila incluidos); a 320 px las tablas pasan a tarjetas (`ADR-054`) y el editor de valores apila sus columnas sin desplazamiento horizontal de página.

#### 10.5.5 Textos (claves nuevas)

**API** — `lang/{es,en,de,fr}/acad.php`:

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `acad.permissions.resources.configuracion_academica` | Configuración académica | Academic settings | Akademische Einstellungen | Paramètres académiques |
| `acad.validation.period_type_invalid` | El tipo de período no es válido. | The period type is not valid. | Die Art des Zeitraums ist ungültig. | Le type de période n’est pas valide. |
| `acad.validation.date_invalid` | La fecha no es válida (formato AAAA-MM-DD). | The date is not valid (format YYYY-MM-DD). | Das Datum ist ungültig (Format JJJJ-MM-TT). | La date n’est pas valide (format AAAA-MM-JJ). |
| `acad.validation.period_ends_before_start` | La fecha de fin debe ser posterior a la de inicio. | The end date must be after the start date. | Das Enddatum muss nach dem Startdatum liegen. | La date de fin doit être postérieure à la date de début. |
| `acad.validation.period_outside_academic_year` | El período debe estar dentro del curso académico (:academic_year_starts_on – :academic_year_ends_on). | The period must fall within the academic year (:academic_year_starts_on – :academic_year_ends_on). | Der Zeitraum muss innerhalb des Schuljahres liegen (:academic_year_starts_on – :academic_year_ends_on). | La période doit se situer dans l’année scolaire (:academic_year_starts_on – :academic_year_ends_on). |
| `acad.validation.period_overlap` | El período se solapa con otro de la misma etapa. | The period overlaps another one in the same stage. | Der Zeitraum überschneidet sich mit einem anderen derselben Stufe. | La période chevauche une autre période de la même étape. |
| `acad.validation.period_type_mismatch` | Todos los períodos de una etapa deben ser del mismo tipo (:period_type). | All periods of a stage must be of the same type (:period_type). | Alle Zeiträume einer Stufe müssen von derselben Art sein (:period_type). | Toutes les périodes d’une étape doivent être du même type (:period_type). |
| `acad.validation.scale_type_invalid` | El tipo de escala no es válido. | The scale type is not valid. | Die Art der Skala ist ungültig. | Le type d’échelle n’est pas valide. |
| `acad.validation.scale_type_immutable` | El tipo de una escala no se puede cambiar. | The type of a scale cannot be changed. | Die Art einer Skala kann nicht geändert werden. | Le type d’une échelle ne peut pas être modifié. |
| `acad.validation.scale_field_required` | Este campo es obligatorio para este tipo de escala. | This field is required for this scale type. | Dieses Feld ist für diese Skalenart erforderlich. | Ce champ est obligatoire pour ce type d’échelle. |
| `acad.validation.not_applicable_to_scale_type` | Este campo no se admite en este tipo de escala. | This field is not allowed for this scale type. | Dieses Feld ist für diese Skalenart nicht zulässig. | Ce champ n’est pas admis pour ce type d’échelle. |
| `acad.validation.decimal_invalid` | Introduce un número con punto decimal, de −999.99 a 999.99. | Enter a number with a decimal point, from −999.99 to 999.99. | Gib eine Zahl mit Dezimalpunkt zwischen −999.99 und 999.99 ein. | Saisissez un nombre avec un point décimal, de −999.99 à 999.99. |
| `acad.validation.decimals_invalid` | Los decimales deben ser 0, 1 o 2. | Decimals must be 0, 1 or 2. | Die Nachkommastellen müssen 0, 1 oder 2 sein. | Les décimales doivent être 0, 1 ou 2. |
| `acad.validation.decimal_precision_exceeded` | El valor tiene más decimales de los que admite la escala (:decimals). | The value has more decimals than the scale allows (:decimals). | Der Wert hat mehr Nachkommastellen, als die Skala zulässt (:decimals). | La valeur a plus de décimales que l’échelle n’en admet (:decimals). |
| `acad.validation.range_invalid` | El mínimo debe ser menor que el máximo. | The minimum must be lower than the maximum. | Das Minimum muss kleiner als das Maximum sein. | Le minimum doit être inférieur au maximum. |
| `acad.validation.passing_out_of_range` | La nota mínima de aprobado debe estar entre el mínimo y el máximo. | The minimum passing grade must be between the minimum and the maximum. | Die Mindestnote zum Bestehen muss zwischen Minimum und Maximum liegen. | La note minimale de réussite doit être comprise entre le minimum et le maximum. |
| `acad.validation.values_required` | La escala necesita al menos un valor. | The scale needs at least one value. | Die Skala braucht mindestens einen Wert. | L’échelle doit comporter au moins une valeur. |
| `acad.validation.values_too_many` | Una escala admite como máximo :max valores. | A scale allows at most :max values. | Eine Skala erlaubt höchstens :max Werte. | Une échelle admet au maximum :max valeurs. |
| `acad.validation.is_passing_required` | Indica si este valor es aprobado. | Indicate whether this value is a pass. | Gib an, ob dieser Wert bestanden bedeutet. | Indiquez si cette valeur correspond à une réussite. |
| `acad.validation.passing_value_required` | Al menos un valor debe ser aprobado. | At least one value must be a pass. | Mindestens ein Wert muss bestanden bedeuten. | Au moins une valeur doit correspondre à une réussite. |
| `acad.validation.value_not_in_scale` | El valor no pertenece a esta escala. | The value does not belong to this scale. | Der Wert gehört nicht zu dieser Skala. | La valeur n’appartient pas à cette échelle. |
| `acad.validation.band_range_invalid` | El tramo debe estar dentro de la escala y su inicio no puede ser mayor que su fin. | The band must lie within the scale and its start cannot exceed its end. | Der Bereich muss innerhalb der Skala liegen und sein Anfang darf sein Ende nicht übersteigen. | La tranche doit se situer dans l’échelle et son début ne peut pas dépasser sa fin. |
| `acad.validation.bands_not_contiguous` | Los tramos deben cubrir toda la escala, en orden y sin huecos ni solapes. | Bands must cover the whole scale, in order, with no gaps or overlaps. | Die Bereiche müssen die ganze Skala lückenlos, geordnet und ohne Überschneidung abdecken. | Les tranches doivent couvrir toute l’échelle, dans l’ordre, sans trou ni chevauchement. |
| `acad.validation.passing_threshold_inside_band` | La nota mínima de aprobado debe coincidir con el inicio de un tramo. | The minimum passing grade must match the start of a band. | Die Mindestnote zum Bestehen muss mit dem Anfang eines Bereichs übereinstimmen. | La note minimale de réussite doit coïncider avec le début d’une tranche. |
| `acad.dependents.academic_periods` | períodos lectivos | academic periods | Unterrichtszeiträume | périodes scolaires |
| `acad.conflict.periods_outside_academic_year` (`RN-ACAD-62`; `message` de la entrada de `acad` en `errors.date_change[]`) | Hay períodos lectivos que quedarían fuera del curso académico: sus fechas deben incluir del :periods_starts_on al :periods_ends_on. | Some academic periods would fall outside the academic year: its dates must include :periods_starts_on to :periods_ends_on. | Einige Unterrichtszeiträume lägen außerhalb des Schuljahres: Seine Daten müssen den Zeitraum vom :periods_starts_on bis zum :periods_ends_on einschließen. | Des périodes scolaires se trouveraient en dehors de l’année scolaire : ses dates doivent inclure la période du :periods_starts_on au :periods_ends_on. |

`acad.conflict.periods_outside_academic_year` va en el grupo `conflict` de `acad.php`, junto a `has_dependents` (patrón comprobado en `lang/es/acad.php`); las fechas se interpolan en `AAAA-MM-DD`, como `period_outside_academic_year`, y el mensaje no usa `params.count` (evita la pluralización; `count` queda para el cliente). Los mensajes de los códigos reutilizados (`code_required`, `code_taken`, `invalid_characters`, `position_invalid`, `academic_year_*`, `parent_*`, `translations.*`) no cambian. `acad.dependents.academic_periods` sigue el patrón comprobado de `lang/es/acad.php` (`dependents.courses`…). Como `stage`/`regime`, `acad.php` gana además `period_type.{trimestre,cuatrimestre,semestre}` y `scale_type.{numerica,cualitativa,mixta}` con las etiquetas de la tabla web siguiente.

**Web** — `locales/{es,en,de,fr}.json` (clave relativa a `acad.`):

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `nav.gradingScales` | Escalas de calificación | Grading scales | Bewertungsskalen | Échelles de notation |
| `nav.backToScales` *(añadida en `1.11d-b`)* | Volver a las escalas de calificación | Back to grading scales | Zurück zu den Bewertungsskalen | Retour aux échelles de notation |
| `periodType.trimestre` | Trimestre | Term (three months) | Trimester | Trimestre |
| `periodType.cuatrimestre` | Cuatrimestre | Four-month term | Tertial | Quadrimestre |
| `periodType.semestre` | Semestre | Semester | Semester | Semestre |
| `scaleType.numerica` | Numérica | Numeric | Numerisch | Numérique |
| `scaleType.cualitativa` | Cualitativa | Qualitative | Qualitativ | Qualitative |
| `scaleType.mixta` | Mixta | Mixed | Gemischt | Mixte |
| `dependents.academic_periods` | Períodos lectivos | Academic periods | Unterrichtszeiträume | Périodes scolaires |
| `periods.title` | Períodos lectivos | Academic periods | Unterrichtszeiträume | Périodes scolaires |
| `periods.caption` | Períodos lectivos de la etapa | Academic periods of the stage | Unterrichtszeiträume der Stufe | Périodes scolaires de l’étape |
| `periods.new` | Nuevo período | New period | Neuer Zeitraum | Nouvelle période |
| `periods.empty` | Esta etapa no tiene períodos lectivos | This stage has no academic periods | Diese Stufe hat keine Unterrichtszeiträume | Cette étape n’a pas de périodes scolaires |
| `periods.yearRange` | Entre el {start} y el {end} | Between {start} and {end} | Zwischen {start} und {end} | Entre le {start} et le {end} |
| `periods.overlapWith` | El período se solapa con «{name}», de la misma etapa. | The period overlaps “{name}” in the same stage. | Der Zeitraum überschneidet sich mit „{name}“ derselben Stufe. | La période chevauche « {name} » de la même étape. |
| `fields.periodType` | Tipo de período | Period type | Art des Zeitraums | Type de période |
| `fields.startsOn` | Inicio | Start | Beginn | Début |
| `fields.endsOn` | Fin | End | Ende | Fin |
| `fields.scaleType` | Tipo de escala | Scale type | Skalenart | Type d’échelle |
| `fields.minValue` | Mínimo | Minimum | Minimum | Minimum |
| `fields.maxValue` | Máximo | Maximum | Maximum | Maximum |
| `fields.decimals` | Decimales | Decimals | Nachkommastellen | Décimales |
| `fields.passingMinValue` | Nota mínima de aprobado | Minimum passing grade | Mindestnote zum Bestehen | Note minimale de réussite |
| `fields.label` | Etiqueta | Label | Bezeichnung | Libellé |
| `fields.isPassing` | Es aprobado | Is a pass | Bestanden | Réussite |
| `fields.rangeMin` | Desde | From | Von | De |
| `fields.rangeMax` | Hasta | To | Bis | À |
| `fields.range` *(añadida en `1.11d-b`)* | Rango | Range | Bereich | Plage |
| `scales.title` | Escalas de calificación | Grading scales | Bewertungsskalen | Échelles de notation |
| `scales.intro` | Escalas con las que se califica en el curso académico seleccionado. | Scales used for grading in the selected academic year. | Skalen, mit denen im ausgewählten Schuljahr bewertet wird. | Échelles utilisées pour noter pendant l’année scolaire sélectionnée. |
| `scales.caption` | Escalas del curso académico | Scales of the academic year | Skalen des Schuljahres | Échelles de l’année scolaire |
| `scales.detailTitle` *(añadida en `1.11d-b`)* | Escala | Scale | Skala | Échelle |
| `scales.rangeValue` *(añadida en `1.11d-b`)* | De {min} a {max} | From {min} to {max} | Von {min} bis {max} | De {min} à {max} |
| `scales.valuesCount` *(añadida en `1.11d-b`; plural de tres formas)* | Sin valores \| {count} valor \| {count} valores | No values \| {count} value \| {count} values | Keine Werte \| {count} Wert \| {count} Werte | Aucune valeur \| {count} valeur \| {count} valeurs |
| `scales.passingFrom` *(añadida en `1.11d-b`)* | Desde {value} | From {value} | Ab {value} | À partir de {value} |
| `scales.new` | Nueva escala | New scale | Neue Skala | Nouvelle échelle |
| `scales.empty` | Este curso académico no tiene escalas | This academic year has no scales | Dieses Schuljahr hat keine Skalen | Cette année scolaire n’a pas d’échelles |
| `scales.values` | Valores | Values | Werte | Valeurs |
| `scales.bands` | Tramos | Bands | Bereiche | Tranches |
| `scales.addValue` | Añadir valor | Add value | Wert hinzufügen | Ajouter une valeur |
| `scales.addBand` | Añadir tramo | Add band | Bereich hinzufügen | Ajouter une tranche |
| `scales.remove` | Quitar | Remove | Entfernen | Retirer |
| `scales.moveUp` | Subir | Move up | Nach oben | Monter |
| `scales.moveDown` | Bajar | Move down | Nach unten | Descendre |
| `scales.itemLegend` | Valor {n} | Value {n} | Wert {n} | Valeur {n} |
| `scales.moved` | {label} ahora en la posición {n} | {label} now in position {n} | {label} jetzt an Position {n} | {label} désormais en position {n} |
| `scales.passing` | Aprobado | Pass | Bestanden | Réussite |
| `scales.failing` | Suspenso | Fail | Nicht bestanden | Échec |
| `scales.typeLocked` | El tipo no se puede cambiar: para otro tipo, crea una escala nueva. | The type cannot be changed: create a new scale for another type. | Die Art kann nicht geändert werden: Legen Sie für eine andere Art eine neue Skala an. | Le type ne peut pas être modifié : créez une nouvelle échelle pour un autre type. |
| `form.title.create.period` / `.scale` | Nuevo período / Nueva escala | New period / New scale | Neuer Zeitraum / Neue Skala | Nouvelle période / Nouvelle échelle |
| `form.title.edit.period` / `.scale` | Editar período / Editar escala | Edit period / Edit scale | Zeitraum bearbeiten / Skala bearbeiten | Modifier la période / Modifier l’échelle |
| `confirm.delete.period.title` | Borrar el período {name} | Delete period {name} | Zeitraum {name} löschen | Supprimer la période {name} |
| `confirm.delete.period.description` | El período se borra de la etapa. | The period is deleted from the stage. | Der Zeitraum wird aus der Stufe gelöscht. | La période est supprimée de l’étape. |
| `confirm.delete.scale.title` | Borrar la escala {code} | Delete scale {code} | Skala {code} löschen | Supprimer l’échelle {code} |
| `confirm.delete.scale.description` | La escala y todos sus valores se borran. | The scale and all its values are deleted. | Die Skala und alle ihre Werte werden gelöscht. | L’échelle et toutes ses valeurs sont supprimées. |
| `flash.periodCreated` / `Updated` / `Deleted` | Período creado. / actualizado. / borrado. | Period created. / updated. / deleted. | Zeitraum angelegt. / aktualisiert. / gelöscht. | Période créée. / mise à jour. / supprimée. |
| `flash.scaleCreated` / `Updated` / `Deleted` | Escala creada. / actualizada. / borrada. | Scale created. / updated. / deleted. | Skala angelegt. / aktualisiert. / gelöscht. | Échelle créée. / mise à jour. / supprimée. |
| `readOnlyConfig` | Este curso académico es de solo lectura: no se pueden crear, modificar ni borrar sus períodos ni sus escalas. | This academic year is read-only: its periods and scales cannot be created, changed or deleted. | Dieses Schuljahr ist schreibgeschützt: Seine Zeiträume und Skalen können nicht angelegt, geändert oder gelöscht werden. | Cette année scolaire est en lecture seule : ses périodes et ses échelles ne peuvent être ni créées, ni modifiées, ni supprimées. |

**Seis claves de web no estaban en esta tabla y las añadió la implementación de `1.11d-b`** (`nav.backToScales`, `fields.range`, `scales.detailTitle`, `scales.rangeValue`, `scales.valuesCount`, `scales.passingFrom`; filas marcadas arriba): son los textos de la columna «Rango»/«Aprobado» del listado, el título de la ficha y el enlace «Volver». Los textos en/de/fr de toda la tabla son los de partida de la especificación (revisados por `traductor` el 2026-10-17: tratamiento de cortesía «Sie» en alemán).

Las traducciones `en`/`de`/`fr` son textos de partida, revisados por el agente `traductor` en `1.11d-b` (no son textos legales). Las filas con «/» son claves distintas, una por acción. Los períodos se muestran por su `name` (no tienen `code`).

---

## 11. Manual de usuario

`docs/manual-usuario/admin.md` (se actualiza): «Estructura académica» (etapas y régimen, cursos, grupos, subgrupos, asignaturas, asignación a grupos, nombres en varios idiomas, por qué no se mueve un grupo ni se borra algo con hijos, curso académico de solo lectura) y el selector. **`docs/manual-usuario/direccion.md`, `secretaria.md` y `docente.md`** se crearon en `1.11-b`: consulta de la estructura y uso del selector, según la siembra aprobada (`CLAUDE.md §6.4`).

**`1.11d`**: `admin.md` gana «Períodos lectivos» (en la ficha de la etapa; un solo tipo por etapa; sin solapes; dentro del curso académico; cómo cambiar de trimestres a semestres) y «Escalas de calificación» (tipos, qué es aprobado, tramos contiguos de la mixta, por qué no se cambia el tipo). `direccion.md`, `secretaria.md` y `docente.md`, que ya existen, se **amplían** con la consulta de períodos y escalas que les da la siembra decidida (`OPEN-ACAD-28` = X: `leer`).

---

## 12. Huecos de extensión que deja `1.11`

| Necesidad futura | Qué deja `1.11` | Coste después |
|------------------|-----------------|---------------|
| *Rollover* (`1.12b`) | Árbol con clave (curso académico, padre) y códigos estables | **Corregido el 2026-10-20**: el contrato de copia vive en `Curso\Domain` (`AcademicYearRolloverParticipant`, `REQ-CURSO RN-CURSO-61`) y `acad` lo implementa en `Acad\Infrastructure` (§21, `RN-ACAD-145`); nada en `Acad\Domain` |
| Matrícula (`1.15`) | `groups` con `UNIQUE (tenant_id, academic_year_id, id)` | `GroupDirectory` + vetos de borrado (`RN-ACAD-21`) |
| Profesorado y ámbitos | `group_subjects` (el par grupo × asignatura que define `clase`, `ADR-063 §5.3`) | **Asignación docente propia de `acad`**, por curso académico y protegida por `ADR-057`, + interfaz de pertenencia en `Acad\Domain` (`ADR-063 §5.2`). Los resolutores los registra **cada módulo dueño del recurso** |
| Capacidad/ratio | Nada | Columna anulable (*expand*) |
| Contenido traducible en otros módulos | Ayudante, validación, resolutor, desglose, `AR-16` y componente de `ADR-062` | Una línea en la migración y la regla común |
| **`CALIF`** (`1.16`): períodos de evaluación, escala por asignatura o etapa, calificaciones (`1.11d`) | `academic_periods` con `UNIQUE (tenant_id, academic_year_id, id)`; `grading_scales` con `UNIQUE (tenant_id, academic_year_id, id, scale_type)`; `grading_scale_values` con `UNIQUE (tenant_id, academic_year_id, id)` (`datos.md §1.7`-`§1.9`): una calificación cualitativa puede apuntar por FK al valor y el motor garantiza que es del mismo curso académico | `AcademicPeriodDirectory`, `GradingScaleDirectory` y registros de vetos (§7.2, `RN-ACAD-63`, `-77`). Si `CALIF` necesitara una FK a la escala solo por `(tenant_id, academic_year_id, id)`, un `UNIQUE` más sobre una tabla pequeña (*expand*) |
| `REQ-CURSO-003`: «número de suspensos» | Aprobado declarado por escala (`RN-ACAD-74`, `-82`, `-87`) | La operación pura de `GradingScaleDirectory` (§7.2) |
| *Rollover* (`1.12b`): «criterios de evaluación» | Períodos por etapa y escalas por curso académico, con códigos estables en escalas y valores | Copia en el contrato de `1.12b` (§21); las fechas de los períodos se desplazan por la diferencia en días entre los inicios de los dos cursos y lo que quede fuera impide copiar (decidido en `1.12b`: `OPEN-CURSO-30` = A, `REQ-CURSO RN-CURSO-72`, `RN-ACAD-148`) |
| Escala adaptada por alumno (`REQ-NEAE-003`, fase 2) | Escalas por curso académico del centro | La adaptación referenciará una escala existente; nada que migrar |
| Calendario lectivo (festivos, `REQ-ACAD-002`) | Nada: un período no es un calendario | Entidad propia de horarios |

---

## 13. Criterios de aceptación

Formato Dado/Cuando/Entonces. API con Pest en `apps/api/tests/Feature/Acad/` (o en el directorio de la pieza compartida); interfaz con Vitest (Playwright donde se indica). Cada test referencia su `CA-ACAD-NNN` y `REQ-ACAD-001`, más el ADR o issue que cite (`INV-015`). Base de datos real (`ADR-033 §10`). «**Las seis entidades**»: etapa, curso de etapa, grupo, subgrupo, asignatura y asignación de asignatura a grupo.

### 13.1 Altas, edición y borrado

- **`CA-ACAD-001`** — *Dado* un curso académico `activo` y un usuario con `estructura_academica.crear`, *cuando* envía `POST /education-levels` con `academic_year`, código, `name_translations` con el idioma por defecto del centro, `stage: "eso"` y `regime: "concertado"`, *entonces* `201` con `public_id` ULID, `academic_year` igual al enviado, `name` y `name_translations`, y una entrada `created` en `audit_logs` con `auditable_type = 'education_level'`.
- **`CA-ACAD-002`** — *Dada* una etapa, *cuando* se crea un curso de etapa, un grupo, un subgrupo y una asignatura colgados de ella, *entonces* cada uno responde `201` con el `academic_year` de la etapa sin haberlo enviado; *y si* el cuerpo lleva `academic_year`, `422 acad.validation.academic_year_not_allowed` y no se crea nada (`RN-ACAD-05`).
- **`CA-ACAD-003`** — *Dado* un código usado por un hermano vivo, `422 acad.validation.code_taken`; el mismo código en otro padre, en otro curso académico o en un hermano borrado, `201` (`RN-ACAD-01`).
- **`CA-ACAD-004`** — *Dadas* dos altas simultáneas con el mismo código bajo el mismo padre (dos procesos reales, `tests/Concurrency/`), exactamente una `201` y la otra `422 code_taken`, nunca `500`.
- **`CA-ACAD-005`** — `stage` o `regime` fuera de vocabulario ⇒ `422 acad.validation.stage_invalid`/`regime_invalid`, sin llegar al `CHECK` (`INV-010`).
- **`CA-ACAD-006`** — `PATCH` con `academic_year`, `education_level`, `course` o `group` ⇒ `422 acad.validation.parent_immutable` y la fila no cambia.
- **`CA-ACAD-007`** — Padre **del cuerpo** (`education_level`, `course`) borrado, inexistente o de otro centro en un alta ⇒ `422 acad.validation.parent_not_found`; grupo **de la ruta** borrado, inexistente o de otro centro en `POST /groups/{public_id}/subgroups` ⇒ `404`, nunca `422`, y no se crea nada.
- **`CA-ACAD-008`** — *Para cada* entidad con hijos vivos (`RN-ACAD-20`), `DELETE` ⇒ `409 acad.conflict.has_dependents` con `params.dependents` y la fila no cambia; sin hijos vivos, `204`, `deleted_at` informado y entrada `deleted`. Desde `1.11d` (`RN-ACAD-61`), la lista de dependientes de una etapa incluye `academic_periods`: una etapa con períodos lectivos vivos da `409 has_dependents` con `academic_periods` en `params.dependents` (caso concreto en `CA-ACAD-072`).

### 13.2 Contenido traducible (`ADR-062`)

- **`CA-ACAD-009`** (`ADR-062 §5.2`) — *Dado* un alta sin la clave del idioma por defecto del centro, *entonces* `422 translations.default_locale_required`; *con* una clave de un idioma no activo, `422 translations.locale_not_active`; *con* un valor vacío tras recortar, `422 translations.empty_value`. *Dado* un centro con `default_locale = en` y `active_locales = ['en']`, un alta con solo `en` es `201` (`OPEN-062-01`).
- **`CA-ACAD-010`** (`ADR-062 §5.2` punto 2) — *Dada* una asignatura con `es-ES` y `en`, *cuando* `PATCH {"name_translations": {"fr": "…"}}`, *entonces* `200` con los tres idiomas (`es-ES` y `en` intactos); *cuando* `{"fr": null}`, se quita solo `fr`; *cuando* `{"es-ES": null}` siendo `es-ES` el idioma por defecto, `422 translations.default_locale_required` y nada cambia. *Dado* un idioma retirado de `active_locales` con traducción guardada, un `PATCH` que no lo menciona no lo toca.
- **`CA-ACAD-011`** (`ADR-062 §5.4`) — Toda representación de etapa, curso de etapa y asignatura incluye `name` (resuelto) y `name_translations` (completo); un `name` en el cuerpo de una escritura se ignora (`200`/`201`, no `422`) y no cambia nada.
- **`CA-ACAD-012`** (`ADR-062 §5.3`) — Resolución con caída: *dada* una fila con `es-ES` y `en` y una petición con idioma `de`, `name` es el del idioma por defecto del centro; *tras* cambiar `default_locale` a `fr` (sin `fr` en la fila), `name` es el de `es-ES`; *dada* una fila solo con `de` y `default_locale = fr`, `name` es el de `de` (primera clave en el orden `es-ES`, `en`, `de`, `fr`). Test unitario del resolutor y test HTTP.
- **`CA-ACAD-013`** (`ADR-062 §5.5`) — *Cuando* un `PATCH` cambia `es-ES` y añade `fr`, *entonces* la entrada `updated` de `audit_logs` tiene exactamente `name_translations.es-ES` y `name_translations.fr` con `{from,to}` (sin entrada para los idiomas que no cambiaron); un valor que supere el tope de `ADR-035 §5` se redacta `oversized` solo en su idioma.
- **`CA-ACAD-014`** (`ADR-062 §5.1`) — *Dada* una inserción directa en base de datos con `name_translations` igual a `{}`, con una clave fuera del vocabulario (`"es"`), o con un valor que no es cadena, *entonces* el motor la rechaza con `23514` (`check_violation`). La columna la crea el ayudante de `TenantMigration`, no un `CHECK` escrito a mano.
- **`CA-ACAD-015`** (`ADR-062 §5.8`) — `AR-16` está en verde con las tablas reales de `acad`; en rojo con una tabla de prueba con una columna `*_translations` de tipo `text`, anulable, o sin el `CHECK`; hace la aserción de no vacuidad sobre el esquema real; su lista de excepciones está vacía.
- **`CA-ACAD-016`** (`ADR-062 §5.7`) — El componente de campo traducible muestra una entrada por idioma activo del centro, el idioma por defecto primero y marcado obligatorio, cada entrada con su atributo `lang`; no envía la petición sin el idioma por defecto; en la presentación, un `name` resuelto por caída lleva `lang` con su idioma de procedencia.

### 13.3 Asignación de asignaturas a grupos

- **`CA-ACAD-017`** — *Dado* un grupo y tres asignaturas de su curso de etapa, `PUT /groups/{id}/subjects` con dos ⇒ `200` y dos `created` de `group_subject`; después con la tercera sola ⇒ `200`, dos `deleted` y un `created`; repetir el mismo cuerpo ⇒ `200` sin entradas nuevas en `audit_logs`.
- **`CA-ACAD-018`** — Asignatura de otro curso de etapa, borrada o de otro centro en el cuerpo ⇒ `422 acad.validation.subject_not_in_course` y nada cambia.
- **`CA-ACAD-019`** — Una inserción directa de `group_subjects` con grupo y asignatura de cursos de etapa distintos la rechaza el motor por la FK compuesta (`23503`).

### 13.4 Curso académico: escritura bloqueada (`RN-CURSO-20/21`, `ADR-057`)

- **`CA-ACAD-020`** — *Dado* un curso académico `cerrado` con una entidad de cada una de las seis, crear, modificar o borrar cada una por su *endpoint* ⇒ `409 urn:pge:error:academic-year-closed`, `errors.academic_year[0].code = "curso.academic_year_closed"`, y la fila no cambia. En `planificacion` o `activo`, se admiten.
- **`CA-ACAD-021`** (`ADR-057 §5.3`, `CA-057-06`) — `AR-13` pasa con las tablas reales y gana la aserción de **no vacuidad sobre el esquema real solo** (sin sondas): al menos una tabla real con `academic_year_id`, todas con el disparador habilitado. Lista de excepciones vacía.
- **`CA-ACAD-022`** — Ninguna FK de las tablas de `acad` (incluidas `created_by`/`updated_by`) tiene acción referencial distinta de `NO ACTION`; `AR-13` lo confirma.
- **`CA-ACAD-023`** (issue #382, `CA-057-09`) — `AcademicYearWriteGuardOverheadTest` ampliado mide sobre `subjects` frente a una tabla de control idéntica sin disparador (20.000 filas en una sentencia y 1.000 sentencias; tres corridas, mediana de cinco repeticiones). La cifra se anota en `CHANGELOG.md` y `memory.md`, comparada con la de la sonda de `1.10` (+182 % a +195 % masiva; ruido fila a fila). Si difiere más allá del ruido, se abre issue; la cifra no se ajusta.

### 13.5 Curso académico: lectura denegada de curso cerrado (`RN-CURSO-33`, uno por recurso)

Forma común: *dado* un usuario con `estructura_academica.leer` **sin** `curso_historico.leer`, y un curso académico `cerrado` (y otro `archivado`, insertado como propietario en el test), *cuando* pide el **listado** filtrando por ese curso *y cuando* pide el **detalle** de un registro de ese curso, *entonces* `404` con el mismo cuerpo que un recurso inexistente; **con** `curso_historico.leer`, los datos.

- **`CA-ACAD-024`** — Etapas: `GET /education-levels?academic_year=…` y `GET /education-levels/{id}`.
- **`CA-ACAD-025`** — Cursos de etapa: `GET /courses?academic_year=…` (y `?education_level=…`) y `GET /courses/{id}`.
- **`CA-ACAD-026`** — Grupos: `GET /groups?academic_year=…` (y `?course=…`) y `GET /groups/{id}`.
- **`CA-ACAD-027`** — Subgrupos: `GET /groups/{id}/subgroups` y `GET /subgroups/{id}`.
- **`CA-ACAD-028`** — Asignaturas: `GET /subjects?academic_year=…` (y `?course=…`) y `GET /subjects/{id}`.
- **`CA-ACAD-029`** — Asignaturas de un grupo: `GET /groups/{id}/subjects` (sin detalle propio).
- **`CA-ACAD-030`** — Con el curso en `planificacion` o `activo`, los mismos *endpoints* responden `200` sin `curso_historico.leer`.
- **`CA-ACAD-031`** — Listado sin `academic_year` y sin curso activo ⇒ `404 curso.no_active_year`; `academic_year` de otro centro o inexistente ⇒ `404`.

### 13.6 Permisos y aislamiento (`INV-001`, `INV-002`)

- **`CA-ACAD-032`** — Todo *endpoint* responde `401` sin sesión y `403` sin su permiso (`leer`, `crear`, `actualizar` —incluido `PUT /groups/{id}/subjects`—, `eliminar`).
- **`CA-ACAD-033`** — Con dos centros con estructuras equivalentes, ningún *endpoint* devuelve, modifica, cuenta ni borra datos del otro; detalle, `PATCH`, `DELETE` y listados anidados sobre un `public_id` ajeno ⇒ `404`, nunca `403`.
- **`CA-ACAD-034`** — Con `acad` descontratado, todo *endpoint* responde `403 urn:pge:error:module-disabled` antes de comprobar el permiso; `AR-07b` en verde con las rutas de `acad`.
- **`CA-ACAD-035`** — Tras `platform:sync-registry`, `permissions` contiene exactamente los cuatro códigos de `permisos.md §2` con `module_code = 'acad'` y `applicable_scopes = ['todos']`, y `modules` contiene `acad` con `depends_on = ['curso']` y `essential = false`. `CA-PERM-134`: etiqueta del recurso en los cuatro idiomas.
- **`CA-ACAD-036`** (issue #387 B-5) — Tras `tenant:provision-defaults` y tras `acad:grant-structure-permissions`, los roles predefinidos tienen exactamente las concesiones de `permisos.md §4`; el comando no toca roles personalizados, solo recorre centros `Activo`, y **no concede nada** si alguno de los cuatro permisos falta en `permissions` o tiene `retired_at` informado, con un mensaje que lo dice.
- **`CA-ACAD-037`** (`ADR-063 §5.1`) — Ningún recurso de `acad` tiene ámbito distinto de `todos`; `acad` no registra nada en `ScopeResolverRegistry` ni entra en el mapa de `AR-10`, que sigue en verde sin ampliarse (**ampliado en `1.12-b`**: `reservas` registra su resolutor (`propios`, `reservas`) y entra en el mapa de `AR-10`, `OPEN-ACAD-42`; el resto de recursos sigue como se dice aquí); conceder a un rol un permiso de `acad` con ámbito `grupo` o `clase` responde `422`.

### 13.7 Piezas compartidas y reglas de arquitectura

- **`CA-ACAD-038`** (issue #407) — *Cuando* una migración de prueba usa `TenantMigration::tenantForeignId($t, 'x_id', 'y')`, *entonces* la tabla resultante tiene un índice cuyas columnas iniciales son `(tenant_id, x_id)`, además de la columna y la FK compuesta; test de regresión sobre el esquema real. Las seis tablas de `acad` tienen ese índice por cada uso del ayudante.
- **`CA-ACAD-039`** (issue #378 B3, `OPEN-ACAD-15`) — `AR-07a` falla con una ruta de prueba cuyo `permission:` nombra un código que ningún módulo declara, y pasa con las rutas reales.
- **`CA-ACAD-040`** (issue #387 B-4, `OPEN-ACAD-14`) — Una regla `arch()` falla si una clase fuera de `database/migrations`, `app/Modules/*/Database/migrations` y los *fixtures* de test usa `TenantMigration` (casos fijos que muerden y que no); `AR-14` escanea además `database/`, `routes/` y `bootstrap/`, con casos fijos que lo demuestran y aserción de no vacuidad.
- **`CA-ACAD-041`** (issue #387 B-5) — `curso:grant-year-permissions` no concede nada si alguno de sus permisos tiene `retired_at` informado, con un mensaje que lo dice; su comportamiento en el caso normal no cambia.

### 13.8 Contrato de `Curso`

- **`CA-ACAD-042`** (`OPEN-ACAD-12`) — Dado `academic_year_id` interno de un curso del centro, `AcademicYearDirectory` devuelve su resumen (uno y varios), consultando la base de datos una vez por identificador y petición; dado el de otro centro, `null`. `acad` no consulta `academic_years` y `AR-01` sigue en verde.
- **`CA-ACAD-043`** (issue #409, `OPEN-ACAD-11`) — Dado un usuario sin ningún permiso de `curso`, `GET /academic-years/selectable` devuelve los cursos `activo` y `planificacion` del centro; con `curso_historico.leer`, también los `cerrado`/`archivado`; nunca cursos de otro centro; `401` sin sesión. La lista cerrada de excepciones de `AR-07a` gana exactamente esa ruta.

### 13.9 Interfaz (Vitest; Playwright contra la API real en `CA-ACAD-053`)

- **`CA-ACAD-044`** — El módulo web cumple `AR-11`.
- **`CA-ACAD-045`** — Sin `estructura_academica.leer`, la entrada «Estructura académica» no aparece y la ruta muestra «sin acceso»; con él, aparece.
- **`CA-ACAD-046`** — «Nuevo…», «Editar», «Borrar» y la edición de asignaturas del grupo aparecen solo con su permiso **y** si el curso académico de la entidad admite escritura.
- **`CA-ACAD-047`** — El selector aparece en `acad-structure` (y en ninguna ruta sin `meta.academicYearScoped`); ofrece lo que devuelve `GET /academic-years/selectable`; al elegir otro curso la URL gana `?academic_year=<public_id>`, la tabla se recarga y nada se escribe en `localStorage` ni en sesión; recargar conserva la selección.
- **`CA-ACAD-048`** — Con un curso de solo lectura seleccionado, aviso persistente y ninguna acción de escritura visible.
- **`CA-ACAD-049`** — Sin curso activo y sin parámetro, estado «sin curso activo» con el selector, no un error genérico.
- **`CA-ACAD-050`** — Un `409 has_dependents`, un `409 academic-year-closed` y un `422 translations.*` se muestran con su mensaje junto al campo o la acción, y la pantalla no cambia hasta recargar.
- **`CA-ACAD-051`** (issue #376, `OPEN-ACAD-16`) — `lint:i18n` limpio, cuatro `locales` con las mismas claves, y `roleLiterals.spec.ts` en verde **sin ampliar ninguna lista**; el código de `acad` y de `src/academic-year/` no compara por `.code`.
- **`CA-ACAD-052`** (issue #408) — `apps/web/src/modules/README.md` lista los módulos que existen (`auth`, `core`, `curso`, `acad`); lo comprueba `doc-reviewer`.
- **`CA-ACAD-053`** — Playwright contra la API real: crear una etapa con nombre en dos idiomas, un curso de etapa, un grupo, un subgrupo y dos asignaturas; asignar una al grupo; intentar borrar el curso de etapa (ver el `409`); cambiar de curso académico con el selector.

### 13.10 Validación de campos y reglas estructurales complementarias

- **`CA-ACAD-054`** (`RN-ACAD-04`) — *Dados* tres hermanos vivos con `position` 1, 2 y 5 y uno borrado con `position` 9, *cuando* se crea otro sin `position`, *entonces* recibe `position: 6` (última viva + 1); *dado* el primer hijo de un padre, recibe `1`. *Dados* dos hermanos con la misma `position`, el listado con `sort=position` los devuelve por `code`.
- **`CA-ACAD-055`** — `position` igual a `0`, negativa o no entera ⇒ `422 acad.validation.position_invalid`, en alta y en `PATCH`, sin llegar al `CHECK`.
- **`CA-ACAD-056`** — Para cada una de las cinco entidades con `code`, un `code` ausente, vacío o solo con espacios ⇒ `422 acad.validation.code_required`; un `code` con espacios en los extremos se guarda recortado.
- **`CA-ACAD-057`** — En grupo y subgrupo, `name` ausente, vacío o solo con espacios ⇒ `422 acad.validation.name_required`; y `name` sí se guarda en `POST` y `PATCH` (`RN-ACAD-13`, contraste con `CA-ACAD-011`).
- **`CA-ACAD-058`** — `POST /education-levels` sin `academic_year` ⇒ `422 acad.validation.academic_year_required`; con un `academic_year` inexistente o de otro centro ⇒ `422 acad.validation.academic_year_not_found` (no `404`: es un campo del cuerpo), y no se crea nada.
- **`CA-ACAD-059`** (`RN-ACAD-12`) — `stage` y `regime` llegan en la respuesta como código técnico, idénticos con cualquier `Accept-Language`; la SPA muestra su etiqueta traducida y, para un código sin clave en el catálogo, el código en crudo (`ADR-038 §7.3`; Vitest).
- **`CA-ACAD-060`** (`RN-ACAD-16`) — Un subgrupo tiene un único `group` en su representación; una inserción directa con un `group_id` de otro curso académico la rechaza la FK compuesta (`23503`); ni la API ni el esquema relacionan un subgrupo con asignaturas (un campo `subject`/`subjects` en el cuerpo no crea ninguna relación).
- **`CA-ACAD-061`** (`RN-ACAD-17`) — `POST /subjects` sin `course` ⇒ `422 acad.validation.parent_not_found`; el mismo `code` (`MAT`) en dos cursos de etapa crea dos asignaturas con `public_id` distintos, y cada una aparece solo en el listado `?course=` de su curso; una inserción directa con `course_id` de otro curso académico la rechaza la FK (`23503`).
- **`CA-ACAD-062`** (`RN-ACAD-31`) — Un grupo recién creado devuelve `GET /groups/{id}/subjects` vacío; crear después una asignatura en su curso de etapa **no** la añade a los grupos existentes.

> **Criterios de `1.11d`**: `CA-ACAD-063` a `-102`, aprobados el 2026-10-16 (el `-102` con las decisiones de `OPEN-CURSO-25` a `-27`); `CA-ACAD-103` a `-106`, añadidos el 2026-10-17 en la revisión de documentación (`M-5`, `B-6`). Cada test referencia su `CA-ACAD-NNN`, `REQ-ACAD-001` y el `RN-ACAD-*` que cita (`INV-015`). «Curso académico de prueba»: del 2026-09-01 al 2027-08-31, `activo`, salvo que se diga otra cosa.

### 13.11 Períodos lectivos (`REQ-ACAD-001` punto 4)

- **`CA-ACAD-063`** (`RN-ACAD-51`, `-53`) — *Dada* una etapa del curso de prueba y un usuario con `configuracion_academica.crear`, *cuando* envía `POST /academic-periods` con `education_level`, `period_type: "trimestre"`, `name_translations` con el idioma por defecto del centro, `starts_on: "2026-09-08"` y `ends_on: "2026-12-22"`, *entonces* `201` con `Location`, `public_id` ULID, `academic_year` igual al de la etapa sin haberlo enviado, `name` y `name_translations`, `position: 1`, y una entrada `created` en `audit_logs` con `auditable_type = 'academic_period'` y las traducciones desglosadas por idioma.
- **`CA-ACAD-064`** (`RN-ACAD-51`, `-52`) — *Cuando* el cuerpo lleva `academic_year` ⇒ `422 acad.validation.academic_year_not_allowed`; *cuando* `education_level` falta, no existe, está borrada, es de otro centro o de un curso `cerrado` que el usuario no puede leer ⇒ `422 acad.validation.parent_not_found`; en ningún caso se crea nada.
- **`CA-ACAD-065`** (`RN-ACAD-55`, `-56`) — `period_type: "bimestre"` ⇒ `422 acad.validation.period_type_invalid`; `starts_on: "2026-13-01"` o `"08/09/2026"` ⇒ `422 acad.validation.date_invalid`; `ends_on` igual o anterior a `starts_on` ⇒ `422 acad.validation.period_ends_before_start`; ninguno llega al `CHECK` (`INV-010`).
- **`CA-ACAD-066`** (`RN-ACAD-57`) — `starts_on: "2026-08-31"` o `ends_on: "2027-09-01"` ⇒ `422 acad.validation.period_outside_academic_year` con `params.academic_year_starts_on = "2026-09-01"` y `params.academic_year_ends_on = "2027-08-31"`; un período del 2026-09-01 al 2027-08-31 exactos ⇒ `201`.
- **`CA-ACAD-067`** (`RN-ACAD-58`) — *Dado* un período vivo del 2026-09-08 al 2026-12-22 en la etapa, *cuando* se crea otro del 2026-12-22 al 2027-03-26, *entonces* `422 acad.validation.period_overlap` con `params.period` igual al `public_id` del primero; del 2026-12-23 al 2027-03-26 ⇒ `201`; del 2026-10-01 al 2026-11-01 en **otra** etapa ⇒ `201`; y solapado con un período **borrado** de la misma etapa ⇒ `201`.
- **`CA-ACAD-068`** (`RN-ACAD-59`) — *Dado* un trimestre vivo en la etapa, un alta con `period_type: "semestre"` ⇒ `422 acad.validation.period_type_mismatch` con `params.period_type = "trimestre"`; un `PATCH` de `period_type` sobre un período con hermanos vivos ⇒ el mismo `422` y la fila no cambia; sobre el único período vivo de la etapa ⇒ `200`.
- **`CA-ACAD-069`** (`RN-ACAD-54`, `-58`, `-59`) — *Dadas* dos altas simultáneas en la misma etapa (dos procesos reales, `tests/Concurrency/`) que solapan entre sí, *y en otra corrida* dos altas no solapadas de tipos distintos, *entonces* en cada corrida exactamente una responde `201` y la otra `422` (`period_overlap` o `period_type_mismatch`), nunca dos `201` ni un `500`; *y dadas* un alta de período y el borrado de su etapa simultáneos, *entonces* o el borrado responde `409 has_dependents` o el alta `422 parent_not_found`, nunca ambos con éxito.
- **`CA-ACAD-070`** (`RN-ACAD-51`, §4.8.3) — `PATCH` de fechas válidas ⇒ `200` y entrada `updated` con `starts_on`/`ends_on` `{from,to}`; un `PATCH` que solo cambia `ends_on` se valida contra los hermanos excluyéndose a sí mismo (no solapa consigo mismo); `PATCH` con `education_level` o `academic_year` ⇒ `422 acad.validation.parent_immutable` y la fila no cambia; `PATCH` sin cambios ⇒ `200` sin entrada de auditoría.
- **`CA-ACAD-071`** (`RN-ACAD-60`) — *Dados* tres períodos creados en orden de fechas inverso, `GET /academic-periods?education_level=…` los devuelve por `starts_on` ascendente; `sort=-starts_on` los invierte; `sort=position` ordena por posición y desempata por `starts_on`; `position` `0` o negativa ⇒ `422 acad.validation.position_invalid`.
- **`CA-ACAD-072`** (`RN-ACAD-61`) — `DELETE /academic-periods/{id}` ⇒ `204`, `deleted_at` informado y entrada `deleted`; *dada* una etapa sin cursos pero con un período vivo, `DELETE /education-levels/{id}` ⇒ `409 acad.conflict.has_dependents` con `params.dependents = ["academic_periods"]`; tras borrar el período, `204`.
- **`CA-ACAD-073`** (`datos.md §1.7`) — Inserciones directas en base de datos: `ends_on <= starts_on` ⇒ `23514`; `period_type = 'bimestre'` ⇒ `23514`; `position = 0` ⇒ `23514`; `education_level_id` de una etapa de otro curso académico ⇒ `23503`; `name_translations = '{}'` ⇒ `23514`.
- **`CA-ACAD-074`** (`api.md §11.5`) — `GET /academic-periods` filtra por `academic_year` (omitido ⇒ activo), `education_level` y `period_type` (múltiples, separados por comas, combinados con `AND`); un `education_level` de otro curso académico distinto del filtrado ⇒ colección vacía; pagina con `page`/`per_page` (máx. 100, más ⇒ `422`).

### 13.12 Escalas de calificación (`REQ-ACAD-001` punto 5)

- **`CA-ACAD-075`** (`RN-ACAD-51`, `-71`, `-74`, `-75`) — *Dado* un usuario con `configuracion_academica.crear`, *cuando* envía `POST /grading-scales` con `academic_year`, `code: "NUM10"`, nombre, `scale_type: "numerica"`, `min_value: "0"`, `max_value: 10`, `decimals: 2` y `passing_min_value: "5"`, *entonces* `201` con `min_value: "0.00"`, `max_value: "10.00"`, `passing_min_value: "5.00"`, `values: []`, y entrada `created` con `auditable_type = 'grading_scale'`.
- **`CA-ACAD-076`** (`RN-ACAD-72`, `-73`, `-74`) — En una `numerica`: falta `passing_min_value` ⇒ `422 acad.validation.scale_field_required` en ese campo; `min_value = max_value` ⇒ `422 acad.validation.range_invalid`; `decimals: 3` ⇒ `422 acad.validation.decimals_invalid`; `passing_min_value` fuera de `[min, max]` ⇒ `422 acad.validation.passing_out_of_range`; `decimals: 0` con `passing_min_value: "4.5"` ⇒ `422 acad.validation.decimal_precision_exceeded`; `"5,5"`, `"1e1"`, `"1000"` ⇒ `422 acad.validation.decimal_invalid`; en ningún caso se crea nada.
- **`CA-ACAD-077`** (`RN-ACAD-72`, `-81`, `-82`, `-83`) — Una `cualitativa` con cinco valores (`IN`, `SU`, `BI`, `NT`, `SB`), `is_passing` falso solo en `IN`, ⇒ `201` con los valores en el orden enviado, `position` 1 a 5, `label` y `label_translations`, y una entrada `created` por valor (`grading_scale_value`); sin `values` ⇒ `422 acad.validation.values_required`; todos con `is_passing: false` ⇒ `422 acad.validation.passing_value_required`; un valor sin `is_passing` ⇒ `422 acad.validation.is_passing_required` en `values.<n>.is_passing`; dos valores con el mismo `code` ⇒ `422 acad.validation.code_taken` en `values.<n>.code`; `min_value` en el cuerpo ⇒ `422 acad.validation.not_applicable_to_scale_type`.
- **`CA-ACAD-078`** (`RN-ACAD-84` a `-87`) — Una `mixta` 0-10 con `decimals: 1`, umbral `5` y tramos 0–4.9, 5–6.9, 7–8.9, 9–10 ⇒ `201`, y la representación da `is_passing` `false` en el primero y `true` en los demás; con tramos 0–4.9 y 5.1–10 ⇒ `422 acad.validation.bands_not_contiguous`; con 0–5.9 y 6–10 ⇒ `422 acad.validation.passing_threshold_inside_band`; un tramo 9–11 ⇒ `422 acad.validation.band_range_invalid`; un tramo con `is_passing` ⇒ `422 acad.validation.not_applicable_to_scale_type`.
- **`CA-ACAD-079`** (`RN-ACAD-81`, `-89`) — Una `numerica` con `values` no vacío ⇒ `422 acad.validation.not_applicable_to_scale_type`; una `cualitativa` con 101 valores ⇒ `422 acad.validation.values_too_many`.
- **`CA-ACAD-080`** (`RN-ACAD-88`) — *Dada* una `cualitativa` con valores A, B y C, *cuando* `PATCH` con `values` = [C (con su `public_id`, etiqueta cambiada en `fr`), A (con su `public_id`), D (nuevo)], *entonces* `200`; C conserva su `public_id`, gana `fr` y mantiene sus otros idiomas; B queda borrado (`deleted`); D creado (`created`); las posiciones son C=1, A=2, D=3; *y* repetir el mismo cuerpo ⇒ `200` sin entradas nuevas en `audit_logs`.
- **`CA-ACAD-081`** (`RN-ACAD-88`) — Un `PATCH` con el `public_id` de un valor de **otra** escala del mismo centro, de otro centro, de un valor borrado o repetido dos veces ⇒ `422 acad.validation.value_not_in_scale` y nada cambia (ni la escala ni sus valores).
- **`CA-ACAD-082`** (`RN-ACAD-70`, `-51`) — `PATCH` con `scale_type` ⇒ `422 acad.validation.scale_type_immutable`; con `academic_year` ⇒ `422 acad.validation.parent_immutable`; la fila no cambia.
- **`CA-ACAD-083`** (`RN-ACAD-88`, `-85`) — *Dada* una `mixta` válida, un `PATCH` que solo cambia `max_value` a `"12"` ⇒ `422 acad.validation.bands_not_contiguous`; el mismo `PATCH` con los tramos ajustados ⇒ `200`.
- **`CA-ACAD-084`** (`RN-ACAD-71`) — *Dado* un código usado por una escala viva del mismo curso académico ⇒ `422 acad.validation.code_taken`; el mismo código en otro curso académico o en una escala borrada ⇒ `201`; dos altas simultáneas con el mismo código (dos procesos reales, `tests/Concurrency/`) ⇒ una `201` y otra `422 code_taken`, nunca `500`.
- **`CA-ACAD-085`** (`RN-ACAD-76`) — `DELETE /grading-scales/{id}` de una cualitativa con cinco valores ⇒ `204`; la escala y los cinco valores tienen `deleted_at`, y `audit_logs` tiene seis entradas `deleted`; `GET` posterior ⇒ `404`.
- **`CA-ACAD-086`** (`datos.md §1.8`, `§1.9`) — Inserciones directas en base de datos: una `numerica` con `min_value >= max_value`, con `passing_min_value` fuera del rango, con `decimals = 3`, con `min_value = 4.5` y `decimals = 0`, o una `cualitativa` con `min_value` informado ⇒ `23514`; un valor con `scale_type = 'numerica'` ⇒ `23514`; un valor `cualitativa` con `range_min` o sin `is_passing`, o un valor `mixta` con `is_passing` o sin `range_max` ⇒ `23514`; un valor cuyo `scale_type` no coincide con el de su escala o cuyo `academic_year_id` es otro ⇒ `23503`; un `UPDATE` del `scale_type` de una escala con valores ⇒ `23503`.
- **`CA-ACAD-087`** (`RN-ACAD-53`, `ADR-062`) — En el alta de un valor sin la clave del idioma por defecto del centro ⇒ `422 translations.default_locale_required` en `values.<n>.label_translations`; un `PATCH` que cambia la etiqueta `es-ES` de un valor y añade `fr` deja en `audit_logs` una entrada `updated` del valor con exactamente `label_translations.es-ES` y `label_translations.fr`; `label`/`name` en el cuerpo se ignoran; con `Accept-Language` sin traducción, `label` sale por caída (`ADR-062 §5.3`).

### 13.13 Curso académico: escritura bloqueada (`RN-ACAD-52`, `ADR-057`)

- **`CA-ACAD-088`** (`RN-ACAD-52`, `RN-ACAD-07`) — *Dado* un curso `cerrado` con una etapa, un período, una escala `cualitativa` y sus valores, crear, modificar o borrar el período, crear la escala, modificarla (con y sin `values`) o borrarla por su *endpoint* ⇒ `409 urn:pge:error:academic-year-closed` con `errors.academic_year[0].code = "curso.academic_year_closed"`, y ninguna fila cambia; en `planificacion` o `activo`, se admiten.
- **`CA-ACAD-089`** (`ADR-057 §5.3`, `AR-13`) — `academic_periods`, `grading_scales` y `grading_scale_values` tienen el disparador `academic_year_write_guard` habilitado; `AR-13` sigue en verde con su lista de excepciones vacía; ninguna FK de las tres tablas (incluidas las de autoría) tiene acción referencial distinta de `NO ACTION`.

### 13.14 Curso académico: lectura denegada de curso cerrado (`RN-CURSO-33`, uno por recurso)

Forma común de §13.5, con `configuracion_academica.leer` en lugar de `estructura_academica.leer`.

- **`CA-ACAD-090`** (`RN-ACAD-52`, `RN-CURSO-33`) — Períodos lectivos: `GET /academic-periods?academic_year=…` (y `?education_level=…` de una etapa de ese curso) y `GET /academic-periods/{id}` ⇒ `404` sin `curso_historico.leer`; con él, los datos.
- **`CA-ACAD-091`** (`RN-ACAD-52`, `RN-CURSO-33`) — Escalas (y sus valores, que solo se leen dentro de la escala): `GET /grading-scales?academic_year=…` y `GET /grading-scales/{id}` ⇒ `404` sin `curso_historico.leer`; con él, los datos con sus valores.
- **`CA-ACAD-092`** (`RN-ACAD-52`, `RN-ACAD-51`) — Con el curso en `planificacion` o `activo`, los cuatro *endpoints* responden `200` sin `curso_historico.leer`; sin `academic_year` y sin curso activo ⇒ `404 curso.no_active_year`; `academic_year` de otro centro o inexistente ⇒ `404`.

### 13.15 Permisos y aislamiento (`INV-001`, `INV-002`)

- **`CA-ACAD-093`** (`RN-ACAD-50`) — Los diez *endpoints* nuevos responden `401` sin sesión y `403` sin su permiso de `configuracion_academica` (`leer`, `crear`, `actualizar`, `eliminar`; el `PATCH` de una escala con `values` exige solo `actualizar`); un usuario con los cuatro de `estructura_academica` y ninguno de `configuracion_academica` recibe `403`, y uno con los cuatro de `configuracion_academica` y ninguno de `estructura_academica` recibe `403` en los 27 *endpoints* de `1.11`.
- **`CA-ACAD-094`** (`RN-ACAD-50`, `RN-ACAD-51`, `RN-ACAD-88`; `INV-001`) — Con dos centros con períodos y escalas equivalentes, ningún *endpoint* devuelve, modifica, cuenta ni borra datos del otro; detalle, `PATCH` y `DELETE` sobre un `public_id` ajeno ⇒ `404`, nunca `403`; una `education_level` ajena en el cuerpo ⇒ `422 parent_not_found`; un `public_id` de valor ajeno en `values` ⇒ `422 value_not_in_scale`; un `academic_year` ajeno en el alta de una escala ⇒ `422 academic_year_not_found`.
- **`CA-ACAD-095`** (`RN-ACAD-50`; `RMOD-009`) — Con `acad` descontratado, los diez *endpoints* responden `403 urn:pge:error:module-disabled` antes de comprobar el permiso; `AR-07a` (lista de excepciones sin cambios: **35**) y `AR-07b` en verde con las rutas nuevas.
- **`CA-ACAD-096`** (`permisos.md §2.2`, `CA-PERM-134`) — Tras `platform:sync-registry`, `permissions` contiene exactamente **ocho** códigos con `module_code = 'acad'`: los cuatro de `estructura_academica` y los cuatro de `configuracion_academica`, todos con `applicable_scopes = ['todos']` e `is_special_category = false`; la etiqueta `acad.permissions.resources.configuracion_academica` existe en los cuatro idiomas; `AR-09` no se activa.
- **`CA-ACAD-097`** (`permisos.md §4.1`, `OPEN-ACAD-28` decidida) — Tras `tenant:provision-defaults` y tras el comando de siembra para centros existentes, los roles predefinidos tienen exactamente las concesiones de `configuracion_academica` de `permisos.md §4.1`; el comando no toca roles personalizados, solo recorre centros `Activo`, es idempotente y no concede nada si alguno de los permisos que siembra falta o tiene `retired_at` informado, con un mensaje que lo dice; conceder `configuracion_academica.leer` con ámbito `grupo` o `clase` ⇒ `422` (`ADR-063 §5.1`), y `acad` sigue sin registrar nada en `ScopeResolverRegistry`.

### 13.16 Interfaz (Vitest; Playwright contra la API real en `CA-ACAD-101`)

- **`CA-ACAD-098`** (`RN-ACAD-50`, §10.5.1, `AR-11`) — El módulo web sigue cumpliendo `AR-11`; las cuatro rutas nuevas declaran `meta.permissions` no vacío; la entrada «Escalas de calificación» aparece solo con `configuracion_academica.leer`; la sección «Períodos lectivos» de la ficha de etapa aparece solo con `configuracion_academica.leer`; el selector de curso aparece en `acad-grading-scales` y en `acad-grading-scale-new`.
- **`CA-ACAD-099`** (`RN-ACAD-42`, §10.5.3) — «Nuevo período», «Nueva escala», «Editar» y «Borrar» aparecen solo con su permiso **y** si el curso académico admite escritura; con un curso de solo lectura, aviso persistente; tablas con estado de carga y de vacío; un `422` en `values.<n>.<campo>` se pinta en su fila y uno de lista sobre la lista, enlazado con `aria-describedby`; `period_overlap` nombra el período con el que solapa.
- **`CA-ACAD-100`** (§10.5.2, §10.5.4, `INV-009`) — El editor de valores reordena con «Subir»/«Bajar» por teclado (sin arrastre) y lo anuncia en una región viva; «aprobado/suspenso» se transmite con texto; en la mixta la columna «Aprobado» se recalcula al cambiar el umbral; `period_type` y `scale_type` se muestran con su etiqueta traducida y un código sin clave cae al código en crudo; `lint:i18n` limpio y los cuatro `locales` con las mismas claves; `roleLiterals.spec.ts` en verde sin ampliar listas.
- **`CA-ACAD-101`** (`RN-ACAD-58`, `RN-ACAD-61`, `RN-ACAD-82`, `RN-ACAD-83`, `RN-ACAD-85`) — Playwright contra la API real: en una etapa, crear tres trimestres; intentar un cuarto solapado (ver el `422` junto a la fecha); intentar borrar la etapa (ver el `409` con «períodos lectivos»); crear una escala cualitativa de cinco valores, reordenarla y guardar; crear una mixta 0-10; y comprobar a 320 px de ancho que el formulario de escala no tiene desplazamiento horizontal de página.

### 13.17 Cambio de fechas del curso académico (`RN-ACAD-62`; `OPEN-ACAD-24` = B)

En `apps/api/tests/Feature/Acad/`, contra la validación real de `acad` registrada en `curso`. Los casos de bordes incluidos, período borrado y `acad` descontratado ya los fija `REQ-CURSO CA-CURSO-113` y no se duplican aquí; el aislamiento entre centros, `CA-CURSO-114`; la concurrencia, `CA-CURSO-115` y `-120`.

- **`CA-ACAD-102`** (`RN-ACAD-62`, `REQ-CURSO RN-CURSO-53`) — *Dado* el curso académico de prueba en `planificacion` (2026-09-01 a 2027-08-31) con dos etapas, un período vivo del 2026-09-08 al 2026-12-22 en la primera y otro del 2027-04-06 al 2027-06-20 en la segunda, y un usuario con `curso_academico.actualizar` y **sin ningún permiso de `acad`**, *cuando* envía `PATCH /academic-years/{id}` con `starts_on: "2026-10-01"` y `ends_on: "2027-06-01"`, *entonces* `409 curso.conflict.date_change_checks_failed` con exactamente una entrada en `errors.date_change[]`: `code` `acad.conflict.periods_outside_academic_year`; `params` exactamente `{"count": 2, "periods_starts_on": "2026-09-08", "periods_ends_on": "2027-06-20"}` (sin `public_id`, nombre ni etapa de ningún período); `message` con esas dos fechas, en el idioma de `Accept-Language` para `es-ES`, `en`, `de` y `fr`; y ni el curso ni los períodos cambian, ni hay entrada nueva en `audit_logs`. *Con* `starts_on: "2026-09-10"` solo, `params.count = 1` y las mismas dos fechas. *Con* la aplicación arrancada, `AcademicYearDateChangeRegistry::all()` contiene exactamente una validación con `moduleCode() = "acad"` (la registra `AcadServiceProvider::boot()`). `lang/{es,en,de,fr}/acad.php` tienen la clave `conflict.periods_outside_academic_year` (`AR-12`).

### 13.18 Criterios añadidos en la revisión de documentación (2026-10-17)

- **`CA-ACAD-103`** (§4.8.1, §10.5.1, `RN-ACAD-52`; Vitest, decisión M-5) — *Dada* la ficha de una etapa de un curso académico en `planificacion` mientras otro curso es el activo, *cuando* se abre la sección «Períodos lectivos», *entonces* la SPA pide `GET /academic-periods` con `academic_year` igual al `academic_year` de la etapa **y** `education_level` igual a la etapa (nunca sin `academic_year`), y muestra sus períodos. *Dada* una etapa de un curso `cerrado` y un usuario con `curso_historico.leer`, la misma petición lleva el curso cerrado, la sección muestra los períodos en solo lectura y no ofrece «Nuevo período», «Editar» ni «Borrar». *Dado* un centro sin curso activo, la sección de una etapa de un curso en `planificacion` carga igual (no aparece el estado «sin curso activo»).
- **`CA-ACAD-104`** (`RN-ACAD-53`) — `POST /grading-scales` con un `code` de escala que contiene NUL, un control C0 o un salto de línea ⇒ `422 acad.validation.invalid_characters` en `code`; con un valor cuyo `code` los contiene ⇒ el mismo `422` en `values.<n>.code`; en `PATCH` igual; en ningún caso se escribe nada.
- **`CA-ACAD-105`** (`RN-ACAD-51`) — `POST /grading-scales` sin `academic_year` ⇒ `422 acad.validation.academic_year_required`; con un `academic_year` inexistente o de otro centro ⇒ `422 acad.validation.academic_year_not_found` (no `404`: es un campo del cuerpo); no se crea nada.
- **`CA-ACAD-106`** (`RN-ACAD-52`, desviación S-3 de §16) — *Dado* un curso académico `cerrado` y un usuario con `configuracion_academica.crear` **sin** `curso_historico.leer`, *cuando* envía `POST /grading-scales` con `academic_year` igual a ese curso, *entonces* `422 acad.validation.academic_year_not_found`, con el mismo cuerpo que para un curso inexistente, y no se crea nada; *con* `curso_historico.leer`, `409 urn:pge:error:academic-year-closed` (disparador).

---

## 14. Riesgos

| Riesgo | Severidad | Mitigación |
|--------|-----------|------------|
| Primera tabla real con el disparador: el contrato de `ADR-057` no encaja | Media | `CA-ACAD-020`, `-021` |
| Coste del disparador en cargas masivas futuras (`1.24`) | Baja | `CA-ACAD-023`; salidas de #382 sin tocar la garantía |
| Olvidar `AcademicYearReadAccess` en un *endpoint* de lectura | Media | `CA-ACAD-024` a `-031`; revisión de `security-reviewer` |
| Piezas compartidas de `ADR-062` construidas con un solo consumidor y forma que luego no encaje en otros módulos | Media | Contrato fijado por ADR antes de la primera tabla; `AR-16`; los módulos citan los códigos comunes, no los redefinen |
| Ámbitos `grupo`/`clase` sin resolutor: un docente no puede tener acceso restringido a sus grupos en ningún módulo | Alta | **Plazo vinculante de `ADR-063 §5.4`**: la asignación docente y su interfaz, especificadas y aprobadas antes de aprobar la especificación de `1.13` o `1.16`. Mientras, fallo en cerrado y prohibición de sustituirlo dando `todos` a `docente` |
| `1.11` excede una sesión: además de `acad`, nace la infraestructura de `ADR-062` y se tocan `curso` y `Core` | Media | Commits por pieza; si no cabe, la sesión orquestadora parte el paso y lo anota (no lo decide un subagente relanzado, `CLAUDE.md §3`) |
| **`1.11d`**: «sin solape» y «un solo tipo por etapa» no los garantiza el motor (sin `btree_gist`, `ADR-034 §4`); una escritura que no tome el bloqueo de la etapa los rompería | Media | Un solo servicio escribe períodos y toma `FOR UPDATE` de la etapa (`RN-ACAD-54`); `CA-ACAD-069` con dos procesos reales; revisión de `db-reviewer`. Lo mismo para la cobertura de tramos (`RN-ACAD-85`), bajo el bloqueo de la escala |
| ~~**`1.11d`**: un cambio de fechas del curso académico deja períodos fuera sin que nadie lo impida~~ | ~~Media~~ — **cerrado** en `1.11d-a` | Punto de extensión de `curso` con la validación de `acad` (`OPEN-ACAD-24` = B, `RN-ACAD-62`) para los períodos ya confirmados, y lectura con bloqueo compartido para los que se están escribiendo (`OPEN-CURSO-25` = B, `RN-ACAD-54`, `-57`); `CA-ACAD-102`, `REQ-CURSO CA-CURSO-113` a `-115` y `-120`. La marca de período fuera de rango en la ficha de etapa estaba condicionada a que no existiera el punto de extensión («mientras no exista»); con él en la misma entrega, la condición no se da |
| **`1.11d`**: períodos fuera del curso por un cambio de fechas hecho con `acad` descontratado (`REQ-CURSO RN-CURSO-52`, `OPEN-CURSO-26` = A) | Baja | Riesgo aceptado por el usuario. Al recontratar, `RN-ACAD-57` se vuelve a comprobar en la siguiente edición de cada período; `REQ-CURSO/operacion.md §7` advierte de no descontratar para esquivar la validación |
| **`1.11d`**: una escritura de períodos que no use la lectura con bloqueo compartido, o que bloquee la etapa antes del curso | Media | Un solo servicio escribe períodos (`RN-ACAD-54`); `CA-CURSO-120` con dos procesos reales; revisión de `db-reviewer`. Fuera de transacción, la lectura falla en voz alta (`REQ-CURSO RN-CURSO-56`) |
| **`1.11d`**: `CALIF` llega y descubre que necesita otra forma de período o escala | Media | Interfaces escritas en §7.2 y huecos en §12; `CALIF` es el único consumidor (`OPEN-ACAD-18`) y su especificación puede pedir una ampliación aditiva (*expand*) antes de su primera FK |
| **`1.11d`**: decimales con coma flotante en algún punto (PHP `float`, JSON) que redondeen un umbral | Media | `numeric(5,2)` en el motor, cadena en la API (`RN-ACAD-75`), comparación en servidor sin `float`, con enteros escalados ×100 (el valor ya está validado con a lo sumo dos decimales; no hace falta extensión ni dependencia nueva) **[DERIVADA]**; `CA-ACAD-076`, `-078` |

---

## 15. Preguntas abiertas

### 15.1 Preguntas de `1.11`

**Todas resueltas** por decisión del usuario el 2026-10-10 (recomendación aceptada en cada una). `05` y `08` se resuelven además por `ADR-062` y `ADR-063`, ACEPTADOS el 2026-10-10.

| ID | Pregunta | Decisión aprobada |
|----|----------|-------------------|
| `OPEN-ACAD-01` | División de `REQ-ACAD-001` | **A**: `1.11` estructura + selector + obligaciones; `1.11d` períodos y escalas; competencias a `1.16` |
| `OPEN-ACAD-02` | Nombres y terminología | **A**: se conserva `Course`; desambiguación en el texto (§0.1). Sin ADR |
| `OPEN-ACAD-03` | ¿Etapas por curso académico? | **B**: por curso académico |
| `OPEN-ACAD-04` | Tipo de etapa y régimen | **A**: etapas del centro con `stage` cerrado de seis valores y `regime` obligatorio |
| `OPEN-ACAD-05` | Contenido del centro traducible | **A**, contrato en **`ADR-062`** (ACEPTADA): `jsonb` por campo con claves `es-ES`/`en`/`de`/`fr`; al escribir se exige el **idioma por defecto del centro** (`OPEN-062-01`); resolución en servidor con caída; `PATCH` por fusión; desglose por idioma en auditoría; `AR-16` |
| `OPEN-ACAD-06` | Subgrupos | **A**: un solo grupo; sin relación con asignaturas |
| `OPEN-ACAD-07` | Asignación de asignaturas a grupos | **A**: explícita |
| `OPEN-ACAD-08` | Ámbitos `grupo`, `clase`, `departamento` | **A**, contrato en **`ADR-063`** (ACEPTADA): `1.11` no registra resolutores; `clase` = pares (grupo, asignatura) que el usuario imparte (`OPEN-063-01`); `acad` aporta la pertenencia y cada dueño de recurso su resolutor; asignación docente candidata en `1.12` con plazo antes de `1.13`/`1.16`; `departamento` sin dueño |
| `OPEN-ACAD-09` | Recurso y siembra | **A + X**: `estructura_academica`; administrador los cuatro; `direccion`, `secretaria`, `docente`, `tutor_grupo`, `orientador`: `leer` |
| `OPEN-ACAD-10` | Esencialidad y `depends_on` | **A**: `essential: false`, `depends_on: ['curso']` |
| `OPEN-ACAD-11` | Selector: qué cursos lista y a quién | **A**: `GET /academic-years/selectable` de autoservicio, con ampliación de `AR-07a` aprobada, y `meta.academicYearScoped` (#409) |
| `OPEN-ACAD-12` | Ampliar `AcademicYearDirectory` | **A**: búsqueda por identificador interno en `1.11` |
| `OPEN-ACAD-13` | Borrado referenciado por otros módulos | **A**: obligación de `1.15` (`RN-ACAD-21`) |
| `OPEN-ACAD-14` | Issue #387 | **A**: comprobación de `retired_at` en el comando nuevo y en `curso:grant-year-permissions`; regla `arch()` sobre `TenantMigration` por ruta; `AR-14` amplía su escaneo |
| `OPEN-ACAD-15` | Issue #378 B3-B5 | **A**: `AR-07a` valida el código de permiso contra el catálogo; B4 y B5 fuera de `1.11` |
| `OPEN-ACAD-16` | Issue #376 | **A**: comparar por `public_id`, sin tocar el test; los literales siguen latentes para `1.14` |
| `OPEN-ACAD-17` | Períodos lectivos | **A + Y**: de `acad`, por curso académico y etapa, un solo tipo por etapa; `CALIF` los referencia |
| `OPEN-ACAD-18` | Escalas | **A**: por curso académico, declarando el aprobado; asignación a asignaturas en `1.16` |
| `OPEN-ACAD-19` | Competencias LOMLOE | **A**: a `1.16`, que resuelve también la fuente del catálogo |
| `OPEN-ACAD-20` | Capacidad y ratio | **A**: fuera de `1.11` |
| `OPEN-ACAD-21` | Unidades concertadas | **A**: fuera de `1.11`; régimen solo por etapa |
| `OPEN-ACAD-22` | Orden del plan (`1.13` antes de `1.15`) | **A**: mover `1.13` tras `1.15`. Lo aplica la sesión principal en `PLAN-IMPLEMENTACION.md` |
| `OPEN-ACAD-23` | Sección de navegación | **A**: `administracion` |

Preguntas de los ADR que siguen abiertas fuera de esta especificación: `OPEN-062-02` (desglose en auditoría como ampliación de `ADR-035 §4`) y `OPEN-063-02` (aclaración de `ADR-044 §3`). Esta especificación asume la recomendación de ambas (sí); si el usuario decidiera otra cosa, cambian `CA-ACAD-013` y §7.2/§12.

### 15.2 Preguntas de `1.11d` (RESUELTAS, 2026-10-16)

**Todas resueltas** por decisión del usuario el 2026-10-16: aceptó la recomendación de cada una (`OPEN-ACAD-24` = B, `-25` = A, `-26` = A, `-27` = A, `-28` = X + 1, `-29` = A, `-30` = A, `-31` = dos entregas). El texto de §4.8-§4.9, §5.7-§5.10 y §13.11-§13.16 ya aplica esas opciones. La columna «Recomendación» de la tabla es, por tanto, la **decisión**.

| ID | Pregunta | Opciones | Recomendación | Afecta a |
|----|----------|----------|---------------|----------|
| `OPEN-ACAD-24` | **¿Qué pasa con los períodos si cambian las fechas del curso académico?** `RN-CURSO-06` permite editar las fechas de un curso en `planificacion`; `curso` no sabe que existen períodos (`acad` depende de `curso`, no al revés) y un `PATCH` de fechas puede dejar períodos fuera, rompiendo `RN-ACAD-57` sin que nadie lo valide | **A** · Tolerarlo: la regla solo se comprueba al escribir el período; la interfaz marca el período fuera de rango. **B** · `curso` gana en `Curso\Domain` un registro de validaciones de cambio de fechas (forma de `RN-CURSO-30`), y `acad` registra una que rechaza el `PATCH` (`409`) si deja fuera algún período vivo. **C** · `acad` recorta o borra los períodos afectados: descartable, escribiría datos sin que el usuario lo pida | **B**: es la única que mantiene la regla como invariante (`INV-010`) sin acoplar `curso` a `acad`; patrón ya existente. Coste: tocar `curso` (especificación de `REQ-CURSO` y su API). Riesgo residual escrito: el alta de un período lee las fechas por `AcademicYearDirectory` sin bloquear la fila del curso, así que un `PATCH` de fechas concurrente aún puede colarse; cerrarlo exigiría que `curso` ofrezca un bloqueo compartido de la fila, que se valora en la especificación de `REQ-CURSO`. *(Histórico: «mientras no se haga B, rige A»; ya no aplica, B está especificada.)* **Ejecutada el 2026-10-16** en `REQ-CURSO/funcional.md §5.6` y §5.6.1, con las decisiones del usuario sobre las tres preguntas que dejó abiertas: `OPEN-CURSO-25` = **B** (el riesgo residual se cierra con la lectura del curso con bloqueo compartido; cambia `RN-ACAD-54` y `-57`), `OPEN-CURSO-26` = **A** (con `acad` no contratado no se valida) y `OPEN-CURSO-27` = **A** (mismo filtro en los registros de cierre y reapertura, implementado en `1.11d-a`). La entrada de `acad` queda fijada en `RN-ACAD-62` | `RN-ACAD-54`, `-57`, `-62`, §6.1, §14, §17.1, `CA-ACAD-102`, `REQ-CURSO` |
| `OPEN-ACAD-25` | **Tramos de la escala mixta**: ¿deben cubrir todo el rango sin huecos y con el umbral en una frontera? La forma aprobada dice «numérica con tramos etiquetados» y nada más | **A** · Sí a las dos (`RN-ACAD-85`, `-86`). **B** · Solo sin solape; huecos permitidos (una nota en un hueco no tendría etiqueta). **C** · Sin reglas entre tramos | **A**: toda nota tiene una etiqueta y una sola, y ningún tramo mezcla aprobado y suspenso, que es lo que `CALIF` necesitará para boletines y para contar suspensos | `RN-ACAD-85`, `-86`, `CA-ACAD-078`, `-083` |
| `OPEN-ACAD-26` | **Representación de los decimales en la API** (primer dato no monetario con decimales del producto; `ADR-038 §3.2` solo regula importes) | **A** · Cadena en la respuesta con `decimals` cifras; entrada cadena o número. **B** · Número JSON en ambos sentidos. **C** · Enteros escalados (centésimas), como los céntimos | **A**: exacta y legible (`"5.50"`), sin el riesgo de coma flotante de B ni la conversión de C en cada cliente. Es convención que heredará `CALIF`; si el usuario quiere fijarla para todo el producto, es un ADR (lo decide él) | `RN-ACAD-75`, `api.md §11` |
| `OPEN-ACAD-27` | **Escritura de los valores de una escala** | **A** · Conjunto completo dentro de la escala (`values` en `POST`/`PATCH`, `RN-ACAD-80`, `-88`). **B** · Sub-recurso `/grading-scales/{id}/values` con su CRUD | **A**: las reglas entre valores (al menos un aprobado, cobertura de tramos) solo se pueden validar sobre el conjunto; con B, cada escritura individual pasa por estados intermedios inválidos. Precedente: `PUT /groups/{id}/subjects`. Coste: la sincronización por `public_id` de `RN-ACAD-88` | `RN-ACAD-80`, `-88`, `api.md` |
| `OPEN-ACAD-28` | **Siembra de `configuracion_academica`** en los roles predefinidos y comando para los centros existentes | Roles: **X** · Como `estructura_academica` (`OPEN-ACAD-09` = X): `administrador_centro` los cuatro; `direccion`, `secretaria`, `docente`, `tutor_grupo`, `orientador`: `leer`. **Y** · Además `direccion` los cuatro (en muchos centros la jefatura de estudios fija el calendario de evaluación). Comando: **1** · Ampliar `acad:grant-structure-permissions` para que siembre los ocho códigos de `acad` (mismo comando, mismo «todo o nada» con `retired_at`). **2** · Comando nuevo `acad:grant-config-permissions` | **X + 1**: X por coherencia con la decisión de `1.11` (el centro puede dar más con un rol propio; lo que afecta al centro entero, al administrador, `OPEN-CURSO-14`); 1 porque un comando por módulo es más simple de operar y el nombre ya se usa en `SYSADMIN.md` (se documenta que cubre ambos recursos). El docente necesita leer escalas y períodos para calificar en `1.16` | `permisos.md §4.1`, `operacion.md §11.2`, `CA-ACAD-097` |
| `OPEN-ACAD-29` | **¿Dónde viven los períodos en la interfaz?** Son por etapa; la API de períodos solo exige `configuracion_academica.leer`, pero ponerles nombre de etapa exige leer etapas (`estructura_academica.leer`) | **A** · Sección «Períodos lectivos» en la ficha de etapa (`acad-level-detail`). **B** · Pantalla propia `/academico/periodos` con filtro por etapa, que necesita también listar etapas | **A**: el contexto natural (todos los períodos de una etapa, para ver solapes y tipo), sin ruta ni entrada de menú nuevas; quien solo tenga `configuracion_academica.leer` no ve períodos en la interfaz (la API sí le responde). Con B habría que resolver igualmente los nombres de etapa | §10.5.1, `CA-ACAD-098` |
| `OPEN-ACAD-30` | **¿Hay un número fijo de períodos por tipo** (3 trimestres, 2 semestres…) **o deben cubrir todo el curso?** Ningún requisito lo dice | **A** · No: el centro define los que quiera dentro de las reglas aprobadas. **B** · Exigir el número canónico por tipo. **C** · Exigir además cobertura del curso | **A**: B y C serían inventar reglas; además, el calendario lectivo real no cubre el curso académico completo (verano) | §6.1 |
| `OPEN-ACAD-31` | **Partición de la implementación de `1.11d`** | **1** · Una entrega. **2** · Dos: **`1.11d-a`** períodos (1 tabla, 5 *endpoints*, sección de la ficha de etapa) **más** lo común (recurso `configuracion_academica`, siembra, comando, ampliación de `RN-ACAD-20`); **`1.11d-b`** escalas (2 tablas, 5 *endpoints* con sincronización de valores, decimales, 4 pantallas) | **2**: coste relativo estimado ~40 % / ~60 %; la parte de escalas concentra el riesgo (decimales, sincronización por `public_id`, `CHECK` entre filas) y se revisa mejor sola. Cada entrega con su revisión de `db-reviewer`/`security-reviewer`/`doc-reviewer` y PR propio, como `1.11-a`/`-b` | Plan, §17 |

---

## 16. Estado de implementación y desviaciones aprobadas (`1.11-b`, 2026-10-12)

**Implementado**: módulo `acad` (`apps/api/app/Modules/Acad/`), seis migraciones, 27 *endpoints* y OpenAPI (`apps/api/openapi/paths/acad.yaml`), cinco pantallas, selector de curso académico y campo traducible en la SPA, `AR-16`, `acad:grant-structure-permissions`, E2E `CA-ACAD-053` contra la API real y la medición de `CA-ACAD-023` (cifra en `CHANGELOG.md`). Cubiertos por test los criterios `CA-ACAD-001` a `-062`; los de interfaz (`-016`, `-044` a `-053`) en Vitest y Playwright, `-023` y `-051` a `-053` con la verificación de cierre. Lo diferido (`1.11d`, períodos y escalas) sigue sin tabla ni *endpoint*.

**Desviaciones respecto a la especificación aprobada, todas decididas por el usuario durante la implementación y la revisión** (no cambian ningún criterio de aceptación; precisan casos que la especificación no fijaba o corrigen una consecuencia no prevista):

| Tema | Qué dice la especificación | Qué hace la implementación | Motivo |
|------|----------------------------|----------------------------|--------|
| `RPERM-013` (`permisos.md §5`) | «Sin cambios» | **Acotada** en `Core` (`PermissionResolver::canGrant()`; fuente `REQ-PERM/funcional.md §8`): al **asignar** un rol existente o **clonar** un rol, se ignoran los permisos de cualquier módulo no contratado (no retirado) que el rol ya contiene (inertes también para el destinatario), salvo `deny` explícito del asignador, código desconocido o retirado. **Introducir un permiso inerte nuevo** en un rol (lista explícita, `PUT` de concesiones) exige poseerlo como siempre | `acad` es el primer módulo **no esencial** con concesiones sembradas: sin esto, un centro sin `acad` no podía asignar ni clonar `administrador_centro`, `direccion`, `secretaria`, `docente`, `tutor_grupo` ni `orientador`. El límite evita una escalada latente al contratar el módulo (S-1) |
| Padre del cuerpo en curso cerrado ilegible (`CA-ACAD-007`, `api.md §6`) | `422 parent_not_found` para padre ausente, inexistente, borrado o de otro centro | Un padre del cuerpo (`education_level`, `course`) de un curso `cerrado`/`archivado` que el usuario no puede leer (sin `curso_historico.leer`) responde **también** `422 parent_not_found`, indistinguible de uno inexistente. `academic_year` del cuerpo de una etapa: `422 academic_year_not_found` en ese caso (S-3). El `404` queda para recursos de la **ruta** | No revelar la existencia ni seguir la letra de la especificación |
| Texto simple (`api.md §6`) | Sin código para caracteres no guardables | `code` y `name` rechazan NUL, controles C0 (salvo tabulación y saltos de línea), DEL y UTF-8 inválido antes de recortar, y `code` además los saltos de línea: **`422 acad.validation.invalid_characters`** (código nuevo; `translations.invalid_characters` es por idioma). Parámetros de consulta y `public_id` con esos caracteres: `422` o inexistentes, nunca `500` | PostgreSQL no guarda un NUL (S-2) |
| Índices de `group_subjects` (`datos.md §3`) | Los únicos parciales «cubren» las FK de tres columnas | No las cubren (un parcial no sirve a la comprobación de integridad). `group_subjects` lleva además los índices no parciales `(tenant_id, course_id, group_id)` y `(tenant_id, course_id, subject_id)`; el comentario de `courses_tenant_level_idx` y `datos.md §3` corregidos | `db-reviewer` BAJO-1/BAJO-2, `#407` |
| Cadena de *middleware* (`api.md` cabecera) | autenticación → `module-enabled` → `permission:` | La autenticación es `App\Http\Middleware\RequireAuthenticatedUser`, **no** el alias `auth` | `auth` se ordena antes de `resolve-tenant` y daba `500` con una cookie real (hallazgo del E2E; los tests con `actingAs()` no lo veían) |
| Claves desconocidas en el cuerpo (`CA-ACAD-060`) | Solo «no crea ninguna relación» | Se ignoran (nunca se leen), como `name` en las entidades traducibles | Decisión del usuario |
| Orden de listados | «Desempate por `id`» | `position` → `code` → `id` ascendente (también en `-position`, `code` y `-code`) | `RN-ACAD-04` |
| `AR-07a` | Lista cerrada de excepciones | **35** (sin cambios: las 27 rutas llevan `permission:`) | — |

Bug de otro módulo destapado y corregido en el paso: **#420**, `ModuleSubscriptionsService::missingDependenciesOf` contaba la dependencia esencial `acad → curso` como incumplida en todos los centros.

---

## 17. Estado de implementación de `1.11d` (períodos lectivos y escalas)

**Estado**: especificación **APROBADA** (2026-10-16): el usuario aceptó las recomendaciones de `OPEN-ACAD-24` a `-31` (§15.2). **`1.11d-a` implementada** (rama `feature/REQ-ACAD-1-11d-a-periodos`, 2026-10-17; PR pendiente de mezcla): permisos `configuracion_academica.*` y su siembra, tabla `academic_periods`, servicio y 5 *endpoints* de períodos con OpenAPI, ampliación del borrado de etapa (`RN-ACAD-61`), validación del cambio de fechas registrada en `curso`, y en `curso` el punto de extensión de fechas, `AcademicYearSharedLock` y el filtro por módulo contratado; web: sección «Períodos lectivos» de la ficha de etapa. Cubiertos por test `CA-ACAD-063` a `-074`, `-088` a `-090`, `-092` a `-097`, `-102`, `-103` y, en lo que toca a períodos, `-098` a `-100`; `CA-CURSO-108` a `-120` y `-122`; `CA-CURSO-121` solo en su mitad de cierre (la de reapertura es de `1.11c`). `CA-ACAD-101` (E2E de períodos contra la API real, `apps/web/e2e/acad-periods.spec.ts`) está escrito y enlazado en `e2e-real-api.sh`, **pendiente de ejecutar**. **`1.11d-b` implementada** (rama `feature/REQ-ACAD-1-11d-b-escalas`, 2026-10-17; sin PR; `traductor` ejecutado y aplicado el 2026-10-17 y revisiones `db-`/`security-`/`doc-reviewer` hechas; pendientes la ejecución del E2E `CA-ACAD-101` de escalas y la suite completa antes del PR): tablas `grading_scales` y `grading_scale_values` con sus modelos auditables, 5 *endpoints* con OpenAPI, y cuatro pantallas (lista, alta, edición y ficha). Cubiertos por test `CA-ACAD-075` a `-087` (API y esquema; `-084` también con dos procesos reales), `-088`, `-089` y `-091` a `-097` en lo que toca a escalas, `-098` a `-100` (Vitest) y `-104` a `-106`. `CA-ACAD-101` (E2E de escalas, `apps/web/e2e/acad-scales.spec.ts`, enlazado en `e2e-real-api.sh`) está escrito y **pendiente de ejecutar**. Decisiones que la especificación no fijaba: §17.4. Las Bajas de las revisiones están en el issue #434.

### 17.1 Qué se entregará (con la partición recomendada de `OPEN-ACAD-31`)

| Entrega | Contenido | Criterios |
|---------|-----------|-----------|
| `1.11d-a` | **En `acad`**: permisos `configuracion_academica.*` en `AcadServiceProvider::declaredPermissions()`, siembra (`ProvisionTenantDefaults` y comando, `OPEN-ACAD-28`), traducciones de recurso; tabla `academic_periods`, modelo `AcademicPeriod`, 5 *endpoints*, OpenAPI; ampliación de `RN-ACAD-20` en `EducationLevelService::delete`; sección de períodos en la ficha de etapa; servicio de períodos con la lectura con bloqueo compartido del curso antes del `FOR UPDATE` de la etapa (`RN-ACAD-54`, `-57`); validación del cambio de fechas registrada en `curso` y su clave `acad.conflict.periods_outside_academic_year` en los cuatro idiomas (`RN-ACAD-62`). **En `curso`** (`REQ-CURSO/funcional.md §5.6`, §5.6.1; especificación de ese módulo, no de esta carpeta): (1) **punto de extensión del cambio de fechas**: `AcademicYearDateChangeCheck`, `AcademicYearDateChange`, `AcademicYearDateChangeFailure`, `AcademicYearDateChangeRegistry` y su implementación en memoria; el `PATCH /academic-years/{id}` pasa a transacción con `FOR UPDATE`, ejecuta las validaciones y responde `409 curso.conflict.date_change_checks_failed` (clave en `curso.php` en los cuatro idiomas, OpenAPI) y la pantalla de edición del curso muestra el error (`RN-CURSO-49` a `-54`); (2) **lectura con bloqueo compartido**: `AcademicYearSharedLock` y su implementación (`RN-CURSO-55` a `-59`); (3) **filtro por módulo contratado** con `ModuleAvailability` en el `PATCH` y en el cierre, y `moduleCode()` en `AcademicYearClosureCheck` (`RN-CURSO-52`, `-60`; el registro de reapertura no existe aún en el código) | `CA-ACAD-063` a `-074`, `-088` a `-090`, `-092` a `-099` en lo que toca a períodos, `CA-ACAD-102` y `-103`; de `REQ-CURSO`, `CA-CURSO-108` a `-122` (de `-121`, solo la mitad de cierre) |
| `1.11d-b` | Tablas `grading_scales` y `grading_scale_values`, modelos `GradingScale`/`GradingScaleValue`, 5 *endpoints*, OpenAPI, cuatro pantallas | `CA-ACAD-075` a `-087`, `-088`, `-089`, `-091`, `-092` a `-101` en lo que toca a escalas, y `-104` a `-106` |

**Coste relativo del trabajo en `curso` dentro de `1.11d-a`** (estimación de especificación, no medida): del orden de un **tercio** de la entrega. Por piezas: el punto de extensión es la mayor (cuatro clases de dominio pequeñas, pero el `PATCH` cambia de forma —transacción, bloqueo, orden de validaciones—, más error, traducciones, OpenAPI e interfaz; `CA-CURSO-108` a `-112`, `-116`); la lectura con bloqueo compartido es pequeña en código (una interfaz y una consulta) y cara en pruebas (dos criterios con dos procesos reales, `CA-CURSO-119`, `-120`, y uno con tres, `CA-CURSO-122`); el filtro es trivial (`CA-CURSO-112`, `-121`). Con él, el reparto ~40 % / ~60 % que estimó `OPEN-ACAD-31` (que no lo incluía) pasa a algo más cercano a **~50 % / ~50 %**; la partición en dos entregas sigue siendo la decidida. Si la sesión orquestadora ve que `1.11d-a` no cabe, puede separar el trabajo de `curso` en una entrega previa dentro del mismo paso, pero es decisión suya y del usuario, no de un subagente (`CLAUDE.md §3`).

### 17.2 Reglas de arquitectura (`AR-*`) que aplican y si necesitan ampliación

| Regla | Qué exige aquí | ¿Ampliación? |
|-------|----------------|--------------|
| `AR-01`/`AR-02` | `acad` solo usa `Curso\Domain` (`AcademicYearDirectory`, `AcademicYearReadAccess`, `AcademicYearContext` y, desde `1.11d-a`, `AcademicYearSharedLock` y las piezas del cambio de fechas), nunca su modelo ni `academic_years`; `curso` no importa nada de `acad` | No |
| `AR-03` | Mismo `AcadServiceProvider`; migraciones nuevas en `Modules/Acad/Database/migrations` | No |
| `AR-04`/`AR-05` | `text`, `timestamptz`, `date` en fechas civiles, `numeric(5,2)` y `smallint` permitidos, sin `ENUM`; `public_id` ULID único en las tres tablas | No (comprobar en implementación que `AR-04` no rechaza `numeric`; si lo hiciera, la ampliación se pide antes de escribirla) |
| `AR-06` | Tres modelos `TenantModel` + `Auditable`, alias `academic_period`, `grading_scale`, `grading_scale_value` en el *morph map* de `AcadServiceProvider::boot()` | No |
| `AR-07a`/`AR-07b` | Diez rutas con `RequireAuthenticatedUser` → `module-enabled:acad` → `permission:configuracion_academica.*`; la lista de excepciones de `AR-07a` sigue en **35** | No |
| `AR-09` | Ningún permiso de categoría especial | No |
| `AR-10` | Ámbito `todos`: no entra en el mapa de `AR-10` (**ampliado en `1.12-b`**: `reservas` sí entra, con `propios` en `eliminar`) | No |
| `AR-12` | Paridad de claves de `acad.php` en los cuatro idiomas | No |
| `AR-13` | Tres tablas más con disparador y FK `NO ACTION`; lista de excepciones vacía | No |
| `AR-15` | Altas, cambios y borrados de valores **por instancia** (la sincronización de `RN-ACAD-88` y el borrado en cascada lógica de `RN-ACAD-76` incluidos); nunca `GradingScaleValue::query()->…->delete()` | No |
| `AR-16` | `name_translations` (períodos, escalas) y `label_translations` (valores) creadas con `translatableColumn()` | No |
| `AR-11` (web) | Rutas nuevas en el mismo `shell.ts`; ningún import fuera de `api/`, `types/`, `shell` de otros módulos | No |

**Ninguna lista cerrada de excepciones se amplía.** Interfaces públicas nuevas en `Acad\Domain`: **ninguna en `1.11d`** (§7.2, `ADR-048`). Eventos: ninguno.

### 17.3 Diferencias con la forma aprobada de `1.11` (`datos.md §1.7`-`§1.9` original)

| Tema | Forma aprobada en `1.11` | Esta especificación | Motivo |
|------|--------------------------|---------------------|--------|
| Valores de la escala mixta | `is_passing boolean` en todos los valores | `is_passing` solo en la cualitativa; en la mixta se deriva del umbral (`RN-ACAD-87`) | Una sola fuente de verdad del aprobado |
| Coherencia tipo de escala ↔ valores | «Validación de servidor» | Además, en el motor: `scale_type` copiado en `grading_scale_values` con FK de cuatro columnas y `CHECK` por fila (`datos.md §1.9`) | Lo que el motor puede garantizar sin `btree_gist`, lo garantiza |
| `position` de los valores | Columna sin regla | La fija el servidor por el orden de la lista (`RN-ACAD-83`) | Reordenar sin estados intermedios |
| Encargo de la sesión principal | «inicio ≤ fin» y valorar `EXCLUDE` con `btree_gist` | `ends_on > starts_on` y sin `EXCLUDE`, como dice la forma aprobada (`datos.md §1.7`, `ADR-034 §4`) | La forma aprobada manda; ver `datos.md §8.3` |

### 17.4 Decisiones de implementación de `1.11d-b` que la especificación no fijaba

Ninguna contradice una regla aprobada; son huecos que cerró la implementación (`GradingScaleValidator`, `GradingScaleService`, vistas de `acad`). Si alguna debe ser otra, se pide una enmienda de esta especificación.

| Tema | Decisión implementada |
|------|----------------------|
| Campos inaplicables con `null` o `[]` | Un campo que no aplica al tipo (`min_value`, `max_value`, `decimals`, `passing_min_value` en una cualitativa; `range_min`/`range_max` en un valor de cualitativa; `is_passing` en un tramo de mixta; `values` en una numérica) enviado como `null` se trata como **ausente**, no como error; `values: []` en una numérica, también. Con un valor distinto de `null` (o una lista no vacía) ⇒ `422 not_applicable_to_scale_type` (`RN-ACAD-81`) |
| `values` no lista o vacía | En una cualitativa o mixta, `values` presente pero que no es una lista indexada o es `[]` ⇒ `422 values_required` en `values` (también en el `PATCH`; para conservar los valores guardados basta **no enviar** `values`). Más de 100 ⇒ `values_too_many` (`RN-ACAD-89`) |
| Intercambio de `code` entre valores supervivientes en un `PATCH` | Si un `PATCH` deja dos valores guardados con los códigos intercambiados (A↔B), la lista resultante no tiene duplicados y pasa el validador, pero la actualización por instancia choca con la unicidad `(tenant, escala, code)` de la base y responde **`422 acad.validation.code_taken` en el campo `code` de la escala** (no en `values.<n>.code`), vía la traducción del `23505` de `WritesStructure::inTransaction`. Un código repetido **dentro de la lista enviada** sí da `code_taken` en `values.<n>.code`. Reutilizar el código de un valor que el mismo `PATCH` borra sí se admite (orden: borrados, actualizaciones, altas). Cubierto por el test `RN-ACAD-88 INV-010` de `AcadGradingScalesApiTest` |
| Bloqueo del curso al escribir escalas | Las escrituras de escalas **no** toman la fila del curso (ni `FOR SHARE`): ninguna regla de escala depende de sus fechas. Solo `FOR UPDATE` de la fila de la escala en el `PATCH` y el `DELETE` (`datos.md §8.3`); el curso de solo lectura lo rechaza el disparador (`409`). No añade ninguna inversión de orden de bloqueos |
| Un `PATCH` sin cambios efectivos | Compara las traducciones por contenido (`sameTranslations`, claves ordenadas) y los decimales en la forma almacenada (`"5.00"`): no deja entrada de auditoría vacía ni `updated_at` falso. Los valores sin cambios no escriben ni auditan |
| Columna «Aprobado» del listado | Numérica y mixta: «Desde {umbral}» (`scales.passingFrom`); cualitativa: los **códigos** de los valores aprobados separados por comas. La columna «Rango» (`fields.range`): «De {min} a {max}» o el número de valores en la cualitativa (`scales.valuesCount`) |
| Pantalla de edición | Página propia (ya en §10.5.1); `scale_type` deshabilitado en edición con el texto `scales.typeLocked` |
| Campo de cada error | Reparto de `errors.<campo>` de escalas fijado por el validador; contrato en `api.md §11.7` |
| Interfaz pública | `GradingScaleDirectory` sigue sin construirse (§7.2) |

## Anexo A · Resumen del contrato de `ADR-062` aplicado a `acad`

> Resumen operativo; la fuente es `ADR-062`. Si discrepan, manda el ADR.

1. **Columna**: `name_translations jsonb NOT NULL` en `education_levels`, `courses` y `subjects` (y en `1.11d`: `name_translations` en `academic_periods` y `grading_scales`, `label_translations` en `grading_scale_values`), creada por el ayudante de `TenantMigration`. `CHECK` en el motor: objeto, no vacío, claves ⊆ {`es-ES`, `en`, `de`, `fr`}, valores cadena. Sin columna `name` en la tabla.
2. **Validación** (servidor, `App\Support\I18n`): alta con la clave del `default_locale` del centro; claves en `active_locales`; valores recortados no vacíos. `PATCH` por fusión; quitar el idioma por defecto ⇒ `422`. Códigos `translations.default_locale_required`, `translations.locale_not_active`, `translations.empty_value`.
3. **API**: lectura con `name` (resuelto, `readOnly`) y `name_translations` (completo), siempre los dos; escritura solo de `name_translations`; `name` en el cuerpo se ignora.
4. **Resolución**: idioma pedido → `default_locale` → `es-ES` → primera clave en el orden `es-ES`, `en`, `de`, `fr`. En documentos, el idioma del destinatario. Ningún módulo resuelve por su cuenta.
5. **Auditoría**: desglose por idioma en `changes` (`name_translations.es-ES`, …), solo los idiomas que cambian; tope y redacción por entrada.
6. **No traducibles en `acad`**: `code` (todas las entidades) y `name` de grupo y subgrupo (texto simple).
7. **Orden y búsqueda**: ningún listado de `1.11` ordena por nombre ni tiene `q`; si se añadiera, `ADR-062 §5.4` puntos 3-4.
8. **SPA**: componente común de edición; presentación con `lang` cuando hay caída (WCAG 3.1.2).

---

## 18. `1.12` · Horarios y asignación docente (`REQ-ACAD-002`) — **APROBADA (2026-10-17); `1.12-a` IMPLEMENTADA (2026-10-18, §19); `1.12-b` IMPLEMENTADA (2026-10-19, §20)**

> **Estado**: APROBADA el 2026-10-17 por el usuario, con las recomendaciones de todas las `OPEN-ACAD-32` a `-44` (incluida la ampliación del mapa cerrado de `AR-10` y la escritura de `direccion` en `OPEN-ACAD-42`). Las decisiones abiertas son `OPEN-ACAD-32` a `-44` (§18.11); donde una regla depende de una de ellas, el texto aplica **la recomendación** y la cita, y cambia si el usuario decide otra cosa. Reglas `RN-ACAD-90` a `-143`, criterios `CA-ACAD-107` a `-140`. El resto de esta especificación (§0-§17, aprobado) no cambia salvo las ampliaciones de `RN-ACAD-20`/`-32` que fija `RN-ACAD-96`.

**Requisito** (`§5.4`, `REQ-ACAD-002`, literal): generador de horarios con detección de conflictos (aulas, profesores, grupos); vista semanal por profesor, aula, grupo y asignatura; exportación de horarios a PDF e importación desde formatos estándar; gestión de horarios especiales (jornada intensiva, festivos, exámenes); reserva de aulas y recursos.

**Obligación de `ADR-063 §5.4`**: esta especificación **confirma expresamente** a `1.12` como el paso donde nace la asignación docente (entidad propia de `acad`, por curso académico, no derivada del horario; las sesiones la referencian) y especifica la interfaz de pertenencia de `§5.2` punto 1 (§18.7). Con su aprobación queda cumplido el plazo que bloquea las especificaciones de `1.13` y `1.16`.

### 18.0 Hallazgos antes de especificar

Ninguno es una contradicción que obligue a detenerse; todos son huecos o dependencias, cada uno con su pregunta.

| # | Hallazgo | Fuentes | Pregunta |
|---|----------|---------|----------|
| H1 | **«Generador» es ambiguo**: puede ser un resolutor automático (motor de restricciones) o un editor con detección de conflictos. El plan dice «rejilla a medida con CSS Grid, detección de conflictos», que describe lo segundo | `REQ-ACAD-002`, `PLAN 1.12` | `OPEN-ACAD-32` |
| H2 | **Aulas y recursos sin entidad** en ningún requisito ni en `§16.1`; tampoco se dice si dependen del curso académico | `REQ-ACAD-002`, `§16.1`, `ADR-034 §4` | `OPEN-ACAD-33` |
| H3 | **¿Quién es «profesor»?** `§16.1` fija `Employee` (personal, vinculado a `Person`) como entidad núcleo, pero no existe (`REQ-RRHH`, fase 2). `ADR-063 §5.2` y el contrato `ScopeResolver` hablan de **usuario** (`constrain(Builder, User)`) | `§16.1`, `ADR-063 §5.2` | `OPEN-ACAD-34` |
| H4 | **Exportación a PDF depende de una pieza no implementada**: el servicio de PDF no tiene motor decidido (`PLAN 0.3`: «motor sin decidir, ver 1.17»). Además `INV-012` exige cola para PDF en lote, y hoy no hay *worker* (`CLAUDE.md §1`, issue #128) | `REQ-ACAD-002`, `PLAN 0.3`, `INV-012` | `OPEN-ACAD-38` |
| H5 | **«Formatos estándar» de importación sin nombrar**: ningún requisito dice cuáles | `REQ-ACAD-002` | `OPEN-ACAD-39` |
| H6 | **«Horarios especiales» mezcla tres cosas de dueños distintos**: festivos (calendario escolar, `1.20 · REQ-AGENDA` en el plan), exámenes (calendario de exámenes, `REQ-ACAD-005`) y jornada intensiva (horario propiamente dicho) | `REQ-ACAD-002`, `REQ-ACAD-005`, `PLAN 1.20` | `OPEN-ACAD-37` |
| H7 | **Hora civil sin fecha** (una franja «lunes 08:30-09:25» se repite cada semana) frente a la convención «`TIMESTAMPTZ` siempre» de `ADR-029`, que no prevé horas sin fecha | `ADR-029`, `ADR-038 §3.2` (precedente `date`) | `OPEN-ACAD-40` |
| H8 | **Copia al curso siguiente**: `REQ-CURSO-002` copia «plantillas de horario»; es `1.12b` (rollover), no `1.12` | `REQ-CURSO-002`, `REQ-CURSO/funcional.md §1.2` | — (hueco en §18.7) |

### 18.1 Alcance y entregas propuestas (`OPEN-ACAD-44`)

| Entrega | Contenido | Requisito |
|---------|-----------|-----------|
| **`1.12-a`** | Asignación docente (profesor × asignatura de grupo, subgrupo opcional) y tutoría de grupo; espacios (aulas y recursos); ancla de horario por curso académico; marcos horarios **ordinarios** por etapa con sus franjas; sesiones; detección de conflictos de profesor, aula y grupo; vistas semanales por profesor, aula, grupo y asignatura; ampliación de `RN-ACAD-20`/`-32`; interfaz de pertenencia **especificada** (§18.7) | `REQ-ACAD-002` puntos 1-2; `ADR-063 §5.4` |
| **`1.12-b`** | Marcos **especiales** con vigencia por fechas (jornada intensiva) y su efecto en los conflictos; reservas de aulas y recursos con detección de conflictos contra sesiones y otras reservas | Puntos 4 (jornada intensiva) y 5 |

**Fuera de `1.12`** (con su pregunta): generación automática (`OPEN-ACAD-32`); exportación PDF (`-38`); importación (`-39`); festivos y exámenes (`-37`); sustituciones, guardias, histórico con fechas de la asignación, co-docencia en la misma sesión (`-35`, `-36`); departamentos (`ADR-063 §5.5`); vistas en los portales de familias y alumnado (`1.22`, `1.23`); copia al curso siguiente (`1.12b`); eventos de dominio (sin consumidor, `ADR-048`).

**Dependencias**: `curso` (implementado), estructura de `1.11` (implementada; `1.11d-b` sin mezclar, no afecta), `Core` para resolver usuarios (§18.7). **No implementadas y fuera por ello**: servicio PDF, `REQ-AGENDA`, `REQ-ACAD-005`, `Employee`.

### 18.2 Conceptos

| Término | Tabla | Qué es |
|---------|-------|--------|
| **Asignación docente** | `teaching_assignments` | Un usuario imparte una asignatura a un grupo (un par de `group_subjects`, la «clase» de `ADR-063 §5.3`), opcionalmente a un **subgrupo** de ese grupo (desdoble). Por curso académico |
| **Tutoría de grupo** | `group_tutors` | Un usuario es tutor de un grupo. Por curso académico. No está en la rejilla |
| **Espacio** | `facilities` | Un aula (`aula`, ocupable por sesiones y reservable) o un recurso (`recurso`, solo reservable: carro de portátiles, proyector). Del centro, sin curso académico (`OPEN-ACAD-33`) |
| **Horario** (ancla) | `timetables` | Una fila por centro y curso académico. Es la entidad `Timetable` de `§5.4` y la fila que serializa toda escritura del horario (`RN-ACAD-125`) |
| **Marco horario** | `timetable_frames` | Conjunto de franjas de una etapa en un curso académico. `ordinaria` (todo el curso) o `especial` (vigencia por fechas: jornada intensiva, `1.12-b`) |
| **Franja** | `time_slots` | Día de la semana ISO (1 = lunes … 7 = domingo) + hora de inicio y fin, hora civil del centro (zona `timezone` del centro) |
| **Sesión** | `timetable_sessions` | Una asignación docente en una franja, con aula opcional. Es la celda de la rejilla |
| **Reserva** (`1.12-b`) | `facility_bookings` | Un espacio en una fecha y un intervalo horario, por quien la crea |

### 18.3 Flujos

1. **Preparar** (administración): alta de espacios (`/academico/espacios`); en la ficha de cada etapa, alta de su marco ordinario y edición de sus franjas como lista (conjunto completo, `RN-ACAD-113`); en la ficha de cada grupo, profesorado por asignatura (y subgrupo) y tutores.
2. **Componer** (`/academico/horarios`): se elige vista (grupo, profesor, aula o asignatura) y entidad; la rejilla muestra las franjas del marco y las sesiones. Al activar una celda vacía, diálogo con las asignaciones del grupo y aula opcional ⇒ `POST /timetable-sessions`. Mover = `PATCH` de franja o aula; quitar = `DELETE`. Un conflicto se rechaza con `422 timetable_conflict`, que nombra la sesión con la que choca (§18.5).
3. **Consultar**: misma pantalla en solo lectura para quien solo tiene `horarios.leer`; curso de solo lectura ⇒ aviso persistente (`RN-ACAD-42`).
4. **Jornada especial** (`1.12-b`): marco `especial` de la etapa con `valid_from`/`valid_to`; se compone igual.
5. **Reservar** (`1.12-b`): `/academico/reservas`: espacio, fecha, inicio y fin ⇒ `POST /facility-bookings`; conflicto con sesión o reserva ⇒ `422 booking_conflict`.

### 18.4 Reglas de negocio

#### 18.4.1 Comunes

| ID | Regla |
|----|-------|
| `RN-ACAD-90` | Toda tabla de `1.12` **salvo `facilities`** lleva `academic_year_id` y el disparador de `ADR-057`: `RN-ACAD-07` (escritura ⇒ `409 academic-year-closed`) y `RN-ACAD-08` (toda lectura invoca `AcademicYearReadAccess` ⇒ `404`) se aplican tal cual. Padres del cuerpo de un curso ilegible ⇒ `422 *_not_found`, como S-3 de §16 |
| `RN-ACAD-91` | El curso académico de cada fila es el de su padre (asignación y tutoría: el del grupo; franja: el del marco; sesión: el de la franja y el de la asignación, iguales por FK) y no se admite `academic_year` en el cuerpo (`422 academic_year_not_allowed`) salvo en el alta de una reserva, que no tiene padre con curso (`RN-ACAD-140`). Padres inmutables (`422 parent_immutable`, `RN-ACAD-06`) |
| `RN-ACAD-92` | **Usuario asignable** (`OPEN-ACAD-34`): un usuario del centro **activo**, resuelto por `public_id` por una interfaz de `Core\Domain`; inexistente, de otro centro o inactivo ⇒ `422 acad.validation.user_not_found`. **No se comprueba ningún rol** (`RN-PERM-46`). Asignar no concede permisos ni roles |

#### 18.4.2 Asignación docente y tutoría

| ID | Regla |
|----|-------|
| `RN-ACAD-93` | Una asignación docente es (usuario, asignatura que cursa un grupo, subgrupo opcional). El cuerpo lleva `teacher`, `group`, `subject` y opcional `subgroup` (`public_id`). La asignatura debe estar asignada **y viva** en ese grupo (`group_subjects`) ⇒ si no, `422 acad.validation.subject_not_in_group`; el subgrupo, vivo y del mismo grupo ⇒ si no, `422 acad.validation.subgroup_not_in_group`. El motor lo refuerza con FK compuestas (`datos.md §9`) |
| `RN-ACAD-94` | Única entre las vivas por (usuario, asignatura del grupo, subgrupo —`NULL` cuenta como valor—) ⇒ `422 acad.validation.assignment_exists`. Varios usuarios pueden tener la misma asignatura del mismo grupo (desdobles por subgrupo, o dos profesores de la misma clase); el choque en la rejilla lo decide `RN-ACAD-123` |
| `RN-ACAD-95` | **Inmutable**: sin `PATCH`. Cambiar de profesor es borrar y crear **[DERIVADA]**: así una sesión nunca cambia de profesor por la espalda y no hay que revalidar conflictos al editar una asignación. Borrar con sesiones vivas ⇒ `409 acad.conflict.has_dependents` con `timetable_sessions` |
| `RN-ACAD-96` | **Amplía `RN-ACAD-20` y `RN-ACAD-32`**: no se borra un grupo con tutores o asignaciones vivas, un subgrupo con asignaciones vivas, una asignatura con asignaciones vivas, ni una etapa con marcos vivos (`409 has_dependents`, `params.dependents` con `group_tutors`, `teaching_assignments` o `timetable_frames`). Un `PUT /groups/{id}/subjects` que quite una asignatura con asignaciones vivas en ese grupo ⇒ el mismo `409` y no cambia nada |
| `RN-ACAD-97` | **Tutoría**: `PUT /groups/{id}/tutors` con la lista completa de usuarios (precedente `RN-ACAD-32`: idempotente, por instancia, sin cambios no escribe). Varios tutores por grupo (cotutoría) y un usuario tutor de varios grupos (`OPEN-ACAD-35`) |
| `RN-ACAD-98` | **Proyección para ámbitos** (`ADR-063 §5.3`), que la interfaz de pertenencia devuelve (§18.7): `grupo(u, curso)` = grupos de sus tutorías vivas ∪ grupos de sus asignaciones vivas; `clase(u, curso)` = pares (grupo, asignatura) de sus asignaciones vivas. Una asignación a un subgrupo **se proyecta sobre su grupo** en los dos ámbitos **[DERIVADA]**: es la decisión que `ADR-063 §5.3` deja a esta especificación; el profesor de un desdoble califica la asignatura de alumnos del grupo |
| `RN-ACAD-99` | Sin fechas de vigencia, sin sustituciones y sin papel principal/secundario en `1.12` (`OPEN-ACAD-35`): la asignación es el estado vigente; el histórico, `audit_logs` |

#### 18.4.3 Espacios

| ID | Regla |
|----|-------|
| `RN-ACAD-100` | `facility_type` ∈ {`aula`, `recurso`}, obligatorio e **inmutable** (`422 acad.validation.facility_type_invalid`/`facility_type_immutable`): las sesiones lo arrastran en su FK (`datos.md §9`) |
| `RN-ACAD-101` | Del centro, **sin curso académico** (`OPEN-ACAD-33`): no los congela el cierre. `code` único entre los espacios vivos del centro (`RN-ACAD-01`/`-02`, `422 code_taken`); `name` texto simple obligatorio, no traducible **[DERIVADA]**: `ADR-021` enumera asignaturas y actividades, no espacios (como el nombre de grupo) |
| `RN-ACAD-102` | Borrado lógico. Bloqueado (`409 has_dependents` con `timetable_sessions` o `facility_bookings`) solo si lo referencian sesiones o reservas vivas de cursos académicos `planificacion` o `activo`; las de cursos `cerrado`/`archivado` conservan la referencia (FK `NO ACTION`, el borrado es lógico) y la representación marca el espacio como dado de baja (`facility.deleted: true`) **[DERIVADA]**: sin esto, un aula usada en un curso cerrado no podría darse de baja nunca |
| `RN-ACAD-103` | Editar `code` o `name` de un espacio se refleja también al leer horarios de cursos cerrados (no hay copia por curso). Consecuencia aceptada de `OPEN-ACAD-33` |

#### 18.4.4 Marcos y franjas

| ID | Regla |
|----|-------|
| `RN-ACAD-110` | Marco por curso académico y etapa. Alta con `education_level` en el cuerpo (el curso es el de la etapa, sin `academic_year`, `RN-ACAD-51`); `frame_type` ∈ {`ordinaria`, `especial`} obligatorio e inmutable (`422 frame_type_invalid`/`frame_type_immutable`); `name_translations` (`ADR-062`, `RN-ACAD-53`). **Como mucho un marco `ordinaria` vivo por etapa** (`422 acad.validation.frame_ordinary_exists`; índice único parcial). En `1.12-a` solo se admite `ordinaria`; `especial` ⇒ `422 frame_type_invalid` hasta `1.12-b` |
| `RN-ACAD-111` | (`1.12-b`) Un marco `especial` lleva `valid_from` y `valid_to` (`date`, `valid_to ≥ valid_from`, `422 acad.validation.frame_dates_invalid`), **dentro** del curso académico (`422 frame_outside_academic_year`, fechas leídas con `AcademicYearSharedLock` en la transacción, orden curso → ancla, como `RN-ACAD-54`), y no se solapa con otro especial vivo de la misma etapa (`422 frame_overlap`). Un `ordinaria` no lleva fechas (`CHECK`) |
| `RN-ACAD-112` | Franja: `weekday` entero 1-7 (`422 weekday_invalid`), `starts_at` y `ends_at` hora `HH:MM` (`422 time_invalid`), `ends_at > starts_at` (`422 slot_ends_before_start`). Intervalo **semiabierto** `[inicio, fin)`: 09:25-10:20 sigue a 08:30-09:25 sin solapar. Sin solape entre franjas del mismo marco y día (`422 slot_overlap`, `params.slot`) **[DERIVADA]** |
| `RN-ACAD-113` | Las franjas **solo se escriben dentro del marco** (`slots` en `POST`/`PATCH` del marco), como conjunto completo sincronizado por `public_id` con las reglas de `RN-ACAD-88` (con `public_id` ⇒ actualiza; sin él ⇒ alta; ausente ⇒ borrado lógico; ajeno o repetido ⇒ `422 acad.validation.slot_not_in_frame`). Tope 100 (`422 slots_too_many`) **[DERIVADA]**, precedente `RN-ACAD-89` |
| `RN-ACAD-114` | Una franja con sesiones vivas no se borra ni cambia `weekday`, `starts_at` o `ends_at` ⇒ `409 has_dependents` con `timetable_sessions` **[DERIVADA]**: así una sesión existente nunca entra en conflicto por un cambio de franja, y la detección solo vive en la escritura de sesiones. Un marco con sesiones vivas no se borra (mismo `409`) |
| `RN-ACAD-115` | El alta del primer marco de un curso académico crea su fila de ancla `timetables` si no existe (crear-o-leer bajo el índice único; la carrera se resuelve releyendo, nunca `500`) |

#### 18.4.5 Sesiones y conflictos

| ID | Regla |
|----|-------|
| `RN-ACAD-120` | Sesión = (franja, asignación docente, aula opcional). La etapa del grupo de la asignación debe ser la del marco de la franja ⇒ si no, `422 acad.validation.slot_not_for_group`. Franja o asignación inexistente, borrada, de otro centro o de otro curso ⇒ `422 parent_not_found` en su campo |
| `RN-ACAD-121` | Aula opcional (`facility`): viva y de tipo `aula` ⇒ si no, `422 acad.validation.facility_not_found` / `facility_not_room` |
| `RN-ACAD-122` | `PATCH` admite `time_slot` y `facility` (`null` quita el aula); `teaching_assignment` es inmutable (`422 parent_immutable`) |
| `RN-ACAD-123` | **Conflicto** (`OPEN-ACAD-36` = rechazar): un alta o `PATCH` que deje dos sesiones vivas **coincidentes** con alguno de estos choques se rechaza con `422 acad.validation.timetable_conflict` en `time_slot` y no escribe nada. `params.conflicts` = lista de `{kind, session}` con `kind` ∈ {`teacher`, `facility`, `group`} y `session` el `public_id` de la otra sesión. Choques: **profesor**: mismo usuario de asignación; **aula**: misma aula no nula; **grupo**: mismo grupo, **salvo** que las dos sesiones sean de subgrupos distintos de ese grupo (desdoble). Dos sesiones del grupo entero a la vez (co-docencia) son conflicto (`OPEN-ACAD-36`) |
| `RN-ACAD-124` | **Coincidentes** = mismo `weekday`, intervalos `[inicio, fin)` que se cortan, y marcos que **coexisten**: dos `ordinaria` coexisten siempre, también de etapas distintas (un profesor de ESO y Bachillerato choca aunque las franjas sean distintas: se compara por hora, no por franja). En `1.12-b`: un `especial` no coexiste con el `ordinaria` de su misma etapa (lo sustituye); coexiste con el `ordinaria` de otra etapa siempre y con un `especial` de otra etapa si sus fechas se cortan |
| `RN-ACAD-125` | **Serialización** **[DERIVADA]**: toda escritura de sesiones (y, en `1.12-b`, de reservas y del alta o cambio de fechas de un marco especial) toma `FOR UPDATE` sobre la fila de `timetables` de su curso académico **antes** de leer las sesiones con que compara. El motor no garantiza los conflictos (sin `btree_gist`, como `datos.md §8.3`); un solo servicio escribe sesiones. Nunca se toma `FOR UPDATE` sobre la fila del curso (condición de `datos.md §8.3`) |
| `RN-ACAD-126` | No se exige que una asignación tenga sesiones, ni que un grupo complete su rejilla, ni número de horas por asignatura (ningún requisito lo dice) |
| `RN-ACAD-127` | **Vistas semanales**: `GET /timetable-sessions` exige uno de los filtros `teacher`, `facility`, `group`, `subject` (`422 acad.validation.view_filter_required`). La vista de grupo incluye las sesiones de sus subgrupos; la de asignatura, las de esa asignatura en todos los grupos. Orden: `weekday`, `starts_at`, `id`. Sin paginar, con tope de 500 filas (`422 acad.validation.view_too_large`) **[DERIVADA]**: una vista semanal tiene decenas de sesiones; el tope evita respuestas desmesuradas (precedente `RN-ACAD-89`) |
| `RN-ACAD-128` | La sesión de un aula dada de baja (de un curso cerrado, `RN-ACAD-102`) se sigue leyendo; no se puede **crear** ni **mover** una sesión a un aula borrada |

#### 18.4.6 Reservas (`1.12-b`)

| ID | Regla |
|----|-------|
| `RN-ACAD-140` | Reserva = (espacio de cualquier tipo, `booked_on` fecha, `starts_at`, `ends_at` hora civil, `[inicio, fin)`), sin repetición y sin aprobación (`OPEN-ACAD-41`). Su curso académico es el que contiene la fecha, que llega como `academic_year` en el cuerpo; la fecha debe caer dentro de él (`422 booking_outside_academic_year`, fechas leídas con `AcademicYearSharedLock`). Reservante = quien la crea (`created_by`) |
| `RN-ACAD-141` | **Conflicto** ⇒ `422 acad.validation.booking_conflict` con `params.conflicts` (`{kind: "booking"|"session", ...}`): otra reserva viva del mismo espacio, misma fecha, intervalos que se cortan; y, si es un aula, una sesión viva en ella con el `weekday` de la fecha, intervalo que se corta y marco **vigente ese día** (el `especial` de la etapa si la fecha cae en su vigencia, si no el `ordinaria`). Bajo el bloqueo de `RN-ACAD-125` |
| `RN-ACAD-142` | Las reservas no se editan (borrar y crear) **[DERIVADA]**. Cancelar: `reservas.eliminar`; con ámbito `propios`, solo las creadas por uno mismo (`OPEN-ACAD-42`) |
| `RN-ACAD-143` | Una sesión creada o movida después de una reserva **no** se valida contra reservas (la rejilla es la planificación base; la reserva es puntual) **[DERIVADA]**; la vista de un aula en una semana concreta queda para cuando exista calendario (`OPEN-ACAD-37`) |
| `RN-ACAD-144` | **Cambio de fechas del curso académico** (`1.12-b`, análoga a `RN-ACAD-62`): `curso` rechaza un `PATCH` de fechas del curso (en `planificacion`) que dejaría fuera de ellas algún **marco `especial` vivo** (`valid_from < nuevo inicio` o `valid_to > nuevo fin`) o alguna **reserva viva** (`booked_on` fuera), bordes incluidos, como `RN-ACAD-111` y `-140`. Lo hace la misma validación de `acad` registrada para `RN-ACAD-62` (`AcademicYearDateChangeCheck`, una consulta agregada de solo lectura, sin bloqueo de fila, bajo el `FOR UPDATE` del curso que retiene `curso`, `RN-CURSO-51`): primero comprueba los períodos (`RN-ACAD-62`) y, si ninguno queda fuera, los marcos especiales y las reservas. Fallo: `code` `acad.conflict.schedule_outside_academic_year`; `params` = `{special_frames, bookings, schedule_starts_on, schedule_ends_on}` (cuántos marcos y reservas quedarían fuera y el menor inicio y mayor fin de **todos** los marcos especiales y reservas vivos del curso; sin `public_id`, nombre ni espacio), porque quien cambia las fechas puede no tener permisos de `acad`. Sale en `errors.date_change[]` del `409 curso.conflict.date_change_checks_failed`. No toma `FOR UPDATE` del curso ni de fila alguna: el orden curso → ancla de `RN-ACAD-125` no cambia, y una escritura de marco especial o reserva (que retiene `FOR SHARE` del curso) y el `PATCH` se excluyen mutuamente |

### 18.5 Ejemplos de conflicto

| Caso | Resultado |
|------|-----------|
| Profesora P con 2º ESO A el lunes 08:30-09:25 y se le asigna 3º ESO B el lunes 09:00-09:55 (otra etapa u otro marco) | `422 timetable_conflict`, `kind: teacher` |
| Lunes 08:30-09:25 y 09:25-10:20, mismo profesor | `201` (semiabierto) |
| Desdoble: subgrupo D1 en Laboratorio y D2 en Aula 1, mismo grupo y franja | `201` |
| Sesión del grupo entero y de su subgrupo D1 a la vez | `422`, `kind: group` |
| Dos sesiones en Aula 1 a la misma hora con grupos y profesores distintos | `422`, `kind: facility` |
| Sesión sin aula a la misma hora que otra sin aula | Sin choque de aula |

### 18.6 Casos límite

| Caso | Comportamiento |
|------|----------------|
| Dos altas simultáneas que chocan (dos procesos reales) | Una `201`, otra `422 timetable_conflict`, nunca dos `201` (`CA-ACAD-126`) |
| Primer marco de un curso creado a la vez por dos peticiones | Una fila de ancla; ambos marcos válidos si son de etapas distintas (`RN-ACAD-115`) |
| Borrar la asignación de un profesor con sesiones | `409 has_dependents`; se borran antes sus sesiones |
| Quitar del grupo una asignatura con profesorado asignado | `409 has_dependents` (`RN-ACAD-96`) |
| Usuario desactivado con asignaciones | Se conservan (histórico del curso); no se puede crear una nueva para él. Sin reacción a `UserDeactivated` en `1.12` **[DERIVADA]** |
| Curso `cerrado` con horario | Todo de solo lectura (`409`); lectura con `curso_historico.leer` |
| Espacio editado tras cerrar un curso | El horario cerrado muestra el nombre nuevo (`RN-ACAD-103`) |

### 18.7 Interacción con otros módulos e interfaz de pertenencia

**Consume**: `Curso\Domain` como en §7.1 (y `AcademicYearSharedLock` en `1.12-b`); de `Core\Domain`, la resolución de un usuario por `public_id` a (id interno, activo, nombre visible). **Comprobar en implementación** qué interfaz de `Core\Domain` existe para ello; si no existe, ampliarla es un cambio en `Core` que debe aprobarse antes de escribirlo (no se consulta `users` desde `acad`, `AR-01`). La FK en base de datos a `users` sí está permitida.

**Interfaz de pertenencia** (`ADR-063 §5.2` punto 1), en `Acad\Domain` (nombre propuesto `TeachingMembership`):

| Operación | Devuelve |
|-----------|----------|
| `groupIdsFor(int $userId, int $academicYearId): list<int>` | Ids internos de los grupos de `grupo(u, curso)` (`RN-ACAD-98`), listos para un `whereIn` |
| `classPairsFor(int $userId, int $academicYearId): list<array{group_id: int, subject_id: int}>` | Pares de `clase(u, curso)` |

Solo filas vivas; memoizada por petición; sin bloqueos. **Construcción** (`OPEN-ACAD-43`): recomendada con su primer consumidor (`1.13` o `1.16`), como las de §7.2 (`ADR-048`); el plazo de `ADR-063 §5.4` exige especificarla y aprobarla, no construirla.

**Huecos que deja**: copia de marcos, franjas y (si `1.12b` lo decide) asignaciones al curso siguiente (`REQ-CURSO-002` «plantillas de horario»; **especificada en §21**, `1.12b`, con la asignación docente como casilla opcional); `GroupDirectory`/`SubjectDirectory` siguen sin consumidor externo; exportación PDF y portales leerán las sesiones por una interfaz de `Acad\Domain` cuando lleguen.

**Eventos**: ninguno.

### 18.8 Interfaz (*shell* de `acad`)

| Nombre | Ruta | `meta.permissions` | Menú | Contenido |
|--------|------|--------------------|------|-----------|
| `acad-timetable` | `/academico/horarios` | `['horarios.leer']` | Sí, `administracion` | Rejilla semanal (§18.3); selector de vista y entidad en la URL (`?view=group&id=…`); `meta.academicYearScoped: true` |
| `acad-facilities` | `/academico/espacios` | `['espacios.leer']` | Sí, `administracion` | Tabla de espacios (`src/data-table`), alta/edición en diálogo |
| *(sección)* `acad-level-detail` | — | sin cambio | — | Sección «Marcos horarios» con `horarios.leer` |
| `acad-timetable-frame` | `/academico/marcos/:publicId` | `['horarios.leer']` | No | Ficha del marco; editor de franjas en lista (como el de valores de escala, §10.5.2) con `horarios.actualizar` |
| *(sección)* `acad-group-detail` | — | sin cambio | — | Sección «Profesorado» (asignaciones por asignatura y subgrupo, tutores) con `asignacion_docente.leer` |
| `acad-bookings` (`1.12-b`) | `/academico/reservas` | `['reservas.leer']` | Sí, `administracion` | Reservas por espacio y fecha |

**Rejilla a medida** (sin dependencia nueva, `CLAUDE.md §1`): CSS Grid con días en columnas y tiempo en filas, filas calculadas desde las franjas del marco (proporcionales a la duración); semántica de tabla (`role="table"`, `row`, `columnheader`, `rowheader`, `cell`) y navegación por teclado entre celdas; cada sesión muestra asignatura, grupo/subgrupo, profesor y aula **como texto**; el conflicto se pinta con texto junto a la acción, no solo con color. Sin arrastrar como única vía (WCAG 2.5.7): mover es «Editar» con selector de franja. A 320 px, lista por día (`ADR-054`), sin desplazamiento horizontal. Componentes de shadcn-vue; reglas de `docs/design-system.md §22`. Literales en `locales/{es,en,de,fr}.json` y `lang/*/acad.php` (`INV-009`); las traducciones `en`/`de`/`fr` las completa `traductor`.

### 18.9 Criterios de aceptación

Pest en `apps/api/tests/Feature/Acad/` (concurrencia en `tests/Concurrency/`), Vitest y Playwright. Cada test referencia su `CA-ACAD-NNN`, `REQ-ACAD-002` y el `RN-ACAD-*` que cita (`INV-015`). Base real (`ADR-033 §10`).

**Asignación docente y tutoría (`1.12-a`)**

- **`CA-ACAD-107`** (`RN-ACAD-93`) — *Dado* un grupo con la asignatura MAT asignada y un usuario activo, *cuando* `POST /teaching-assignments` con `teacher`, `group`, `subject`, *entonces* `201` con `public_id`, `academic_year` del grupo, `teacher.public_id`/`teacher.name`, `subgroup: null`, y entrada `created` con `auditable_type = 'teaching_assignment'`.
- **`CA-ACAD-108`** (`RN-ACAD-93`) — Asignatura no asignada al grupo, o asignada y borrada ⇒ `422 subject_not_in_group`; subgrupo de otro grupo ⇒ `422 subgroup_not_in_group`; inserción directa con subgrupo de otro grupo o `group_subject` de otro grupo ⇒ `23503`.
- **`CA-ACAD-109`** (`RN-ACAD-92`) — Usuario inexistente, de otro centro o inactivo ⇒ `422 user_not_found`; un usuario sin ningún rol docente ⇒ `201` (no se comprueba rol); la asignación no añade permisos al usuario.
- **`CA-ACAD-110`** (`RN-ACAD-94`) — Repetir (usuario, asignatura del grupo, sin subgrupo) ⇒ `422 assignment_exists`; con subgrupo distinto ⇒ `201`; otro usuario en la misma asignatura del grupo ⇒ `201`; dos altas iguales simultáneas (dos procesos) ⇒ una `201` y otra `422`, nunca `500`.
- **`CA-ACAD-111`** (`RN-ACAD-95`) — `PATCH /teaching-assignments/{id}` ⇒ `405`; `DELETE` sin sesiones ⇒ `204`; con sesiones vivas ⇒ `409 has_dependents` con `timetable_sessions`.
- **`CA-ACAD-112`** (`RN-ACAD-96`) — Con una asignación viva: `DELETE` del grupo, del subgrupo de la asignación o de la asignatura ⇒ `409 has_dependents` con `teaching_assignments`; `PUT /groups/{id}/subjects` sin esa asignatura ⇒ `409` y el conjunto no cambia; con un tutor vivo, `DELETE` del grupo ⇒ `409` con `group_tutors`; con un marco vivo, `DELETE` de la etapa ⇒ `409` con `timetable_frames`.
- **`CA-ACAD-113`** (`RN-ACAD-97`) — `PUT /groups/{id}/tutors` con dos usuarios ⇒ `200` y dos `created`; repetir ⇒ sin entradas nuevas; con uno ⇒ un `deleted`; lista vacía ⇒ sin tutores; un usuario inactivo ⇒ `422 user_not_found` y nada cambia.
- **`CA-ACAD-114`** (`RN-ACAD-98`; test unitario de la consulta que respaldará la interfaz) — Usuario tutor de A y con asignaciones de MAT en B y de LEN en el subgrupo D1 de C: `grupo` = {A, B, C}; `clase` = {(B, MAT), (C, LEN)}; filas borradas y de otro curso académico no cuentan.

**Espacios (`1.12-a`)**

- **`CA-ACAD-115`** (`RN-ACAD-100`, `-101`) — Alta de un `aula` y de un `recurso` ⇒ `201`; `facility_type` fuera de vocabulario ⇒ `422 facility_type_invalid`; en `PATCH` ⇒ `422 facility_type_immutable`; código repetido entre vivos del centro ⇒ `422 code_taken`; `academic_year` en el cuerpo se ignora (no existe en el recurso) **[a confirmar con `OPEN-ACAD-33`]**.
- **`CA-ACAD-116`** (`RN-ACAD-102`) — Un aula con una sesión viva en un curso `activo` ⇒ `DELETE` `409 has_dependents`; con sesiones solo en un curso `cerrado` ⇒ `204`, y la sesión cerrada se sigue leyendo con `facility.deleted: true`.

**Marcos y franjas (`1.12-a`)**

- **`CA-ACAD-117`** (`RN-ACAD-110`, `-115`) — `POST /timetable-frames` con `education_level`, `frame_type: "ordinaria"`, nombre y cinco franjas ⇒ `201` con las franjas ordenadas por `weekday`, `starts_at`; se crea la fila de `timetables` del curso si no existía; un segundo `ordinaria` vivo en la etapa ⇒ `422 frame_ordinary_exists`; `especial` ⇒ `422 frame_type_invalid` (en `1.12-a`); `academic_year` en el cuerpo ⇒ `422 academic_year_not_allowed`.
- **`CA-ACAD-118`** (`RN-ACAD-112`) — `weekday: 8` ⇒ `422 weekday_invalid`; `"8:5"` ⇒ `422 time_invalid`; fin ≤ inicio ⇒ `422 slot_ends_before_start`; dos franjas del mismo día 08:30-09:25 y 09:00-09:55 ⇒ `422 slot_overlap`; 08:30-09:25 y 09:25-10:20 ⇒ válido.
- **`CA-ACAD-119`** (`RN-ACAD-113`) — `PATCH` con `slots` que reordena, actualiza una por `public_id`, añade una y omite otra ⇒ `200`, una `updated`, una `created` y una `deleted`; `public_id` de franja de otro marco o repetido ⇒ `422 slot_not_in_frame` y nada cambia; 101 franjas ⇒ `422 slots_too_many`.
- **`CA-ACAD-120`** (`RN-ACAD-114`) — Franja con una sesión viva: omitirla o cambiar su hora en `PATCH` ⇒ `409 has_dependents` con `timetable_sessions`; `DELETE` del marco ⇒ el mismo `409`.
- **`CA-ACAD-121`** (`datos.md §9`) — Inserciones directas: franja con `weekday = 0`, `ends_at <= starts_at`; marco `ordinaria` con `valid_from`; marco `especial` sin fechas ⇒ `23514`; franja con `timetable_frame_id` de otro curso académico ⇒ `23503`.

**Sesiones y conflictos (`1.12-a`)**

- **`CA-ACAD-122`** (`RN-ACAD-120`, `-121`) — `POST /timetable-sessions` con `time_slot`, `teaching_assignment` y `facility` (aula) ⇒ `201` con los resúmenes de franja, profesor, grupo, asignatura y aula; franja de un marco de otra etapa ⇒ `422 slot_not_for_group`; un `recurso` como aula ⇒ `422 facility_not_room`; aula borrada ⇒ `422 facility_not_found`; inserción directa con un espacio `recurso` ⇒ `23503`.
- **`CA-ACAD-123`** (`RN-ACAD-123`, `-124`) — Los seis casos de §18.5 dan exactamente el resultado indicado, con `params.conflicts` que contiene el `kind` y el `public_id` de la sesión con la que choca; ninguna fila nueva en los rechazos.
- **`CA-ACAD-124`** (`RN-ACAD-124`) — Profesor con sesión en el marco ordinario de ESO (lunes 09:00-09:55) y nueva sesión en el de Bachillerato (lunes 09:30-10:25, franjas distintas) ⇒ `422 timetable_conflict`, `kind: teacher`.
- **`CA-ACAD-125`** (`RN-ACAD-122`) — `PATCH` de `time_slot` a una franja libre ⇒ `200` y `updated`; a una que choca ⇒ `422` y la sesión no se mueve; `facility: null` ⇒ `200`; `teaching_assignment` ⇒ `422 parent_immutable`.
- **`CA-ACAD-126`** (`RN-ACAD-125`) — Dos procesos reales crean a la vez dos sesiones que chocan por profesor (en grupos distintos), *y en otra corrida* por aula: exactamente una `201` y otra `422 timetable_conflict`, nunca dos `201` ni `500`.
- **`CA-ACAD-127`** (`RN-ACAD-127`) — `GET /timetable-sessions` sin ninguno de los cuatro filtros ⇒ `422 view_filter_required`; `?group=` incluye las sesiones de sus subgrupos; `?subject=` las de todos los grupos; `?teacher=` y `?facility=` las suyas; orden por día y hora.

**Curso académico, permisos y aislamiento (`1.12-a`)**

- **`CA-ACAD-128`** (`RN-ACAD-90`) — Con un curso `cerrado`, crear, modificar o borrar asignaciones, tutorías, marcos (con y sin `slots`) y sesiones ⇒ `409 academic-year-closed` y nada cambia; los espacios siguen editables.
- **`CA-ACAD-129`** (`RN-ACAD-90`, `RN-CURSO-33`) — Forma de §13.5 para asignaciones, tutorías de un grupo, marcos y sesiones: listado y detalle de un curso cerrado sin `curso_historico.leer` ⇒ `404`; con él, los datos.
- **`CA-ACAD-130`** (`ADR-057`, `AR-13`) — Las tablas con `academic_year_id` de §18 tienen el disparador; `facilities` no tiene la columna ni el disparador; ninguna FK con acción referencial distinta de `NO ACTION`; `AR-13` en verde sin excepciones.
- **`CA-ACAD-131`** (`permisos.md §10`) — Cada *endpoint* nuevo responde `401` sin sesión y `403` sin su permiso; con `acad` descontratado, `403 module-disabled` antes del permiso; `AR-07a`/`AR-07b` en verde y la lista de excepciones de `AR-07a` sin cambios.
- **`CA-ACAD-132`** (`INV-001`) — Con dos centros equivalentes, ningún *endpoint* lee, cuenta, modifica ni borra datos del otro; `public_id` ajeno en ruta ⇒ `404`; en cuerpo (usuario, grupo, asignatura, subgrupo, franja, asignación, aula) ⇒ `422` de su campo; un usuario de otro centro nunca aparece en `teacher`.
- **`CA-ACAD-133`** (`permisos.md §10.3`) — Tras `platform:sync-registry` y la siembra, los roles predefinidos tienen exactamente las concesiones de `permisos.md §10.3`; conceder cualquier permiso nuevo con ámbito `grupo`/`clase` ⇒ `422`.

**Interfaz (`1.12-a`)**

- **`CA-ACAD-134`** (§18.8; Vitest) — La rejilla tiene semántica de tabla con cabeceras de día y de hora, se recorre con teclado, muestra cada sesión con texto (asignatura, grupo, profesor, aula), y a 320 px pasa a lista por día sin desplazamiento horizontal; acciones de escritura solo con su permiso y curso escribible; `422 timetable_conflict` se muestra junto a la acción nombrando la sesión con la que choca; `lint:i18n` limpio y `AR-11` en verde.
- **`CA-ACAD-135`** (Playwright contra la API real) — Crear un aula, un marco ordinario con franjas, asignar dos profesores a dos asignaturas de un grupo, componer dos sesiones, provocar un conflicto de profesor y ver el mensaje, y cambiar de vista a la del profesor.

**Jornada especial y reservas (`1.12-b`)**

- **`CA-ACAD-136`** (`RN-ACAD-111`) — Marco `especial` con fechas dentro del curso ⇒ `201`; fechas fuera ⇒ `422 frame_outside_academic_year`; `valid_to < valid_from` ⇒ `422 frame_dates_invalid`; otro especial solapado de la misma etapa ⇒ `422 frame_overlap`.
- **`CA-ACAD-137`** (`RN-ACAD-124`) — Mismo profesor a la misma hora en el ordinario y en el especial de su misma etapa ⇒ `201`; en el especial de Primaria y en el ordinario de ESO ⇒ `422 timetable_conflict`.
- **`CA-ACAD-138`** (`RN-ACAD-140`, `-141`) — Reserva de un recurso ⇒ `201`; otra del mismo recurso, misma fecha y hora solapada ⇒ `422 booking_conflict`, `kind: booking`; reserva de un aula a la hora de una sesión del marco vigente ese día ⇒ `422`, `kind: session`; fuera del curso ⇒ `422 booking_outside_academic_year`; dos reservas simultáneas que chocan (dos procesos) ⇒ una `201`.
- **`CA-ACAD-139`** (`RN-ACAD-142`, `OPEN-ACAD-42`) — Con `reservas.eliminar` ámbito `propios`, cancelar una reserva propia ⇒ `204` y una ajena ⇒ `404`; con ámbito `todos`, ambas `204`.
- **`CA-ACAD-140`** (`RN-ACAD-90`) — Lectura de reservas y escritura en curso cerrado: forma de `CA-ACAD-128`/`-129`.
- **`CA-ACAD-141`** (`RN-ACAD-144`, `RN-CURSO-53`) — *Dado* un curso en `planificacion` (2026-09-01 a 2027-06-30) con un marco especial vivo del 2026-12-01 al 2026-12-11 y una reserva viva el 2027-06-15, y un usuario con `curso_academico.actualizar` y sin permisos de `acad`, *cuando* envía `PATCH /academic-years/{id}` con `ends_on: "2027-06-10"`, *entonces* `409 curso.conflict.date_change_checks_failed` con una entrada en `errors.date_change[]`: `code` `acad.conflict.schedule_outside_academic_year`, `params` exactamente `{"special_frames": 0, "bookings": 1, "schedule_starts_on": "2026-12-01", "schedule_ends_on": "2027-06-15"}` (sin identificadores) y `message` traducido; con `starts_on: "2026-12-05"`, `special_frames = 1`; un marco o una reserva borrados no cuentan; con fechas que los incluyen, `200`. Ni el curso ni las filas cambian. Los períodos fuera tienen prioridad (`RN-ACAD-62`). `lang/{es,en,de,fr}/acad.php` tienen `conflict.schedule_outside_academic_year`.

### 18.10 Riesgos

| Riesgo | Severidad | Mitigación |
|--------|-----------|------------|
| Conflictos no garantizados por el motor; una escritura que no tome el ancla los rompería | Media | Un solo servicio escribe sesiones y reservas, `FOR UPDATE` del ancla (`RN-ACAD-125`); `CA-ACAD-126`, `-138` con dos procesos; `db-reviewer` |
| La rejilla a medida es el componente de interfaz más complejo hasta ahora (accesibilidad, *reflow*) | Media | Semántica de tabla y vista de lista a 320 px desde el principio; `CA-ACAD-134`; sin librería nueva |
| Plazo de `ADR-063 §5.4`: si §18 no se aprueba, `1.13` y `1.16` siguen bloqueados | Alta | Aprobar la parte de asignación (§18.4.2, §18.7) aunque se aplace el resto; se puede partir la aprobación |
| Asignación por **usuario** y no por `Employee`: el personal sin cuenta no puede impartir en el horario, y llegar a `Employee` (`REQ-RRHH`) obligará a vincular | Media | `OPEN-ACAD-34`; la FK a `users` es aditiva respecto a una futura a `employees` |
| Espacios del centro sin copia por curso: un horario cerrado muestra el nombre actual del aula | Baja | `RN-ACAD-103`, `OPEN-ACAD-33` |
| `time` sin zona podría chocar con `AR-04` o con la convención de `ADR-029` | Media | Cerrado por `ADR-065`: `AR-04` admite `time` sin cambios |
| Exponer nombres de profesorado a quien lea horarios | Baja | Es el uso normal de un horario; solo nombre visible, sin datos de contacto; `security-reviewer` |

### 18.11 Preguntas abiertas de `1.12` (decididas el 2026-10-17: todas con la recomendación)

| ID | Pregunta | Opciones | Recomendación |
|----|----------|----------|---------------|
| `OPEN-ACAD-32` | **¿«Generador» es un resolutor automático o un editor con detección?** | **A** · Editor manual con detección de conflictos (rejilla a medida). **B** · Además, generación automática (motor de restricciones, en cola, `INV-012`) | **A** en `1.12`: es lo que el plan describe y lo que hace útil el horario. B es un proyecto propio (dependencia o algoritmo nuevo, ADR, *worker* que hoy no existe); si se quiere, paso aparte posterior |
| `OPEN-ACAD-33` | **Espacios: ¿del centro o por curso académico? ¿Una tabla para aulas y recursos?** | **A** · Del centro (sin `academic_year_id`), una tabla con `facility_type`. **B** · Por curso académico (copia en el *rollover*, congelados al cerrar). **C** · Dos tablas | **A**: un aula es física y no cambia de curso; sin copia anual ni disparador. Coste aceptado: `RN-ACAD-102`/`-103` |
| `OPEN-ACAD-34` | **¿A quién se asigna la docencia: `User` o `Employee`?** (`§16.1` fija `Employee`, que no existe) | **A** · `User` del centro, activo, sin comprobar rol. **B** · Esperar a `Employee` (fase 2) | **A**: `ADR-063 §5.2` y `ScopeResolver` resuelven por usuario; B bloquea `1.13`/`1.16`. Consecuencia escrita en §18.10 |
| `OPEN-ACAD-35` | **Granularidad de la asignación** | **A** · Subgrupo opcional, varios tutores por grupo, sin fechas de vigencia ni sustituciones. **B** · Añadir vigencia por fechas y sustituciones | **A**: nada en los requisitos pide B; las sustituciones no deben cambiar quién califica (`ADR-063 §5.4`) y encajan en guardias (`REQ-GUAR`, fase 2) |
| `OPEN-ACAD-36` | **¿Un conflicto se rechaza o se avisa? ¿Co-docencia?** | **A** · Se rechaza siempre (`422`); co-docencia del grupo entero no soportada. **B** · Se guarda y se marca (lista de conflictos). **C** · A con co-docencia permitida si la asignatura es la misma | **A**: el horario nunca queda inconsistente y no hace falta un informe de conflictos. Si el centro piloto usa co-docencia, C es aditiva |
| `OPEN-ACAD-37` | **Horarios especiales** | **A** · Jornada intensiva = marcos `especial` con vigencia (`1.12-b`); festivos al calendario escolar (`1.20 · REQ-AGENDA`); exámenes a `REQ-ACAD-005`. **B** · Todo en `1.12` | **A**: festivos y exámenes son calendarios con su propio dueño; meterlos aquí crea dos fuentes de verdad |
| `OPEN-ACAD-38` | **Exportación a PDF** (servicio PDF sin motor, `PLAN 0.3`/`1.17`; sin *worker*, #128) | **A** · Diferir a un paso posterior a `1.17`, con permiso `horarios.exportar`, en cola. **B** · Hoja de impresión del navegador en `1.12` | **A**: B no es «exportación a PDF» y crearía un segundo formato que mantener. No se declara `horarios.exportar` hasta entonces (precedente `permisos.md §2.1`) |
| `OPEN-ACAD-39` | **Importación «desde formatos estándar»: ¿cuáles?** | **A** · Fuera de `1.12`; el usuario indica los formatos (p. ej. el de la herramienta del centro piloto) y se especifica con el importador (`1.24`/`1.25`). **B** · Elegir ahora un formato | **A**: elegir sin saber qué usa el piloto es inventar requisito |
| `OPEN-ACAD-40` | **Tipo de la hora civil de franjas y reservas** | **A** · `time` (sin zona), como `date` en fechas civiles; interpretada en la zona del centro. **B** · `smallint` de minutos desde medianoche | **A**, con una nota de convención: la decide un **ADR corto** de `architect` (lo reutilizarán asistencia y agenda), no esta especificación; si `AR-04` la rechaza, su ampliación se aprueba antes de la migración |
| `OPEN-ACAD-41` | **Reservas** | **A** · Fecha + intervalo libre, sin repetición ni aprobación. **B** · Por franja del marco. **C** · Con repetición semanal o aprobación | **A**: los recursos no siguen el marco de ninguna etapa; repetición y aprobación no las pide ningún requisito |
| `OPEN-ACAD-42` | **Permisos y siembra** (`permisos.md §10`) | **X** · Recursos `asignacion_docente`, `horarios`, `espacios`, `reservas`; `direccion` con los cuatro de `horarios` y `asignacion_docente` (`§11.1`: «horarios»); lectura para `secretaria`, `docente`, `tutor_grupo`, `orientador`; reservas: `docente` y `tutor_grupo` `crear` y `eliminar` con **`propios`**. **Y** · Escritura solo para `administrador_centro` (criterio de `OPEN-CURSO-14`) | **X**: `§11.1` nombra los horarios en Dirección/Jefatura. `propios` en reservas exige que `acad` registre su resolutor (`propios`, `reservas`) y **ampliar el mapa cerrado de `AR-10`**, que solo se amplía con aprobación expresa: esta fila la pide. Ningún permiso de horarios contiene datos de alumnado, así que `ADR-063 §5.4` no impide `todos` a `docente` |
| `OPEN-ACAD-43` | **¿Se construye ya la interfaz de pertenencia?** | **A** · Especificada aquí, construida con su primer consumidor (`1.13`/`1.16`). **B** · Construida en `1.12-a` | **A** (`ADR-048`, precedente §7.2); el plazo de `ADR-063 §5.4` pide especificación aprobada. `CA-ACAD-114` deja probada la consulta que la respaldará |
| `OPEN-ACAD-44` | **Partición** | **1** · Una entrega. **2** · `1.12-a` (asignación, espacios, marcos ordinarios, sesiones, conflictos, vistas) y `1.12-b` (jornada especial y reservas) | **2**: `1.12-a` ya es del tamaño de `1.11-b` (seis tablas más dos restricciones en tablas existentes, ~21 *endpoints*, la rejilla); `1.12-b` es independiente y de menor riesgo |

---

## 19. Estado de implementación de `1.12-a` (asignación docente, espacios, marcos ordinarios, sesiones y vistas)

Implementado el 2026-10-18 sobre la especificación aprobada de §18 (`CA-ACAD-107` a `-134`; `CA-ACAD-135` Playwright queda pendiente de la ejecución E2E contra la API real). Los **21** *endpoints* de `api.md §12.2` de `1.12-a` están hechos: 5 de `facilities`, 5 de `timetable-frames`, 4 de `teaching-assignments` (sin `PATCH`: `405`, `RN-ACAD-95`), 2 de `groups/{id}/tutors` y 5 de `timetable-sessions`. `1.12-b` (marcos especiales, reservas) no se ha tocado.

### 19.1 Decisiones de implementación que la especificación no fijaba

| # | Decisión | Motivo |
|---|----------|--------|
| I-1 | **`Core\Domain\UserDirectory` ampliada** (aprobada por el usuario antes de escribirla, `§18.7`): `findActiveByPublicId()` (`ActiveUserRef{id, publicId, displayName}`; `null` si no existe, es de otro centro, está inactivo, pendiente o borrado) y `displayNamesByIds()`. Además, **`refsByIds()`** (id interno → `ActiveUserRef`, de usuarios vivos o no), añadida al implementar | Sin el `public_id` del profesor al leer no se puede representar `teacher.public_id` (`api.md §12.1`) sin consultar `users` desde `acad` (`AR-01`); `refsByIds()` es aditiva y `displayNamesByIds()` la usa. **Desviación confirmada por el usuario el 2026-10-19**: no figuraba en la aprobación literal. El nombre visible **nunca cae al correo** (revisión de seguridad M-1): sin nombre, `Core` devuelve cadena vacía y `acad` muestra «Usuario sin nombre» (`acad.staff.unnamed`) |
| I-2 | El filtro `teacher` de `GET /teaching-assignments` y de `GET /timetable-sessions` resuelve el usuario con `findActiveByPublicId()`: **un profesor dado de baja devuelve una colección vacía** (no sus asignaciones históricas) | `Core` no ofrece resolver un usuario inactivo por `public_id`; ampliar más `Core` está fuera de lo aprobado. Las lecturas por id (`teacher` de cada fila) sí incluyen inactivos |
| I-3 | La interfaz de pertenencia `TeachingMembership` **no se construye** (`OPEN-ACAD-43` = A); `CA-ACAD-114` prueba la consulta que la respaldará (`AcadGroupTutorsApiTest`) | Construcción con su primer consumidor (`1.13`/`1.16`, `ADR-048`) |
| I-4 | Franja y asignación de cursos distintos en el alta de una sesión: `422 parent_not_found` en `teaching_assignment` (la franja fija el curso) | `RN-ACAD-120` dice «de otro curso» sin elegir campo |
| I-5 | El orden de comprobación de un `DELETE` con dependientes en un curso cerrado es dependientes primero (`409 has_dependents`) y curso cerrado después (`409 academic-year-closed`) | Mismo comportamiento que el resto de `acad` (`GroupService::delete`) |
| I-6 | El selector de profesor de la interfaz usa el listado de usuarios activos de `Core` (`GET /users`, `usuario.leer`), con búsqueda; sin ese permiso no se puede elegir profesor | La especificación no fija el selector; se reutiliza la API pública de `core` (`AR-11`) |
| I-7 | La rejilla permite **crear** sesiones solo en la vista de grupo (hay un grupo cuyas asignaciones elegir); las vistas de profesor, aula y asignatura permiten editar y quitar | Una sesión se crea para una asignación de un grupo (§18.3) |

### 19.2 Dónde está

Servicios en `apps/api/app/Modules/Acad/Application/` (`TeachingAssignmentService`, `GroupTutorsService`, `TimetableSessionService`, `TimetableAnchor`, `StaffDirectory`); controladores, *requests* y *resources* en `Http/`; *tests* en `apps/api/tests/Feature/Acad/` (`AcadTeachingAssignmentsApiTest`, `AcadGroupTutorsApiTest`, `AcadTimetableSessionsApiTest`, `AcadTimetableYearAndTenantTest`, `AcadTimetableAccessTest`) y `tests/Concurrency/AcadConcurrencyTest.php` (`CA-ACAD-110`, `-126`). Interfaz en `apps/web/src/modules/acad/` (`components/TeachingSection.vue`, `StaffPicker.vue`, `TimetableGrid.vue`, `SessionFormDialog.vue`, `views/TimetableView.vue`).

---

## 20. Estado de implementación de `1.12-b` (marcos especiales y reservas)

Implementado el 2026-10-19 sobre la especificación aprobada de §18 (`CA-ACAD-136` a `-140`, más `CA-ACAD-128`, `-129`, `-131` a `-133` y `-116` en lo que afectan a `reservas`; `CA-ACAD-135` Playwright sigue pendiente de la ejecución E2E contra la API real, que recrea el centro `demo`). Los **4** *endpoints* de reservas de `api.md §12.2` están hechos (`GET`/`POST /facility-bookings`, `GET`/`DELETE /facility-bookings/{id}`; sin `PATCH`, `405`, `RN-ACAD-142`) y los marcos `especial` se crean y editan por los mismos cinco de `timetable-frames`. **No** se construye `TeachingMembership` (`OPEN-ACAD-43` = A). La ampliación del mapa cerrado de `AR-10` (recurso `reservas`, modelo `FacilityBooking`, fichero `FacilityBookingService.php`) es la aprobada con `OPEN-ACAD-42`.

### 20.1 Decisiones de implementación que la especificación no fijaba

| # | Decisión | Motivo |
|---|----------|--------|
| I-8 | **Fechas de un marco especial**: ausentes o `null` ⇒ `422 frame_dates_invalid` en ese campo; con formato que no es `AAAA-MM-DD` real ⇒ `422 date_invalid` (código ya existente de los períodos); `valid_to < valid_from` ⇒ `frame_dates_invalid` en `valid_to`. En el `PATCH`, `null` en un especial es `frame_dates_invalid` (no quita la vigencia) y una fecha no nula en un ordinario también | `RN-ACAD-111` nombra `frame_dates_invalid` solo para `valid_to < valid_from`; reutilizar los códigos existentes evita inventar otros |
| I-9 | **Cambio de fechas de un especial con sesiones** (`RN-ACAD-125` lo cita como escritura que lee sesiones, sin decir qué comprueba ni qué responde): se comprueba cada sesión del marco contra las de los marcos que **pasan a coexistir** con las fechas nuevas (`FrameCoexistence`); el primer choque se rechaza con `422 timetable_conflict` en **`valid_from`**, con `params.conflicts` (`kind`, `session` de la otra sesión). Un `PATCH` que deja las fechas iguales no toma el ancla ni comprueba nada. No se comprueba contra reservas (misma razón que `RN-ACAD-143`) | Sin esto, mover las fechas de un especial crearía conflictos de profesor, aula o grupo que la escritura de sesiones ya no vuelve a mirar |
| I-10 | **Orden de bloqueos del `PATCH` de fechas**: curso (`FOR SHARE`, `AcademicYearSharedLock`) → marco y todas sus franjas (`FOR UPDATE`) → ancla (`FOR UPDATE`). Difiere de «curso → ancla» de `RN-ACAD-54`/`-125` en que el ancla va **después** de marco y franjas | Una sesión concurrente toma `FOR SHARE` de su franja **antes** que el ancla; si el `PATCH` tomara el ancla primero y luego las franjas, ambos se esperarían (interbloqueo `40P01`). En el alta de un especial no hay franjas con sesiones, y el orden es el de la especificación (curso → etapa `FOR SHARE` → ancla) |
| I-11 | **`params.conflicts` de `booking_conflict`**: `{kind: "booking", booking: <public_id>}` o `{kind: "session", session: <public_id>}`; primero las reservas (por hora e `id`), luego las sesiones | `RN-ACAD-141` escribe `{kind, ...}` sin fijar la clave del identificador |
| I-12 | **Códigos de la reserva sin código propio en la especificación**: `ends_at <= starts_at` ⇒ `422 slot_ends_before_start` (el de las franjas); `academic_year` ausente ⇒ `422 academic_year_required`; inexistente o ilegible (curso de solo lectura sin `curso_historico.leer`, `S-3`) ⇒ `422 academic_year_not_found`; espacio inexistente, de otro centro o borrado ⇒ `422 facility_not_found` | Códigos ya existentes; `RN-ACAD-140` solo nombra `booking_outside_academic_year` y `booking_conflict` |
| I-13 | **Representación de `FacilityBooking`**: `public_id`, `academic_year`, `facility` (`public_id`, `facility_type`, `code`, `name`, `deleted`), `booked_on`, `starts_at`, `ends_at`, `booked_by` (`{public_id, name}` de `created_by`, o `null`) y `created_at`; sin `updated_at` (no se editan). El listado no admite `sort` (orden fijo) | `api.md §12.1` dice «espacio (resumen)» sin fijarlo |
| I-14 | **Siembra con ámbito `propios`**: constante nueva `ProvisionTenantDefaults::STRUCTURE_PERMISSION_PROPIOS_GRANTS` (`docente` y `tutor_grupo` ⇒ `reservas.eliminar`); `acad:grant-structure-permissions` la recorre tras la de `todos` y concede por (rol, código): una concesión previa con otro ámbito no se toca. `CA-CORE-042` («ninguna fila fuera de `todos`») pasa a excluir exactamente esas dos filas | `permisos.md §10.3` pide `propios`; la lista única de `todos` no podía expresarlo |
| I-15 | **Interfaz**: «Cancelar» se ofrece por el permiso `reservas.eliminar` (`/me.permissions` no distingue el ámbito); con `propios`, una reserva ajena responde `404` y se muestra junto a la acción. La selección de espacio del alta exige `espacios.leer`. La vista de **grupo** gana el selector «Marco horario» (`?frame=`, por defecto el ordinario) y solo pinta las sesiones del marco elegido; las demás vistas rotulan con texto las sesiones de una jornada especial con su vigencia | Una celda puede reunir a la vez la sesión del ordinario y la del especial (`RN-ACAD-124`); sin selector no habría forma de componer el especial |
| I-16 | **Identificadores en `booking_conflict`** (revisión de seguridad M1): `params.conflicts` conserva `kind`, pero `booking` / `session` (`public_id`) solo se incluyen si el solicitante tiene `reservas.leer` / `horarios.leer` respectivamente; si no, el elemento es solo `{kind}`. La interfaz lo trata como «otra reserva / una sesión» sin nombre | Quien solo puede crear reservas no debe obtener identificadores de reservas ni de sesiones que no puede leer |
| I-18 | **Rastro de la cancelación fuera de ámbito** (revisión de seguridad B2): cancelar con `propios` una reserva ajena sigue siendo `404` idéntico al de una inexistente, pero se registra un aviso `acad.booking.cancel.out_of_scope` (reserva, actor, centro y `request_id`, sin más datos) en el registro de la aplicación (`logging.default`). No hay precedente de auditoría de denegaciones por ámbito (`audit_logs.event` es un enumerado cerrado, `ADR-039`; el registro de accesos a recursos ajenos es el issue #258) y no se crea infraestructura nueva: se sigue el patrón de los avisos `auth.*` | Detectar intentos de cancelar reservas ajenas sin cambiar lo que ve el cliente |
| I-17 | **Cambio de fechas del curso** (decisión del usuario, 2026-10-19; `RN-ACAD-144`, `CA-ACAD-141`): la validación de `acad` de `RN-ACAD-62` se amplía —no se registra una segunda, porque `CA-ACAD-102` exige exactamente una con `moduleCode() = acad`— y comprueba, tras los períodos, marcos especiales y reservas vivos. Devuelve un solo fallo por intento, así que si fallan períodos y marcos/reservas, el segundo aparece al corregir el primero | Un especial o una reserva fuera del curso incumplirían `RN-ACAD-111`/`-140` hasta su siguiente edición |

### 20.2 Dónde está

Servicios en `apps/api/app/Modules/Acad/Application/` (`FacilityBookingService`, `FrameCoexistence`; ampliados `TimetableFrameService`, `TimetableSessionService`, `FacilityService`); resolutor `Domain/ReservasPropiosScopeResolver.php`; controlador, *requests* y *resource* de reservas en `Http/`; migración `2026_10_19_100100_create_facility_bookings_table.php`. *Tests* en `apps/api/tests/Feature/Acad/` (`AcadSpecialFramesApiTest`, `AcadFacilityBookingsApiTest`, y ampliados `AcadTimetableSchemaTest`, `AcadPermissionsTest`, `AcadEndpointsMatrixTest`, `AcadOpenApiTest`), `tests/Feature/Architecture/ScopedQueryConfinementTest.php` (mapa de `AR-10`) y `tests/Concurrency/AcadConcurrencyTest.php` (`CA-ACAD-136`, `-138`, dos procesos reales). Interfaz en `apps/web/src/modules/acad/` (`views/BookingsView.vue`, `components/BookingFormDialog.vue`, `api/bookings.ts`; ampliados `FramesSection.vue`, `TimetableFrameDetailView.vue`, `TimetableView.vue`, `TimetableGrid.vue`).

---

## 21. `1.12b` · Participante de `acad` en la apertura del curso siguiente (`REQ-CURSO-002`) — **APROBADA (2026-10-20)**

> **Estado**: **APROBADA el 2026-10-20 por el usuario**, con la especificación de `1.12b` de `REQ-CURSO` (`OPEN-CURSO-28` a `-37`, `REQ-CURSO/funcional.md §15.4`). **No implementada.** Trasladada desde `REQ-CURSO/funcional.md §7.4` (que queda como referencia a esta sección) **sin cambiar lo aprobado**, y ampliada con la casilla «copiar la asignación docente» que el usuario decidió en `OPEN-CURSO-29`: `acad` ofrece **siempre** estructura, períodos lectivos, escalas y plantillas de horario (marcos y franjas), y **además**, de forma opcional y desmarcada por defecto, las asignaciones docentes y las tutorías de grupo. Las cuatro sub-preguntas de esa ampliación (`OPEN-CURSO-29-a` a `-d`, `REQ-CURSO/funcional.md §15.4.2`) las **resolvió el usuario el 2026-10-20** con la recomendación, incluida la ampliación de `Core\Domain\UserDirectory` (§21.8, I-19); también aceptó que los cuatro grupos siempre ofrecidos son casillas elegibles y que la asignación docente exige subgrupos aunque se copien 0. **Sin preguntas abiertas.** Reglas `RN-ACAD-145` a `-157`, criterios `CA-ACAD-142` a `-153`.
>
> El **contrato** (interfaz `AcademicYearRolloverParticipant`, registro, objetos de valor, transacción única, orden de bloqueos, autorización, auditoría, reversión) es de `curso` y está en `REQ-CURSO/funcional.md §5.7` (`RN-CURSO-61` a `-82`). Esta sección fija **solo lo que hace `acad`**: qué ofrece, cómo copia, qué impide copiar y cómo revierte.

### 21.1 Dependencias

| Pieza | Estado | Consecuencia |
|-------|--------|--------------|
| Contrato de `curso` (`RN-CURSO-61` a `-82`) | **No implementado**: se construye en la misma entrega, con este participante como primer consumidor (`ADR-048`) | — |
| Tablas de `acad` (`1.11`, `1.11d`, `1.12-a`, `1.12-b`) | Implementadas | — |
| *Worker* de colas (issue #128) | **No desplegado**. Decisión del usuario (`OPEN-CURSO-28` = A, 2026-10-20): **#128 se resuelve antes de implementar `1.12b`** (`queue:work` sobre el *driver* `database`, sin Horizon, en otra rama) | La implementación de `1.12b` espera a que #128 esté mezclado |
| **`TeachingMembership`** (`§18.7`, `OPEN-ACAD-43` = A) | Especificada, **no construida** | **El participante no la usa ni la construye** **[DERIVADA]**: es código de `acad` y lee sus propias tablas (`teaching_assignments`, `group_tutors`). La interfaz de pertenencia es para que **otros** módulos resuelvan ámbitos (`ADR-063 §5.2`); su construcción sigue ligada a su primer consumidor (`1.13`/`1.16`) |
| **`Core\Domain\UserDirectory`** | Implementada con `findActiveByPublicId()`, `displayNamesByIds()` y `refsByIds()` (§19.1, I-1; no verificado contra el código en esta especificación) | Ninguna de las tres responde «¿qué identificadores internos de esta lista son de usuarios activos?», que necesita `RN-ACAD-154`. **Ampliación aprobada por el usuario el 2026-10-20** (`OPEN-CURSO-29-d`): `activeIdsAmong(array $ids): array` (`list<int>` → `list<int>`), firma y semántica en §21.8, **I-19**. Se implementa en `Core` en la misma entrega que `1.12b`; si al implementar se encontrara ya una operación equivalente, se usa esa y se corrige I-19. `acad` nunca consulta `users` (`AR-01`) |

### 21.2 Elementos que ofrece (`RN-ACAD-145`)

Solo filas **vivas** del origen. Etiquetas: claves `acad.rollover.elements.<nombre>` (§21.6). La columna «Paquete» la define `RN-CURSO-82`: los elementos del paquete `teaching_assignments` no se eligen uno a uno; se copian **todos juntos** cuando la petición lleva `copy_teaching_assignments: true` (la casilla del asistente).

| `code` | Copia | `requires` | `createPermission` | Paquete |
|--------|-------|------------|--------------------|---------|
| `acad.education_levels` | Etapas: `code`, `name_translations`, `stage`, `regime`, `position` | — | `estructura_academica.crear` | — |
| `acad.courses` | Cursos de etapa: `code`, `name_translations`, `position`, colgados de la etapa copiada | `acad.education_levels` | `estructura_academica.crear` | — |
| `acad.groups` | Grupos: `code`, `name`, `position` | `acad.courses` | `estructura_academica.crear` | — |
| `acad.subgroups` | Subgrupos: `code`, `name`, `position` | `acad.groups` | `estructura_academica.crear` | — |
| `acad.subjects` | Asignaturas: `code`, `name_translations`, `position` | `acad.courses` | `estructura_academica.crear` | — |
| `acad.group_subjects` | Asignaturas que cursa cada grupo | `acad.groups`, `acad.subjects` | `estructura_academica.actualizar` (el de `PUT /groups/{id}/subjects`) | — |
| `acad.academic_periods` | Períodos lectivos: `period_type`, `name_translations`, `position`, y `starts_on`/`ends_on` **desplazadas** (`RN-CURSO-72`) | `acad.education_levels` | `configuracion_academica.crear` | — |
| `acad.grading_scales` | Escalas con todos sus campos y sus valores o tramos vivos (`code`, `label_translations`, `position`, `is_passing`, `range_min`, `range_max`) | — | `configuracion_academica.crear` | — |
| `acad.timetable_frames` | Plantillas de horario: marcos `ordinaria` y `especial` (con `valid_from`/`valid_to` desplazadas) y sus franjas (`weekday`, `starts_at`, `ends_at`). Crea el ancla `timetables` del destino si no existe (`RN-ACAD-115`) | `acad.education_levels` | `horarios.crear` | — |
| `acad.teaching_assignments` | Asignaciones docentes: el **mismo usuario** (`user_id`), la asignatura del grupo copiada y, si la hay, el subgrupo copiado (`RN-ACAD-93`) | `acad.group_subjects`, `acad.subgroups` | `asignacion_docente.crear` | `teaching_assignments` |
| `acad.group_tutors` | Tutorías: el grupo copiado y el mismo usuario (`RN-ACAD-97`) | `acad.groups` | `asignacion_docente.actualizar` (el de `PUT /groups/{id}/tutors`) | `teaching_assignments` |
| `acad.timetable_sessions` | Sesiones del horario: la franja copiada, la asignación docente copiada y la misma aula si sigue viva (`RN-ACAD-155`; `OPEN-CURSO-29-a`) | `acad.teaching_assignments` | `horarios.crear` | `teaching_assignments` |

**No ofrece**: reservas de espacios (puntuales y con fecha: no son estructura) ni espacios (`facilities`, sin curso académico, `OPEN-ACAD-33`: ya existen para el destino).

### 21.3 Reglas

| ID | Regla |
|----|-------|
| `RN-ACAD-145` | **Participante.** La clase vive en `Acad\Infrastructure`, implementa `AcademicYearRolloverParticipant` (`RN-CURSO-61`) con `moduleCode()` = `acad`, declara los doce elementos de §21.2 y se registra en `AcadServiceProvider::boot()`. Lee y escribe **solo tablas de `acad`**. Las fechas del curso las recibe en el `AcademicYearRolloverPlan`, nunca de `academic_years`; el estado de los usuarios, de `UserDirectory` (`RN-ACAD-157`), nunca de `users` (`AR-01`, `INV-007`). *(Antes, `REQ-CURSO/funcional.md §7.4`, párrafo inicial.)* |
| `RN-ACAD-146` | **Fidelidad** *(antes A-1)*: lo copiado reproduce el origen —mismos códigos, atributos, posiciones y relaciones entre filas copiadas— con identificadores nuevos (`public_id` ULID nuevo). Los `*_translations` se copian tal cual (`REQ-CURSO/funcional.md §6`). Una asignación docente o una tutoría conserva el **mismo usuario**; una sesión, la **misma aula** (los espacios son del centro, sin curso, `RN-ACAD-101`), salvo lo que dicen `RN-ACAD-154` y `-155`. Criterio 2 de `§5.28`: «la estructura académica es equivalente a la del curso origen» |
| `RN-ACAD-147` | **Reglas entre filas por construcción** *(antes A-2)*: el origen las cumple y la copia conserva el conjunto, así que se cumplen en el destino sin revalidar una a una —códigos únicos entre hermanos (`RN-ACAD-01`, con el destino vacío del elemento, `RN-ACAD-148`), sin solape y del mismo tipo por etapa (`RN-ACAD-58`, `-59`; el desplazamiento es el mismo para todos), un marco `ordinaria` por etapa y especiales sin solape (`RN-ACAD-110`, `-111`), franjas sin solape (`RN-ACAD-112`), tramos contiguos (`RN-ACAD-85`, `-86`)— y, con la casilla, unicidad de la asignación (`RN-ACAD-94`) y de la tutoría (`datos.md §9.1`) y ausencia de conflictos de profesor, aula y grupo (`RN-ACAD-123`, `-124`): **omitir** sesiones (`RN-ACAD-154`, `-155`) o **quitar** el aula de una sesión nunca crea un conflicto que el origen no tuviera **[DERIVADA]**. Por eso las sesiones se insertan sin pasar por la detección de conflictos del servicio de sesiones, bajo el ancla (`RN-ACAD-149`). Lo que garantiza el motor (FK compuestas con `academic_year_id`, `CHECK`, únicos parciales) se comprueba igualmente al insertar. Un criterio lo verifica sobre el resultado (`CA-CURSO-128`, `CA-ACAD-148`) |
| `RN-ACAD-148` | **Incidencias que impiden ejecutar** *(antes A-3)*, en `preview()` y de nuevo en `copy()`: `acad.rollover.target_not_empty` (`params.element`, `params.count`) si el destino ya tiene filas **vivas** de un elemento de la selección efectiva, **incluidos los del paquete** (`OPEN-CURSO-34` = A); `acad.rollover.dates_outside_target` (`params.element`, `params.count`, `params.starts_on`, `params.ends_on`: menor inicio y mayor fin resultantes) si algún período o marco especial desplazado queda fuera del destino (`RN-CURSO-72`). Sin `public_id`, nombres ni datos personales (`RN-CURSO-73`). Una omisión (`RN-ACAD-154`, `-155`) **no** es incidencia: no impide ejecutar |
| `RN-ACAD-149` | **Escritura** *(antes A-4)*: por instancia a través de los modelos de `acad` (cada fila deja su `created`, `RN-CURSO-79`), de padres a hijos, en este orden: etapas, cursos de etapa, grupos, subgrupos, asignaturas, asignaturas de cada grupo, períodos, escalas y valores, marcos y franjas, asignaciones docentes, tutorías, sesiones. Antes de escribir marcos, franjas o sesiones toma `FOR UPDATE` sobre el ancla `timetables` del destino (crear-o-leer, `RN-ACAD-115`, `-125`), después del `FOR SHARE` del curso destino que ya retiene `curso` (`RN-CURSO-70`). No bloquea filas del origen |
| `RN-ACAD-150` | **`checkRevert()`** *(antes A-5)*: incidencia `acad.rollover.revert_has_dependents` (`params.dependents`: lista de alias de las tablas afectadas; `params.count`) si alguna fila **viva** del destino que **no** está en los *items* de la copia depende de una fila de la copia: cursos de etapa, períodos o marcos de una etapa copiada; grupos o asignaturas de un curso de etapa copiado; subgrupos, asignaturas asignadas, asignaciones docentes o tutorías de un grupo copiado; asignaciones de una asignatura o un subgrupo copiados; franjas de un marco copiado; sesiones de una franja copiada **o de una asignación docente copiada**; valores de una escala copiada |
| `RN-ACAD-151` | **`revert()`** *(antes A-6)*: borrado lógico por instancia, de hijos a padres (**sesiones, tutorías, asignaciones docentes**, franjas, marcos, valores, escalas, períodos, asignaturas asignadas a grupos, subgrupos, asignaturas, grupos, cursos de etapa, etapas), de las filas de sus *items* que sigan vivas. Toma antes el ancla `timetables` si hay marcos, franjas o sesiones. El ancla no se borra nunca (`datos.md §9.1`). Una fila de los *items* que ya no existe se trata como ya borrada (`REQ-CURSO/datos.md §6`) |
| `RN-ACAD-152` | **Cambio de fechas posterior** del destino *(antes A-7)*: lo validan `RN-ACAD-62` y `-144` como para cualquier período, marco especial o reserva; el participante no añade nada |
| `RN-ACAD-153` | **Paquete de asignación docente** (`OPEN-CURSO-29`, decisión del usuario del 2026-10-20; forma `OPEN-CURSO-29-c` = A, aprobada). `acad.subgroups` es requisito **aunque el centro no tenga subgrupos** y se copien 0 (decisión del usuario, 2026-10-20). `acad.teaching_assignments`, `acad.group_tutors` y `acad.timetable_sessions` declaran el paquete `teaching_assignments` (`RN-CURSO-82`): no se eligen por separado y se copian **los tres** si y solo si la petición lleva `copy_teaching_assignments: true`; por defecto, `false`. Sin la casilla, ninguna asignación docente, tutoría ni sesión se copia ni aparece en la simulación. Con la casilla, `acad.teaching_assignments` exige `acad.group_subjects` y `acad.subgroups` en la selección, y `acad.group_tutors`, `acad.groups` (`RN-CURSO-64`: `422 rollover_element_requires` si faltan; la interfaz los marca sola). Copiar la asignación **no concede permisos ni roles** a nadie (`RN-ACAD-92`) |
| `RN-ACAD-154` | **Profesor o tutor que ya no está activo** (`OPEN-CURSO-29-b` = A, aprobada el 2026-10-20). En `preview()` y de nuevo en `copy()`, el participante pregunta a `UserDirectory::activeIdsAmong()` (`RN-ACAD-157`) qué usuarios de las asignaciones y tutorías vivas del origen siguen **activos** en el centro. Una asignación de un usuario inactivo, pendiente o borrado **se omite** con motivo `teacher_inactive`, y sus sesiones con el mismo motivo; una tutoría, con motivo `tutor_inactive`. **Nunca hace fallar la copia** ni es incidencia (`RN-ACAD-148`). Es coherente con `RN-ACAD-92`: no se crea una asignación nueva para un usuario inactivo. El informe y el resultado solo llevan **cuentas** por motivo (`skipped: [{reason, count}]`), ni nombres ni identificadores (`RN-CURSO-73`): quien copia puede no tener `usuario.leer`. El estado que cuenta es el de la ejecución; la simulación puede quedar desfasada si alguien se desactiva entre ambas (`RN-CURSO-65`: no es garantía) |
| `RN-ACAD-155` | **Sesiones** (`OPEN-CURSO-29-a` = A, aprobada el 2026-10-20: se copian con la misma casilla, porque cuelgan de las asignaciones). Cada sesión se copia con su franja copiada y su asignación docente copiada. (1) Si la selección **no** incluye `acad.timetable_frames`, no hay franjas copiadas a las que colgarlas: **todas** las sesiones se omiten con motivo `frames_not_copied`, sin impedir ejecutar **[DERIVADA]** (por eso `acad.timetable_sessions` solo exige `acad.teaching_assignments`, no los marcos: exigirlos obligaría a copiar el horario para copiar el profesorado). (2) Una sesión cuya asignación se omite (`RN-ACAD-154`) se omite con el mismo motivo. (3) Una sesión cuya aula está **dada de baja** (`RN-ACAD-102`, `-128`) se copia **sin aula** y se cuenta en `adjusted: [{reason: "facility_removed", count}]` **[DERIVADA]**: perder la sesión entera por el aula sería peor que tener que reasignarla; y `RN-ACAD-128` ya impide crear una sesión en un aula borrada |
| `RN-ACAD-156` | **Autorización por elemento**: la aplica `curso` con el `createPermission` de cada elemento de la selección efectiva (`RN-CURSO-78`); con la casilla, `asignacion_docente.crear`, `asignacion_docente.actualizar` y `horarios.crear`. El participante no vuelve a autorizar. La reversión no exige permisos de borrado de `acad` (`RN-CURSO-78`) |
| `RN-ACAD-157` | **Estado de los usuarios** (`OPEN-CURSO-29-d` = A, aprobada el 2026-10-20: se amplía `Core`). El participante obtiene los usuarios activos con **una única** llamada a `UserDirectory::activeIdsAmong(array $ids): array` (§21.8, I-19) por cada `preview()` o `copy()`, con los `user_id` distintos de las asignaciones y tutorías vivas del origen, dentro de la transacción de la copia y del centro en curso (RLS y `TenantScope`, `INV-001`). Un `user_id` que no vuelve en el resultado se trata como no activo (`RN-ACAD-154`) |

### 21.4 Casos límite

| Caso | Comportamiento |
|------|----------------|
| Casilla marcada sin seleccionar «Asignaturas de cada grupo» | `422 curso.validation.rollover_element_requires` (`params.element = "acad.teaching_assignments"`, `params.requires = "acad.group_subjects"`); la interfaz lo evita marcando los requisitos |
| Casilla marcada sin «Marcos horarios y franjas» | Se copian asignaciones y tutorías; las sesiones se omiten con `frames_not_copied` (`RN-ACAD-155`) |
| Profesora desactivada después del cierre del origen | Su asignación, sus sesiones y su tutoría no se copian; el informe dice cuántas por motivo. El centro asigna después a quien la sustituya |
| Usuario reactivado entre la simulación y la ejecución | Se copia: cuenta el estado de la ejecución (`RN-ACAD-154`) |
| Aula dada de baja usada en el origen | La sesión se copia sin aula (`adjusted`) |
| Origen sin ninguna asignación docente y casilla marcada | Los tres elementos del paquete cuentan 0; no es `curso.rollover.empty` si el resto de la selección crea filas (`RN-CURSO-66`) |
| Destino con una asignación docente creada a mano y casilla marcada | No puede ocurrir sin asignaturas de grupo en el destino, que ya bloquean por `target_not_empty` en `acad.group_subjects`; si ocurriera, `target_not_empty` también en `acad.teaching_assignments` |
| Revertir tras añadir a mano una sesión que usa una asignación copiada | `409 rollover_revert_blocked` con `acad.rollover.revert_has_dependents` y `timetable_session` en `params.dependents` (`RN-ACAD-150`) |
| Revertir tras borrar a mano una asignación copiada (sin sesiones) | Se salta: ya estaba borrada (`RN-CURSO-75`) |

### 21.5 Criterios de aceptación

Pest en `apps/api/tests/Feature/Acad/`, Vitest en `apps/web`. Cada test referencia su `CA-ACAD-NNN`, `REQ-CURSO-002`, `REQ-ACAD-002` y las reglas que cita (`INV-015`). Base de datos real (`ADR-033 §10`). El trabajo en cola se ejecuta en el test de forma directa. «Origen de prueba» y «destino de prueba»: los de `REQ-CURSO/funcional.md §13.8`, más, en el origen, cuatro asignaciones docentes de tres usuarios activos (una de ellas en un subgrupo), dos tutorías, seis sesiones (una en un aula dada de baja) y una asignación, una tutoría y una sesión borradas.

- **`CA-ACAD-142`** (`RN-ACAD-145`, `-153`) — *Dado* `acad` contratado, *cuando* se pide `GET /academic-year-rollovers/elements`, *entonces* `data` tiene los doce elementos de §21.2 en el orden de la tabla; `acad.teaching_assignments`, `acad.group_tutors` y `acad.timetable_sessions` llevan `bundle: "teaching_assignments"` y los nueve restantes `bundle: null`; cada `label` sale traducido en los cuatro idiomas según `Accept-Language`.
- **`CA-ACAD-143`** (`RN-ACAD-146`, `-153`; criterio 2 de `§5.28`) — *Dados* el origen y el destino de prueba, *cuando* se copia con los nueve elementos y `copy_teaching_assignments: true`, *entonces* `completado`; el destino tiene las cuatro asignaciones (mismo `user_id`, asignatura del grupo y subgrupo **copiados**, verificado por los códigos de sus padres), las dos tutorías (grupo copiado, mismo `user_id`) y las seis sesiones (franja y asignación copiadas, misma aula salvo la de `CA-ACAD-147`); ninguna fila borrada del origen se copia; `year_rollover_items` tiene una fila por cada una con alias `teaching_assignment`, `group_tutor` y `timetable_session`; cada una deja su `created` con actor el solicitante; y el origen no cambia.
- **`CA-ACAD-144`** (`RN-ACAD-153`) — *Dada* la misma copia con `copy_teaching_assignments` ausente o `false`, *entonces* el destino no tiene ninguna asignación docente, tutoría ni sesión; la simulación no lista los elementos del paquete; y `year_rollovers.copy_teaching_assignments` es `false`.
- **`CA-ACAD-145`** (`RN-ACAD-154`, `RN-CURSO-73`) — *Dado* el origen con uno de los tres usuarios desactivado (una asignación con dos sesiones y una tutoría suyas), *cuando* se simula con la casilla, *entonces* `executable: true`, `acad.teaching_assignments` con `to_copy: 3` y `skipped: [{reason: "teacher_inactive", count: 1}]`, `acad.timetable_sessions` con `skipped: [{reason: "teacher_inactive", count: 2}]` y `acad.group_tutors` con `skipped: [{reason: "tutor_inactive", count: 1}]`; *cuando* se ejecuta, `completado` con esas cuentas en `result` y sin esas filas en el destino. Ni la simulación ni el resultado contienen nombres, correos ni `public_id` de usuarios. *Y* un usuario **borrado** o **pendiente** se omite igual. *Y dado* el usuario reactivado entre la simulación y la ejecución, la ejecución lo copia.
- **`CA-ACAD-146`** (`RN-ACAD-155` punto 1) — *Dada* la casilla marcada y `acad.timetable_frames` sin seleccionar, *cuando* se copia, *entonces* `completado`, asignaciones y tutorías copiadas, y `acad.timetable_sessions` con `copied: 0` y `skipped: [{reason: "frames_not_copied", count: 6}]`; la simulación lo mostró igual y con `executable: true`.
- **`CA-ACAD-147`** (`RN-ACAD-155` punto 3) — *Dada* la sesión del origen en un aula dada de baja, *cuando* se copia con la casilla y los marcos, *entonces* la sesión copiada tiene `facility_id` y `facility_type` nulos, y `acad.timetable_sessions` lleva `adjusted: [{reason: "facility_removed", count: 1}]` en la simulación y en el resultado; las demás sesiones conservan su aula.
- **`CA-ACAD-148`** (`RN-ACAD-147`) — Tras `CA-ACAD-143` y `-145`, una consulta de comprobación cuenta **cero** violaciones en el destino de `RN-ACAD-94` (asignación única), de la unicidad de tutorías y de `RN-ACAD-123`/`-124` (conflictos de profesor, aula y grupo entre sesiones coincidentes).
- **`CA-ACAD-149`** (`RN-ACAD-150`, `-151`) — *Dada* la copia de `CA-ACAD-143`, *cuando* se revierte, *entonces* `revertido`, y sesiones, tutorías y asignaciones copiadas tienen `deleted_at`, con su `deleted` y actor el que pidió revertir, y se borraron antes que franjas, grupos y asignaturas (orden de `RN-ACAD-151`, comprobado por el orden de las entradas de `audit_logs`). *Dada* en cambio una sesión creada a mano que usa una asignación copiada, *entonces* `409 curso.conflict.rollover_revert_blocked` con `acad.rollover.revert_has_dependents` y `timetable_session` en `params.dependents`, y nada cambia; *y dada* una asignación creada a mano en un grupo copiado, el mismo `409` con `teaching_assignment`.
- **`CA-ACAD-150`** (`RN-ACAD-156`, `RN-CURSO-78`) — *Con* `apertura_curso_academico.crear` y los permisos de los nueve elementos pero sin `asignacion_docente.crear`, simular o confirmar con la casilla ⇒ `403 urn:pge:error:forbidden` con la entrada `curso.rollover.element_forbidden` (`params.element = "acad.teaching_assignments"`) en `errors.elements[]`, y nada creado; sin `asignacion_docente.actualizar`, la entrada de `acad.group_tutors`; sin `horarios.crear`, la de `acad.timetable_sessions` (y la de `acad.timetable_frames` si se seleccionó); sin la casilla, `200`/`202`. El catálogo marca esos elementos con `allowed: false`.
- **`CA-ACAD-151`** (`INV-001`, `RN-ACAD-157`) — *Con dos centros* A y B con usuarios y asignaciones, la copia en A no lee asignaciones, tutorías ni sesiones de B, y la consulta de usuarios activos solo recibe y devuelve identificadores de A (un `user_id` de B nunca aparece en el destino). La consulta de usuarios activos se hace **una** vez por `preview()` y una por `copy()` (registro de consultas).
- **`CA-ACAD-152`** (`INV-009`, `AR-12`) — `lang/{es,en,de,fr}/acad.php` tienen las mismas claves de §21.6 (etiquetas de los doce elementos, tres incidencias, tres motivos de omisión y uno de ajuste); `lint:i18n` limpio.
- **`CA-ACAD-153`** (Vitest, `REQ-CURSO/funcional.md §10.6`) — En el paso «Elementos» del asistente, la casilla «Copiar la asignación docente» aparece **desmarcada** por defecto y solo si el catálogo trae elementos del paquete; al marcarla se marcan «Asignaturas de cada grupo», «Subgrupos» y sus requisitos con un aviso textual; si alguno de los tres elementos del paquete llega con `allowed: false`, la casilla está deshabilitada con su motivo; el texto de ayuda dice que se omiten las personas que ya no están activas y que las sesiones solo se copian si se copian también los marcos horarios; la simulación muestra las omisiones y los ajustes por motivo.

### 21.6 Textos (`INV-009`)

En `lang/{es,en,de,fr}/acad.php`. Textos de partida no legales; los revisa `traductor` al implementar. **Alineados el 2026-10-20 (revisión de documentación, M-5) con la terminología que ya usan `lang/{es,en,de,fr}/acad.php`** (`entities.*`, `dependents.*`, `permissions.resources.*`): p. ej. en «Stages»/de «Stufen»/fr «Étapes» para la etapa, en «Academic periods»/de «Unterrichtszeiträume»/fr «Périodes scolaires» para los períodos, de «Stundenplanrahmen»/fr «Cadres d’emploi du temps» para los marcos, de «Lehraufträge»/fr «Affectations d’enseignement» para la asignación docente, de «Klassenleitungen»/fr «Professeurs principaux» para los tutores. Las escalas no tienen término previo.

| Clave | es | en | de | fr |
|-------|----|----|----|----|
| `acad.rollover.elements.education_levels` | Etapas | Stages | Stufen | Étapes |
| `acad.rollover.elements.courses` | Cursos de etapa | Courses | Jahrgänge | Niveaux |
| `acad.rollover.elements.groups` | Grupos | Groups | Gruppen | Groupes |
| `acad.rollover.elements.subgroups` | Subgrupos | Subgroups | Untergruppen | Sous-groupes |
| `acad.rollover.elements.subjects` | Asignaturas | Subjects | Fächer | Matières |
| `acad.rollover.elements.group_subjects` | Asignaturas de cada grupo | Subjects of each group | Fächer je Gruppe | Matières de chaque groupe |
| `acad.rollover.elements.academic_periods` | Períodos lectivos | Academic periods | Unterrichtszeiträume | Périodes scolaires |
| `acad.rollover.elements.grading_scales` | Escalas de calificación | Grading scales | Bewertungsskalen | Barèmes de notation |
| `acad.rollover.elements.timetable_frames` | Marcos horarios y franjas | Timetable frames and time slots | Stundenplanrahmen und Zeitfenster | Cadres d’emploi du temps et créneaux |
| `acad.rollover.elements.teaching_assignments` | Asignaciones docentes | Teaching assignments | Lehraufträge | Affectations d’enseignement |
| `acad.rollover.elements.group_tutors` | Tutores de grupo | Group tutors | Klassenleitungen | Professeurs principaux |
| `acad.rollover.elements.timetable_sessions` | Sesiones de horario | Timetable sessions | Unterrichtsstunden | Séances d’emploi du temps |
| `acad.rollover.target_not_empty` | El curso de destino ya tiene datos de «:element» (:count). Quítalos de la selección o bórralos antes. | The target year already has “:element” data (:count). Remove it from the selection or delete that data first. | Das Zielschuljahr enthält bereits Daten von „:element“ (:count). Entferne es aus der Auswahl oder lösche die Daten vorher. | L’année de destination contient déjà des données « :element » (:count). Retirez-le de la sélection ou supprimez-les d’abord. |
| `acad.rollover.dates_outside_target` | :count fechas de «:element» quedarían fuera del curso de destino (del :starts_on al :ends_on tras desplazarlas). | :count dates of “:element” would fall outside the target year (:starts_on to :ends_on after shifting). | :count Daten von „:element“ lägen außerhalb des Zielschuljahres (:starts_on bis :ends_on nach der Verschiebung). | :count dates de « :element » tomberaient hors de l’année de destination (du :starts_on au :ends_on après décalage). |
| `acad.rollover.revert_has_dependents` | Hay datos añadidos después de la copia que dependen de lo copiado (:count). | Data added after the copy depends on what was copied (:count). | Nach der Kopie hinzugefügte Daten hängen vom Kopierten ab (:count). | Des données ajoutées après la copie dépendent de ce qui a été copié (:count). |
| `acad.rollover.skipped.teacher_inactive` | Profesorado que ya no está activo | Teachers who are no longer active | Nicht mehr aktive Lehrkräfte | Enseignants qui ne sont plus actifs |
| `acad.rollover.skipped.tutor_inactive` | Tutores de grupo que ya no están activos | Group tutors who are no longer active | Nicht mehr aktive Klassenleitungen | Professeurs principaux qui ne sont plus actifs |
| `acad.rollover.skipped.frames_not_copied` | No se copian los marcos horarios | Timetable frames are not being copied | Stundenplanrahmen werden nicht kopiert | Les cadres d’emploi du temps ne sont pas copiés |
| `acad.rollover.adjusted.facility_removed` | Se copian sin aula (aula dada de baja) | Copied without a room (room withdrawn) | Ohne Raum kopiert (Raum außer Betrieb) | Copiées sans salle (salle retirée) |

Las claves de la casilla del asistente son de `curso` (`REQ-CURSO/api.md §5.1`).

### 21.7 Riesgos

| Riesgo | Severidad | Mitigación |
|--------|-----------|------------|
| **Se copia el vínculo persona ↔ docencia al curso siguiente** (dato personal del personal, `datos.md §9.6`) para personas que quizá no sigan en el centro | Media | Casilla desmarcada por defecto (decisión del usuario); omisión de inactivos (`RN-ACAD-154`); el asistente y el manual dicen que lo copiado es un punto de partida que el centro revisa; reversión mientras el destino esté en `planificacion` |
| **Sesiones insertadas sin la detección de conflictos** (`RN-ACAD-147`) | Media | Argumento por construcción (omitir o quitar aula no crea conflictos); `CA-ACAD-148` cuenta violaciones en el destino; las inserta un único camino bajo el ancla |
| **Ampliación de `Core`** (`RN-ACAD-157`, I-19, aprobada) | Baja | Operación aditiva de solo lectura; `CA-ACAD-151` prueba el aislamiento entre centros y la consulta única |
| **Volumen**: con la casilla, un centro de 40 grupos añade ≈ 1.200 sesiones y cientos de asignaciones a la transacción de la copia | Baja | Entra en la medición de `CA-CURSO-149` |

### 21.8 Decisiones fijadas al especificar

Numeradas a continuación de las de §19.1 y §20.1 (I-1 a I-18) para no colisionar; viven aquí porque son de `1.12b`, no de `1.12-a`.

| # | Decisión | Motivo |
|---|----------|--------|
| I-19 | **Segunda ampliación de `Core\Domain\UserDirectory`** (**aprobada por el usuario el 2026-10-20** antes de escribirla, `OPEN-CURSO-29-d`; no implementada). Operación nueva, aditiva y de solo lectura: **`public function activeIdsAmong(array $ids): array`**, con `@param list<int> $ids` y `@return list<int>`. Devuelve, sin repetidos y en orden ascendente, el subconjunto de `$ids` que son usuarios **del centro en curso** en estado **activo** (no inactivos, ni pendientes, ni borrados: el mismo criterio que `findActiveByPublicId()`). Los identificadores de otro centro o inexistentes no se devuelven ni producen error (RLS y `TenantScope`, `INV-001`). Lista vacía ⇒ lista vacía **sin consultar** la base de datos; si no, **una sola consulta** sin bloqueos de fila; sin memoización entre llamadas (el estado cuenta en el momento de la copia). La implementa `Core\Infrastructure`; la usan el participante del *rollover* (`RN-ACAD-154`, `-157`) y, desde la revisión de documentación del 2026-10-21, los trabajos en cola de `curso` para comprobar que el solicitante sigue activo (`REQ-CURSO RN-CURSO-83`) | `refsByIds()` no informa del estado y `findActiveByPublicId()` es por `public_id` y de uno en uno; sin esta operación el participante tendría que consultar `users` desde `acad` (`AR-01`). Mismo procedimiento que I-1 (§19.1) |
