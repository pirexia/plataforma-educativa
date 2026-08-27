# Manual de administración

> Documento vivo, se amplía en cada fase con las pantallas que existan. Hoy cubre lo que ya tiene código detrás: el registro de auditoría (paso 0.9), las cuentas bloqueadas y el tiempo de sesión (paso 1.2), la autenticación en dos pasos por rol (paso 1.3), y el correo como segundo factor, las excepciones temporales y la pantalla mínima de administración de MFA (paso 1.3b). El resto de secciones del manual de Administrador de Centro (usuarios, roles, módulos contratados) llegan con `REQ-BO` (paso 1.6) y las pantallas restantes de `REQ-CORE` (1.1, `OPEN-CORE-02`, con 1.8).

## Cuentas bloqueadas

### Qué es

Tras 5 intentos fallidos de inicio de sesión seguidos, el sistema bloquea la cuenta automáticamente durante 15 minutos (por defecto) para frenar un ataque por fuerza bruta. La persona recibe un correo con un enlace propio para desbloquearla antes de que pase ese tiempo. Como administrador del centro, también puedes desbloquearla tú directamente, sin esperar a que la propia persona lo haga ni a que pasen los 15 minutos.

### Cómo consultarlas y desbloquear una cuenta

El listado de cuentas bloqueadas se filtra por estado (vigente o ya levantado) y se puede buscar por correo. Cada fila muestra desde cuándo está bloqueada, cuántos intentos fallidos la provocaron, y si ya se levantó, cómo (por la propia persona, por caducidad del plazo, o por un administrador). Levantar un bloqueo desde aquí tiene efecto inmediato: la persona puede volver a intentar iniciar sesión con su contraseña de siempre en el momento en que lo haces, sin esperar ningún correo.

## Tiempo de sesión del centro

### Qué es

Cuánto tiempo puede estar una persona sin actividad en la aplicación antes de que su sesión se cierre sola por seguridad (entre 5 minutos y 8 horas). Es un valor único para todo el centro, no por persona ni por rol: se configura junto con el resto de opciones de seguridad del centro, y se aplica a toda sesión nueva que se abra después del cambio — no cierra de golpe las que ya estaban abiertas con el valor anterior.

## Autenticación en dos pasos (MFA)

### Qué es

La autenticación en dos pasos (o «segundo factor», MFA) añade, además de la contraseña, un código de un solo uso: uno que cambia cada 30 segundos y que solo genera la aplicación de autenticación del propio dispositivo de la persona, o —si tu centro lo activa— un código de 6 dígitos que se envía por correo a la dirección de acceso. Como administrador del centro puedes hacerla obligatoria para determinados roles, consultar quién la tiene activada y quién no, restablecerla cuando alguien pierde el acceso a su dispositivo, y conceder excepciones temporales a quien no pueda cumplirla todavía.

Todo lo de esta sección —consultar cumplimiento, activar la obligatoriedad por rol, restablecer y conceder o revocar excepciones— se gestiona desde una única pantalla, `/administracion/mfa`. Es una pantalla provisional y mínima: no tiene editor de roles ni matriz de permisos (eso llega con `1.5`), y solo la ven quienes tienen los permisos de cada acción concreta — si te falta alguno, la propia pantalla te lo dice al intentarlo, en vez de ocultarte la opción sin explicación.

**Antes de activar el correo como segundo factor, ten en cuenta esto:** un código por correo protege menos que la aplicación de autenticación, porque si el buzón de alguien está comprometido, su segundo factor también lo está — y es el mismo buzón al que va la recuperación de contraseña. Actívalo solo si de verdad hay personas en tu centro sin un teléfono compatible con la aplicación de autenticación; no lo actives «por si acaso» ni como alternativa cómoda a la aplicación. El segundo factor de la aplicación de autenticación **no se puede desactivar** para el centro entero: siempre estará disponible, se active o no el correo.

### Hacer obligatorio el segundo factor para un rol

Desde la pantalla de roles, cada rol tiene un interruptor de «segundo factor obligatorio». Al activarlo, todas las personas que tengan ese rol pasan a estar obligadas a configurar su segundo factor **en los siete días siguientes** (plazo de gracia, configurable por el centro); durante esos días verán un aviso en cada acceso con los días que les quedan. Pasado el plazo sin haberlo configurado, la persona sigue pudiendo entrar con su contraseña, pero llega a una pantalla de la que no puede salir hasta darse de alta el segundo factor — no pierde el acceso a la aplicación, pero no puede usar ninguna otra pantalla hasta completarlo.

