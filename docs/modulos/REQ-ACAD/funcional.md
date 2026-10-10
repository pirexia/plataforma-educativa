# REQ-ACAD · Gestión académica: estructura académica · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-ACAD` (sección 5.4 del documento de requisitos). Esta especificación cubre **solo `REQ-ACAD-001`** (estructura académica). `REQ-ACAD-002` (horarios, paso `1.12`), `-003` (asistencia, `1.13`), `-004` (tutorías) y `-005` (exámenes) quedan fuera |
| Prioridad | MUST |
| Fase | 1 · paso **1.11** del plan y sub-paso nuevo **`1.11d`** (períodos lectivos y escalas, `OPEN-ACAD-01` = A, aprobada) |
| Depende de | Documento: `CORE`. Real: `CORE` (esencial) y `CURSO` (esencial; toda tabla de este módulo lleva `academic_year_id`). Aprobado: `depends_on: ['curso']` (`OPEN-ACAD-10`) |
| Esencial | Aprobado: **`essential: false`** (`OPEN-ACAD-10`) |
| Estado | **PROPUESTA** (2026-10-10). El usuario aprobó el 2026-10-10 las recomendaciones de `OPEN-ACAD-01` a `-23` (§15). `OPEN-ACAD-05` y `-08` se resuelven por **`ADR-062`** y **`ADR-063`**, ambos en estado PROPUESTA, con dos decisiones del usuario ya tomadas sobre ellos (`OPEN-062-01`: se exige al escribir el idioma por defecto del centro; `OPEN-063-01`: se fija ya el significado de `clase`). Falta la aprobación formal de esta especificación y la ratificación de los dos ADR |
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
| C2 | **«Grupos/clases» como sinónimos frente a dos ámbitos distintos**; `departamento` sin entidad | `REQ-ACAD-001`, `RPERM-004`, `ADR-044 §3` | **Resuelta por `ADR-063`** (PROPUESTA): `clase` = pares (grupo, asignatura) que el usuario imparte (`§5.3`, `OPEN-063-01` = fijarlo); `departamento` queda en el vocabulario sin dueño ni resolutor (`§5.5`) |
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
| **`1.11d`** (nuevo) | Períodos lectivos y escalas de calificación (puntos 4-5), §4.8-§4.9. **Obligación**: su especificación, al abrir el sub-paso, escribe su API y numera sus reglas (`RN-ACAD-50` en adelante) y criterios (`CA-ACAD-` a continuación del último de `1.11`, sin huecos) |
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
| Períodos lectivos y escalas de calificación | `REQ-ACAD-001` puntos 4-5 | División aprobada | `1.11d` |
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

- **`REQ-CURSO` está implementado** (1.10) salvo la reapertura (`1.11c`), que no afecta: la reapertura cambia `academic_years.status` y el disparador deja de bloquear (`RN-CURSO-46`).
- **Cambios en el módulo `curso`** aprobados para este paso: §1.2 (búsqueda por identificador interno, `GET /academic-years/selectable`, comprobación de `retired_at` en su comando de siembra). La sesión principal actualiza `docs/modulos/REQ-CURSO/` (no es carpeta de esta especificación).
- **`ADR-062` y `ADR-063` están en PROPUESTA**: la implementación de `1.11` no arranca hasta que se ratifiquen.
- **Primera tabla real con `academic_year_id`** del producto (si `1.11c` no se adelanta).

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

> **Obligación para `1.11d`** (§4.8 y §4.9): su especificación numera sus propias reglas y criterios de aceptación (`RN-ACAD-50` en adelante y `CA-ACAD-` a continuación del último de `1.11`, sin huecos), incluidos los de lectura denegada de curso cerrado por recurso (`RN-CURSO-33`), aislamiento, permisos de `configuracion_academica` y contenido traducible (`ADR-062`). Lo que sigue es la forma funcional aprobada, no especificación ejecutable.

### 4.8 Períodos lectivos — sub-paso `1.11d` (`OPEN-ACAD-17` = A + Y, aprobada)

1. Con `configuracion_academica.crear`, **por curso académico y etapa**, el centro define sus períodos: tipo (`trimestre`, `cuatrimestre`, `semestre`), posición, nombre traducible (`ADR-062`), inicio y fin.
2. Reglas: fechas dentro de las del curso académico; sin solape entre períodos de la misma etapa; **todos los períodos de una etapa del mismo tipo**.
3. `acad` es dueño de los períodos lectivos; `CALIF` define sus períodos de evaluación (incluidas final y extraordinaria) referenciándolos.
4. Se bloquean con el curso académico como cualquier dato por curso.

