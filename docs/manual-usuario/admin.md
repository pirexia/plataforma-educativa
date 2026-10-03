# Manual de administración

> Documento vivo, se amplía en cada fase con las pantallas que existan. Hoy cubre lo que ya tiene código detrás: el registro de auditoría (paso 0.9), las cuentas bloqueadas y el tiempo de sesión (paso 1.2), la autenticación en dos pasos por rol (paso 1.3), el correo como segundo factor, las excepciones temporales y la pantalla mínima de administración de MFA (paso 1.3b), la navegación, el panel de inicio, el selector de idioma y el control de modo de color (paso 1.8), el manejo común de las tablas de datos (paso 1.9), los usuarios y las invitaciones, con la exportación de usuarios y los diálogos de confirmación (paso 1.9b), y la importación de usuarios con el catálogo de tipos de documento (paso 1.9c). El resto de secciones del manual de Administrador de Centro (auditoría, roles, módulos contratados, configuración del centro, activos de marca, perfil propio) llegan con los demás sub-pasos de las pantallas pendientes de `REQ-CORE` (**1.9d** a **1.9f**, `OPEN-CORE-30`); el editor de roles y la matriz de permisos llegan con `REQ-PERM` (paso **1.5b**, posterior a 1.9) — el núcleo de autorización granular ya está implementado desde 1.5, pero solo por API, sin interfaz todavía.

## Navegación y panel de inicio

Al entrar, ves el panel de inicio (`/`): un saludo con tu nombre y el del centro, un aviso si tienes pendiente activar el segundo factor de acceso (con el enlace directo para hacerlo), y tus accesos directos — las pantallas del menú marcadas como tales, o un mensaje si todavía no tienes ninguna disponible.

El menú de navegación, a la izquierda en pantallas grandes y tras el botón de menú en móvil y tableta, muestra únicamente lo que tu perfil puede usar: si no ves una entrada que esperabas, es que tu rol no tiene el permiso correspondiente, no un error de la aplicación — pídelo a quien administre los roles del centro. La miga de pan, sobre cada pantalla, marca dónde estás dentro de esa navegación.

## Menú de usuario, idioma y modo de color

El menú de usuario (arriba a la derecha, con tu nombre) reúne el acceso a tu contraseña, tus sesiones abiertas, la seguridad de tu cuenta, el selector de idioma, el control de modo de color y cerrar sesión.

**Idioma**: el selector ofrece los idiomas que el centro tiene activos (de los cuatro disponibles: español, inglés, alemán, francés), cada uno en su propia lengua. El cambio es inmediato, sin recargar la página. Si el centro retira mientras tanto el idioma que tenías elegido, la próxima vez que entres verás la interfaz en el idioma por defecto del centro.

**Modo de color**: tres opciones — seguir el sistema operativo (por defecto), claro fijo, oscuro fijo. Es una preferencia de tu navegador, no se guarda en el servidor ni se comparte entre tus dispositivos.

## Tablas de datos: filtrar, ordenar, elegir columnas y exportar

Todas las listas de la aplicación (hoy, el cumplimiento de segundo factor de `/administracion/mfa`; después, usuarios, invitaciones o auditoría) se manejan igual. Esta sección es común a los manuales de todos los perfiles.

