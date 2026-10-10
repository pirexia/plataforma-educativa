# ADR-063 · Ámbitos `grupo`, `clase` y `departamento`: no se registran en `1.11`; significado de `clase` y paso en que nace la asignación docente

**Estado**: **ACEPTADA** (2026-10-10; ratificada por el usuario con `OPEN-063-02` = sí). La decisión de fondo (no registrar ningún resolutor en `1.11`) la aprobó el usuario al aceptar las recomendaciones de la especificación de `1.11` (`OPEN-ACAD-08`, opción A). El usuario decidió el 2026-10-10 `OPEN-063-01` (fijar ya el significado de `clase`). Lo que este ADR somete a ratificación es la **aclaración de `ADR-044 §3`** (`§5.2`), la **definición de `clase`** (`§5.3`) y el **plazo** de `§5.4`.
**Fecha**: 2026-10-10
**Paso**: entrada de `1.11` (`REQ-ACAD`). Resuelve `OPEN-ACAD-08` de `docs/modulos/REQ-ACAD/funcional.md §15` y las contradicciones C2/C3 de su `§0.2`.
**Se apoya en**: `RPERM-004`, `§11.1` (rol «Tutor de grupo»); `INV-002`, `INV-007`, `INV-015`; `ADR-044 §1.2`, `§3`, `§4.1`, `§4.2`, `§4.8`, `§8`; `ADR-048` (sin consumidor no se construye); `REQ-ACAD-001`, `REQ-ACAD-002`, `REQ-ALUM-003`.
**Enmienda `ADR-044`**, sin editarlo y sin cambiar ninguna de sus decisiones: sustituye **solo** la frase de `§3` opción B «`REQ-ACAD` (1.11) registra `departamento`, `grupo` y `clase` cuando crea esas entidades» y las menciones derivadas de `§1.2` («no existirán hasta 1.11») y de la cabecera «Afecta a» («1.11–1.14 … registran ámbitos»), en lo que toca a `REQ-ACAD`. Lo que dice de `REQ-FAM-UNIT` (`unidad_familiar`, `1.14`) no cambia. El vocabulario cerrado (`§4.1`), el contrato de resolutor, el fallo en cerrado y la regla de absorción de `§4.8` siguen intactos.
**Afecta a**: `1.11` (no registra nada), al paso que cree la asignación docente (candidato `1.12`), a las especificaciones de `1.13` (asistencia) y `1.16` (calificaciones) y a todo módulo que exponga datos de alumnado por grupo.

---

## 1 · Contexto

`ADR-044 §3` eligió la opción B: el núcleo fija el vocabulario de seis ámbitos y los resolutores se registran cuando existe la entidad. Para tres de ellos encargó el trabajo a `REQ-ACAD` en `1.11`, «cuando crea esas entidades».

La especificación de `1.11` encontró que el encargo no se puede cumplir sin inventar requisitos:

1. **`grupo`** necesita saber qué grupos imparte o tutoriza un usuario. `REQ-ACAD-001` crea grupos, pero **no pide asignar profesorado ni tutor** a ninguno, aunque `§11.1` defina el rol «Tutor de grupo». En `1.11` no hay relación usuario ↔ grupo sobre la que resolver.
2. **`clase`** no está definida en ningún requisito. `REQ-ACAD-001` dice «grupos/clases» como sinónimos; `RPERM-004` los trata como dos ámbitos distintos.
3. **`departamento`** no tiene entidad en ningún requisito de `REQ-ACAD`.

### 1.1 Hechos verificados contra el código (sobre `3cbe5b0`)

| Premisa | Dato |
|---|---|
| Vocabulario | `App\Support\Authorization\Scope` tiene los seis casos (`todos`, `propios`, `departamento`, `grupo`, `clase`, `unidad_familiar`); `permission_role.scope` con `CHECK` sobre ellos |
| Contrato | `ScopeResolver` resuelve **un par (ámbito, recurso)**: `scope()`, `resource()` y `constrain(Builder $query, User $subject)`, que recibe la consulta **del recurso** |
| Registro | `ScopeResolverRegistry::register()`; conceder un ámbito sin resolutor para ese recurso responde `422`, y una fila así deniega |
| Único resolutor real | `propios` sobre `auditoria` (`Core`) |

El segundo hecho es el que obliga a aclarar `ADR-044 §3`, no solo a aplazarlo (`§5.2`).

---

## 2 · Qué NO decide este ADR

