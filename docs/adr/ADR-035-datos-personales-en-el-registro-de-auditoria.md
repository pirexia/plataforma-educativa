# ADR-035 · Datos personales en el registro de auditoría frente al derecho de supresión

**Estado**: ACEPTADA (2026-08-18, implementada en el paso 0.9 — auditoría e i18n)
**Fecha**: 2026-08-18
**Resuelve**: `OPEN-12`
**Concreta**: `ADR-004` (borrado en tres niveles) aplicado a `audit_logs`; `ADR-034 §3` (esquema y política de redacción de `audit_logs`)
**Se apoya en**: `ADR-034` (modelo de datos núcleo), `ADR-033` (aislamiento y roles de PostgreSQL), `ADR-029` (identificadores y tipos)
**Afecta a**: `INV-003`, `INV-004`, `INV-008`, `REQ-CORE-005`, `REQ-PRIV-003`, `REQ-PRIV-006`, `REQ-BKP-005`, `REQ-BO-007`, `RSEC-GDPR-002`, paso **0.9** del plan
**No sustituye a ningún ADR anterior.** Cierra una pregunta que `ADR-034` dejó abierta a propósito y **acota la lectura de `INV-003`** (ver Consecuencias).

---

## Contexto

`ADR-034 §3` fijó `audit_logs` como tabla única polimórfica, append-only, con inmutabilidad forzada en el motor (`REVOKE UPDATE, DELETE ON audit_logs FROM plataforma_app`) y con una columna `changes` de tipo `jsonb` que guarda los atributos modificados. Fijó también que en los modelos de categoría especial (salud, NEAE, convivencia) se registra **qué** atributo cambió pero **no su valor**.

Lo que dejó sin resolver —`OPEN-12`— es qué pasa con los identificadores personales **no especiales** de una persona: nombre, apellidos, fecha de nacimiento, documento, correo de contacto, teléfono. Son datos personales ordinarios, no de categoría especial, y con la política de `ADR-034` se escribirían en claro en `changes`.

El choque es directo y no se deja resolver con matices:

- `audit_logs` es inmutable por diseño y por privilegios del motor (`REQ-CORE-005`: *«registro inmutable»*; `REQ-BO-007`: *«registro independiente e inmutable»*). El rol de aplicación solo tiene `INSERT` y `SELECT`.
- La anonimización de `ADR-004` nivel 2 exige que los identificadores personales dejen de ser reversibles.

Una fila que registró `people.document_number: "12345678Z" → "87654321X"` sigue conteniendo el documento después de anonimizar a esa persona. Redactarla a posteriori viola la inmutabilidad; dejarla vacía el derecho de supresión y convierte la auditoría en la copia en claro de lo que se acaba de anonimizar — exactamente el mismo fallo que `ADR-034` ya evitó al prohibir el nombre del actor desnormalizado.

Tres cosas más encuadran el problema y ninguna es cosmética:

1. **El evento `created` es el caso grande, no el `updated`.** Un nombre se cambia una vez cada varios años; un alta de persona ocurre por cada alumno, cada tutor legal y cada empleado del centro. Con la regla de `ADR-034` («solo los atributos que cambiaron»), en un `created` han cambiado todos: el alta de cada persona escribiría su identidad completa en `audit_logs`. El volumen de datos personales que acaba en la tabla inmutable lo produce el alta, no la corrección.
2. **`REQ-BKP-005` ya obliga a que una restauración no reintroduzca datos suprimidos**, mediante un *registro de supresiones* que se reaplica tras cualquier restauración. Cualquier mecanismo que dependa de una operación destructiva puntual (redactar una fila, destruir una clave) tiene que ejecutarse **otra vez** después de cada restauración de copia, o la supresión se deshace sola.
3. **El paso 0.9 no puede empezar sin esta decisión.** 0.9 escribe el *observer* que rellena `changes` desde el ciclo de vida del ORM. Para `Person` y `User` necesita saber hoy si serializa el valor, lo cifra o lo omite. Sin esto, 0.9 solo es implementable para las entidades sin datos personales, que son la minoría de las que vendrán.

`REQ-PRIV-006` (catálogo de retención y ciclo de vida del dato) es hoy tres viñetas sin implementación. La decisión no puede depender de que exista.

---

## Decisión

### 1. El principio: el registro de auditoría no se suprime, se retiene

**El derecho de supresión no se ejerce *dentro* de `audit_logs`. Se ejerce evitando que entre en `audit_logs` cualquier valor que permita reidentificar al sujeto, y purgando la fila entera al vencer su plazo de retención.**

La premisa que hay que rechazar es que la supresión tenga que alcanzar el interior de una fila de auditoría ya escrita. No tiene que hacerlo, y no debe. El registro de auditoría existe para **imputación** —quién hizo qué, cuándo y desde dónde— no para ser el sistema de registro del valor anterior de un dato personal. Confundir las dos finalidades es lo que produce el bloqueo: una tabla que a la vez tiene que ser inmutable (porque es prueba) y editable (porque contiene datos personales suprimibles) es una tabla mal definida.