Los roles **Administrador de centro** y **Soporte de la plataforma** llevan el segundo factor obligatorio activado por defecto desde que existe el producto, aunque hasta ahora no tenía ningún efecto. Si tu centro está actualizando a una versión que ya lo aplica, las personas con esos dos roles empezarán a ver el aviso de plazo el día del cambio, sin que hayas tenido que activar nada tú.

Desactivar el interruptor no borra el segundo factor de nadie que ya lo tenga configurado: simplemente deja de exigirse a quien todavía no lo tenía.

### Consultar quién cumple

Hay dos vistas de cumplimiento, ambas de solo lectura:

- **Resumen por rol**: cuántas personas de un rol tienen el segundo factor activado, cuántas están dentro del plazo de gracia y cuántas ya lo tienen exigible sin haberlo configurado. Sirve también para simular el efecto de activar la obligación en un rol **antes** de activarla de verdad, sin cambiar nada todavía.
- **Listado individualizado**: el nombre y el correo de cada persona, con su estado de cumplimiento. Es información sensible a propósito restringida — dice exactamente a quién le falta el segundo factor, que es también decir a quién sería más fácil atacar — así que solo la ven quienes tienen el permiso específico de MFA, no cualquiera que pueda consultar el listado general de usuarios del centro.

### Restablecer el segundo factor de una persona

Si alguien pierde su dispositivo (o el acceso a los códigos de respaldo que se le entregaron al activarlo), puedes restablecer su segundo factor desde tu panel de administración: la persona vuelve a quedar sin segundo factor configurado, con un plazo de gracia completo desde ese momento si su rol lo exige.

**Antes de restablecerlo, verifica su identidad.** No es un trámite opcional ni una casilla que se marca sin más — es la única defensa contra que alguien se haga pasar por otra persona para quitarle el segundo factor y entrar en su lugar. Verifícala por uno de estos dos caminos:

1. **En persona**: pide su documento de identidad y compruébalo contra el registro del centro.
2. **A distancia**, cuando no sea posible en persona: por un canal **distinto** del que se está intentando recuperar — por ejemplo, una videollamada mostrando el documento de identidad, o una llamada al número de teléfono que **ya** tenías registrado del centro (nunca a un número que la persona te dé en ese mismo momento: eso no verifica nada).

Un correo pidiendo el restablecimiento, una llamada entrante sin cotejar el número, o «reconocer la voz» **no son verificación válida** — precisamente porque el correo o el teléfono de esa persona pueden ser lo que está comprometido.

Al restablecer, el sistema te pide un motivo (mínimo 10 caracteres) que queda guardado junto con tu nombre y la fecha. Describe también **cómo verificaste la identidad**, no solo por qué lo pierde — por ejemplo «presencial, DNI cotejado, perdió el móvil» en vez de solo «perdió el móvil» — porque es el único registro de que la verificación ocurrió. La persona afectada recibe una notificación automática de que su segundo factor se ha restablecido, y todas sus sesiones abiertas se cierran en el acto.

**No puedes restablecer tu propio segundo factor**, tengas el permiso que tengas: si pudieras, cualquiera con ese permiso podría quitarse a sí mismo la obligación en cualquier momento. Si pierdes tu propio dispositivo, necesitas que otro administrador de centro te lo restablezca a ti siguiendo el mismo procedimiento. Si tu centro solo tiene un administrador de centro y ese es quien pierde el acceso, no hay salida desde la propia aplicación — contacta con soporte de la plataforma.

### Conceder una excepción temporal

Hay situaciones en las que alguien no puede configurar su segundo factor todavía y aun así necesita entrar: acaba de perder su dispositivo y está pendiente de uno nuevo, por ejemplo. Para esos casos puedes conceder una **excepción temporal nominal**: mientras dura, esa persona entra solo con su contraseña, sin que el sistema se lo impida ni le muestre la pantalla de la que no puede salir.

Una excepción exige siempre:

