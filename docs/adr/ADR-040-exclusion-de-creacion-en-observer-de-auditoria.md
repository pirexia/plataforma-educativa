# ADR-040 · El *observer* de auditoría gana exclusión por modelo y evento: `UserSession` no registra `created`

**Estado**: ACEPTADA (2026-08-25, decisión del usuario en `docs/modulos/REQ-AUTH/funcional.md §B.14`, punto 4)
**Fecha**: 2026-08-25
**Resuelve**: `OPEN-AUTH-16` (`docs/modulos/REQ-AUTH/funcional.md §B.10` y `§B.13`)
**Amplía**: el mecanismo automático de `ADR-035 §4`/`§9` (*trait* `RecordsAuditTrail`) con una exclusión declarativa por **modelo y evento**. No cambia ninguna otra decisión de `ADR-035`: ni la política de redacción, ni la inmutabilidad de `audit_logs`, ni la tabla de `§8`
**Sigue el precedente de**: `ADR-036` (excepción explícita por modelo dentro de este mismo mecanismo, motivada caso por caso). Sigue su **forma**, no su contenido: allí el modelo entero quedó fuera; aquí el modelo se queda dentro y sale un solo evento
**Se apoya en**: `ADR-039 §4.2` (el `event` nombra el hecho, y el hecho ya tiene nombre: `login`), `ADR-039 §4.5` (frontera entre escritura automática y manual), `ADR-034 §3` (`audit_logs` es tabla de tenant, vigilada por volumen)
**Concreta**: `INV-003` (auditoría de toda creación, modificación y borrado)
**Afecta a**: el paso **1.2b** (`REQ-AUTH-005`, puntos 2-4) y, por tocar un mecanismo transversal, a los **53 módulos** del producto
**No decide**: qué otros modelos podrán excluir qué otros eventos. `§4.5` fija el procedimiento, no la lista

---

## 1. Contexto

### 1.1 El hecho que se registra dos veces

`ADR-039` decidió que cada acceso consumado escribe en `audit_logs` una fila `login` sobre `User`: quién entró, cuándo, desde qué IP, con qué `request_id`. Es el registro completo del hecho, y es el que la pantalla de auditoría de `REQ-CORE-005` filtra para responder «quién entró en el sistema».

El paso `1.2b` introduce `UserSession` (`datos.md §B.2`), una fila por sesión viva, para el panel de «mis sesiones» y su revocación. `datos.md §B.2` le asigna política de auditoría `Selective` con `session_id` declarado como secreto — todo correcto. Pero el modelo, declarado auditable, entra por el camino automático de `0.9`, y ese camino engancha `created` **siempre**.

El resultado, si no se decide nada: **cada login escribe dos filas en `audit_logs`**. La `login` de `ADR-039`, que describe el hecho. Y una `created` sobre `UserSession` con el mismo actor, el mismo instante, la misma IP y el mismo `request_id`, que no añade una sola pregunta que la primera no responda ya.

El coste no es teórico y no es pequeño. El login es el evento más frecuente del producto —una fila por persona y día, multiplicada por el censo del centro—, `audit_logs` conserva **dos años** por `REQ-CORE-005`, es *append-only* con `REVOKE UPDATE, DELETE` en el motor, y `ADR-034 §3` ya la señala como la candidata a particionado que hay que vigilar. Duplicar de forma permanente el mayor generador de filas de la tabla que menos margen tiene, a cambio de cero información, es la definición de un coste sin contrapartida.

### 1.2 Estado real del mecanismo, verificado en el código

`apps/api/app/Support/Audit/RecordsAuditTrail.php` engancha cuatro momentos del ciclo de vida —`created`, `updated`, `deleted`, `restored`— y delega en `AuditRecorder::record()`. Ya contiene una supresión, la del `updated` interno que dispara `SoftDeletes` y que duplicaría la fila `deleted`/`restored`:

```php
if ($businessKeys === [] || $businessKeys === ['deleted_at']) {
    return;
}
```