Separadas las dos finalidades, cada una tiene su capa y ninguna cede:

| Finalidad | Dónde vive | Régimen |
|-----------|-----------|---------|
| Imputación: quién, qué, cuándo, desde dónde | `audit_logs` | Inmutable, append-only, sin valores personales, purga por retención |
| Estado actual del dato personal | Tabla de negocio (`people`, facetas) | Mutable, borrado lógico, anonimizable (`ADR-004` nivel 2) |
| Histórico de un atributo que de verdad hay que conservar | Tabla de histórico propia del módulo (punto 7) | Tabla de negocio normal: anonimizable y con su regla en `REQ-PRIV-006` |

En consecuencia se elige la **opción 1** de `ADR-034` —no registrar el valor de los atributos identificativos— con tres precisiones que la convierten en una regla aplicable y no en una pérdida ciega de trazabilidad: la clasificación es **por modelo y fallo en cerrado** (punto 2), se conserva la parte no identificativa del cambio (punto 3), y existe una salida nombrada para los casos en que el valor anterior sea de verdad necesario (punto 7).

Se descartan la **opción 2** (*crypto-shredding*) y la **opción 3** (redacción dirigida). Los motivos completos están en «Alternativas descartadas»; el resumen es que ambas resuelven el problema **después** de haber escrito el dato, y por tanto ambas heredan la obligación de `REQ-BKP-005` de volver a ejecutarse tras cada restauración, más una infraestructura nueva que un operador en solitario tendría que mantener correcta durante tres años para que la supresión siga siendo efectiva. El dato que nunca se escribió no necesita mantenimiento.

### 2. Tres políticas de valor por modelo, declaradas y sin valor por defecto

Todo modelo auditable implementa la interfaz `Auditable`, que **obliga** a declarar `auditValuePolicy(): AuditValuePolicy`. No hay valor por defecto: un modelo que no declara política no es auditable y no compila su registro en el *morph map*. Es la misma técnica de `ADR-033` (test 8/9) y de `ADR-034` (registro de borrado físico permitido): convertir la disciplina en build roto.

| Política | Qué hace | Para qué modelos |
|----------|----------|------------------|
| `Full` | Registra el valor de todos los atributos | Modelos **sin ningún dato personal**: `AcademicYear`, `Role`, `ModuleSubscription`, `Tenant`, configuración, catálogos |
| `Selective` | Registra el valor **solo** de los atributos enumerados en `$auditRecordedAttributes`; **todo lo demás se redacta** | Modelos con identificadores personales mezclados con datos operativos: `Person`, `User`, y en el futuro las facetas `Student`, `Guardian`, `Employee` |
| `Redacted` | No registra ningún valor, solo qué atributos cambiaron | Modelos de categoría especial: salud, NEAE, convivencia (ya decidido en `ADR-034 §3`) |

**`Selective` enumera lo que sí se registra, nunca lo que no.** Es la decisión de detalle más importante de este punto. Una lista de exclusión falla en abierto: la columna que 1.1 añada a `people` sin acordarse de la lista acabaría en claro en la tabla inmutable, que es un fallo irreversible. Una lista de inclusión falla en cerrado: la columna nueva se redacta, alguien lo nota al mirar la pantalla de auditoría y se corrige añadiéndola a la lista si procede. El error barato en un lado, el caro en el otro.

**`Full` está sujeto a registro explícito con test.** El conjunto de modelos que declaran `Full` se comprueba contra una lista fija en el test de arquitectura. Añadir un modelo a `Full` obliga a editar el test, lo que fuerza una decisión consciente y hace que aparezca en la revisión. Sin esto, `Full` sería la política que se elige por comodidad y el sistema se erosionaría modelo a modelo.

### 3. Qué se conserva de un cambio redactado

Redactar el valor no significa registrar solo el nombre del atributo. Se conserva la parte del cambio que **no reidentifica** y que es la que un auditor consulta casi siempre:

- **Qué atributo cambió** (su nombre).
- **Por qué no está el valor** (`identifier`, `special`, `secret`, `oversized`).
- **Si el valor anterior estaba vacío y si el nuevo lo está** (`from_empty`, `to_empty`).

La distinción entre *rellenar un campo vacío*, *sobrescribir un valor existente* y *vaciar un campo* cubre la mayoría del uso real de la pantalla de `REQ-CORE-005` —«¿alguien borró el teléfono de contacto de esta familia?»— y ninguno de esos tres booleanos permite reidentificar a nadie. Después de anonimizar a la persona, la fila dice que el 4 de marzo alguien sobrescribió su documento; no dice por cuál.

**Prohibido guardar un resumen criptográfico (*hash*) del valor redactado.** Es la primera mejora que se propondrá dentro de dieciocho meses («así al menos se puede verificar un valor alegado») y hay que dejarla cerrada aquí: el espacio de un DNI son ~47 millones de combinaciones y el de un nombre propio bastante menos. Un *hash* sin sal de un dato personal de baja entropía **es** el dato personal, se invierte en segundos por fuerza bruta, y reintroduce en la tabla inmutable exactamente lo que este ADR saca de ella. Un *hash* con sal por sujeto es *crypto-shredding* con otro nombre y con sus mismos problemas.