- **Filtrar**. Encima de la lista hay una barra de herramientas. Los filtros de varias opciones (por ejemplo, el estado) son menús con casillas: marcas todas las que quieras y se muestran las filas que cumplan **cualquiera** de ellas. Los filtros por fechas tienen un «desde» y un «hasta», ambos inclusivos. Si hay un campo de búsqueda, la lista se actualiza cuando dejas de escribir, no con cada tecla. «Limpiar filtros» aparece en cuanto hay alguno activo y devuelve la lista completa. Si un filtro no es válido (por ejemplo, un rango de fechas demasiado largo), la aplicación te lo dice junto a los filtros y **sigue mostrando los resultados anteriores, avisándote de que el filtro no se ha aplicado**: nunca te muestra como filtrada una lista que no lo está.
- **Ordenar**. Pulsa el nombre de una columna ordenable: primero ascendente, luego descendente, y una tercera pulsación quita el orden (vuelve al orden habitual de la lista). Solo se ordena por una columna a la vez. En el móvil, donde no hay cabeceras, el orden se elige con el botón «Ordenar».
- **Paginar**. Las listas de catálogo (personas, roles, invitaciones) tienen paginador numerado y eliges cuántas filas ver por página (25, 50 o 100). Las listas de sucesos (como la auditoría) no tienen páginas: un botón «Cargar más» añade filas al final. Se pueden cargar hasta **1.000 filas**; al llegar al máximo, la aplicación te pide que acotes los filtros o exportes.
- **Elegir columnas**. El botón «Columnas» te deja mostrar u ocultar columnas, y «Restablecer columnas» deja las que la pantalla muestra por defecto. La columna que identifica la fila y la de acciones no se pueden ocultar. Es una preferencia de **tu navegador**, no de tu cuenta: no viaja a otros dispositivos y, si compartes navegador con otra persona, compartís la configuración de columnas. Ocultar una columna solo cambia lo que ves; no cambia los datos que el servidor envía ni lo que se exporta.
- **En el móvil**. Por debajo de 768 píxeles, cada fila se muestra como una tarjeta con sus datos en lista. Algunas tablas de comparación (por ejemplo, calificaciones) se muestran siempre como tabla, con desplazamiento horizontal dentro de ella; se puede llegar a esa tabla y moverse por ella con el teclado.
- **Vínculos compartibles**. Si la pantalla lo admite, la página, el orden y los filtros quedan en la dirección del navegador: puedes volver atrás, recargar o enviar el enlace a un compañero y veréis lo mismo. **El texto de búsqueda nunca se guarda en la dirección** (puede contener nombres de personas).
- **Exportar** (solo si tu rol tiene el permiso de exportar ese recurso). El botón «Exportar» no descarga lo que ves en pantalla: pide al servidor que prepare un fichero con **todas** las filas que cumplen los filtros estructurados actuales (sin tener en cuenta el orden de la pantalla), y te avisa cuando está listo con un enlace de descarga que caduca. **Con una búsqueda escrita, el botón queda deshabilitado**: borra la búsqueda y los demás filtros sí se aplican. La preparación se hace en segundo plano; mientras tanto puedes seguir trabajando, pero **si sales de la pantalla no podrás descargar esa exportación desde aquí** (aparece un aviso). Si la exportación tarda más de 10 minutos, la pantalla deja de comprobarla y te ofrece «Comprobar de nuevo».
- **Abrir el CSV en Excel**. Los ficheros CSV usan la coma como separador y codificación UTF-8. En Excel con configuración regional española, un doble clic puede mostrar todo en una sola columna. Para verlo bien: abre Excel en un libro vacío, ve a *Datos > Obtener datos > Desde texto/CSV*, elige el fichero y, en el cuadro de importación, indica como delimitador la **coma** y como origen **UTF-8**. Los acentos se ven bien porque el fichero lleva marca de orden de bytes (BOM). Las celdas de texto que empezarían por `=`, `+`, `-` o `@` llevan un apóstrofo delante para que Excel no las ejecute como fórmula.
- **Columnas y valores del CSV**. Los ficheros exportados usan nombres técnicos de columna (`occurred_at`, `actor`, `actor_type`, `auditable_type`, `auditable_public_id`, `event`, `request_id`) y códigos de evento sin traducir (la columna `actor` lleva el nombre de la persona tal como figura en el sistema), iguales para todos los usuarios y en cualquier idioma, para que puedan procesarse con programas sin sorpresas. Las columnas con texto legible por idioma no están disponibles de momento. Esto describe el fichero de la auditoría; el de usuarios tiene sus propias doce columnas (véase «Exportar la lista de usuarios»).

## Usuarios

Menú **Administración > Usuarios** (`/administracion/usuarios`). Solo lo ves si tu rol tiene el permiso de consultar usuarios; cada botón de esta pantalla y de la ficha aparece solo si tu rol tiene el permiso de esa acción concreta (si falta uno, pídelo a quien administre los roles del centro).