Es decir: **el mecanismo ya sabe que no todo lo que el ORM dispara merece una fila**, y ya suprime una duplicación por criterio propio. Lo que no tiene es forma de que un modelo declare la suya. Lo que un modelo sí puede declarar hoy, por el contrato `Auditable` de `ADR-035 §2`, son tres cosas: su política de valores, sus atributos registrados y sus atributos secretos. Este ADR añade una cuarta.

`AuditRecorder::record()` es, además, el punto por donde pasa **también** la escritura manual que `ADR-039 §4.5` abrió para `login`, `logout` y `password_reset_requested`. Ese detalle decide dónde va el filtro (`§4.2`).

### 1.3 La premisa que hace legítima la exclusión: `user_sessions` tiene un único productor

`funcional.md §B.4.1` es explícito: la fila de `user_sessions` se crea **dentro de la transacción del login**, después de `regenerate()`, de `Auth::login()` y del propio registro de auditoría `login`. No hay ningún otro camino que la cree: no hay endpoint de alta, no hay importación, no hay comando, y las sesiones anónimas de `GET /auth/csrf-cookie` **no tienen fila aquí**.

Esto importa más que ninguna otra cosa de este documento, porque es lo que convierte «excluir `created`» en «no repetir un hecho ya registrado» en lugar de «dejar de auditar creaciones». Si mañana existiera un segundo productor de filas de `user_sessions`, la exclusión pasaría a ocultar un hecho real y este ADR habría que sustituirlo. `§4.4` lo ata con un test en vez de con una nota.

### 1.4 Por qué esto no se decide dentro de `REQ-AUTH`

El mecanismo de `0.9` es común a los 53 módulos. Darle una capacidad nueva —que un modelo pueda declarar que un evento suyo no se audita— es exactamente el tipo de decisión que, tomada dentro de la especificación de un módulo, no es visible desde los otros 52, no es inmutable, y nadie va a buscar ahí. Es `CLAUDE.md §6.3`, y es el mismo camino que ya recorrieron `OPEN-CORE-09` → `ADR-038` y `OPEN-AUTH-02` → `ADR-039`.

La propia especificación de `1.2b` lo dejó como pregunta abierta con recomendación y no lo resolvió por su cuenta (`§B.10`, `§B.13`). Este documento es la resolución.

---

## 2. Qué NO decide este ADR

- **No abre la exclusión a nadie más.** Excluir un evento es la excepción, y `§4.5` pone la carga de la prueba del lado de quien la pida. La respuesta por defecto sigue siendo que no.
- **No cambia el esquema de `user_sessions`.** `datos.md §B.2` ya lo dice: la política de redacción de esa tabla no depende de esta decisión.
- **No amplía el vocabulario de `audit_logs.event`.** `ADR-039 §5.3` sigue gobernando eso, y `1.2b` no necesita ningún valor nuevo (`funcional.md §B.10`).
- **No toca la escritura manual** de `ADR-039 §4.5` ni sus tres valores admitidos.
- **No reabre** `OPEN-AUTH-13`, `OPEN-AUTH-14`, `OPEN-AUTH-15` ni `OPEN-AUTH-17`, resueltas en `funcional.md §B.14`.

---

## 3. Opciones consideradas

Las tres reales, las que estaban sobre la mesa cuando se escribió `§B.10`.

**A · Aceptar la fila duplicada.** Coste de implementación: cero, literalmente ninguna línea. Cumple `INV-003` sin discusión posible. Coste de mantenimiento a tres años: duplicar de forma permanente el evento más voluminoso del producto en la tabla con dos años de retención que `ADR-034 §3` ya vigila. Reversibilidad: **asimétrica y mala**. Dejar de escribir la fila mañana es fácil; las filas escritas hasta entonces no se pueden borrar —`audit_logs` no admite `DELETE` desde la aplicación por diseño— y se arrastran hasta que vence su retención.

**B · Exclusión declarativa por modelo y evento en el mecanismo de `0.9`.** Coste de implementación: un método en el contrato `Auditable`, su valor por defecto en `HasAuditableAttributes`, tres líneas de filtro en `RecordsAuditTrail`, una línea en `UserSession` y dos tests. Coste de mantenimiento: una capacidad más que un revisor debe vigilar en un mecanismo transversal — la parte incómoda, y se trata en `§6`. Impacto en invariantes: ninguno negativo si el alcance se acota, porque el hecho excluido sigue registrado con su propio evento. Reversibilidad: **alta y simétrica**. Quitar la exclusión devuelve el comportamiento anterior sin ningún dato que limpiar.