### 4. Formato exacto de `changes`

Uniforme: **toda entrada es un objeto**, para que ningún consumidor tenga que ramificar por tipo.

```json
{
  "locale":          { "from": "es-ES", "to": "en" },
  "document_number": { "redacted": "identifier", "from_empty": false, "to_empty": false },
  "contact_phone":   { "redacted": "identifier", "from_empty": true,  "to_empty": false },
  "password":        { "redacted": "secret" },
  "observations":    { "redacted": "oversized", "from_empty": false, "to_empty": false }
}
```

**Orden de evaluación del *observer*, por atributo.** Es determinista y se implementa exactamente así:

1. **¿Es secreto?** Atributo en `$auditSecretAttributes` del modelo, o su nombre encaja con el patrón global de respaldo (`*password*`, `*token*`, `*secret*`, `*_key`, `*totp*`, `*recovery_code*`). → `{"redacted": "secret"}`, **sin banderas de vacío**. Esta regla es absoluta y no depende de la política del modelo: es `ADR-034 §3` («contraseñas, *tokens*, semillas TOTP y códigos de respaldo **nunca** se escriben»), y el patrón global es defensa en profundidad para la columna `api_token` que alguien añadirá sin declararla.
2. **¿Política `Redacted`?** → `{"redacted": "special", "from_empty": …, "to_empty": …}`.
3. **¿Política `Selective` y el atributo no está en `$auditRecordedAttributes`?** → `{"redacted": "identifier", "from_empty": …, "to_empty": …}`.
4. **¿El valor codificado en JSON supera el tope?** → `{"redacted": "oversized", "from_empty": …, "to_empty": …}`.
5. En otro caso → `{"from": …, "to": …}`.

Dos aclaraciones sobre `ADR-034`, coherentes con él y no contradictorias:

- **El nombre del atributo secreto sí se registra; su valor no.** Que una contraseña ha cambiado es información de seguridad de primer orden y `INV-003` la exige; el valor no aporta nada y su registro sería una vulnerabilidad. `ADR-034` prohíbe escribir el secreto, no prohíbe registrar que hubo cambio — es la misma forma que ya prescribe para categoría especial.
- **En los eventos `read` y `exported`, `changes` es `NULL`.** La auditoría de lectura de categoría especial (sección 8 de `CLAUDE.md`) registra *que se leyó el registro X*, nunca lo que decía.

### 5. El tope de tamaño como red de seguridad

**Todo valor cuya codificación JSON supere 256 caracteres se redacta como `oversized`. Nunca se trunca el contenido.**

Es la regla que sigue funcionando cuando nadie está mirando. El riesgo de cola larga no está en `people.given_name` —que está clasificado y controlado— sino en el campo de texto libre de un módulo de la fase 2: una observación de tutoría que dice «hablé con la madre de Ana, María López, en el 6XX XXX XXX». Ese texto es dato personal (y a veces de categoría especial) dentro de una columna que nadie clasificó como identificativa. El tope lo ataja sin depender de que el autor del modelo se acuerde, y de paso acota el tamaño de fila de la tabla más grande del sistema.

**No se trunca**: un fragmento de prosa personal sigue siendo dato personal. O entero o nada, y por defecto nada.

El tope es configurable en `config/audit.php` y se aplica sobre el valor **codificado**, de modo que un `jsonb` como `module_subscriptions.settings` queda cubierto por la misma regla sin necesidad de un tratamiento propio.

`context` (`ADR-034 §3`) se rige por la misma regla y por una restricción adicional: **`context` transporta identificadores y códigos, nunca valores de atributos.** Un módulo que necesite adjuntar el valor de algo lo tiene prohibido por la misma razón que el `changes`.

### 6. Qué queda en la fila después de anonimizar, y por qué no reidentifica

Aplicada la regla, una fila de `audit_logs` referida a una persona conserva:

| Dato | Por qué no rompe la supresión |
|------|------------------------------|
| `auditable_id`, `auditable_public_id` | Identificadores **seudónimos**. Resueltos por clave foránea contra `people`, que está anonimizada: la pantalla muestra una persona anonimizada. Es el mismo argumento por el que `ADR-034` no desnormaliza el nombre del actor. `ADR-004` nivel 2 conserva el registro con identificadores irreversibles: esto es exactamente eso |
| `actor_user_id` | Ídem, para el actor |
| `occurred_at`, `event`, `auditable_type` | Metadatos de la operación, no de la persona |
| `ip_address`, `user_agent` | Datos personales **del actor**, no del sujeto. Ver abajo |
| `changes` con entradas redactadas | Nombres de atributo y banderas de vacío. No reidentifican |

