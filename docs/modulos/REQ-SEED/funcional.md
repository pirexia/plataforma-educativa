# REQ-SEED · Datos de demostración · Funcional

| Campo | Valor |
|-------|-------|
| Código | `REQ-SEED` (`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §5.53`) |
| Paso del plan | `1.15b`, habilitante. Va después de `1.15a` (los centros de demostración nacen con la capa visual nueva) |
| Prioridad | MUST (`ADR-030`: sin datos sintéticos no se puede desarrollar) |
| Fase | 1 |
| Depende de | Según el requisito: `CORE`, `CURSO`, `ALUM`, `RRHH`. **`RRHH` no está implementado** (§0.2). Dependencias reales de `1.15b`: `Core`, `Auth`, `Curso`, `Acad`, `Alum`, `FamUnit`, `Prl` y `Backoffice` (aprovisionamiento) |
| Esencial | No aplica: se propone que no sea un módulo del catálogo (`OPEN-SEED-01`) |
| Naturaleza | Herramienta interna. **No se despliega en producción** (`§5.53`) |
| Estado | PROPUESTO (2026-11-12). Ninguna pregunta de §13 está decidida |
| **Clasificación del paso** | **crítico** las tres entregas (`ADR-069 §4.2`), motivo por entrega en §11 |
| Decisiones vinculantes de partida | `ADR-020`, `ADR-029`, `ADR-030`, `ADR-033`, `ADR-034`, `ADR-035`, `ADR-039`, `ADR-045`, `ADR-048`, `ADR-057`, `ADR-060`, `ADR-066`, `ADR-068`, `ADR-076`. En `PROPUESTA` y no leídos (§15): `ADR-077` (purga de tenant) y `ADR-080` (staging) |

## 0. Resumen y avisos previos

### 0.1 Qué es

Un generador de consola que crea tres centros ficticios completos con datos sintéticos, de forma reproducible a partir de una semilla, para desarrollar, medir rendimiento, probar permisos y aislamiento con casos verosímiles y hacer demostraciones. Se construye **por bloques**: `1.15b` entrega los bloques de los módulos que existen hoy y cada paso posterior del plan añade el suyo (§6.3).

Precedentes que sustituye o absorbe:

- `seed/` (Python, biblioteca estándar): genera JSON, no escribe en la base. Contiene la lógica de dominio y los catálogos que se portan (`seed/README.md`, «Paso a Laravel»). Su destino tras `1.15b` es `OPEN-SEED-16`.
- `apps/api/tests/Support/e2e-real-tenant.php`: crea centros de usar y tirar para el E2E real y el escaparate de la landing. **No se sustituye**: sigue siendo el soporte del E2E. `1.15b` le hace un único cambio: marcar como sintéticos los centros que crea (`RN-SEED-08`).

### 0.2 Avisos que el usuario debe conocer antes de aprobar

1. **Dependencia no implementada: `RRHH`.** `REQ-SEED-003` pide plantilla completa con jornadas, contratos y personal de empresa externa. No existe `Employee` ni nada de `REQ-RRHH` (fase 2). En `1.15b` solo se puede representar al personal como persona con cuenta y rol predefinido. La parte de plantilla que exige `RRHH` se difiere a su bloque (`OPEN-SEED-06`, bloqueante de alcance).
2. **Contradicción entre `REQ-SEED-005` y una regla ya implementada.** `REQ-SEED-005` exige documentos de identidad con dígito de control deliberadamente incorrecto. `Core` valida el dígito de control de `dni`/`nie` (`RN-CORE-90` a `-93`, `PersonDocumentRules`) en el alta y **revalida el par resultante en cada edición**. Un documento con dígito inválido solo puede escribirse por debajo de esa validación, y la persona resultante no se podría editar desde la aplicación (cualquier `PATCH` devolvería `422`). El precedente E2E lo esquivó con `pasaporte` ficticio (`REQ-PRL/operacion.md`). **No lo resuelvo**: `OPEN-SEED-03`, bloqueante.
3. **`REQ-SEED-004` casi entero queda fuera de `1.15b`** porque sus módulos no existen: asistencia (`1.13`), calificaciones (`1.16`), convivencia, comunicaciones (`1.19`), transporte, cuotas, becas y facturación. También NEAE (`REQ-SEED-002`): no hay tabla de categoría especial y `AR-09` es un cable trampa. Entran con el bloque de cada módulo (§6.3). El paso `1.15b` del plan dice «datos operativos»: con lo construido hoy, el único dato operativo disponible son horarios y asignación docente (`Acad`) y, si se aprueba, movimientos de puerta (`OPEN-SEED-13`).
4. **No existe hoy ninguna marca de «sintético» en el esquema** (búsqueda en `apps/api` sin resultados). `REQ-SEED-005` y la guarda de `REQ-SEED-006` («detecta datos no sintéticos») la necesitan: `OPEN-SEED-02`, bloqueante, toca la tabla de plataforma `tenants`.
5. **No hay purga física de un centro en la aplicación.** Las tablas con `tenant_id` no tienen cascada y el rol de plataforma no puede borrar de las de solo anexar; el E2E purga con el superusuario del contenedor de desarrollo desde un guion de *shell*. El «comando complementario de purga» de `REQ-SEED-006` depende de esa decisión (`ADR-077`, en `PROPUESTA`): `OPEN-SEED-04`, bloquea solo la entrega `1.15b-c`.

## 1. Alcance

### 1.1 Entra en `1.15b`

| Requisito | Qué entra | Entrega |
|---|---|---|
| `REQ-SEED-001` | Tres perfiles de centro (concertado con 0-3 privado, público, privado), cada uno con subdominio, color de marca, curso y calendario, y módulos contratados distintos | `-a` |
| `REQ-SEED-002` | 300-1.200 alumnos por centro, distinto en cada uno; reparto por etapa, curso y líneas; edades coherentes y repetidores; altas y bajas a mitad de curso; unidades familiares variadas, custodia compartida y restricción judicial; consentimiento de imagen en los tres estados; idioma preferido entre los cuatro | `-b` |
| `REQ-SEED-003` | Personal **con cuenta**: equipo directivo, docentes (tutores y especialistas), administración y conserjería, con los roles predefinidos existentes | `-a` |
| `REQ-SEED-004` | Horarios y asignación docente (`Acad`). Movimientos de puerta, si se aprueba (`OPEN-SEED-13`) | `-a`, `-b` |
| `REQ-SEED-005` | Convención de datos sintéticos completa, con verificación automática (§4.3) | `-a`, `-b` |
| `REQ-SEED-006` | Comando con parámetros, semilla reproducible, bloqueo en producción, ejecución por lotes en cola con progreso, purga, base de las mediciones | `-a`, `-b`, `-c` |

### 1.2 No entra (y por qué)

| Qué | Motivo | Cuándo |
|---|---|---|
| Jornadas, contratos, personal subcontratado, puestos sin cuenta (cocina, limpieza, mantenimiento, monitores, enfermería) | Exige `REQ-RRHH` | Bloque `rrhh` |
| NEAE en tabla separada | No existe la tabla ni el mecanismo de categoría especial (`ADR-044 §4.4`) | Bloque `neae` |
| Asistencia de un trimestre | `1.13` sin implementar | Bloque `asistencia` |
| Calificaciones de una evaluación | `1.16` sin implementar | Bloque `calif` |
| Convivencia, comunicaciones | Sin implementar | Bloques `conv`, `com` |
| Transporte, cuotas 0-3, becas, facturación, IBAN de prueba | `REQ-TRAN`, `REQ-FIN`, `REQ-BEC` en fase 2 | Bloques propios |
| Cualquier *endpoint* HTTP, pantalla o permiso | El requisito pide un comando de consola. Lanzar el generador desde el backoffice no está en el documento | No previsto |
| Pruebas de carga (`RNF-PERF-008`) | El generador es su base, no las pruebas | Paso propio |
| Datos reales, importados o anonimizados | Prohibido (`ADR-030`, `CLAUDE.md §11`). La anonimización de una copia real no es un dato sintético | Nunca |