**C · Sacar `UserSession` entera del *observer*, como `ADR-036` hizo con `Tenant`.** Coste de implementación: también cero. Y es la lectura literal del precedente, por eso se consideró. Pero `ADR-036` sacó `Tenant` porque **ningún** evento suyo cabía en `audit_logs` (vive en la conexión de plataforma, sin `tenant_id`). Aquí no es el caso: la revocación de una sesión, el cierre por `cambio_credencial`, el cierre por `baja_usuario` y el borrado lógico **sí** son hechos que `INV-003` exige registrar y que hoy nadie más registraría. Sacar el modelo entero para evitar una fila redundante dejaría fuera cinco hechos que no lo son. Es cambiar un problema de volumen por un agujero de auditoría.

---

## 4. Decisión

### 4.1 El contrato `Auditable` gana una cuarta declaración

El *trait* `RecordsAuditTrail` consulta al modelo qué eventos automáticos **no** debe registrar, del mismo modo que ya le consulta su política de valores y sus atributos secretos. Es una declaración **del modelo**, en el cuerpo de la clase, junto a las otras tres de `ADR-035 §2` — no una lista central en `config/audit.php` ni en un *provider*.

Que la declaración viva en el modelo no es estética. Quien lea `UserSession` para entender qué se audita de ella ve las cuatro declaraciones juntas, y la exclusión no se puede perder de vista al leer la clase; una lista central en `config/audit.php` sería un fichero que nadie abre al modificar un modelo, y una exclusión invisible es exactamente lo que degrada un registro de auditoría con el tiempo.

Forma concreta:

- Un método del contrato `Auditable`, del tipo `auditExcludedEvents(): array`, que devuelve los eventos automáticos que ese modelo no registra.
- **Valor por defecto `[]` en `HasAuditableAttributes`**, con cuerpo literal y no leyendo una propiedad del modelo. Consecuencia buscada: los diez modelos auditables existentes y los de los 52 módulos futuros **no cambian una sola línea**, y su comportamiento es idéntico al de hoy. Solo el modelo que excluye algo escribe algo.
- `UserSession` lo declara devolviendo `['created']`. Es la línea que `funcional.md §B.10` anticipó.

### 4.2 El filtro va en `RecordsAuditTrail`, nunca en `AuditRecorder`

Esto no es un detalle de implementación, es la frontera que sostiene `ADR-039 §4.5`.

`AuditRecorder::record()` tiene **dos** clientes: el camino automático del *observer* y la llamada manual de `login`/`logout`/`password_reset_requested`. Un filtro puesto ahí convertiría la exclusión en una trampa: una llamada manual deliberada, escrita por alguien que quiere esa fila, desaparecería en silencio porque un modelo declaró algo en otro fichero. Un registro de auditoría que descarta escrituras explícitas sin decirlo no es un registro de auditoría.

El filtro va, por tanto, en el *trait*, en el enganche `created`, antes de llamar a `AuditRecorder`. `AuditRecorder` sigue escribiendo todo lo que se le pide, sin excepciones, y sus dos guardas actuales (`isPlatformMode()` y `hasTenant()`) no se tocan.

### 4.3 Alcance exacto: qué queda fuera y qué sigue dentro

Fuera queda **una sola operación**. Todo lo demás de `UserSession` se sigue auditando por el mecanismo genérico, sin ninguna llamada manual (`CA-AUTH-102`).