### La lista

La lista se maneja como el resto de tablas (sección «Tablas de datos»). Muestra el nombre («Apellidos, Nombre», con enlace a la ficha), el correo de acceso, el estado y los roles; el idioma y la fecha de alta están ocultos por defecto (botón «Columnas»). **No muestra el documento de identidad, la fecha de nacimiento ni los datos de contacto**: la lista sirve para localizar a una persona; esos datos están en su ficha.

- **Buscar** por nombre, apellidos o correo de acceso. La búsqueda no queda en la dirección del navegador.
- **Filtrar** por estado (pendiente, activo, inactivo), por **rol** (solo si tu rol puede consultar roles) y por idioma. **Incluir dados de baja** (solo si tu rol puede dar de baja usuarios) es una casilla: desmarcada, no se ven; marcada, aparecen con el estado «Dado de baja».
- **Ordenar** por nombre, correo de acceso o fecha de alta.
- **Exportar** (solo con el permiso de exportar usuarios): véase «Exportar la lista de usuarios».
- **Nuevo usuario** abre el formulario de alta.

### La ficha

Muestra todos los datos de la persona: estado, correo de acceso, tipo y número de documento, fecha de nacimiento, idioma, correo y teléfono de contacto, verificación del correo, fechas de alta y de baja, y sus roles. Las acciones dependen de tu rol y del estado de la cuenta:

| Acción | Cuándo está disponible |
|--------|------------------------|
| **Editar** | Cuenta no dada de baja |
| **Activar / Desactivar** | Cuentas activas o inactivas. Una cuenta **pendiente** no se activa a mano: la activa la propia persona con su invitación. **Desactivar pide confirmación**; activar no |
| **Enviar invitación** | Cuentas pendientes. Pide confirmación. Emite un enlace nuevo y el anterior deja de valer; se avisa de cuándo caduca. Hay un límite de reenvíos por hora: si lo superas, la pantalla te dice cuántos segundos esperar |
| **Dar de baja** | Cuenta no dada de baja. Pide confirmación. La cuenta deja de estar disponible y no puede iniciar sesión; se puede restaurar |
| **Restaurar** | Cuenta dada de baja. No pide confirmación. **La cuenta vuelve como «inactiva»**: usa «Activar» si la persona debe volver a entrar |

**Tu propia cuenta**: desde la ficha de tu propia persona, desactivar, dar de baja y cambiar roles están deshabilitados («No puedes modificar tu propia cuenta desde aquí»). **Siempre debe quedar al menos un Administrador de Centro activo**: si una acción lo impide, el sistema te lo explica con su propio mensaje y no cambia nada.

**Roles de la persona**: la ficha lista sus roles. Con el permiso de asignar roles, marca o desmarca roles y pulsa «Guardar roles». **No puedes conceder un rol que incluya permisos que tú no tienes**: el sistema lo rechaza y muestra el motivo junto a los roles. Quitar un rol pide confirmación, porque la persona pierde los permisos que concedía.

Las confirmaciones son un diálogo que nombra a la persona y la consecuencia («Dar de baja a Ana López»). Se cierran con la tecla **Esc** o con «Cancelar», sin hacer nada, y devuelven el foco al botón que las abrió.

### Alta y edición

El formulario pide: correo de acceso, nombre y primer apellido (obligatorios, marcados con *), y opcionalmente segundo apellido, fecha de nacimiento, tipo y número de documento, correo y teléfono de contacto e idioma preferido (solo los idiomas activos del centro). En el alta aparecen además los **roles** (si tu rol puede asignarlos) y la casilla «Enviar invitación por correo», marcada por defecto. En la edición solo se envían los campos que cambias.