## 2. Actores y roles implicados

- **Operador de consola**: quien ejecuta el comando dentro del contenedor de la API en un entorno permitido. No es un usuario de la aplicación; no hay sesión ni permiso (`permisos.md`).
- **Trabajador de cola `worker-long`**: ejecuta los lotes.
- **Cuentas sintéticas** de cada centro: reciben roles predefinidos (`permisos.md §3`). Son el resultado, no actores del generador.

## 3. Flujos principales

### 3.1 Generar (`demo:seed`)

1. El operador ejecuta el comando con perfiles, semilla, volumen y bloques (`api.md §2`).
2. **Guardas** (`RN-SEED-01` a `-07`), antes de cualquier escritura. Si alguna falla, el comando termina con código distinto de cero, un mensaje que dice qué guarda falló, y no escribe nada.
3. Se valida la entrada completa (perfiles conocidos, bloques conocidos y con sus dependencias, semilla entera, slugs libres, contraseña conforme a `RN-SEED-30`). Un error aquí tampoco escribe nada.
4. Se crea la ejecución (`seed_runs`, estado `pendiente`) con sus parámetros, y un registro por centro y por bloque (`datos.md`).
5. Por cada perfil, el bloque `centros` crea el centro por la superficie pública de aprovisionamiento (`ADR-048`), **marcado como sintético en la misma operación** (`RN-SEED-09`), y contrata sus módulos.
6. Se encolan los lotes de los demás bloques en orden de dependencias (§6.2). Cada lote fija el contexto del centro, repite las guardas, escribe en una transacción y anota su resultado y sus recuentos.
7. El comando informa del identificador de la ejecución y termina; el progreso se consulta con `demo:status`. Con la opción síncrona (`OPEN-SEED-15`) espera y muestra el progreso.
8. Al completarse todos los bloques de todos los centros, la ejecución pasa a `completada`, se calcula la huella del conjunto (`RN-SEED-23`) y se ejecuta el verificador de la convención sintética (§4.3). Si el verificador falla, la ejecución queda `fallida`.

### 3.2 Consultar progreso (`demo:status`)

Muestra, por ejecución, centro y bloque: estado, lotes hechos y totales, recuentos, duración y último error. Solo lectura.

### 3.3 Ampliar (`demo:seed --blocks=…` sobre una ejecución existente)

Cuando un paso posterior añade un bloque, se ejecuta ese bloque sobre los centros de una ejecución `completada`, sin regenerar lo anterior. La semilla de un bloque se deriva de la semilla de la ejecución, del perfil y del código del bloque (`RN-SEED-21`), de modo que añadir un bloque no cambia los datos de los ya generados.

### 3.4 Purgar (`demo:purge`)

1. Guardas (las mismas; además `RN-SEED-40` a `-43`).
2. El operador indica una ejecución o un centro y confirma escribiendo su slug.
3. Se retiran las filas de todas las tablas con `tenant_id` de ese centro, los objetos de su prefijo en el almacén, sus claves de caché y la fila de `tenants`. El mecanismo es `OPEN-SEED-04`.
4. Se anota la purga en la ejecución (`purgada`) con recuentos por tabla.

### 3.5 Medir (§10)

No es un flujo del generador: es el uso del conjunto generado para las mediciones pendientes de pasos anteriores.

## 4. Reglas de negocio

### 4.1 Bloqueo y seguridad (`REQ-SEED-006`)

- **RN-SEED-01** El generador y la purga solo se ejecutan si el entorno de la aplicación está en una **lista cerrada de entornos permitidos**. Se comprueba por lista de permitidos, no por lista de prohibidos: `production`, un valor desconocido o vacío se rechazan igual. Lista: `local` y `testing`; `staging` solo si se aprueba (`OPEN-SEED-05`).
- **RN-SEED-02** No existe parámetro, variable de entorno, opción ni confirmación interactiva que permita saltar `RN-SEED-01` ni `RN-SEED-03`. Un test lo comprueba sobre la firma de los comandos y sobre el comportamiento (`CA-SEED-003`).
- **RN-SEED-03** El generador se niega a ejecutarse si detecta **datos no sintéticos** en la base. Definición propuesta (`OPEN-SEED-07`): existe al menos un centro sin la marca de sintético. El mensaje dice cuántos y sus slugs, y no escribe nada.
- **RN-SEED-04** El código del generador **no forma parte de la imagen de producción**, si se aprueba `OPEN-SEED-01` = A: en producción el comando no existe. Las guardas en ejecución se mantienen igualmente (defensa en profundidad): la ausencia del código no sustituye a `RN-SEED-01`.
- **RN-SEED-05** Solo se ejecuta por consola. No hay ruta HTTP, tarea programada ni disparador que lo invoque.
- **RN-SEED-06** Las guardas se evalúan en el comando **y al comienzo de cada lote en cola**. Un lote que no las supera se marca `fallido` y no escribe.
- **RN-SEED-07** Las guardas se evalúan antes de leer cualquier otro parámetro que pueda escribir, y antes de `platform:sync-registry` o cualquier otra orden con efectos.
- **RN-SEED-08** `e2e-real-tenant.php` marca como sintéticos los centros que crea (modos normal, `door`, `alum` y `showcase`). Sin esto, `RN-SEED-03` bloquearía el generador en cualquier base de desarrollo con un centro de E2E. Es el único cambio a ese guion.
- **RN-SEED-09** La marca de sintético se fija **al crear el centro** y no se puede cambiar después, ni de sintético a real ni al revés (`datos.md §1.1`). Ninguna pantalla ni *endpoint* del backoffice la escribe.
- **RN-SEED-10** El generador no envía correos, SMS ni notificaciones, ni encola trabajos de otros módulos que los envíen. Las cuentas nacen activas sin invitación. Un test lo comprueba con el correo simulado (`CA-SEED-014`).
- **RN-SEED-11** El generador no hace ninguna petición de red saliente (ni servicios de nombres, ni imágenes, ni generadores externos).

### 4.2 Aislamiento y escritura (`INV-001`, `INV-003`, `INV-007`)

- **RN-SEED-12** Todo dato de un centro se escribe dentro del contexto de ese centro (`TenantContext::runFor`) y por la **conexión de tenant**, con RLS activa. El generador no usa el rol propietario ni un superusuario para escribir datos de negocio. Únicas escrituras de plataforma: crear el centro, sus suscripciones de módulo y las tablas `seed_*`.
- **RN-SEED-13** Un lote pertenece a un único centro. Ningún lote, transacción ni consulta del generador abarca dos centros.
- **RN-SEED-14** La escritura va por los **modelos y factorías** de cada módulo (`database/factories/…`, que `REQ-ALUM funcional.md §7.5` dejó preparadas) o por sus servicios de dominio, de modo que los observadores de auditoría (`INV-003`) y los disparadores (`academic_year_write_guard`, tablas de solo anexar) actúan como en una escritura normal. Prohibido el SQL de inserción masiva que los salte. Qué bloque usa servicio y cuál factoría lo fija el briefing de `explorer` de cada tramo; el criterio es: servicio de dominio cuando exista y no envíe comunicaciones; factoría en caso contrario.
- **RN-SEED-15** Toda escritura del generador queda en `audit_logs` del centro como cualquier otra, con el `actor_type` de `OPEN-SEED-14` (propuesto: `console`, valor ya existente en `ADR-039`). El generador no añade valores al vocabulario de auditoría.
- **RN-SEED-16** La ejecución, sus parámetros, su resultado, sus recuentos y su purga quedan registrados en `seed_runs` (`datos.md`): es el rastro de qué hizo el generador, cuándo y con qué semilla.
- **RN-SEED-17** Un curso académico cerrado no se escribe (`ADR-057`). Si un bloque futuro necesita histórico de cursos cerrados, crea los datos con el curso activo y lo cierra después por el flujo de `REQ-CURSO`; nunca siembra como propietario. En `1.15b` solo se genera el curso activo (`OPEN-SEED-17`).
- **RN-SEED-18** El generador vive fuera de `app/Modules` y no añade ninguna excepción a las listas cerradas de los tests de arquitectura. Si un tramo necesitara ampliarlas, para y lo reporta.