| Operación sobre `UserSession` | Evento | ¿Se registra en `audit_logs`? |
|---|---|---|
| Creación de la fila en el login (`funcional.md §B.4.1`) | `created` | **No.** El hecho ya lo registra `login` sobre `User` (`ADR-039 §4.3`), con el mismo actor, instante, IP y `request_id` |
| Cierre por `logout` (`§4.3`) | `updated` | **Sí** |
| Revocación individual, `DELETE /auth/sessions/{public_id}` (`§B.4.3`) | `updated` | **Sí**, con `ended_at`, `end_reason = 'revocada_usuario'` y `ended_by` |
| Revocación masiva, `DELETE /auth/sessions` con `scope=others`/`all` (`§B.4.4`) | `updated` | **Sí**, una fila por sesión cerrada (ver la trampa de `§6`) |
| Cierre por `inactividad` (`§4.6`), `caducidad` (`§B.4.2` y `§B.4.7`), `cambio_credencial` (`RN-AUTH-22`, `RN-AUTH-36`), `baja_usuario` (`§8.2`) y `tenant_incoherente` (`RN-AUTH-31`) | `updated` | **Sí**, las siete razones de `§B.4.6` sin excepción |
| Borrado lógico (`INV-004`) | `deleted` | **Sí** |
| Restauración | `restored` | **Sí** |
| Purga física a los 90 días (`PurgeUserSessions`, `operacion.md §B.3`) | — | **No pasa por el *observer***, y no es una exclusión: es un borrado masivo de retención, el mismo camino que `ADR-035`/`OPEN-12` ya fijó para completar la supresión por vencimiento de plazo |

Dicho en una frase, que es la que debe sobrevivir a este documento: **el ciclo de vida de `UserSession` se audita entero; lo único que no se repite es su nacimiento, porque tiene nombre propio en la fila de al lado.**

### 4.4 La exclusión es declarativa, no condicionada al flujo de llamada

Se rechaza expresamente la variante «excluir `created` **solo cuando** la creación ocurre dentro del flujo de login», implementada como una comprobación en tiempo de ejecución del contexto de llamada.

El motivo es que el mecanismo tendría que preguntar quién le está llamando, y eso —un indicador de ámbito, una bandera en el contenedor, una comprobación de la pila— es precisamente la clase de estado implícito que hace imposible razonar sobre qué se auditó y qué no, y que ningún test cubre bien. Añade complejidad permanente a un mecanismo transversal a 53 módulos para expresar una condición que hoy es siempre cierta.

La condición «solo la creación de login» se cumple por otra vía, más barata y más verificable: **el login es el único productor de filas de `user_sessions`** (`§1.3`). La exclusión declarativa y la condicional describen hoy exactamente el mismo conjunto de filas.

Y como esa equivalencia es la premisa entera de este ADR, se fija con un test, no con confianza:

- Un test de `1.2b` verifica que un login escribe **exactamente una** fila en `audit_logs` (la `login` sobre `User`) y **ninguna** `created` sobre `UserSession`.
- Un test verifica que una revocación **sí** escribe su `updated`, con `session_id` redactado (`CA-AUTH-102`).
- Un test de arquitectura verifica que la exclusión declarada por `UserSession` es exactamente `['created']` y que ningún otro modelo del repositorio declara exclusión alguna. **Ese test es el guardián del `§4.5`**: el día que alguien añada una segunda exclusión, el test falla y obliga a pasar por aquí.

Si un paso futuro necesita crear una fila de `user_sessions` fuera del login, la premisa se rompe y **este ADR debe sustituirse**, no ampliarse por analogía.

### 4.5 Procedimiento para el próximo modelo que quiera excluir un evento

Este es el precedente que hay que acotar, porque es lo único de este ADR que puede hacer daño a los otros 52 módulos. Quien quiera excluir un evento de un modelo suyo debe demostrar las tres cosas, en un ADR corto que cite este:

1. **Que el hecho excluido queda registrado en `audit_logs` por otro camino**, con su propio `event`, su propio actor y su propio instante. No «está en los logs de aplicación», no «se puede deducir», no «no interesa»: otra fila en la misma tabla.
2. **Que ese otro camino es el único productor del hecho**, verificado en el código y fijado con un test, como `§1.3` y `§4.4`.
3. **Que el ahorro es de volumen material**, no cosmético. Una fila redundante por login del producto entero lo es; una fila redundante en una operación que ocurre tres veces al trimestre no.