- **Tipo de documento**: se elige de una lista cerrada (**DNI**, **NIE** o **Pasaporte**) o se deja en «Sin indicar». Tipo y número van **los dos o ninguno**: con «Sin indicar» el campo del número queda deshabilitado. El sistema guarda el número **normalizado**: sin espacios al principio ni al final y en mayúsculas, y en DNI y NIE además sin espacios ni guiones intermedios (`12345678-z` se guarda como `12345678Z`; los puntos no se quitan: `12.345.678-Z` es un error de formato). Dos personas del centro no pueden tener el mismo documento aunque lo escriban distinto. Formato de cada tipo: ver la tabla de «Importación de usuarios».
- Si algún dato no es válido o el correo ya lo usa otra cuenta, el formulario indica cada error junto a su campo, lo resume arriba y lleva el foco al primero.
- Al crear con invitación, la ficha te dice cuándo caduca el enlace. **El enlace en sí nunca se muestra**: solo le llega a la persona por correo.

## Invitaciones

Menú **Administración > Invitaciones** (`/administracion/invitaciones`). Lista los enlaces de activación enviados: usuario, estado (**vigente**, **caducada**, **revocada** o **aceptada**), caducidad, fecha de emisión y fecha de aceptación o revocación. Se filtra por uno o varios estados; no tiene búsqueda ni orden propio (siempre la más reciente primero). El correo enlaza con la ficha si tu rol puede consultar usuarios.

- **Revocar** (solo invitaciones vigentes): el enlace deja de funcionar. Pide confirmación.
- **Reenviar** (invitaciones caducadas o revocadas): emite un enlace nuevo. Pide confirmación. Si la persona ya activó su cuenta, el sistema lo indica y la fila se actualiza; si hay demasiados reenvíos seguidos, te dice cuántos segundos esperar.
- Las invitaciones **aceptadas** no admiten ninguna acción.

## Exportar la lista de usuarios

El botón «Exportar» de la lista de usuarios (solo con el permiso de exportar usuarios) prepara en segundo plano un fichero **CSV con todos los usuarios que cumplen los filtros** que tienes puestos (estado, rol, idioma, dados de baja); la búsqueda de texto no se aplica y, si hay una escrita, el botón queda deshabilitado. Te avisa con un enlace de descarga que **caduca a los 7 días**. Si la preparación falla, la pantalla lo dice enseguida («No se ha podido generar la exportación») y puedes volver a pedirla.

El fichero **no contiene el tipo ni el número de documento ni la fecha de nacimiento** (minimización de datos personales, sobre todo del alumnado menor de edad). Contiene exactamente estas doce columnas, con estos nombres y estos códigos, **iguales para todos los usuarios y en cualquier idioma**:

| Columna | Qué contiene |
|---------|--------------|
| `public_id` | Identificador único de la persona usuaria |
| `status` | Estado, como código: `pendiente`, `activo` o `inactivo` |
| `deleted_at` | Fecha y hora de la baja (ISO 8601); vacía si no está dada de baja |
| `created_at` | Fecha y hora del alta (ISO 8601) |
| `email` | Correo de acceso |
| `given_name` | Nombre |
| `family_name_1` | Primer apellido |
| `family_name_2` | Segundo apellido; vacío si no tiene |
| `contact_email` | Correo de contacto; vacío si no tiene |
| `contact_phone` | Teléfono de contacto; vacío si no tiene |
| `locale` | Idioma preferido: `es-ES`, `en`, `de` o `fr` |
| `roles` | **Códigos** de los roles, en orden alfabético y separados por `\|` (p. ej. `docente\|tutor`); vacío si no tiene |

Las filas van ordenadas por primer apellido, nombre e identificador, no por el orden de la pantalla. Para abrirlo en Excel con configuración regional española, sigue los pasos de «Abrir el CSV en Excel» (sección «Tablas de datos»). El fichero **no sirve para reimportar usuarios** sin editarlo: le faltan columnas de la importación (documento, fecha de nacimiento) y le sobran otras.

## Importación de usuarios

Menú **Administración > Importación de usuarios** (`/administracion/importaciones`). Solo lo ves si tu rol puede importar usuarios. Sirve para dar de alta a muchas personas a la vez desde un fichero CSV. **Una importación no se deshace**: antes de ejecutarla se valida, y hasta que la ejecutas no se crea nada.