- **Un motivo de al menos 10 caracteres**, que queda guardado junto con tu nombre y la fecha — igual que al restablecer un segundo factor. Describe la situación con la misma precisión, y **nunca incluyas datos de salud** en el motivo: quien tenga permiso para leer excepciones podrá leer también este texto.
- **Una fecha de caducidad**, obligatoria y de como mucho 90 días vista. No existe la excepción permanente: pasada esa fecha, la persona vuelve a estar obligada, con el mismo plazo de gracia completo que tendría si acabaran de asignarle el rol — no se le da menos tiempo por haber tenido ya una excepción.

**No puedes concederte una excepción a ti mismo**, por el mismo motivo que no puedes restablecerte tu propio segundo factor: si pudieras, la excepción sería un interruptor para apagar tu propia obligación en cualquier momento. Sí puedes **revocar la tuya propia** si ya no la necesitas — renunciar a una excepción no tiene el mismo riesgo que concedérsela.

Mientras dura una excepción, la persona **también puede desactivar su segundo factor** si lo tenía activado — es una consecuencia aceptada del mecanismo, no un error: quien está exento de la obligación lo está de verdad mientras dure.

Puedes consultar en cualquier momento quién tiene una excepción viva, por qué se le concedió y quién la concedió, y revocarla antes de su caducidad si la situación cambia. Revocarla no borra el registro: queda constancia de que existió y de cuándo se retiró, igual que con un bloqueo de cuenta levantado.

## Registro de auditoría

### Qué es

Cada creación, modificación, borrado y restauración de un dato de negocio queda registrada de forma automática e inmutable: quién, qué, cuándo y desde dónde. Es un requisito legal (`INV-003`) y una herramienta de trabajo: permite responder "¿quién cambió esto y cuándo" sin depender de que alguien se acuerde.

### Por qué algunos cambios muestran "valor no registrado"

Al consultar el historial de un alumno, un tutor o un empleado, vas a encontrar entradas como:

> `document_number` — valor no registrado (identificador)
> `given_name` — valor no registrado (identificador)

Esto **no es un fallo**. El registro de auditoría existe para saber *que* alguien cambió el documento de identidad de una persona, no para guardar una segunda copia sin cifrar de ese documento. Guardar el valor anterior de un dato personal en una tabla que nunca se puede editar ni borrar entraría en conflicto directo con el derecho de las familias, del personal y de los propios alumnos a pedir la supresión de sus datos — un conflicto que no tiene una solución intermedia satisfactoria (detalle técnico y legal completo en `docs/adr/ADR-035-datos-personales-en-el-registro-de-auditoria.md`, si lo necesitas).

Lo que sí ves siempre, aunque el valor esté redactado:

- **Qué atributo cambió** (el nombre del campo).
- **Cuándo** y **quién** lo hizo (o "sistema"/"consola" si no hubo un usuario detrás).
- **Si el campo pasó de estar vacío a tener contenido, o al revés** — por ejemplo, para responder "¿alguien borró el teléfono de contacto de esta familia?" sin necesidad de conservar el número.

### Motivos de redacción que puedes encontrar

| Lo que ves | Qué significa |
|------------|----------------|
| `valor no registrado (identificador)` | El campo identifica directamente a una persona (nombre, documento, fecha de nacimiento, contacto...) y la política del sistema es no duplicarlo en el histórico |
| `valor no registrado (categoría especial)` | El campo pertenece a un dato de salud, necesidades educativas especiales o convivencia — nunca se registra su valor, ni aquí ni en ningún otro sitio fuera de su tabla propia y cifrada |
| `valor no registrado (secreto)` | Contraseñas, códigos de verificación y similares. Nunca se registran, bajo ninguna circunstancia |
| `valor no registrado (texto largo)` | El valor era demasiado extenso (más de 256 caracteres) para descartar que contuviera datos personales sin clasificar — se prefiere no registrarlo a arriesgarse |

### Qué campos sí muestran el valor completo

Los campos que no identifican a nadie por sí solos (por ejemplo, el idioma de comunicación preferido de una persona, o el estado de una cuenta) sí muestran el valor anterior y el nuevo con normalidad. Y para entidades sin ningún dato personal — cursos académicos, roles, módulos contratados — el historial es completo, sin ninguna redacción.

### Si necesitas el valor anterior de un dato redactado

No está disponible por diseño. Si tu centro tiene una necesidad real de conservar el histórico de un campo concreto (por ejemplo, el historial de cambios de documento de identidad ante un caso de fraude), es un requisito a plantear para que se modele como un histórico propio de ese dato — con su propia regla de conservación — no como una excepción al registro de auditoría general.