**La IP y el `user-agent` del actor merecen una respuesta explícita**, porque son el punto donde alguien dirá que la regla no cierra. Un empleado que deja el centro y ejerce supresión tiene sus IP en `audit_logs`. La respuesta no es redactarlas: es que **el propio registro de auditoría tiene su base jurídica y su plazo**. Se conserva por obligación de responsabilidad proactiva y seguridad del tratamiento (`REQ-CORE-005`: retención mínima de dos años, configurable por cumplimiento), lo que encaja en las excepciones al derecho de supresión, y **se suprime solo por vencimiento del plazo, con la purga de la fila entera**. Esa purga es una operación de retención prevista desde `ADR-034 §3` (ejecutada por el rol propietario desde una tarea de mantenimiento), no una edición de una fila viva. La tabla no es inmune a la supresión: se suprime por completo y a su tiempo.

Esta es la parte de la decisión que hay que saber defender ante una reclamación: **no se deniega la supresión, se ejerce por retención**, y se sostiene porque en la fila no queda nada del sujeto salvo una clave que ya no lleva a ningún dato identificativo.

### 7. Dónde vive el histórico cuando el valor anterior sí hace falta

La objeción legítima a esta decisión es que se pierde el dato que más a menudo se quiere auditar. Se acepta como coste general y se le da una salida nombrada para que no se resuelva metiéndolo de vuelta en `audit_logs`:

> Cuando el valor anterior de un atributo personal deba conservarse por una necesidad de negocio concreta, se modela como **atributo historiado**: una tabla de histórico propia del módulo que lo posee, creada con `tenantTable()`, con borrado lógico, y **declarada en el catálogo de `REQ-PRIV-006`** con su regla de retención y su estrategia de supresión.

Una tabla de negocio normal es anonimizable y purgable como cualquier otra: no hereda el conflicto porque no es append-only. La necesidad se justifica con un caso real, no por si acaso.

**En 0.9 no se crea ninguna.** El único candidato previsible es el histórico de cambio de documento de identidad, y le corresponde decidirlo a `REQ-ALUM`/`REQ-SEC` cuando tengan delante el caso de fraude de identidad o de corrección de matrícula que lo motive. Adelantarlo aquí sería inventar un requisito.

### 8. Declaración de los modelos del núcleo

Lo que 0.9 escribe, sin más decisiones:

| Modelo | Política | `$auditRecordedAttributes` | Nota |
|--------|----------|---------------------------|------|
| `Person` | `Selective` | `locale`, `deleted_at`, `created_by`, `updated_by` | Todo lo demás redactado: `given_name`, `family_name_1`, `family_name_2`, `birth_date`, `document_type`, `document_number`, `contact_email`, `contact_phone` |
| `User` | `Selective` | `status`, `email_verified_at`, `deleted_at`, `created_by`, `updated_by` | `email` redactado (`identifier`); `password` y `remember_token` redactados (`secret`) por la regla 1 |
| `AcademicYear` | `Full` | — | Sin datos personales |
| `Role` | `Full` | — | `name` es contenido del centro, no dato personal |
| `ModuleSubscription` | `Full` | — | `settings` (`jsonb`) queda acotado por el tope de tamaño |
| `Tenant` | `Full` | — | Datos del centro, persona jurídica |
| `AuditLog` | — | — | No se audita a sí misma |
| `Permission`, `Module` | — | — | Catálogos de referencia, sin `tenant_id`; los cambia `platform:sync-registry` y su registro es de plataforma |

**`birth_date` se redacta**, aunque no sea un identificador directo: fecha de nacimiento junto a centro y grupo es un cuasi-identificador clásico y reidentifica a un alumno en una población de mil. **`document_type` se redacta** por el mismo criterio: `DNI` → `NIE` revela información sobre la situación de la persona sin aportar nada a la trazabilidad.

**`users.email` se redacta, y se asume la pérdida.** Es una credencial y su cambio es relevante en una investigación de toma de control de cuenta. Se acepta porque el valor **actual** se lee de `users` —la investigación no necesita el diff para saber a dónde apunta la cuenta ahora— y lo que se pierde son los valores intermedios de una cadena de cambios. La cobertura correcta de ese caso es un **evento de seguridad** con su propio contenido y su propia retención (`REQ-AUTH-005`, detección de acceso desde dispositivo o ubicación nuevos), no un diff de auditoría genérico. **Queda anotado como entrada para el paso 1.2**, que es quien construye `REQ-AUTH`.

### 9. Compatibilidad con lo que viene