### Cómo es el fichero

Un fichero **CSV** de hasta 10 MB y 20 000 filas (si tiene más, el lote falla y debes dividirlo), en UTF-8, con `;` o `,` como separador. La **primera fila** debe ser exactamente esta (la pantalla la muestra y tiene un botón «Copiar la cabecera»; no hay plantilla descargable):

```
email;given_name;family_name_1;family_name_2;document_type;document_number;birth_date;contact_email;contact_phone;locale;roles
```

| Columna | Qué poner | ¿Obligatoria? |
|---------|-----------|---------------|
| `email` | Correo de acceso. No puede repetirse en el fichero ni existir ya en el centro | Sí |
| `given_name` | Nombre | Sí |
| `family_name_1` | Primer apellido | Sí |
| `family_name_2` | Segundo apellido | No |
| `document_type` | Uno de los códigos de la tabla de abajo, con mayúsculas o minúsculas y con espacios alrededor si hace falta | No, pero va **junto** con `document_number` |
| `document_number` | Número del documento (se normaliza antes de comprobarlo) | No, pero va **junto** con `document_type` |
| `birth_date` | Fecha de nacimiento, `AAAA-MM-DD` | No |
| `contact_email` | Correo de contacto | No |
| `contact_phone` | Teléfono de contacto | No |
| `locale` | Idioma: `es-ES`, `en`, `de` o `fr`, uno de los **activos del centro** | No |
| `roles` | **Códigos** de rol separados por `\|` (p. ej. `docente\|tutor`); deben existir en el centro | No |

**Tipos de documento admitidos** (columna `document_type`; la pantalla de importación los lista con su nombre en tu idioma):

| Código | Documento | Formato del número (tras normalizar) | Control |
|--------|-----------|--------------------------------------|---------|
| `dni` | DNI (documento nacional de identidad) | 8 cifras y una letra (`12345678Z`) | Letra de control (módulo 23) |
| `nie` | NIE (número de identidad de extranjero) | `X`, `Y` o `Z`, 7 cifras y una letra (`X1234567L`) | Letra de control, igual que el DNI |
| `pasaporte` | Pasaporte, de cualquier país | Letras y cifras sin separadores, de 1 a 32 caracteres | Ninguno |

Para DNI y NIE el sistema ignora espacios y guiones al comprobar el número (`12345678-z` vale). No hay un tipo «otro»: si una persona no tiene ninguno de estos documentos, deja las dos columnas vacías.

### Subir y validar

1. Elige el fichero, decide si se **enviarán invitaciones** a las personas importadas (casilla marcada por defecto) y pulsa «Subir y validar».
2. Se abre el detalle del lote. Mientras está **subido**, **validando** o **ejecutando**, la pantalla consulta el estado sola y anuncia cada cambio. Si el lote no avanza en unos 10 minutos deja de consultar y ofrece «Comprobar de nuevo» (probablemente el proceso de importación no esté en marcha: avisa a quien administre el servidor).
3. Al terminar la validación, el lote queda **validado** y la pantalla muestra el número de filas, cuántas tienen errores y una tabla de **incidencias** con línea, columna y motivo. Solo se muestran las 50 primeras; el aviso te lo dice y el enlace «Descargar el informe completo» baja el CSV con todas (el enlace caduca a los 15 minutos; «Actualizar el enlace» pide uno nuevo). **El texto del motivo sale en el idioma de quien subió el fichero**, y se queda así para quien abra el lote después.
4. Si la cabecera del fichero no es la esperada, el lote queda **fallido** y se muestra el motivo y la cabecera correcta. No se puede ejecutar: descártalo y sube otro.

### Incidencias de documento

