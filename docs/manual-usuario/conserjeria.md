# Manual de conserjería

> Entrega de alumnos en la puerta (`REQ-PRL-004`, pasos `1.14b-b`, `1.14b-c2` y `1.14b-d1`). Para el personal de conserjería y para quien tenga permisos de entrega de menores. La sección equivalente, desde el punto de vista de dirección, está en `docs/manual-usuario/direccion.md` («Conserjería: entrega de alumnos en la puerta»). **Capturas de pantalla: pendientes** (no se han podido generar; se añadirán con el centro `demo`). **Idiomas**: este manual está solo en castellano; algunos textos de seguridad de la pantalla están todavía solo en castellano hasta que una persona los traduzca.

## Qué es la puerta de entrega de menores

Es la pantalla **Conserjería** (`/conserjeria`, en el menú lateral). Sirve para decidir, en la puerta, **a quién se puede entregar un alumno** y dejar constancia de ello. El sistema calcula, a la hora de la consulta, la **lista de personas que pueden recoger a ese alumno ahora** (tutores con acceso vigente y personas autorizadas, incluida una autorización «solo hoy»). La regla de oro:

> **No entregues al alumno a nadie que no esté en esa lista.** Si la lista está vacía, no lo entregues y avisa a dirección.

Qué puedes hacer depende de tus permisos: las acciones que no tienes no aparecen. Registrar entradas y salidas requiere el permiso de crear entregas de menores (`entrega_menor.crear`); conserjería y administración lo tienen, **dirección solo consulta**. Abrir una alerta requiere crear alertas de entrega (`alerta_entrega.crear`). Si te falta uno, díselo a la administración del centro.

## Salida: entregar un alumno

1. **Busca al alumno.** Escribe al menos 2 letras del nombre o de los apellidos en **«Buscar alumno por nombre o apellidos»**. Cada resultado muestra la **unidad familiar**; elige al alumno.
2. **Lee la lista de recogida.** Aparece **«Lista calculada a las …»** y, por cada persona: fotografía, relación (**«Tutor/a»**, **«Autorizado/a»** o **«Solo hoy»**), documento (parcialmente oculto) y teléfono. Esta consulta **queda registrada con tu usuario, la fecha y la hora**, y hay un **cupo de consultas por hora**. Si ha pasado un rato, pulsa **«Volver a cargar la lista»**: puede haber cambiado (una revocación, una retirada).
3. **Compara a quien se presenta con la fotografía** y, si procede, con su documento.
4. **Pulsa «Entregar a …»** junto a esa persona. Elige **«Cómo has comprobado su identidad»**: **«Por la fotografía»**, **«Por el documento»** o **«Por la fotografía y el documento»**. Si la persona **no tiene fotografía**, o la fotografía no se puede mostrar, solo vale **«Por el documento»**: verifica con el documento.
5. **Pulsa «Registrar».** Aparece «Salida registrada: … a las …». La hora la pone el servidor.

El sistema **vuelve a comprobar al registrar** que la persona sigue figurando en la lista. Si en esos segundos dejó de figurar, la salida **no se registra**, el diálogo te lo dice y te ofrece **«Volver a cargar la lista»** o **«Abrir una alerta»**: no entregues al alumno.

**Si pulsas dos veces o falla la conexión.** Reintentar el mismo envío no duplica la salida: el sistema reconoce que es el mismo intento. No hace falta que compruebes si «se ha guardado»; vuelve a pulsar **«Registrar»**.

## Entrada, incluida la entrada fuera de horario

Con **«Registrar entrada fuera de horario»** (en la ficha del alumno, mismo permiso `entrega_menor.crear`) registras que el alumno entra fuera de su horario. Indica el **motivo** (**«Médico»**, **«Familiar»**, **«Actividad»**, **«Retraso»** u **«Otro»**); si eliges **«Otro»**, escribe una **nota** (hasta 500 caracteres). Al confirmar aparece «Entrada registrada a las …».

Una **salida fuera de horario** se registra como una salida normal (pasos anteriores), marcando **«Fuera de horario»** y el mismo motivo (y la nota si es «Otro»).

## Alerta: quien se presenta no está en la lista