- **La forma de la asignación docente** (tablas, granularidad por subgrupo, sustituciones, cotutorías, histórico). La decide la especificación del paso donde nace (`§5.4`).
- **Qué permisos de otros módulos admiten `grupo` o `clase`.** Lo declara cada módulo en `applicable_scopes` (`ADR-044 §4.1`).
- **Quién es dueño de `departamento`.** Ningún requisito lo define; no se le asigna dueño (`§5.5`).
- **El orden de `1.13` y `1.15`** (`OPEN-ACAD-22`). Es decisión del usuario sobre el plan; este ADR solo fija un plazo que vale con cualquier orden.

---

## 3 · Opciones reales

| | Opción |
|---|---|
| **A** | `1.11` no registra ningún resolutor; todos sus permisos son `todos`. La asignación docente y lo que hace resolubles `grupo`/`clase` nacen en un paso posterior con plazo. `departamento` queda sin dueño |
| **B** | Añadir a `1.11` la asignación docente y los resolutores de `grupo` y `clase` |
| **C** | A, pero fijando ya el significado de `clase` |

## 4 · Evaluación

| Criterio | A | B | C |
|---|---|---|---|
| Coste en solitario | Nulo en `1.11` | Alto: tabla, pantallas, permisos y resolutores no pedidos por `REQ-ACAD-001`, en un paso que ya iguala a `1.10` | Nulo en código; una definición |
| Mantenimiento a 3 años | Bueno si el plazo se cumple | Malo: una asignación diseñada sin horarios (`REQ-ACAD-002`) que habrá que rehacer cuando lleguen | Bueno |
| Invariantes | `INV-002` intacto: un ámbito sin resolutor no se concede y deniega (`ADR-044 §3`) | Riesgo de `INV-015`: resolutores sin consumidor real que los ejercite | Igual que A |
| Reversibilidad | Alta | Baja: asignaciones sembradas en centros | Alta: una definición sin código se puede afinar antes de su primer uso |

**Se adopta A, con la parte de C que no inventa requisito**: el significado de `clase` es vocabulario central (`ADR-044 §4.1`) y dejarlo indefinido es lo que produjo C2; fijarlo **no** crea tablas, permisos ni resolutores. La forma de los datos sigue siendo del paso que los construya.

---

## 5 · Decisión

### 5.1 `1.11` no registra ningún resolutor

- Todos los permisos de `acad` en `1.11` declaran `applicable_scopes = ['todos']`. `acad` no entra en el mapa de `AR-10` ni registra nada en `ScopeResolverRegistry`.
- Es correcto y no un aplazamiento cómodo: la estructura académica es del centro entero y no contiene datos personales (`RN-ACAD-09`). Que un docente vea todos los grupos no expone a ningún alumno.
- Hasta que existan resolutores, un permiso de cualquier módulo con ámbito `grupo`, `clase` o `departamento` **no se puede conceder** (`422`) y, si existiera, **deniega**. Falla en cerrado, como `ADR-044 §3` previó.

### 5.2 Aclaración de `ADR-044 §3`: quién registra qué

`ADR-044 §3` decía que `REQ-ACAD` «registra» `grupo` y `clase`. Con el contrato construido en `1.5`, eso no es posible tal como está escrito: un resolutor se registra **por par (ámbito, recurso)** y su `constrain()` recibe la consulta **del recurso**, cuya tabla es de otro módulo (asistencia, calificaciones…). `acad` no puede escribir la restricción sobre tablas que no conoce sin violar `INV-007`.

Se aclara así, sin cambiar el contrato:

1. **`acad` aporta el dato y la pregunta**: la asignación docente (usuario ↔ grupo, usuario ↔ grupo × asignatura, tutor ↔ grupo) y una **interfaz pública en `Acad\Domain`** que responde, para un usuario, el conjunto de grupos y de pares (grupo, asignatura) que le corresponden. Nombre y firma, en la especificación del paso de `§5.4`.
2. **El módulo dueño de cada recurso registra el resolutor** del par (ámbito, su recurso), consumiendo esa interfaz. Ej.: asistencia registra (`grupo`, `asistencia`) y filtra sus filas por los grupos que devuelve `acad`.
3. **`acad` registra resolutores solo para sus propios recursos**, si alguno llega a admitir `grupo`/`clase` (hoy ninguno).

Es la lectura de «el módulo propietario de la entidad implementa el resolutor» (`ADR-044 §4.1`) que el contrato permite: el dueño de la entidad de ámbito (grupo) aporta la pertenencia; el dueño de la entidad consultada aporta la restricción.

**Consecuencia sobre el curso académico**: los grupos son por curso académico (`datos.md` de `REQ-ACAD`), así que «los grupos de este usuario» ya es una respuesta de un curso concreto. Un docente de «2º ESO A» en 2025-2026 no queda autorizado sobre el «2º ESO A» de 2026-2027, que es otra fila. La asignación docente será, por tanto, por curso académico y protegida por `ADR-057` como cualquier dato por curso.