- **Particionado futuro de `audit_logs`** (`ADR-034 §3`, disparador a 50M filas): esta decisión no solo es compatible, lo facilita. Nada modifica una fila después del `INSERT`, de modo que la conversión a particiones mensuales por `occurred_at` sigue siendo un asunto puramente de DDL, y la purga por retención pasa a ser `DROP PARTITION` en vez de un `DELETE` masivo. Las opciones 2 y 3 habrían obligado a operaciones dispersas por todas las particiones.
- **`REQ-BKP-005`**: una restauración no reintroduce datos personales en `audit_logs` porque nunca los hubo. El *registro de supresiones* solo tiene que reaplicar la anonimización de las tablas de negocio, que es su cometido.
- **`REVOKE UPDATE, DELETE` de `ADR-033`/`ADR-034`**: se mantiene intacto y sin excepciones. **Este ADR no concede a ningún rol el privilegio de modificar `audit_logs`.** El rol propietario conserva `DELETE` únicamente para la purga por retención de filas completas, que es lo que ya preveía `ADR-034 §3`.

### 10. Mínimo de `REQ-PRIV-006` exigible antes del primer dato real

Esta decisión funciona **hoy**, con lo que hay, y 0.9 no depende de nada pendiente. Pero la supresión no está completa hasta que exista, antes del hito **H0**:

1. **Plazo de retención de `audit_logs` y su purga.** Por defecto dos años (`REQ-CORE-005`), configurable. Tarea de mantenimiento ejecutada por `plataforma_owner`, con informe de lo purgado (`REQ-PRIV-006`, tercera viñeta). Sin esto, la fila nunca se suprime y el argumento del punto 6 no se sostiene.
2. **Procedimiento de anonimización de `people`.** Qué columna se sustituye por qué valor irreversible. Se ejecuta como una escritura de negocio normal y genera su propio registro de auditoría — cuyo `changes`, por esta misma decisión, no contiene los valores antiguos. La regla es autoconsistente: **anonimizar no filtra lo anonimizado**, que con cualquiera de las otras dos opciones habría requerido un caso especial.
3. **Registro de supresiones de `REQ-BKP-005`**, para que una restauración reaplique las anonimizaciones ya practicadas.

Los tres son trabajo de `REQ-PRIV-006`, no de 0.9, y ninguno modifica lo que 0.9 escribe.

### 11. Tests que exige esta decisión

En 0.9, referenciando `INV-003`, `INV-008` y `ADR-035`:

| # | Qué prueba | Por qué existe |
|---|-----------|----------------|
| 1 | Un `UPDATE` sobre `people.document_number` produce `{"redacted":"identifier", …}` y el valor **no aparece en ningún punto** de la fila (`changes` ni `context`) | El caso de uso |
| 2 | Un `created` de `Person` no escribe ningún valor identificativo | Es el caso de volumen, no el `updated` |
| 3 | Añadir un atributo nuevo a un modelo `Selective` sin tocar `$auditRecordedAttributes` lo deja redactado | Prueba que falla **en cerrado** |
| 4 | Un atributo que encaja con el patrón de secretos se redacta como `secret` aunque el modelo no lo declare | Defensa en profundidad |
| 5 | Un valor de 300 caracteres se redacta como `oversized` y **no se trunca** | Cola larga de texto libre |
| 6 | Todo modelo del *morph map* implementa `Auditable`; el conjunto de modelos `Full` coincide con la lista fija del test | Impide la erosión modelo a modelo |
| 7 | Tras anonimizar una persona, ninguna fila de `audit_logs` referida a ella contiene una cadena que permita reidentificarla | La prueba de la propiedad, no del mecanismo |

El test 7 es el que hay que escribir aunque parezca redundante: prueba la **propiedad legal**, y seguirá siendo válido si algún día cambia el mecanismo.

---

## Motivo

**Porque el dato que no se escribe no hay que gestionarlo después.** Las opciones 2 y 3 comparten un defecto que no es de coste sino de forma: ambas escriben el dato personal y luego se comprometen a neutralizarlo. Ese compromiso hay que cumplirlo en cada supresión, en cada restauración de copia (`REQ-BKP-005`), en cada migración de la tabla y durante los tres años en que el sistema lo mantenga un operador que también hace todo lo demás. La opción 1 no tiene nada que cumplir después: la propiedad se sostiene sola, incluso si nadie la vuelve a mirar. Para un proyecto de una sola persona esa diferencia no es de esfuerzo, es de si la garantía sigue siendo cierta a los tres años.

**Porque es minimización, que es lo que la norma pide primero.** El artículo 5.1.c y el 25 del RGPD piden no recoger lo que no se necesita y protegerlo desde el diseño. Un registro de auditoría cuya finalidad es la imputación no necesita el valor del apellido. Responder a un ejercicio de supresión con «no hay nada suyo ahí» es una posición defendible sin argumentación; responder con «está cifrado y hemos destruido la clave» es defendible pero cargando con la prueba, y responder con «lo hemos redactado con un procedimiento manual auditado» es defendible solo si se demuestra que el procedimiento se ejecutó siempre y encontró todas las apariciones.

**Porque la contradicción de partida era una confusión de finalidades, y se resuelve nombrándola.** `audit_logs` es prueba de quién hizo qué. El valor anterior de un dato personal es estado de negocio. Cuando se mezclan, la tabla necesita ser a la vez inmutable y editable, que es imposible, y cualquier solución acaba siendo un apaño sobre una de las dos propiedades. Separadas, `audit_logs` se queda con la inmutabilidad íntegra —sin excepciones, sin roles con `UPDATE`, sin comandos de redacción— y la erasabilidad se queda donde ya funciona: en tablas de negocio con borrado lógico y anonimización.