### 4.3 Convención de datos sintéticos (`REQ-SEED-005`)

- **RN-SEED-19** Convención, verificada por un verificador automático que se ejecuta al final de cada ejecución y en la suite (porta `seed/verificar.py`):

| Elemento | Regla | Comprobación |
|---|---|---|
| Centros | Nombre explícitamente ficticio (`Colegio Demo Uno`, `CEIP Demo Dos`, `Colegio Demo Tres`); slug con prefijo reservado `demo-` | Nombre y slug en lista cerrada |
| Personas | Nombres y apellidos de catálogos propios con localización española (portados de `seed/catalogos.py`) | Ninguno fuera del catálogo |
| Correos | Solo `@example.com` y `@example.org`, de acceso y de contacto | Cero filas fuera de esos dominios |
| Teléfonos | Rangos no asignables a personas reales. **El rango concreto se verifica con fuente primaria antes de implementar** (§15) | Todos dentro del rango |
| Documentos de identidad | Según `OPEN-SEED-03` | Ningún `dni`/`nie` con dígito de control válido |
| Direcciones | Vías inexistentes en municipios reales | Vía de catálogo ficticio |
| Fotografías | Ninguna, o marcadores sin rostro generados por el propio generador (`OPEN-SEED-12`). Nunca fotos de personas reales ni de bancos de imágenes | Ningún fichero de imagen de origen externo en el repositorio |
| Fechas de nacimiento | Sintéticas, coherentes con el curso | Edad dentro del rango del curso, salvo repetidores |
| Marca | Todo registro generado es distinguible como sintético (`OPEN-SEED-02`) | Todo centro generado lleva la marca |

- **RN-SEED-20** Datos de menores (`INV-008`): no hay interesado real, así que no hay tratamiento de datos personales. El generador sí crea, coherentes, los registros que los módulos exigen para un menor (vínculos de tutor con representación legal verificada, decisiones de consentimiento de imagen), porque sin ellos el dato no sería verosímil ni probaría las reglas. Ninguna de esas filas es un consentimiento real y los textos legales de demostración lo dicen de forma visible (`OPEN-SEED-11`).

### 4.4 Reproducibilidad (`REQ-SEED-006`)

- **RN-SEED-21** Una única semilla entera por ejecución. De ella se deriva una subsemilla por (perfil, bloque, lote). Ningún generador de números aleatorios se comparte entre lotes: el orden en que la cola ejecute los lotes no cambia el resultado.
- **RN-SEED-22** Ninguna fuente de azar fuera de la semilla: ni reloj, ni azar del sistema, ni orden de filas sin `ORDER BY`. Las fechas del conjunto son relativas al **curso académico de referencia**, que es un parámetro de la ejecución y se guarda con ella; no a la fecha del día.
- **RN-SEED-23** «El mismo conjunto» se define por una **huella**: resumen criptográfico de una exportación canónica y ordenada de los datos de negocio generados, **excluidos** identificadores internos, `public_id`, marcas de tiempo de sistema, *hashes* de contraseña y secretos. Misma semilla, mismos perfiles, mismo volumen, mismo curso de referencia y misma versión del generador producen la misma huella. La huella se guarda en `seed_runs`.
- **RN-SEED-24** El generador tiene una **versión** propia, que se guarda en cada ejecución. Un cambio que altere los datos de un bloque existente para la misma semilla sube la versión y se anota en `CHANGELOG.md`.
- **RN-SEED-25** Los catálogos (nombres, vías, materias por etapa) son ficheros de datos del propio generador, no los de una biblioteca externa cuyo contenido cambie entre versiones. Si se usa una biblioteca de datos ficticios, solo como apoyo y nunca como fuente de lo que entra en la huella.

### 4.5 Perfiles y volumen (`REQ-SEED-001`, `-002`)

- **RN-SEED-26** Tres perfiles, definidos en el código del generador (`OPEN-SEED-19`):

| Clave y slug | Nombre | Régimen | Particularidad |
|---|---|---|---|
| `demo-concertado` | Colegio Demo Uno | Concertado | Etapas obligatorias concertadas; primer ciclo de Infantil 0-3 en régimen privado (`ADR-020`) |
| `demo-publico` | CEIP Demo Dos | Público | Sin facturación de enseñanza |
| `demo-privado` | Colegio Demo Tres | Privado | Todas las etapas |

  El régimen por etapa se escribe solo si el modelo de `Acad` lo tiene hoy (§15); si no, queda para el bloque que lo introduzca y se dice en el informe del tramo.
- **RN-SEED-27** Cada centro tiene un conjunto de módulos contratados distinto, para probar `RMOD-008` y `RMOD-009`. Reparto propuesto con los módulos no esenciales existentes: `OPEN-SEED-08`. Las suscripciones las escribe la conexión de plataforma con motivo (`RMOD-002`), respetando `depends_on`.
- **RN-SEED-28** Número de alumnos por centro: entero en [300, 1.200] derivado de la semilla, **distinto en los tres**. Volúmenes: `full` usa todo el rango; `reduced` (día a día, `ADR-030`) acota a la parte baja del rango, sin bajar de 300 [DERIVADA: el requisito fija 300 como mínimo y `ADR-030` pide 300 para el día a día]. Un volumen de medición por encima de 1.200 es `OPEN-SEED-10`.
- **RN-SEED-29** Distribuciones mínimas que todo conjunto contiene, sea cual sea la semilla (lo comprueba el verificador): al menos un repetidor por etapa con repetición posible; altas y bajas a mitad de curso; unidades biparentales, monoparentales, con hermanos y con custodia compartida; **al menos un caso con restricción judicial de acceso** por centro (`REQ-FAM-UNIT-002`); los tres estados de consentimiento de imagen en ambos niveles; los cuatro idiomas preferidos.

### 4.6 Cuentas y contraseñas

- **RN-SEED-30** Ninguna contraseña de una cuenta sintética está escrita en el repositorio, ni en código, ni en documentación, ni en ejemplos de `.env`. Origen, por entorno (`OPEN-SEED-05`):
  - `local` y `testing`: si no se define la variable de `operacion.md §1`, el generador crea una contraseña aleatoria por ejecución y la muestra **una vez** por la salida estándar. No se guarda en `seed_runs` ni en ningún registro.
  - cualquier otro entorno permitido: la variable es **obligatoria** y viene del gestor de secretos; si falta, el comando se niega. Nunca hay valor por defecto.
- **RN-SEED-31** La contraseña cumple `PasswordPolicy` (`REQ-AUTH`, la interfaz se expuso para esto). Una que no la cumpla se rechaza antes de escribir nada.
- **RN-SEED-32** La salida del comando nunca imprime una contraseña que venga de la variable de entorno.
- **RN-SEED-33** MFA de las cuentas cuyo rol lo exige (`REQ-AUTH-003`): `OPEN-SEED-09`. Propuesto: exención temporal solo en `local`/`testing`, como hace el E2E; en cualquier otro entorno, ninguna exención.
- **RN-SEED-34** Las cuentas sintéticas reciben solo roles predefinidos (`permisos.md §3`). Ninguna recibe un permiso de categoría especial.