Si falla cualquiera de las tres, la respuesta es **no**, y la alternativa correcta casi siempre no es excluir: es modelar mejor. `AccountLockout` en `funcional.md §10.1` es el precedente de eso —modelar el bloqueo como entidad hizo que su auditoría saliera gratis— y `ADR-039 §5.3` dice lo mismo desde el otro lado del mismo mecanismo.

**La exclusión nunca es un modo aceptable de callar un hecho que nadie más registra.** Ese es el abuso que este documento habilita y que este párrafo prohíbe.

---

## 5. Motivo

**5.1 Porque no se está dejando de auditar nada.** Es la diferencia entre esta decisión y un recorte de auditoría, y decide sola. El hecho «esta persona inició sesión en este momento desde esta IP» sigue en `audit_logs`, con nombre propio, gracias a `ADR-039`. Lo que desaparece es una segunda fila que dice lo mismo con peor vocabulario: `created` sobre una entidad técnica en lugar de `login` sobre el usuario. `ADR-039 §4.2` fijó que `event` nombra **lo que pasó**; entre dos filas que describen el mismo hecho, la que sobra es la que lo nombra peor.

**5.2 Porque la asimetría de reversibilidad apunta en sentido contrario a la opción barata.** Es el mismo argumento de `ADR-039 §5.2`, aplicado al volumen en vez de al vocabulario. Implementar la exclusión y arrepentirse cuesta borrar cuatro líneas: a partir de ese despliegue las filas `created` vuelven, y no falta ninguna información porque el `login` correspondiente siempre estuvo escrito. No implementarla y arrepentirse deja dos años de filas redundantes que **no se pueden borrar**: `audit_logs` no admite `DELETE` desde la aplicación por diseño, y dárselo para limpiar ruido destruiría la garantía que hace útil la tabla. Cuando una de las dos direcciones es gratis y la otra es irreversible, no es un empate que se resuelva por gusto.

**5.3 Porque el precedente ya existe y este ADR lo hace explícito en vez de ampliarlo en silencio.** `ADR-036` estableció que este mecanismo admite excepciones por modelo cuando están motivadas caso por caso. Y el propio `RecordsAuditTrail` ya suprime por criterio propio el `updated` interno de `SoftDeletes` (`§1.2`), exactamente por este motivo: no duplicar una fila. Lo que hace este ADR es sacar esa capacidad de la implementación y ponerla en el contrato, donde se declara, se lee y se revisa. Un mecanismo que suprime filas según reglas escondidas en su propio código es peor que uno que exige al modelo declararlo.

**5.4 Porque el coste de implementación es proporcional al problema.** Un método en una interfaz, un valor por defecto que no obliga a tocar ningún modelo existente, un filtro de tres líneas y tres tests. Con un solo desarrollador, esto es la diferencia entre una decisión que se toma hoy y una deuda que se paga durante dos años de retención. Si el mecanismo hubiera exigido reescribir `AuditRecorder` o tocar los diez modelos auditables, la respuesta razonable habría sido la opción A.

**5.5 Y se dice que no a lo que venía detrás.** La tentación inmediata al construir esto es hacerlo configurable: una lista de exclusiones en `config/audit.php`, un patrón por tipo de modelo, o —la peor— excluir `created` para toda entidad «técnica». Las tres se rechazan. Una lista central es un fichero que nadie mira al escribir un modelo. Un patrón convierte cada modelo nuevo en una exclusión accidental. Y «entidad técnica» no es una categoría que exista en este producto: `UserSession` guarda IP y `User-Agent` de una persona identificada, que es lo contrario de técnico. Una exclusión, un modelo, un evento, un ADR.

---

## 6. Consecuencias

**Buenas**

- Un login escribe **una** fila en `audit_logs`, no dos. El mayor generador de filas del producto deja de duplicarse en la tabla con dos años de retención que `ADR-034 §3` vigila.
- `1.2b` cumple `INV-003` sin ninguna llamada manual a `AuditRecorder`, que es lo que `funcional.md §B.10` perseguía y `CA-AUTH-102` comprueba.
- La pantalla de auditoría de `REQ-CORE-005` deja de mostrar, para cada acceso, una fila `created` sobre una entidad que el usuario del centro no sabe qué es. La legibilidad del registro para quien no escribió el código no es un detalle: es la razón por la que se conserva.
- El mecanismo pasa a tener un lugar declarado para una decisión que ya tomaba por su cuenta (`§5.3`), y un procedimiento escrito para el próximo que la pida (`§4.5`).