**Porque `ADR-034` ya tomó esta decisión para la mitad más sensible del problema.** Para categoría especial ya está decidido registrar qué cambió y no su valor, y nadie discute que ahí sea correcto. Aplicar dos doctrinas distintas a los datos especiales y a los identificativos crearía una frontera que hay que recordar en cada modelo nuevo. Una sola regla, con tres niveles de intensidad declarados, es lo que se puede sostener con 53 módulos.

**Porque la pérdida real es menor de lo que parece y tiene salida.** Lo que se pierde no es «la trazabilidad del cambio» sino «el valor concreto anterior»: se sigue sabiendo quién lo cambió, cuándo, desde dónde, qué atributo y si pasó de vacío a lleno. Y cuando un módulo necesite de verdad el valor anterior, el punto 7 le da un sitio donde ponerlo que no rompe nada. La alternativa —conservarlo «por si acaso» en la tabla que no se puede tocar— es el patrón que produce las brechas por acumulación.

**Y el criterio transversal de `ADR-033` y `ADR-034`: reversible antes que óptimo.** Esta decisión es reversible hacia adelante y no hacia atrás, que es la dirección segura. Si dentro de un año se concluye que hace falta más detalle, se cambia la política de un modelo y a partir de ese momento se registra más; las filas antiguas simplemente tienen menos. Si se hubiera elegido registrar en claro y luego hubiera que retirarlo, las filas antiguas ya contendrían el dato y no habría marcha atrás sin romper la inmutabilidad — que es el problema que este ADR está resolviendo.

---

## Consecuencias

**A favor**

- El paso 0.9 queda desbloqueado para `Person` y `User` y para todos los modelos con datos personales de las fases siguientes, con un mecanismo único.
- `audit_logs` conserva la inmutabilidad **sin excepciones**: ningún rol adquiere `UPDATE`, no hay comando de redacción, no hay ruta por la que una fila cambie después del `INSERT`. `REQ-CORE-005` y `REQ-BO-007` se cumplen literalmente.
- El ejercicio de supresión no requiere ninguna operación sobre `audit_logs`, luego tampoco requiere reejecutarla tras una restauración de copia (`REQ-BKP-005`).
- Cero infraestructura nueva: sin gestor de claves, sin tabla nueva, sin dependencia externa, sin comando de mantenimiento adicional en 0.9.
- El particionado futuro y la purga por retención quedan más simples, no más complicados.
- La anonimización de una persona no filtra por su propio registro de auditoría, sin necesitar un caso especial.
- El tope de tamaño acota el crecimiento de la tabla más grande del sistema como efecto colateral.

**En contra, y se asume**

- **Se pierde el valor anterior de los identificadores personales.** Es una pérdida real de trazabilidad sobre el dato que más a menudo se querrá consultar. Mitigada parcialmente por las banderas de vacío y por la salida del punto 7, no eliminada.
- **`users.email` deja de tener diff.** La investigación de toma de control de cuenta pierde los valores intermedios. Se traslada a 1.2 como evento de seguridad.
- **`INV-003` queda acotado.** Su texto dice «valores antes/después» sin matices. A partir de este ADR se lee como *«valores antes/después, salvo los atributos clasificados como no registrables por `ADR-035`»*. Ya estaba acotado de hecho por `ADR-034` para categoría especial y para secretos; este ADR lo hace explícito y le pone regla. Es una restricción de una invariante por ADR, no una excepción tácita, y por eso se escribe aquí y se referencia desde la sección 0.5 del documento de requisitos.
- **La corrección depende de una declaración por modelo.** Está mitigada con fallo en cerrado (lista de inclusión), patrón global de secretos, tope de tamaño y test de arquitectura, pero un modelo mal clasificado como `Full` teniendo datos personales escribiría en claro. Por eso `Full` lleva registro explícito y test.
- **La pantalla de auditoría mostrará muchas entradas sin valor** en los modelos de persona. Es visible para el usuario y hay que explicarlo en el manual (`docs/manual-usuario/admin.md`), no dejar que se descubra como si fuera un fallo.

**Reversibilidad**

- **Alta**: la política de cualquier modelo, el contenido de `$auditRecordedAttributes` y el tope de tamaño. Son código y configuración; el cambio afecta a las filas nuevas y ninguna migración hace falta. En la dirección segura (registrar más a partir de ahora) es inmediato.
- **Media**: crear un atributo historiado del punto 7 para un caso concreto. Es una tabla nueva y su regla de retención; acotado a un módulo.
- **Baja, y por eso se decide ahora**: el principio del punto 1. Si en algún momento se decidiera que `audit_logs` sí debe contener valores personales, todas las filas escritas bajo esta regla ya no los tienen y no hay forma de recuperarlos. Es asimetría deliberada: se ha elegido el lado en el que el error se corrige.