### 4.7 Ejecución (`INV-012`)

- **RN-SEED-35** Los bloques se ejecutan por lotes en la cola `long` (`worker-long`, `retry_after` de 900 s). El tamaño de lote se fija para que un lote termine muy por debajo de ese plazo en el equipo de desarrollo; se calibra en el primer tramo y se documenta en `operacion.md`.
- **RN-SEED-36** Un lote es atómico: sus datos y su anotación de «hecho» se confirman en la misma transacción. Reintentar un lote ya hecho no duplica nada; reintentar uno fallido parte de cero.
- **RN-SEED-37** El progreso es visible en todo momento (`demo:status`), por centro y bloque.
- **RN-SEED-38** Una ejecución fallida se puede reanudar: se reencolan solo los lotes no hechos, con las mismas subsemillas.
- **RN-SEED-39** Todo texto que el comando muestra pasa por el sistema de traducción (`INV-009`), en los cuatro idiomas, con el idioma de consola por defecto del proyecto. El contenido del centro traducible (`ADR-062`) se genera en los idiomas activos del centro.

### 4.8 Purga

- **RN-SEED-40** Solo se purga un centro que cumpla **las tres** condiciones: marca de sintético, slug con prefijo `demo-` y estar registrado en `seed_run_tenants` como creado por el generador. Precedente: la triple marca del escaparate en `e2e-real-tenant.php`.
- **RN-SEED-41** La purga nunca se ejecuta sin que el operador escriba el slug del centro. No hay purga «de todo» sin enumerar.
- **RN-SEED-42** La purga valida el `tenant_id` y el slug antes de usarlos y nunca construye SQL por concatenación (`CLAUDE.md §8`).
- **RN-SEED-43** Tras purgar se verifica que no queda ninguna fila con ese `tenant_id` en ninguna tabla que lo tenga, ni objetos bajo su prefijo, y el resultado se anota en la ejecución.

## 5. Casos límite y errores

| Caso | Comportamiento |
|---|---|
| Entorno no permitido | Código de salida distinto de cero, mensaje, cero escrituras (`RN-SEED-01`) |
| Existe un centro sin marca de sintético | Igual, con el recuento y los slugs (`RN-SEED-03`) |
| Ya existe un centro con el slug de un perfil | No se reutiliza ni se toca; el comando se niega y sugiere `demo:purge` si lo creó el generador |
| Módulo de un bloque no contratado en ese centro | El bloque se omite en ese centro y queda `omitido`, no `fallido` |
| Bloque pedido sin su dependencia | Rechazo en validación, antes de escribir |
| Sin trabajador de cola en marcha | El comando lo avisa (`core:queue-health` ya detecta la cola sin consumidor); la ejecución queda `pendiente` |
| Lote que supera el plazo de la cola | Se reintenta entero (`RN-SEED-36`); tras agotar reintentos, bloque y ejecución `fallida`, reanudable |
| Sin almacén S3 configurado y bloque con ficheros | Los ficheros se omiten y se anota; el resto del bloque se genera (`OPEN-SEED-12`) |
| La semilla no permite tres volúmenes distintos al primer intento | Se deriva de forma determinista el siguiente valor hasta que difieran |
| Dos ejecuciones simultáneas | La segunda se rechaza mientras haya una `pendiente` o `en_curso` (cerrojo en `seed_runs`) |
| Purga de un centro con la ejecución en curso | Rechazo |
| Límite de accesos por IP durante una demostración | No lo cambia el generador; es una medición pendiente (§10.3) |

## 6. Interacción con otros módulos

### 6.1 Principio

El generador es herramienta de desarrollo, no un módulo de negocio: no emite ni consume eventos de dominio y ningún módulo depende de él. Usa de cada módulo sus factorías y sus servicios (`RN-SEED-14`). Si un evento de dominio emitido al escribir tuviera un consumidor que envía comunicaciones, el bloque lo evita y lo declara (`RN-SEED-10`).

### 6.2 Contrato de bloque

Cada bloque declara: **código**, **módulo** del que depende (se omite si no está contratado en el centro), **bloques previos**, **división en lotes** y, por lote, qué genera a partir de su subsemilla. Además aporta: su parte del **verificador** (las distribuciones mínimas que garantiza), su parte de la **exportación canónica** para la huella y su entrada en la tabla de §6.3. Un bloque no lee datos de otro centro ni escribe fuera del suyo.

### 6.3 Bloques

Bloques de `1.15b`:

| Bloque | Módulo | Contenido | Previos | Entrega |
|---|---|---|---|---|
| `centros` | `Core`, `Backoffice` | Centro marcado como sintético, ajustes iniciales, color de marca, módulos contratados, administrador | — | `-a` |
| `curso` | `Curso` | Curso académico activo de referencia y su calendario (lo que `REQ-CURSO` tenga construido) | `centros` | `-a` |
| `personal` | `Core`, `Auth` | Personas con cuenta y rol predefinido: dirección, jefatura, secretaría, docentes, conserjería. Idioma preferido repartido | `centros` | `-a` |
| `estructura` | `Acad` | Etapas, cursos, grupos (líneas según tamaño), materias, espacios, asignación docente, tutorías y horarios | `curso`, `personal` | `-a` |
| `alumnado` | `Alum` | Alumnos y matrículas en el curso activo, repetidores, altas y bajas a mitad de curso | `estructura` | `-b` |
| `familias` | `FamUnit` | Unidades, tutores (parte con cuenta y rol `tutor_legal`), vínculos, custodia compartida, revocación por restricción judicial, textos legales de demostración, decisiones de consentimiento en los tres estados, autorizados de recogida con documento ficticio | `alumnado` | `-b` |
| `puerta` | `Prl` | Movimientos de salida de un periodo (`OPEN-SEED-13`) | `familias` | `-b` |

Bloques futuros (no se especifican aquí; cada uno entra en la especificación de su módulo, en una sección «Bloque de `REQ-SEED`», y en su definición de terminado):

| Bloque | Paso | Requisito de `REQ-SEED` que cubre |
|---|---|---|
| `asistencia` | `1.13` | `-004`: un trimestre con faltas y retrasos |
| `calif` | `1.16` | `-004`: una evaluación completa |
| `inf` | `1.18` | `-001`, `-004`: 0-3 |
| `com` | `1.19` | `-004`: comunicaciones |
| `rrhh` | Fase 2 | `-003`: plantilla completa, jornadas, contratos, subcontratas |
| `neae`, `conv` | Fase 2 | `-002`, `-004`: categoría especial en tabla separada |
| `fin`, `bec`, `tran` | Fase 2 | `-004`, `-005` (IBAN de prueba) |

## 7. Descriptor del módulo

**No aplica**, si se aprueba `OPEN-SEED-01` = A: el generador no es un módulo del catálogo. Un descriptor lo materializaría en `modules` (`platform:sync-registry`) y lo haría contratable desde el backoffice y visible para un centro, lo que contradice «herramienta interna, no se despliega en producción». Sin descriptor no hay `name_key`, `depends_on`, `essential` ni *feature flags*.

## 8. Comportamiento con el módulo desactivado

**No aplica**: no es contratable. Lo que sí aplica es la regla inversa: un bloque cuyo módulo no está contratado en un centro se omite (§5).

## 9. Interfaz: *shell* del frontend

**No aplica**: sin pantallas, rutas, navegación ni bloques de panel. `apps/web` no cambia.

## 10. Medición con volumen

### 10.1 Qué se mide en `1.15b`: `STATE_SQL`