### 4.9 Escalas de calificación — sub-paso `1.11d` (`OPEN-ACAD-18` = A, aprobada)

1. Con `configuracion_academica.crear`, **por curso académico**, el centro define escalas: `numerica` (mínimo, máximo, decimales; «0-10» es una instancia), `cualitativa` (valores ordenados con código y etiqueta traducible) o `mixta` (numérica con tramos etiquetados). «Personalizable» = el centro crea las suyas.
2. **Cada escala declara qué es aprobado** (umbral numérico o valores cualitativos marcados como aprobado), porque `REQ-CURSO-003` y `REQ-CALIF-003` cuentan «suspensos».
3. Qué escala usa cada asignatura o etapa lo decide `1.16` (`CALIF`), su único consumidor.
4. Al cerrarse el curso académico, el disparador congela sus escalas.

---

## 5. Reglas de negocio

*Numeración por bloques de diez (precedente `REQ-CURSO`): `RN-ACAD-14`, `-18`, `-19`, `-22` a `-29` y `-33` a `-39` son **huecos intencionados**, reservados sin uso; no se renumera. `RN-ACAD-13` es regla común aunque su número caiga en el bloque de etapas: se añadió con `ADR-062` y no se movió para no cambiar referencias. Las reglas de `1.11d` las numerará su especificación (§4.8, §4.9).*

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
| `RN-ACAD-20` | No se borra una entidad con hijos vivos: etapa con cursos de etapa; curso de etapa con grupos o asignaturas; grupo con subgrupos o asignaturas asignadas; asignatura asignada a algún grupo ⇒ `409 acad.conflict.has_dependents` con `params.dependents` **[DERIVADA]** |
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

---

## 7. Interacción con otros módulos

### 7.1 Lo que consume

| De | Qué | Cómo |
|----|-----|------|
| `curso` | `AcademicYearContext`, `AcademicYearDirectory` (ampliado), `AcademicYearReadAccess`, `AcademicYearWriteGuard` (opcional), `AcademicYearStatus` | Interfaces de `Curso\Domain` |
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

### 7.3 Eventos

Ni emite ni consume en `1.11`.

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

---

## 11. Manual de usuario

`docs/manual-usuario/admin.md` (se actualiza): «Estructura académica» (etapas y régimen, cursos, grupos, subgrupos, asignaturas, asignación a grupos, nombres en varios idiomas, por qué no se mueve un grupo ni se borra algo con hijos, curso académico de solo lectura) y el selector. **`docs/manual-usuario/direccion.md`, `secretaria.md` y `docente.md` son ficheros nuevos** (no existen todavía): consulta de la estructura y uso del selector, según la siembra aprobada (`CLAUDE.md §6.4`).

---

## 12. Huecos de extensión que deja `1.11`

| Necesidad futura | Qué deja `1.11` | Coste después |
|------------------|-----------------|---------------|
| *Rollover* (`1.12b`) | Árbol con clave (curso académico, padre) y códigos estables | Contrato de copia en `Acad\Domain` |
| Matrícula (`1.15`) | `groups` con `UNIQUE (tenant_id, academic_year_id, id)` | `GroupDirectory` + vetos de borrado (`RN-ACAD-21`) |
| Profesorado y ámbitos | `group_subjects` (el par grupo × asignatura que define `clase`, `ADR-063 §5.3`) | **Asignación docente propia de `acad`**, por curso académico y protegida por `ADR-057`, + interfaz de pertenencia en `Acad\Domain` (`ADR-063 §5.2`). Los resolutores los registra **cada módulo dueño del recurso** |
| Capacidad/ratio | Nada | Columna anulable (*expand*) |
| Contenido traducible en otros módulos | Ayudante, validación, resolutor, desglose, `AR-16` y componente de `ADR-062` | Una línea en la migración y la regla común |

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
- **`CA-ACAD-008`** — *Para cada* entidad con hijos vivos (`RN-ACAD-20`), `DELETE` ⇒ `409 acad.conflict.has_dependents` con `params.dependents` y la fila no cambia; sin hijos vivos, `204`, `deleted_at` informado y entrada `deleted`.

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
- **`CA-ACAD-037`** (`ADR-063 §5.1`) — Ningún recurso de `acad` tiene ámbito distinto de `todos`; `acad` no registra nada en `ScopeResolverRegistry` ni entra en el mapa de `AR-10`, que sigue en verde sin ampliarse; conceder a un rol un permiso de `acad` con ámbito `grupo` o `clase` responde `422`.

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