### 5.3 Significado de `clase` frente a `grupo`

Para todo el producto, como vocabulario:

| Ámbito | Significado | Ejemplo |
|---|---|---|
| **`grupo`** | Los datos ligados a los **grupos** con los que el usuario tiene asignación en el curso académico: como tutor del grupo o como docente de **cualquier** asignatura en él | La tutora de 2º ESO A ve la asistencia de todo 2º ESO A, en todas las asignaturas |
| **`clase`** | Los datos ligados a los **pares (grupo, asignatura)** que el usuario imparte. Más estrecho que `grupo` en la práctica | El profesor de Matemáticas de 2º ESO A califica Matemáticas de 2º ESO A, y no Lengua de ese grupo |

Precisiones:

- «Clase» **no es una entidad** nueva ni un sinónimo de grupo: es el par grupo × asignatura, que en `1.11` ya existe como `group_subjects`. Si la asignación docente se hace por **subgrupo** (desdobles), su especificación dice cómo un subgrupo se proyecta sobre su grupo para ambos ámbitos; no se decide aquí.
- **Sin contención en el resolutor.** Que `grupo` «incluya» `clase` es intuitivo, pero `ADR-044 §4.8` admite una sola absorción (`todos`). Se mantiene: un rol con `grupo` no absorbe un `deny` ni un `clase` de otro; son dos restricciones que se unen con `OR` (`ADR-044 §4.2`). No se abre una retícula.
- En `REQ-ACAD-001`, «grupos/clases» sigue significando grupos (la entidad). El término «clase» como ámbito es el de esta tabla.

### 5.4 Dónde nace la asignación docente, y plazo

- **Candidato: `1.12` (`REQ-ACAD-002`, horarios).** Es el primer requisito que relaciona profesorado con grupos («conflictos de aulas, profesores, grupos», «vista por profesor»). La especificación de `1.12` **confirma o rechaza** el candidato de forma expresa; si lo rechaza, propone otro paso con el mismo plazo.
- **La asignación es entidad propia de `acad`**, no un derivado de las sesiones del horario: el tutor de un grupo no aparece en la rejilla, y una sustitución no debe cambiar quién califica. Las sesiones del horario referenciarán la asignación, no al revés. (Es restricción de forma para `1.12`, no diseño de tablas.)
- **Plazo vinculante**: la asignación docente y la interfaz de `§5.2` punto 1 deben estar **especificadas y aprobadas antes de aprobar la especificación de `1.13` o de `1.16`, la que llegue antes**, sea cual sea el orden final del plan (`OPEN-ACAD-22`). Son los dos primeros pasos en que un docente necesita ver «solo sus» datos de alumnos.
- **Mientras no exista**, ningún módulo con datos de alumnado sustituye la falta de resolutor dando `todos` a `docente` en la siembra predefinida como solución provisional. Un módulo que necesite `grupo`/`clase` lo declara ya en `applicable_scopes`; la concesión fallará en cerrado hasta que exista el resolutor, que es el comportamiento correcto.

### 5.5 `departamento`: sin dueño

- Ningún requisito define «departamento» (didáctico, de orientación…) ni su relación con usuarios o asignaturas. **No se asigna a `REQ-ACAD`**.
- El ámbito sigue en el vocabulario (quitarlo exigiría ADR y no hay motivo) y sigue sin resolutor: no se puede conceder.
- El primer requisito que defina departamentos fija su dueño en su especificación, con ADR si afecta al vocabulario o al contrato.

---

## 6 · Motivo

1. **Cumplir `ADR-044 §3` al pie de la letra en `1.11` exigiría inventar requisitos** (asignación docente, definición de departamento). B y la carga de `ADR-044` sobre `1.11` se escribieron cuando `REQ-ACAD` no estaba especificado.
2. **El contrato real (`ScopeResolver` por par ámbito × recurso) hace que `acad` no pueda «registrar `grupo`» para recursos ajenos.** Sin la aclaración de `§5.2`, el primer módulo con ámbito `grupo` descubriría el hueco al implementar.
3. **El fallo sigue siendo en cerrado**: aplazar los resolutores no abre ningún acceso.
4. **El riesgo real está en el plazo, no en el aplazamiento**: por eso `§5.4` lo ata a la aprobación de especificaciones concretas, no a una intención.

---

## 7 · Consecuencias

**Buenas**

- `1.11` mantiene su alcance; sin tablas ni pantallas que nadie pidió.
- `clase` deja de ser ambiguo antes de que lo use el primer permiso.
- El reparto «`acad` aporta pertenencia, el dueño del recurso aporta la restricción» vale igual para `unidad_familiar` (`REQ-FAM-UNIT` aportará la pertenencia; expediente, calificaciones… sus restricciones), aunque este ADR no lo impone a `1.14`.