`ImageConsentService::STATE_SQL` (`REQ-FAM-UNIT`, `ADR-066 §5.2`) calcula el estado de consentimiento en cada consulta, sin caché. Quedó «sin probar con miles de alumnos» desde `1.14-b`. Tiene cuatro usos y un riesgo conocido por lectura del código:

| Uso | Consulta | Riesgo a comprobar |
|---|---|---|
| `GET /image-consents` | Página con `count(*) OVER ()`, `LIMIT`/`OFFSET` sobre el resultado completo | Se calcula el estado de **todos** los alumnos alcanzables para devolver una página |
| Informe (`exportRows`) | El mismo cálculo completo repetido por cada tramo de 1.000 filas | Coste que crece con el cuadrado del número de alumnos |
| `authorizedAmong` | Lista de identificadores como parámetro de *array* | Tamaño del parámetro con 1.200 alumnos |
| `statesOf` | Igual | Igual |

**Método propuesto:**

1. **Conjunto**: los tres centros generados a la vez con volumen `full` y una semilla fija anotada, de modo que la selectividad de `tenant_id` sea real y no la de una base con un solo centro. El centro medido es el de mayor número de alumnos; si `OPEN-SEED-10` se aprueba, además el volumen de medición.
2. **Datos que estresan la consulta**: el bloque `familias` genera, por alumno, uno o dos tutores con representación legal y, por tutor y nivel, entre una y cuatro decisiones sucesivas (cambios de opinión), más vínculos revocados y vínculos sin verificar. Es la forma realista de que `DISTINCT ON` y los filtros tengan trabajo.
3. **Condiciones**: estadísticas actualizadas tras la carga; conexión con el **rol de tenant y RLS activa** (medir con un superusuario cambia el plan y no mide lo que se ejecuta); caché caliente; base sin otra carga.
4. **Qué se registra por uso**: plan de ejecución con tiempos y *buffers*; mediana y percentil 95 de 30 ejecuciones; número de filas de `guardian_student_links` e `image_consent_decisions` del centro. Para el listado: primera página con cada ordenación admitida, filtro `state=pendiente`, y última página. Para el informe: duración total.
5. **Umbral**: `RNF-PERF-001`, percentil 95 por debajo de 200 ms en servidor para el listado. El informe va en cola y no tiene umbral de respuesta; se registra su duración.
6. **Dónde queda**: en `operacion.md §5.1` de este módulo (tabla de resultados con fecha, semilla, versión del generador y equipo) y una nota en `REQ-FAM-UNIT/operacion.md`.
7. **Qué pasa si no cumple**: **no se corrige en `1.15b`**. Se abre un issue contra `REQ-FAM-UNIT` con la severidad de `CLAUDE.md §5` y el plan de ejecución adjunto. `1.15b` mide; no optimiza código ajeno.
8. **Límite de validez**: `ADR-030` declara orientativas las cifras del equipo de desarrollo. El resultado detecta órdenes de magnitud y planes malos; el dimensionado se valida en el entorno de destino.

Quién lo ejecuta: `verificador`, con un guion de medición que escribe `implementer` en `1.15b-c`. No añade *endpoints* ni cambia `FamUnit`.

### 10.2 Otras mediciones que el conjunto habilita

Inventario de lo que pasos anteriores aplazaron a «`REQ-SEED` (`1.15b`)», localizado en el repositorio. Solo las tres primeras se proponen dentro de `1.15b-c`; el resto, como issue (Baja) por medición, para no desbordar los tres tramos.

| Medición | Origen | En `1.15b-c` |
|---|---|---|
| `STATE_SQL` | `memory.md`, `1.14-b` | Sí |
| `GET /users`: ¿hace falta índice de búsqueda por texto? | `REQ-CORE/datos.md §A.7` | Sí |
| `GET /me`: coste de `PermissionResolver::decideAll` | `REQ-CORE/operacion.md §11.2` | Sí |
| `student_movements` sin particionar (~180.000 filas por curso con 1.000 alumnos) | `REQ-FAM-UNIT/datos.md §8.6` | Solo si entra el bloque `puerta` |
| `MAX_CURSOR_ROWS = 1000` | `ADR-054`, `apps/web/src/data-table/constants.ts` | Issue |
| Límite de 10 accesos por minuto e IP | `REQ-AUTH/operacion.md §6` | Issue (es prueba de carga) |
| `AUTH_NEW_DEVICE_ALERTS_PER_DAY = 5` | `REQ-AUTH/operacion.md §B.2.1` | Issue |
| Índices de filtros múltiples de `Core` | `REQ-CORE/datos.md` | Issue |

### 10.3 Lo que el generador no puede medir

Concurrencia y picos (`RNF-PERF-002`, `-008`): requieren una herramienta de carga, que sería una dependencia nueva (`CLAUDE.md §1`) y un paso propio.

## 11. Entregas y tramos

`1.15b` no cabe en tres tramos de `implementer` (`CLAUDE.md §2.7`). Se parte en tres entregas, cada una con hasta tres tramos secuenciales y su PR:

| Entrega | Contenido | Tramos | Clasificación |
|---|---|---|---|
| **`1.15b-a` Cimientos** | T1: migraciones (marca de sintético en `tenants`, tablas `seed_*`), guardas, cambio en `e2e-real-tenant.php`. T2: comandos `demo:seed` y `demo:status`, cola y progreso, semilla y subsemillas, bloque `centros`. T3: bloques `curso`, `personal` y `estructura`, contraseñas por entorno, verificador, *spec* de E2E real (acceso a un centro generado) | 3 | **crítico**: migración que crea tablas y cambia una tabla de plataforma; autenticación (cuentas, contraseñas, exención de MFA); tablas que cruzan tenants |
| **`1.15b-b` Alumnado y familias** | T1: bloque `alumnado`. T2: bloque `familias`. T3: bloque `puerta` si se aprueba, huella de reproducibilidad, ampliación por bloque (§3.3), volumen `full`, E2E real | 3 | **crítico**: sin migraciones ni *endpoints*, pero escribe datos con forma de datos de menores, ficheros si entra `OPEN-SEED-12`, y vínculos revocados. Duda: crítico |
| **`1.15b-c` Purga y medición** | T1: `demo:purge` según `OPEN-SEED-04`. T2: guion de medición y resultados de §10. T3: destino de `seed/` (`OPEN-SEED-16`), documentación | 3 | **crítico**: borrado físico por encima de RLS y de tablas de solo anexar |

`1.15b-c` no empieza hasta decidir `OPEN-SEED-04`. Las entregas `-a` y `-b` son útiles sin la purga: en desarrollo la base se puede recrear, que es lo que se hace hoy.

Para cada tramo, la sesión pasa a `implementer` solo las secciones que necesita y el briefing de `explorer`, con las comprobaciones pendientes de §15.

## 12. Criterios de aceptación

Formato Dado/Cuando/Entonces. Cada uno tiene un test que cita su ID (`INV-015`).

### 12.1 Bloqueo (`REQ-SEED-006`)