---

## Alternativas descartadas y por qué

- **Opción 2 · Cifrado por sujeto con destrucción de clave (*crypto-shredding*).** Es la opción técnicamente más elegante y la que más trazabilidad conserva, y aun así se descarta por cuatro motivos acumulativos, no por uno:
  1. **Introduce el componente más delicado del sistema** —un almacén de claves por sujeto— con todo lo que arrastra: dónde vive (tabla propia, KMS, HSM), cómo se respalda sin que el respaldo deshaga la destrucción, cómo se rota, qué pasa si se pierde (el histórico de auditoría se vuelve ilegible en bloque). Cualquiera de esas preguntas mal resuelta convierte una garantía legal en una creencia.
  2. **Choca de frente con `REQ-BKP-005`.** Si la clave vive en la misma base de datos, restaurar una copia anterior a la destrucción **restaura la clave** y con ella el dato suprimido, que es precisamente lo que el criterio de aceptación de `REQ-BKP-005` prohíbe. Sacar el almacén de claves fuera del ámbito de las copias resuelve eso y crea un componente con su propio ciclo de vida, su propia copia y su propio riesgo de pérdida.
  3. **No es infraestructura gratis para este proyecto.** Un KMS es una dependencia nueva (`CLAUDE.md §1`: hay que justificar mantenimiento, licencia y releases) y un servicio más que operar en un host que hoy solo ejecuta contenedores. Una tabla de claves hecha a mano es criptografía propia, que es exactamente lo que no debe escribir un desarrollador en solitario.
  4. **Y compra un beneficio pequeño.** Lo que devuelve es poder leer el nombre anterior de una persona que no ha ejercido supresión. Ese es el balance real: el componente más frágil de la plataforma a cambio de un campo en una pantalla de consulta.
  Se anota como salida si alguna vez un requisito obliga a conservar valores personales históricos de forma verificable — y en ese caso el sitio correcto sería el atributo historiado del punto 7, cifrado, no `audit_logs`.

- **Opción 3 · Redacción dirigida por el rol propietario con registro en `admin_action_logs`.** Descartada, y es la que más conviene descartar de forma explícita:
  1. **Destruye la propiedad que da valor a la tabla.** Un registro de auditoría que un comando puede reescribir vale como prueba exactamente lo que valga la confianza en ese comando. `REQ-CORE-005` y `REQ-BO-007` piden inmutabilidad, no inmutabilidad con procedimiento.
  2. **La garantía es circular.** Se protege la redacción registrándola en `admin_action_logs`, que es otra tabla de auditoría con el mismo problema y sin nadie que la proteja a ella.
  3. **No acota la búsqueda.** Los datos de una persona pueden aparecer en filas de auditoría de **otras** entidades (una autorización de recogida que registra el nombre del tutor). El índice `(auditable_type, auditable_id)` no las alcanza: haría falta un recorrido con búsqueda dentro del `jsonb` sobre la tabla más grande del sistema, y sin garantía de haberlas encontrado todas. Una supresión que no puede demostrar que fue completa no es una supresión.
  4. **Escala mal operativamente.** Cada ejercicio de supresión de cada centro exigiría una intervención del rol de plataforma. Con 200 tenants es un procedimiento manual que se acaba omitiendo, y un procedimiento de cumplimiento que se omite es peor que no tenerlo, porque figura documentado.
  5. **Y hay que reejecutarla tras cada restauración** (`REQ-BKP-005`), lo que multiplica los puntos en que puede fallar en silencio.

- **Registrar un *hash* del valor redactado** («así se puede verificar un valor alegado sin conservarlo»): un *hash* sin sal de un dato de baja entropía —DNI, nombre, teléfono, correo— se invierte por fuerza bruta en segundos y por tanto **es** dato personal. Con sal por sujeto es *crypto-shredding* disfrazado, con su gestión de claves y sin sus ventajas. Queda cerrado aquí para que no reaparezca como «mejora».

- **Truncar los valores largos en vez de redactarlos**: un fragmento de prosa es dato personal igual que la prosa entera, y a menudo el fragmento inicial es justo el que contiene el nombre. Se redacta entero.

- **Registrar solo el dominio del correo** (`{"to_domain": "…"}`) como término medio para la detección de toma de control de cuenta: en un centro pequeño un dominio identifica a una familia, y añade un caso especial por atributo a un mecanismo cuyo valor está en ser uniforme. El caso de uso es legítimo y se atiende donde corresponde: como evento de seguridad en `REQ-AUTH` (paso 1.2), con su propia base jurídica y su propia retención.

- **Lista de exclusión en `Selective`** (declarar qué no se registra, en vez de qué sí): falla en abierto. La columna que 1.1 añada a `people` sin acordarse acabaría en claro en una tabla que no se puede corregir. El coste del error es asimétrico y la lista de inclusión lo pone del lado barato.