**Malas, o al menos incómodas, y se asumen**

- **Existe ahora una forma de que un modelo deje de auditar algo.** Antes no la había: el mecanismo era ciego y por eso nadie podía debilitarlo. Es el coste real de esta decisión y no se puede eliminar, solo acotar — con `§4.5`, con el test de arquitectura de `§4.4` que falla ante la segunda exclusión, y con la obligación de que `db-reviewer` y `security-reviewer` miren toda declaración nueva de `auditExcludedEvents()` como miran una migración de `audit_logs`.
- **La trampa de la revocación masiva.** `DELETE /auth/sessions` cierra N sesiones (`§B.4.4`). Si se implementa como un `UPDATE` masivo por consulta, **el ORM no dispara eventos y no se escribe ninguna fila de auditoría**, y el síntoma es idéntico al de una exclusión: silencio. No lo causa este ADR —ocurriría igual sin él—, pero este ADR lo hace más fácil de confundir con «ah, es la exclusión». La implementación de `1.2b` debe cerrar las filas modelo a modelo, o registrar explícitamente, y el test de `§4.4` sobre la revocación debe cubrir el caso masivo además del individual.
- **La traza de una `UserSession` empieza por un `updated`.** Quien consulte `audit_logs` filtrando por `auditable_type = 'user_session'` verá cierres sin la creación correspondiente. No es una pérdida de información —`started_at` está en la propia fila, y el `login` está en `audit_logs` con el mismo `request_id` (`INV-013`)— pero es contraintuitivo, y por eso se documenta en `datos.md §B.2` y en `docs/modulos/REQ-CORE/datos.md`, donde alguien lo va a buscar.
- **`ADR-035 §8` y el contrato `Auditable` dejan de contarse enteros por separado.** El contrato tiene ahora cuatro declaraciones y `ADR-035 §2` describe tres. No se edita `ADR-035`: la regla de inmutabilidad (`docs/adr/README.md`) obliga a que quien lo lea encuentre este documento al lado, y por eso la entrada del índice de la sección 18 dice explícitamente que amplía el mecanismo.

**Reversibilidad**: alta. Quitar `['created']` de `UserSession` restaura el comportamiento anterior sin ningún dato que limpiar ni migración que revertir, y sin ninguna información perdida en el intervalo. Es la propiedad que decide entre las opciones A y B de `§3`.

**Afecta a**: `REQ-AUTH-005` (`funcional.md §B.10`, `datos.md §B.2`, `CA-AUTH-102`), `REQ-CORE-005` (pantalla y exportación de auditoría), `INV-003`, `INV-004`, `INV-013`, el contrato `Auditable` de `ADR-035 §2` y el mecanismo automático común a los 53 módulos.

---

## 7. Alternativas descartadas y por qué