- **CA-SEED-001** *Dado* el entorno `production`, *cuando* se ejecuta `demo:seed` con cualquier combinación de parámetros, *entonces* termina con código distinto de cero, no crea ninguna fila en ninguna tabla y no encola ningún trabajo.
- **CA-SEED-002** *Dado* un entorno con un nombre que no está en la lista de permitidos (desconocido o vacío), *cuando* se ejecuta `demo:seed` o `demo:purge`, *entonces* el resultado es el de `CA-SEED-001`.
- **CA-SEED-003** *Dado* el código de los comandos, *cuando* se inspeccionan sus opciones y las variables de entorno que leen, *entonces* ninguna desactiva ni relaja las guardas; y *dado* cualquier valor de cualquier opción existente, *cuando* se ejecuta en `production`, *entonces* se niega igual.
- **CA-SEED-004** *Dado* un entorno permitido y un centro sin marca de sintético, *cuando* se ejecuta `demo:seed`, *entonces* se niega, el mensaje indica cuántos centros no sintéticos hay, y no escribe nada.
- **CA-SEED-005** *Dado* un lote ya encolado, *cuando* el trabajador lo toma en un entorno no permitido o con un centro no sintético en la base, *entonces* el lote no escribe y queda `fallido`.
- **CA-SEED-006** *Dado* un entorno no permitido, *cuando* se ejecuta `demo:seed`, *entonces* no se ha ejecutado `platform:sync-registry` ni ninguna otra orden con efectos.
- **CA-SEED-007** (si `OPEN-SEED-01` = A) *Dado* el árbol de ficheros que entra en la imagen de producción, *cuando* se busca el código del generador, *entonces* no está, y `demo:seed` no figura entre los comandos disponibles.
- **CA-SEED-008** *Dado* un centro creado por `e2e-real-tenant.php` en cualquiera de sus modos, *cuando* se consulta, *entonces* lleva la marca de sintético y no impide ejecutar `demo:seed`.
- **CA-SEED-009** *Dado* un centro existente, *cuando* se intenta cambiar su marca de sintético en cualquier sentido, por la conexión de plataforma o por la de tenant, *entonces* la base de datos lo rechaza.

### 12.2 Perfiles, volumen y contenido (`REQ-SEED-001` a `-004`)

- **CA-SEED-010** *Dado* un entorno permitido y una base sin centros reales, *cuando* se ejecuta `demo:seed` con los tres perfiles y termina, *entonces* existen tres centros con los slugs y nombres de `RN-SEED-26`, cada uno resoluble por su subdominio, con su color de marca, su curso activo y un conjunto de módulos contratados distinto de los otros dos.
- **CA-SEED-011** *Dado* ese conjunto con volumen `full`, *cuando* se cuentan los alumnos con matrícula viva por centro, *entonces* cada recuento está entre 300 y 1.200 y los tres son distintos.
- **CA-SEED-012** *Dado* un centro generado, *cuando* se ejecuta el verificador, *entonces* confirma todas las distribuciones mínimas de `RN-SEED-29`.
- **CA-SEED-013** *Dado* un centro generado sin el módulo `X` contratado, *cuando* un usuario con sesión pide un *endpoint* de `X`, *entonces* recibe `403` con `urn:pge:error:module-disabled`, y el bloque de `X` figura como `omitido` en la ejecución.
- **CA-SEED-014** *Dado* el correo simulado, *cuando* se ejecuta una generación completa, *entonces* no se ha enviado ni encolado ningún correo ni notificación.
- **CA-SEED-015** *Dado* un centro generado, *cuando* un docente sintético inicia sesión por HTTP real y pide sus grupos, *entonces* ve solo los de su asignación docente (E2E real).
- **CA-SEED-016** *Dado* el caso de restricción judicial generado, *cuando* el tutor revocado con cuenta pide los datos del alumno, *entonces* recibe la misma respuesta que si no existiera (`REQ-FAM-UNIT-002`).
- **CA-SEED-017** *Dado* un centro generado, *cuando* se consulta `GET /image-consents` filtrando por cada estado y nivel, *entonces* cada combinación devuelve al menos un alumno.
- **CA-SEED-018** *Dado* un centro generado, *cuando* se agrupan las personas por idioma preferido, *entonces* aparecen `es`, `en`, `de` y `fr`.
- **CA-SEED-019** *Dado* un curso generado, *cuando* se compara la edad de cada alumno con la del curso en el que está matriculado, *entonces* es coherente, salvo en los marcados como repetidores, que tienen exactamente la edad esperada más los años repetidos.

### 12.3 Convención sintética (`REQ-SEED-005`)

- **CA-SEED-020** *Dado* un conjunto generado, *cuando* se buscan correos de acceso o de contacto fuera de `@example.com` y `@example.org`, *entonces* no hay ninguno.
- **CA-SEED-021** *Dado* un conjunto generado, *cuando* se valida el dígito de control de cada documento `dni`/`nie`, *entonces* ninguno es válido. (La redacción final depende de `OPEN-SEED-03`.)
- **CA-SEED-022** *Dado* un conjunto generado, *cuando* se revisan los teléfonos, *entonces* todos están en el rango no asignable documentado.
- **CA-SEED-023** *Dado* un conjunto generado, *cuando* se revisan los nombres de centro y los slugs, *entonces* están en la lista cerrada de `RN-SEED-26`.
- **CA-SEED-024** *Dado* el repositorio, *cuando* se revisan los ficheros que añade el generador, *entonces* no hay ninguna imagen de origen externo ni ninguna fotografía de una persona.
- **CA-SEED-025** *Dado* un conjunto generado, *cuando* un informe pregunta por los registros sintéticos, *entonces* puede distinguir todos los registros de los tres centros de los de cualquier centro no sintético.

### 12.4 Reproducibilidad

- **CA-SEED-026** *Dado* una semilla, unos perfiles, un volumen, un curso de referencia y una versión del generador, *cuando* se genera dos veces en bases vacías, *entonces* las dos huellas coinciden.
- **CA-SEED-027** *Dado* lo anterior con otra semilla, *cuando* se genera, *entonces* la huella es distinta.
- **CA-SEED-028** *Dado* una generación con los lotes ejecutados en orden distinto (un trabajador frente a ejecución en serie), *cuando* se comparan las huellas, *entonces* coinciden.
- **CA-SEED-029** *Dado* una ejecución `completada`, *cuando* se añade después un bloque nuevo, *entonces* la huella de cada bloque anterior no cambia.
- **CA-SEED-030** *Dado* una generación en dos fechas del calendario distintas con el mismo curso de referencia, *cuando* se comparan las huellas, *entonces* coinciden.

### 12.5 Aislamiento, auditoría y ejecución

- **CA-SEED-031** (`INV-001`) *Dado* los tres centros generados, *cuando* un usuario de uno pide por `public_id` un alumno, una unidad familiar o un usuario de otro, y *cuando* pide los listados, *entonces* recibe la misma respuesta que si no existieran y los listados no los cuentan.
- **CA-SEED-032** (`INV-001`) *Dado* un bloque en ejecución, *cuando* intenta escribir una fila con el `tenant_id` de otro centro, *entonces* la base de datos lo rechaza por RLS (test con un bloque de prueba malicioso).
- **CA-SEED-033** *Dado* un bloque, *cuando* se inspecciona la conexión con la que escribe datos de negocio, *entonces* es la de tenant, no la de plataforma ni la del propietario.
- **CA-SEED-034** (`INV-003`) *Dado* un centro generado, *cuando* se consulta su auditoría, *entonces* cada alta de entidad auditable tiene su registro `created`, con el `actor_type` acordado y los identificadores personales redactados (`ADR-035`).
- **CA-SEED-035** *Dado* una ejecución terminada, *cuando* se consulta `seed_runs`, *entonces* constan semilla, perfiles, volumen, curso de referencia, versión, entorno, inicio, fin, estado, recuentos por bloque y huella, y **no** consta ninguna contraseña.
- **CA-SEED-036** *Dado* un lote que falla a mitad, *cuando* se reintenta, *entonces* no quedan filas duplicadas ni a medias, y la huella final es la de una ejecución sin fallos.
- **CA-SEED-037** *Dado* una ejecución en curso, *cuando* se ejecuta `demo:status`, *entonces* muestra el estado de cada centro y bloque con lotes hechos y totales.
- **CA-SEED-038** *Dado* una ejecución `pendiente` o `en_curso`, *cuando* se lanza otra, *entonces* se rechaza.
- **CA-SEED-039** (`INV-009`) *Dado* los textos de los comandos, *cuando* se ejecuta la paridad de traducciones, *entonces* existen en `es`, `en`, `de` y `fr`.