---

## 15. Preguntas abiertas

**Todas resueltas** por decisión del usuario el 2026-10-10 (recomendación aceptada en cada una). `05` y `08` se resuelven además por `ADR-062` y `ADR-063`, en PROPUESTA hasta su ratificación.

| ID | Pregunta | Decisión aprobada |
|----|----------|-------------------|
| `OPEN-ACAD-01` | División de `REQ-ACAD-001` | **A**: `1.11` estructura + selector + obligaciones; `1.11d` períodos y escalas; competencias a `1.16` |
| `OPEN-ACAD-02` | Nombres y terminología | **A**: se conserva `Course`; desambiguación en el texto (§0.1). Sin ADR |
| `OPEN-ACAD-03` | ¿Etapas por curso académico? | **B**: por curso académico |
| `OPEN-ACAD-04` | Tipo de etapa y régimen | **A**: etapas del centro con `stage` cerrado de seis valores y `regime` obligatorio |
| `OPEN-ACAD-05` | Contenido del centro traducible | **A**, contrato en **`ADR-062`** (PROPUESTA): `jsonb` por campo con claves `es-ES`/`en`/`de`/`fr`; al escribir se exige el **idioma por defecto del centro** (`OPEN-062-01`); resolución en servidor con caída; `PATCH` por fusión; desglose por idioma en auditoría; `AR-16` |
| `OPEN-ACAD-06` | Subgrupos | **A**: un solo grupo; sin relación con asignaturas |
| `OPEN-ACAD-07` | Asignación de asignaturas a grupos | **A**: explícita |
| `OPEN-ACAD-08` | Ámbitos `grupo`, `clase`, `departamento` | **A**, contrato en **`ADR-063`** (PROPUESTA): `1.11` no registra resolutores; `clase` = pares (grupo, asignatura) que el usuario imparte (`OPEN-063-01`); `acad` aporta la pertenencia y cada dueño de recurso su resolutor; asignación docente candidata en `1.12` con plazo antes de `1.13`/`1.16`; `departamento` sin dueño |
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

---

## Anexo A · Resumen del contrato de `ADR-062` aplicado a `acad`

> Resumen operativo; la fuente es `ADR-062`. Si discrepan, manda el ADR.

1. **Columna**: `name_translations jsonb NOT NULL` en `education_levels`, `courses` y `subjects` (y `label_translations`/`name_translations` en las tablas de `1.11d`), creada por el ayudante de `TenantMigration`. `CHECK` en el motor: objeto, no vacío, claves ⊆ {`es-ES`, `en`, `de`, `fr`}, valores cadena. Sin columna `name` en la tabla.
2. **Validación** (servidor, `App\Support\I18n`): alta con la clave del `default_locale` del centro; claves en `active_locales`; valores recortados no vacíos. `PATCH` por fusión; quitar el idioma por defecto ⇒ `422`. Códigos `translations.default_locale_required`, `translations.locale_not_active`, `translations.empty_value`.
3. **API**: lectura con `name` (resuelto, `readOnly`) y `name_translations` (completo), siempre los dos; escritura solo de `name_translations`; `name` en el cuerpo se ignora.
4. **Resolución**: idioma pedido → `default_locale` → `es-ES` → primera clave en el orden `es-ES`, `en`, `de`, `fr`. En documentos, el idioma del destinatario. Ningún módulo resuelve por su cuenta.
5. **Auditoría**: desglose por idioma en `changes` (`name_translations.es-ES`, …), solo los idiomas que cambian; tope y redacción por entrada.
6. **No traducibles en `acad`**: `code` (todas las entidades) y `name` de grupo y subgrupo (texto simple).
7. **Orden y búsqueda**: ningún listado de `1.11` ordena por nombre ni tiene `q`; si se añadiera, `ADR-062 §5.4` puntos 3-4.
8. **SPA**: componente común de edición; presentación con `lang` cuando hay caída (WCAG 3.1.2).