**Malas, asumidas**

- **Entre `1.11` y el paso de `§5.4`, un docente no puede tener acceso restringido a sus grupos en ningún módulo.** Sin centros con datos reales antes de H0 (`ADR-030`), el coste es de calendario, no de exposición.
- **Cada módulo dueño de un recurso con `grupo`/`clase` escribe su propio resolutor** (`§5.2` punto 2). Es más código que un resolutor único de `acad`, pero es el que el contrato y `INV-007` permiten. Mitigación: la interfaz de `acad` devuelve conjuntos de identificadores ya preparados para un `whereIn`, de modo que cada resolutor sea de pocas líneas, y `AR-10` sigue vigilando el uso de `ScopedQuery`.
- **Riesgo de que el plazo se incumpla en silencio.** Mitigación: `§5.4` queda como entrada obligatoria de las especificaciones de `1.12`, `1.13` y `1.16`; la sesión que abra cualquiera de ellas debe leerlo.

---

## 8 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **B** · Asignación docente y resolutores en `1.11` | Alcance no pedido por `REQ-ACAD-001`; una asignación diseñada antes de los horarios se rehace en `1.12` |
| Dejar `clase` sin definir hasta su primer uso (A literal) | Es vocabulario central; dejarlo abierto es lo que produjo C2, y la definición no crea código |
| `clase` como sinónimo de `grupo` | Haría redundante un ámbito del vocabulario cerrado y dejaría sin expresión el caso de `REQ-CALIF` (el profesor de una asignatura no califica las demás) |
| Retirar `clase` o `departamento` del vocabulario | Exige ADR y migración del `CHECK`; no hay motivo mientras deneguen sin resolutor |
| Resolutor único de `grupo` en `acad` para todos los recursos | `constrain()` recibe la consulta del recurso; `acad` tendría que conocer las tablas de los demás módulos (`INV-007`) |
| Derivar la asignación docente de las sesiones del horario | El tutor no está en la rejilla, y una sustitución puntual cambiaría quién califica |
| Conceder `todos` a `docente` en la siembra de los módulos de alumnado mientras no haya resolutor | Abre en silencio el acceso que el ámbito debía cerrar; es exactamente el fallo que `ADR-044 §1.2` prohibió repetir |

---

## 9 · Ajustes que la especificación de `REQ-ACAD` debe absorber (no los edita `architect`)

1. `funcional.md §0.2` C2 y C3: remitir a este ADR como resolución.
2. `funcional.md §1.3` (fila «Resolutores de ámbito…» y fila «Asignación de profesorado…»): «Dónde se retoma» = `ADR-063 §5.4` (candidato `1.12`, plazo antes de `1.13`/`1.16`).
3. `funcional.md §12` (fila «Profesorado y ámbitos»): coste después = asignación docente propia de `acad` + interfaz en `Acad\Domain` (`ADR-063 §5.2`); los resolutores los registra cada módulo dueño del recurso.
4. `funcional.md §14` (riesgo «Ámbitos `grupo`/`clase` sin resolutor»): mitigación = plazo de `ADR-063 §5.4`.
5. `funcional.md §15` `OPEN-ACAD-08`: resuelta por este ADR.
6. `permisos.md §3.1` punto 3: sustituir «No hacerlo es apartarse de ese ADR» por la referencia a `ADR-063`, que enmienda `ADR-044 §3`; punto 2: `clase` definida en `ADR-063 §5.3`; `departamento` sin dueño (`§5.5`).
7. `funcional.md §7.2`: añadir como interfaz futura la de `§5.2` punto 1 (primer consumidor: el módulo de `1.13` o `1.16`).

Fuera de `REQ-ACAD`, para la sesión principal: `PLAN-IMPLEMENTACION.md` debería anotar en `1.12` la obligación de `§5.4` y en `1.13`/`1.16` el plazo, como se hizo con las obligaciones heredadas de `1.10`.

---

## 10 · Preguntas abiertas (las decide el usuario)

| ID | Pregunta | Recomendación |
|---|---|---|
| `OPEN-063-01` | ¿Se acepta fijar ya el significado de `clase` (`§5.3`), que va un paso más allá de la letra de la opción A aprobada («se define entonces o se descarta»)? | **Sí**: es vocabulario, no crea código ni tablas, y evita que `1.12` y `1.16` lo interpreten distinto |
| `OPEN-063-02` | ¿Se acepta la aclaración de `§5.2` (resolutor registrado por el dueño del recurso, pertenencia aportada por `acad`)? | **Sí**: es la única lectura compatible con el contrato `ScopeResolver` ya construido |