### 12.6 Cuentas

- **CA-SEED-040** *Dado* el repositorio, *cuando* se busca cualquier contraseña de cuenta sintética, *entonces* no hay ninguna.
- **CA-SEED-041** *Dado* un entorno permitido distinto de `local`/`testing` sin la variable de contraseña, *cuando* se ejecuta `demo:seed`, *entonces* se niega sin escribir.
- **CA-SEED-042** *Dado* una contraseña que no cumple `PasswordPolicy`, *cuando* se ejecuta `demo:seed`, *entonces* se niega sin escribir.
- **CA-SEED-043** *Dado* una contraseña tomada de la variable de entorno, *cuando* se revisa la salida del comando, los registros de la aplicación y `seed_runs`, *entonces* no aparece.
- **CA-SEED-044** *Dado* un centro generado, *cuando* se revisan los roles de sus cuentas, *entonces* todos son predefinidos y ninguno concede un permiso de categoría especial.

### 12.7 Purga (`1.15b-c`)

- **CA-SEED-045** *Dado* un centro que no cumple las tres condiciones de `RN-SEED-40` (cada una por separado), *cuando* se ejecuta `demo:purge` sobre él, *entonces* se niega y no borra nada.
- **CA-SEED-046** *Dado* un centro generado y purgado, *cuando* se cuenta su `tenant_id` en toda tabla que lo tenga y los objetos de su prefijo, *entonces* el resultado es cero, y los otros dos centros conservan exactamente sus recuentos.
- **CA-SEED-047** *Dado* el entorno `production`, *cuando* se ejecuta `demo:purge`, *entonces* el resultado es el de `CA-SEED-001`.
- **CA-SEED-048** *Dado* un centro purgado, *cuando* se vuelve a generar con la misma semilla, *entonces* la huella coincide con la original.

### 12.8 Criterios de la plantilla

- **Ámbito restringido, acceso denegado en listado y detalle**: no aplica al generador (no tiene recursos ni *endpoints*). Los datos generados lo ejercitan en `CA-SEED-015`, `-016` y `-031`.
- **Lectura denegada de curso cerrado**: no aplica en `1.15b` (solo se genera el curso activo, `RN-SEED-17`).
- **Aislamiento entre tenants**: `CA-SEED-031` a `-033`.

## 13. Preguntas abiertas

Ninguna está decidida. Cada una lleva recomendación, impacto y quién decide.

### 13.1 Bloquean la implementación

| ID | Pregunta | Opciones | **Recomendada** | Impacto | Decide | Bloquea |
|---|---|---|---|---|---|---|
| `OPEN-SEED-01` | ¿Dónde vive el generador y con qué forma? | **A** Herramienta de desarrollo fuera de `app/Modules`, cuyo código no entra en la imagen de producción; sin descriptor. **B** Módulo normal `Seed` con descriptor. **C** Módulo de plataforma como `Backoffice` (excepción nominal en los tests de arquitectura) | **A** | A cumple «no se despliega en producción» de forma estructural y no amplía listas cerradas; exige comprobar cómo se construye la imagen (§15). B lo hace contratable y visible en el catálogo. C amplía excepciones cerradas. Revertir A a B es barato; B a A, no una vez sincronizado el catálogo | Usuario | `-a` |
| `OPEN-SEED-02` | ¿Cómo se marca un registro como sintético? | **A** Marca en el centro (`tenants`), inmutable; todo registro es sintético por su `tenant_id`. **B** Columna en cada tabla de negocio. **C** Tabla de registro de identificadores generados | **A** | A es una columna y un disparador en una tabla de plataforma; no admite mezclar datos sintéticos y reales en un mismo centro, que es justo lo que se quiere prohibir. B toca más de treinta tablas y todo módulo futuro. C no impide la mezcla y crece con el volumen. Si «todo registro» se lee de forma literal, A lo cumple por derivación: es una interpretación y por eso se pregunta | Usuario | `-a` |
| `OPEN-SEED-03` | Documentos de identidad: `REQ-SEED-005` (dígito inválido) contra `RN-CORE-90` a `-93` (se valida en alta y en cada edición) | **A** Pasaporte ficticio con prefijo reconocible para casi todos, y un grupo pequeño y fijo por centro con `dni`/`nie` de dígito inválido, escrito por debajo de la validación y documentado como no editable hasta corregir el documento. **B** Literal: todos con `dni`/`nie` de dígito inválido; ninguna persona generada se puede editar. **C** Relajar la validación de `Core` en centros sintéticos. **D** Enmendar `REQ-SEED-005` y usar solo pasaporte | **A** | A mantiene la demo utilizable y conserva casos para probar la validación. B rompe la edición de toda persona de la demo. C hace que la demo se comporte distinto que producción: lo desaconsejo. D es A sin casos de dígito inválido. Cualquiera salvo B exige enmendar o aclarar `REQ-SEED-005` | Usuario | `-a` (`personal`), `-b` |
| `OPEN-SEED-06` | Plantilla de `REQ-SEED-003` sin `RRHH` | **A** En `1.15b` solo personal con cuenta y rol predefinido; el resto, con el bloque `rrhh`. **B** Crear además a los puestos sin cuenta como personas sueltas, sin puesto. **C** Aplazar todo `1.15b` hasta `RRHH` | **A** | B deja personas sin ningún papel en el modelo, que ninguna pantalla muestra y que habría que reconciliar después. C contradice `ADR-030` (habilitante). Con A, `REQ-SEED-003` queda cubierto parcialmente y así debe constar en el plan | Usuario | `-a` |
| `OPEN-SEED-04` | Mecanismo de la purga | **A** Guion de desarrollo que generaliza el del E2E (superusuario del contenedor de desarrollo), restringido por `RN-SEED-40`; se sustituye por el mecanismo de `ADR-077` cuando se implemente. **B** Esperar a `ADR-077` y reutilizarlo. **C** No hay purga por centro: se recrea la base | **A** | A no sirve en un entorno donde no haya superusuario disponible (staging). B retrasa `-c` a un paso sin fecha. C incumple «comando complementario de purga» | Usuario, con el `architect` de `ADR-077` | Solo `-c` |

### 13.2 No bloquean (hay valor por defecto si no se deciden)