| Código | Columna | Significa |
|--------|---------|-----------|
| `tipo_documento_no_valido` | `document_type` | El tipo no es uno de los admitidos |
| `documento_incompleto` | `document_type` o `document_number` (la que falta) | Hay tipo sin número o número sin tipo |
| `formato_invalido` | `document_number` | El número no tiene el formato de su tipo o la letra de control no cuadra |
| `duplicado_en_fichero` | `document_number` | Otra fila del mismo fichero tiene el mismo documento (aunque esté escrito con o sin guion) |
| `duplicado_en_base_de_datos` | `document_number` | Una persona del centro tiene ya ese documento |

### Ejecutar y descartar

- **Ejecutar** solo está disponible con el lote **validado**. Pide confirmación: te dice **cuántos usuarios se crearán** (filas menos filas con error), que las filas con error se omiten, si se enviarán invitaciones y que la importación no se deshace. Las filas se revalidan al ejecutar: si entre tanto alguien creó a esa persona, la fila se omite. Si la petición falla sin respuesta (red caída), el botón «Reintentar la ejecución» repite **la misma** operación sin duplicar usuarios; una confirmación nueva es otra operación distinta.
- **Descartar** (lotes subidos, en validación, validados o fallidos) borra el fichero y el informe, con confirmación. Un lote ya ejecutado no se puede descartar.
- El listado de lotes muestra fichero, fecha de subida, estado, filas, filas con error y usuarios creados.

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

Todo lo de esta sección —consultar cumplimiento, activar la obligatoriedad por rol, restablecer y conceder o revocar excepciones— se gestiona desde una única pantalla, `/administracion/mfa`. Es una pantalla provisional y mínima: no tiene editor de roles ni matriz de permisos (eso llega con `1.5b`), y solo la ven quienes tienen los permisos de cada acción concreta — si te falta alguno, la propia pantalla te lo dice al intentarlo, en vez de ocultarte la opción sin explicación.

**Antes de activar el correo como segundo factor, ten en cuenta esto:** un código por correo protege menos que la aplicación de autenticación, porque si el buzón de alguien está comprometido, su segundo factor también lo está — y es el mismo buzón al que va la recuperación de contraseña. Actívalo solo si de verdad hay personas en tu centro sin un teléfono compatible con la aplicación de autenticación; no lo actives «por si acaso» ni como alternativa cómoda a la aplicación. El segundo factor de la aplicación de autenticación **no se puede desactivar** para el centro entero: siempre estará disponible, se active o no el correo.

### Hacer obligatorio el segundo factor para un rol

Desde `/administracion/mfa`, cada rol tiene un interruptor de «segundo factor obligatorio», con una vista previa de a cuántas personas afecta antes de confirmar el cambio. Al activarlo, todas las personas que tengan ese rol pasan a estar obligadas a configurar su segundo factor **en los siete días siguientes** (plazo de gracia, configurable por el centro); durante esos días verán un aviso en cada acceso con los días que les quedan. Pasado el plazo sin haberlo configurado, la persona sigue pudiendo entrar con su contraseña, pero llega a una pantalla de la que no puede salir hasta darse de alta el segundo factor — no pierde el acceso a la aplicación, pero no puede usar ninguna otra pantalla hasta completarlo.

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

## Inicio de sesión único (SSO) institucional

### Qué es

Permite que el personal del centro entre a la aplicación con las credenciales del propio centro (Microsoft Entra ID, Google Workspace u otro sistema de identidad compatible con OIDC o con SAML 2.0), en vez de una contraseña propia de esta plataforma. Lo configuras tú, desde `Administración → Proveedores de identidad`, dando de alta cada sistema de identidad como un proveedor.

Importante: entrar por aquí **nunca crea una cuenta nueva**, en ningún caso. Solo vincula el inicio de sesión institucional con una cuenta que ya exista en el centro, con el mismo correo. Si la persona no tiene todavía cuenta, tiene que dársela de alta primero de la forma habitual (invitación).

### Añadir un proveedor

Al pulsar «Añadir proveedor» eliges primero **qué tipo de sistema de identidad es** — OIDC o SAML 2.0 — según lo que use tu centro; una vez guardado, ese tipo no se puede cambiar (si te equivocas, borras el proveedor y das de alta uno nuevo del tipo correcto).