Si la persona **no figura** en la lista, **no entregues al alumno**:

1. Pulsa **«Quien se presenta no está en la lista»** y, en **«Nombre que dice tener y, si lo enseña, su documento»**, describe a la persona (hasta 500 caracteres). Pulsa **«Abrir alerta»**.
2. Aparece **«Qué hacer ahora»** con el protocolo del centro: *retén al alumno en el centro, avisa a dirección y llama al tutor principal con el teléfono que figura en la alerta; anota después la hora de la llamada y la resolución*. Verás los teléfonos del **tutor principal** y del **contacto de emergencia** (o «No disponible»).
3. **Llama tú.** El sistema **no avisa a nadie automáticamente**.
4. **Avisa a dirección.** Registrar la llamada y cerrar la alerta lo hacen dirección y la administración del centro (`alerta_entrega.actualizar`); a conserjería se le muestra «Avisa a dirección para que registre la llamada y cierre la alerta». Una alerta cerrada **no se puede reabrir ni borrar**.

Si la persona debe recoger al alumno de forma excepcional, el cauce es una **autorización puntual** registrada por quien tenga permiso (secretaría, tras devolver la llamada al tutor principal; conserjería no puede darla de alta); después aparece en la lista como **«Solo hoy»** y la salida se registra con normalidad. Nunca se «salta» la lista.

En **Alertas de entrega** (`/conserjeria/alertas`, `alerta_entrega.leer`) ves las alertas, con filtro por estado y por **«Fecha de apertura»**. El listado muestra nombres, no teléfonos. Abrir y consultar una alerta abierta (donde están los teléfonos) **también queda registrado y gasta cupo de consultas**.

## Si algo falla

| Lo que ves | Qué significa | Qué hacer |
|------------|---------------|-----------|
| «La puerta está ocupada registrando otro cambio de esta persona o de este alumno. Inténtalo de nuevo en un segundo.» (error `503`, `prl.errors.busy`) | Otro cambio sobre la misma persona o el mismo alumno (p. ej. una revocación hecha en ese instante por secretaría) estaba terminando. **No se ha registrado nada.** | **Espera un segundo y vuelve a pulsar «Registrar».** No se duplica. Si se repite varias veces seguidas, avisa a administración: indica que hay una operación larga en curso. La pantalla no reintenta sola. |
| «Has alcanzado el límite de consultas de la lista de recogida y de fotografías (o de alertas y movimientos). Inténtalo de nuevo en unos N minutos.» / «Vuelve a intentarlo en N segundos.» (error `429`) | Has agotado el cupo de consultas por hora. | Espera el tiempo indicado, o avisa a administración, que lo gestiona con quien administra el sistema. **No uses ese rato para entregar al alumno «de memoria»**: usa el procedimiento de contingencia del centro. |
| «Esa persona no figura en la lista de recogida de este alumno: no se le puede entregar.» | Dejó de figurar entre la consulta y el registro, o nunca figuró. | **No entregues.** Pulsa **«Volver a cargar la lista»** o **«Abrir una alerta»**. |
| «Nadie puede recoger a este alumno en este momento…» | La lista está vacía. | No lo entregues y avisa a dirección. |
| «No tienes permiso para ver esta información.» / «No se encuentra esta información.» | Te falta el permiso o el dato ya no existe. | Avisa a administración. |

Si la pantalla no está disponible, el centro tiene un **procedimiento de contingencia** (consúltalo con dirección). **Sigue sin valer entregar a quien no se pueda comprobar.**

## Qué no debes hacer

- **No apuntes fuera del sistema** datos de alumnos, de tutores ni de las personas que recogen (papel, móvil, hojas de cálculo, mensajes): son datos de menores y de terceros. Lo que haya que dejar anotado se registra en el sistema (el motivo, la nota, la alerta).
- No entregues a quien no figura en la lista, aunque lo conozcas o te lo pidan por teléfono.
- No compartas tu sesión: cada consulta y cada registro queda a nombre de tu usuario.

## Pendiente de este manual

- Capturas de pantalla del centro `demo` (no se han podido generar al redactarlo).
- Traducciones (en, de, fr) conforme a la política vigente de manuales.