- **Una política global de aplicación en vez de por modelo** («no registrar valores en ningún sitio»): sería más simple y perdería la auditoría de las entidades que no tienen ningún problema de datos personales —cursos académicos, roles, suscripciones de módulo, configuración— que son justo donde el valor antes/después es más útil y donde `INV-003` se cumple sin fricción.

- **Deducir la clasificación automáticamente** del nombre de la columna o del tipo, sin declaración: no es determinable. `name` es identificativo en `people` y no lo es en `roles`. Adivinar produciría falsos negativos silenciosos, que es la única clase de fallo que este ADR no puede permitirse.

- **Excluir de la auditoría los modelos con datos personales** (no registrar nada sobre `Person`): incumple `INV-003` de frente y deja sin trazabilidad la entidad cuya manipulación más importa vigilar. Redactar el valor no es lo mismo que no auditar.

- **Esperar a `REQ-PRIV-006` para decidir**: bloquearía 0.9 durante toda la fase 0 y no aportaría nada, porque el catálogo de retención responde a *cuánto tiempo se conserva cada dato*, no a *qué se escribe en `changes`*. Son preguntas distintas y esta es previa: el catálogo se aplica sobre lo que se haya escrito.

---

## Preguntas abiertas

Ninguna que bloquee el paso 0.9.

Quedan **anotadas para su paso**, y no son preguntas de este ADR:

- **Evento de seguridad para cambios de credencial** (`users.email`, correo de recuperación, factores MFA), con su contenido, su base jurídica y su retención propia, distinta de la de `audit_logs`. Le corresponde al paso **1.2** con `REQ-AUTH-005` delante.
- **Plazo de retención por tipo de registro de auditoría.** `REQ-CORE-005` fija dos años como mínimo configurable; si algún tipo de evento necesita más (o menos), lo decide el catálogo de `REQ-PRIV-006`, no este ADR.
- **`OPEN-13`** (lista definitiva de columnas de `Person` y su base legal por campo) sigue abierta y **no la toca este ADR**. Sí interactúa en un punto que conviene dejar dicho: cualquier columna que `OPEN-13` añada a `people` nace **redactada** por la lista de inclusión del punto 2, de modo que resolverla más tarde no crea una ventana de exposición.

---

## Plan de implementación

Lo que este ADR aporta al paso **0.9** (que además cubre i18n, fuera del alcance de aquí). Cada commit referencia `[REQ-0.9]` y el `INV`/`ADR` que cubre.

- **0.9.a · Contrato de auditoría.** Interfaz `Auditable` con `auditValuePolicy()`, enum `AuditValuePolicy` (`Full`, `Selective`, `Redacted`), propiedades `$auditRecordedAttributes` y `$auditSecretAttributes`, y `config/audit.php` con el tope de tamaño y el patrón global de secretos. Sin *observer* todavía.
- **0.9.b · Constructor del `changes`.** Clase única responsable de convertir el `getDirty()`/`getOriginal()` de un modelo en el `jsonb` del punto 4, implementando los cinco pasos del orden de evaluación. **Es el único camino por el que un valor de atributo puede llegar a `audit_logs`**; ningún otro código serializa atributos. Tests unitarios de los cinco pasos, sin base de datos.
- **0.9.c · *Observer* de ciclo de vida.** Enganche en `created`, `updated`, `deleted`, `restored`; resolución del actor (`actor_user_id`, `actor_type`), `ip_address`, `user_agent`, `request_id` (`INV-013`), `occurred_at`. Alias del *morph map* en `auditable_type`, nunca el FQCN (`ADR-034 §3`).
- **0.9.d · Declaración de los siete modelos del núcleo** según la tabla del punto 8.
- **0.9.e · Tests de la tabla del punto 11**, los siete. El 6 se añade a la batería de tests de arquitectura de 0.8.10; el 7 es de propiedad y se escribe aunque de momento la anonimización de `people` sea manual en el test.
- **0.9.f · Documentación.** `SECURITY.md` y `PRIVACY.md`: qué contiene y qué no contiene el registro de auditoría, y cómo se atiende un ejercicio de supresión sobre él. `docs/manual-usuario/admin.md`: por qué la pantalla de auditoría muestra «valor no registrado» en los datos de personas. `docs/modulos/REQ-CORE/datos.md`: el formato de `changes`.

**Fuera de 0.9, y hay que dejarlo escrito para que no se dé por hecho**: la purga por retención de `audit_logs`, el procedimiento de anonimización de `people` y el registro de supresiones son trabajo de `REQ-PRIV-006` y `REQ-BKP`, exigibles antes del hito **H0** (punto 10). 0.9 es correcto sin ellos; la supresión no está completa hasta que existan.

**Revisiones obligatorias antes de mezclar** (`CLAUDE.md §6`): `security-reviewer` sobre el constructor del `changes` y el *observer*, con atención expresa a que ningún valor redactado alcance los logs de aplicación, el informe de excepciones ni la serialización del modelo; y `doc-reviewer` sobre `SECURITY.md`, `PRIVACY.md` y el manual.