**Con OIDC** rellenas: un nombre visible (el que verá el personal en el botón de acceso), la URL de descubrimiento que publica tu sistema de identidad (termina en `.well-known/openid-configuration`), el identificador de cliente (`client_id`) que te dé tu proveedor, y opcionalmente qué dominios de correo se admiten.

**Con SAML 2.0** rellenas: un nombre visible, y los metadatos de tu sistema de identidad — o pegas la URL donde los publica, o pegas directamente el XML si tu proveedor no lo publica en una URL —, el nombre del campo (atributo) del que sale el correo de la persona si tu sistema de identidad no usa el correo como identificador principal, y opcionalmente los dominios admitidos. Al guardar, el sistema valida esos metadatos al momento: si algo no es correcto (el documento no es válido, no incluye un certificado de firma, o ya tienes otro proveedor con el mismo emisor), te lo dice ahí mismo y no se crea nada.

En los dos casos, opcionalmente puedes restringir por dominio de correo — déjalo vacío si no quieres restringir, o indica el tuyo (p. ej. `sucentro.es`) para que solo entren cuentas de ese dominio.

Tras guardar, la pantalla te muestra los datos que tienes que copiar en la configuración de tu propio sistema de identidad para completar la integración por su lado: con OIDC, la URI de redirección, los ámbitos y los campos que se leen; con SAML, el identificador de nuestra plataforma (`entityID`), la dirección a la que tiene que enviar la respuesta, y — si activaste la firma de peticiones (ver más abajo) — el certificado con el que puede comprobarla. Para SAML hay además un botón para descargarte estos datos ya empaquetados en el formato que la mayoría de sistemas de identidad esperan.

### Cargar la credencial o el certificado

**Con OIDC**, un proveedor recién creado no puede activarse todavía: primero necesita al menos una credencial de cliente (`client_secret`) vigente, que te da tu propio sistema de identidad. Puedes tener más de una credencial cargada a la vez — útil para rotarla sin cortar el acceso, porque siempre se usa la más reciente. Si le indicas una fecha de caducidad, el sistema te avisa con antelación antes de que caduque.

**Con SAML**, el certificado con el que se comprueban las respuestas de tu sistema de identidad se recoge normalmente solo con pegar la URL o el XML de metadatos, y se mantiene al día en solitario si diste la URL (el sistema la revisa periódicamente). Si tu proveedor rota su certificado y prefieres subir el nuevo tú mismo, o si diste el XML pegado y necesitas actualizarlo a mano, puedes cargar un certificado adicional en cualquier momento — al igual que con la credencial OIDC, puedes tener varios vigentes a la vez para no cortar el acceso durante una rotación. Retirar un certificado **no lo anula en tu propio sistema de identidad**: si quieres invalidarlo del todo, también tienes que hacerlo allí. Si tu sistema de identidad exige que las peticiones de acceso vayan firmadas por nosotros (algunos lo exigen, la mayoría no), activa «Firmar peticiones de acceso» en la configuración del proveedor.

### Activar, editar y retirar un proveedor

Un proveedor no activo no aparece como opción de acceso para nadie. Puedes editarlo en cualquier momento (nombre, dominios admitidos, modo de aprovisionamiento) y retirarlo si tu centro deja de usarlo — al retirarlo, los vínculos ya creados con esa identidad siguen viéndose desde el perfil de cada persona, pero nadie podrá volver a entrar por ese proveedor. **Con SAML hay una particularidad a tener en cuenta**: si retiras un proveedor y luego das de alta otro con el mismo nombre para sustituirlo, la dirección a la que tu sistema de identidad tiene que enviar la respuesta cambia, así que tendrás que volver a configurarlo por su lado — el aviso aparece en la pantalla antes de confirmar el borrado.

En ningún caso, con ningún proveedor, el inicio de sesión único sustituye del todo a la contraseña: si el sistema de identidad del centro falla o su certificado caduca sin que te hayas dado cuenta del aviso, el personal siempre puede seguir entrando con su contraseña habitual de esta plataforma.

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