| ID | Pregunta | **Recomendada** (y valor por defecto) | Alternativas | Impacto | Decide |
|---|---|---|---|---|---|
| `OPEN-SEED-05` | Entornos permitidos y contraseñas fuera de desarrollo (`ADR-080`, `OPEN-STG-09`: credenciales de demostración con repositorio público) | Lista permitida `local` y `testing`. `staging` se añade **solo** al aceptar `ADR-080`, con contraseña obligatoria desde el gestor de secretos, nunca en el repositorio, y sin cuenta compartida publicada | Contraseña fija documentada (descartada: con repositorio público cualquiera entraría en staging, que tiene subida de ficheros y cuentas de administrador); cuentas sin contraseña activadas por invitación (inviable con `@example.com`) | Sin `staging` en la lista, la demo comercial solo existe en local. No bloquea `-a` | Usuario |
| `OPEN-SEED-07` | ¿Qué es «detectar datos no sintéticos»? | Cualquier centro sin marca, sin excepciones | Ignorar centros vacíos; ignorar por nombre | Con la recomendada, un centro creado a mano desde el backoffice en desarrollo bloquea el generador hasta retirarlo. Es el fallo en cerrado | Usuario |
| `OPEN-SEED-08` | Módulos contratados por centro | `demo-concertado`: todos. `demo-publico`: sin `prl`. `demo-privado`: todos (se diferenciará al llegar módulos económicos y de transporte) | Otro reparto | `seed/README.md` diferenciaba por transporte, que no existe. Hoy solo hay cuatro no esenciales y `alum`, `fam_unit` y `acad` son necesarios para generar alumnado | Usuario |
| `OPEN-SEED-09` | MFA de las cuentas con rol que lo exige | Exención temporal solo en `local`/`testing`; en otros entornos, ninguna | Exención en todos; ninguna en ninguno | Sin exención en local, cada regeneración obliga a inscribir un factor | Usuario |
| `OPEN-SEED-10` | Volumen de medición por encima de 1.200 | Medir a 1.200 (máximo del requisito). No añadir un volumen mayor en `1.15b` | Un perfil técnico de 3.000 (`RNF-PERF-002` habla de 3.000 usuarios por centro) | «Miles de alumnos» por centro excede `REQ-SEED-002`; añadirlo es un requisito nuevo | Usuario |
| `OPEN-SEED-11` | Texto legal de demostración (consentimiento de imagen, condiciones de uso) | Un texto marcador que dice que es de demostración y sin validez, escrito a mano en los cuatro idiomas; no es un texto legal y no se traduce automáticamente | Solo en es-ES | Sin texto publicado no se puede registrar `autorizo` | Usuario |
| `OPEN-SEED-12` | Fotografías y activos de marca | En `1.15b`, ninguna fotografía; color de marca sin imagen. Marcadores sin rostro generados, en un bloque posterior | Marcadores ya en `-b` | Las fotos exigen almacén S3 levantado y vuelven crítico el tramo (ficheros). `REQ-FAM-UNIT §21.11` las pide para los autorizados de recogida | Usuario |
| `OPEN-SEED-13` | Bloque `puerta` (movimientos de `Prl`) | Incluirlo en `-b`, un trimestre | No incluirlo | No está en `REQ-SEED-004`; lo piden las mediciones aplazadas de `REQ-FAM-UNIT/datos.md`. Es una propuesta, no un requisito | Usuario |
| `OPEN-SEED-14` | `actor_type` de la auditoría | `console`, existente | `system`; valor nuevo `seed` | Un valor nuevo cambia un `CHECK` de auditoría y `ADR-039` | Técnico |
| `OPEN-SEED-15` | Modo síncrono | Opción que ejecuta los mismos lotes en línea, para tests, CI y el perfil reducido de `ADR-030` (sin trabajadores) | Solo cola | Es consola, no HTTP: no afecta a `INV-012` | Técnico |
| `OPEN-SEED-16` | Destino de `seed/` (Python) | Retirarlo en `-c` una vez portados catálogos y verificador | Conservarlo para prototipos | Dos generadores divergen | Usuario |
| `OPEN-SEED-17` | Cursos anteriores cerrados | No en `1.15b` | Un curso anterior cerrado | No lo pide el requisito; lo necesitarán las pruebas de histórico | Usuario |
| `OPEN-SEED-18` | Slug `demo` | No usarlo: queda para el E2E, que lo destruye y recrea. Los centros persistentes usan `demo-…`, con el ajuste de orígenes permitidos en desarrollo (`operacion.md §1`) | Que un perfil ocupe `demo` | El E2E lo borraría | Técnico |
| `OPEN-SEED-19` | `DemoProfile`: ¿tabla o definición en código? | Definición en código, versionada con el generador | Tabla | El requisito lo lista como entidad principal sin exigir persistencia | Técnico |

## 14. Cambios propuestos en ficheros compartidos

No se tocan desde esta rama; los aplica la sesión orquestadora.

| Fichero | Cambio |
|---|---|
| `PLAN-IMPLEMENTACION.md` | Partir `1.15b` en `1.15b-a`, `-b` y `-c` (§11). Cambiar «plantilla completa de personal y datos operativos» por el alcance real: personal con cuenta; el resto por bloques. Añadir a cada paso posterior con módulo nuevo (`1.13`, `1.16`, `1.18`, `1.19` y fase 2) la línea «incluye su bloque de `REQ-SEED`». Anotar que `1.15b-c` depende de `OPEN-SEED-04` |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` | Solo si el usuario decide: aclarar `REQ-SEED-005` según `OPEN-SEED-03` y `-02`; anotar en `§5.53` que `REQ-SEED-003` depende de `RRHH` para la plantilla sin cuenta. Es contenido de requisito, no índice: lo aprueba el usuario |
| `memory.md` | Nota viva de `1.15b`; quitar «`STATE_SQL` con miles de alumnos» de lo no probado cuando cierre `-c` |
| `CHANGELOG.md` | Entrada de la especificación y, después, una por entrega |
| `SYSADMIN.md` | Variables de `operacion.md §1`, orden de uso de los comandos y nota de orígenes permitidos en desarrollo |
| `RUNBOOK.md` | «Regenerar los centros de demostración» y «el generador se niega a ejecutarse» |
| `ARCHITECTURE.md` | §3.4: referencia al contrato de bloque; mención de la marca de sintético en `tenants` |
| `README.md` | Sección de datos de demostración; retirar la referencia a `seed/` si se aprueba `OPEN-SEED-16` |
| `.claude/skills/modulo-nuevo` | Añadir al checklist de alta: «bloque de `REQ-SEED` del módulo» |
| `docs/modulos/_PLANTILLA/funcional.md` | Sección «Bloque de `REQ-SEED`» (no es fichero compartido de la lista, pero es de todos: se propone, no se toca) |
| `docs/modulos/REQ-FAM-UNIT/operacion.md` | Nota con el resultado de la medición de `STATE_SQL` (al cerrar `-c`) |

## 15. Lo que no se pudo comprobar

Esta sesión no disponía de búsqueda local ni de consola; se localizó con lectura directa y con la búsqueda de código del repositorio. Queda para el briefing de `explorer` de cada tramo:

1. **`ADR-080` y `OPEN-STG-09`**: la rama `chore/adr-080-staging-y-runtime-de-contenedores` no está publicada y no se pudo leer. `OPEN-SEED-05` se formula sin conocer su texto; hay que contrastarlos al integrar.
2. **`ADR-077`** (purga de tenant): no leído. `OPEN-SEED-04` depende de él.
3. **Cómo se construye la imagen de producción** (si excluye dependencias y código de desarrollo): sin verificar. De ello depende que `OPEN-SEED-01` = A sea estructural (`CA-SEED-007`).
4. **Rango de teléfonos no asignables en España**: no verificado con fuente primaria (CNMC). No se fija ningún rango de memoria.
5. **Si `Acad` modela hoy el régimen por etapa** (`ADR-020`) y qué calendario tiene `Curso`: sin verificar (`RN-SEED-26`, bloque `curso`).
6. **Lista de roles predefinidos**: vistos `administrador_centro`, `direccion`, `secretaria`, `docente`, `conserjeria_pas` y `tutor_legal` en el precedente E2E. No se comprobó si existen jefatura de estudios u orientación como roles propios.
7. **Si `audit_logs` admite un identificador de correlación** con el que enlazar cada registro a su ejecución: sin verificar. Si no existe, el enlace es por intervalo de tiempo y `actor_type`; no se propone columna nueva.
8. **Que el aprovisionamiento público (`ADR-048`) admita pasar la marca de sintético en la creación**: no existe hoy; es parte de `1.15b-a` y toca `Core`.
9. **Orígenes permitidos en desarrollo**: el precedente dice que solo `demo` está permitido; no se leyó la configuración.
10. No existe tabla de lotes de trabajos del framework (búsqueda sin resultados): el progreso se lleva en las tablas `seed_*`, que es lo especificado.
11. **Tamaño de lote y duración total** con 1.200 alumnos: sin medir; se calibra en `1.15b-a` T2.

---

¿Se aprueba esta especificación para pasar a implementación? Antes hacen falta, como mínimo, las decisiones de `OPEN-SEED-01`, `-02`, `-03` y `-06`.