| Alternativa | Por qué no |
|---|---|
| **Aceptar la fila duplicada** (opción A de `§3`) | Duplica para siempre el evento más voluminoso del producto en la tabla con dos años de retención, a cambio de cero información. Y las filas escritas no se pueden borrar: `audit_logs` no admite `DELETE` desde la aplicación (`§5.2`) |
| **Sacar `UserSession` entera del *observer*, como `ADR-036` con `Tenant`** (opción C de `§3`) | Dejaría sin auditar la revocación, los siete cierres de `§B.4.6` y el borrado lógico, que son hechos reales que nadie más registra. Cambia un problema de volumen por un agujero de `INV-003` |
| **No registrar `login` y quedarse con el `created` genérico** | Invierte la decisión de `ADR-039 §4.2`, que exige que el `event` nombre el hecho. Un acceso al sistema registrado como «creación de una fila de sesión» es el mismo defecto que ese ADR prohibió, con otro disfraz. Y `ADR-039` está en vigor: cambiarlo exigiría sustituirlo, no rodearlo |
| **Exclusión condicionada en tiempo de ejecución al flujo de login** | Obliga al mecanismo a saber quién le llama —bandera de ámbito o inspección de contexto—, estado implícito difícil de testear, en un mecanismo transversal a 53 módulos, para expresar una condición hoy siempre cierta (`§4.4`) |
| **Filtrar dentro de `AuditRecorder` en vez de en el *trait*** | `AuditRecorder` es también el camino manual de `ADR-039 §4.5`. Una llamada explícita desaparecería en silencio por una declaración escrita en otro fichero (`§4.2`) |
| **Lista central de exclusiones en `config/audit.php`** | Quien modifica un modelo no abre `config/audit.php`. Una exclusión invisible desde la clase afectada es la forma en que un registro de auditoría se degrada sin que nadie lo note (`§4.1`, `§5.5`) |
| **Excluir `created` para toda entidad «técnica», por patrón o por marca de interfaz** | «Técnica» no es una categoría de este producto: `UserSession` guarda IP y `User-Agent` de una persona identificada. Un patrón convierte cada modelo nuevo en una exclusión accidental (`§5.5`) |
| **Reducir el problema bajando la retención de `audit_logs`** | La retención de dos años la fija `REQ-CORE-005` por motivos de cumplimiento, no de volumen. Cambiarla para ahorrar filas redundantes sería resolver el problema equivocado con la palanca equivocada |

---

## 8. Cambios que este ADR obliga a hacer

Los ejecuta `implementer` en `feature/REQ-AUTH-005-1.2b-sesiones-activas`. Este ADR no los implementa.

1. **`apps/api/app/Support/Audit/Auditable.php`** — cuarto método del contrato, `auditExcludedEvents(): array`, documentado con referencia a este ADR.
2. **`apps/api/app/Support/Audit/HasAuditableAttributes.php`** — implementación por defecto que devuelve `[]` con cuerpo literal, no leyendo una propiedad del modelo (`§4.1`). Ningún modelo existente cambia.
3. **`apps/api/app/Support/Audit/RecordsAuditTrail.php`** — el enganche `created` consulta la exclusión y retorna antes de llamar a `AuditRecorder`, junto a la supresión ya existente del `updated` de `SoftDeletes`. `AuditRecorder` **no se toca** (`§4.2`).
4. **`UserSession`** (`app/Modules/Auth/Domain/Models/`) — declara `['created']`, con un comentario de una línea que cite este ADR y `ADR-039`. Ninguna otra exclusión en todo el repositorio.
5. **Tests** — los tres de `§4.4`: un login escribe exactamente una fila (`login`, ninguna `created`); una revocación individual **y** una masiva escriben su `updated` con `session_id` redactado (`CA-AUTH-102`); test de arquitectura que fija el conjunto de exclusiones declaradas del repositorio en exactamente una (`INV-015`).
6. **`docs/modulos/REQ-AUTH/funcional.md`** — `OPEN-AUTH-16` queda cerrada por este ADR, y `§B.10` deja de ofrecer las dos salidas (a)/(b): la decisión es (b).
7. **`docs/modulos/REQ-AUTH/datos.md §B.2`** — la nota «sobre el evento `created`» se sustituye por la referencia a este ADR y a `§4.3`.
8. **`docs/modulos/REQ-CORE/datos.md`** — documentar que el mecanismo automático admite exclusión declarada por modelo y evento, con la única vigente y el procedimiento de `§4.5`.
9. **`docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md §18`** — entrada de `ADR-040` en la tabla de ADR en fichero propio (hecho).

## 9. Preguntas abiertas

Ninguna. La decisión está tomada por el usuario (`funcional.md §B.14`, punto 4) y su alcance está cerrado en `§4.3`.

Queda anotado, sin abrir pregunta, el disparador de revisión de `§4.4`: **si algún paso futuro crea filas de `user_sessions` fuera del flujo de login, este ADR se sustituye.** El test de arquitectura de `§4.4` y el test de «un login, una fila» son quienes lo detectan; no se confía en que alguien lo recuerde.
