# Manual de administración

> Documento vivo, se amplía en cada fase con las pantallas que existan. Hoy cubre lo que ya tiene código detrás: el registro de auditoría (paso 0.9), las cuentas bloqueadas y el tiempo de sesión (paso 1.2), la autenticación en dos pasos por rol (paso 1.3), el correo como segundo factor, las excepciones temporales y la pantalla mínima de administración de MFA (paso 1.3b), la navegación, el panel de inicio, el selector de idioma y el control de modo de color (paso 1.8), el manejo común de las tablas de datos (paso 1.9), los usuarios y las invitaciones, con la exportación de usuarios y los diálogos de confirmación (paso 1.9b), la importación de usuarios con el catálogo de tipos de documento (paso 1.9c), la pantalla de auditoría y el listado de roles (paso 1.9d), y la configuración del centro, los activos de marca, los módulos contratados y el perfil propio (paso 1.9e), los cursos académicos con su ciclo de vida (paso 1.10), y la estructura académica con los períodos lectivos y las escalas de calificación (pasos 1.11-b, 1.11d-a y 1.11d-b). El resto de secciones del manual de Administrador de Centro llegan con el último sub-paso de las pantallas pendientes de `REQ-CORE` (**1.9f**, `OPEN-CORE-30`); el editor de roles y la matriz de permisos llegan con `REQ-PERM` (paso **1.5b**, posterior a 1.9) — el núcleo de autorización granular ya está implementado desde 1.5, pero solo por API, sin interfaz todavía.

## Navegación y panel de inicio

Al entrar, ves el panel de inicio (`/`): un saludo con tu nombre y el del centro, un aviso si tienes pendiente activar el segundo factor de acceso (con el enlace directo para hacerlo), y tus accesos directos — las pantallas del menú marcadas como tales, o un mensaje si todavía no tienes ninguna disponible.

El menú de navegación, a la izquierda en pantallas grandes y tras el botón de menú en móvil y tableta, muestra únicamente lo que tu perfil puede usar: si no ves una entrada que esperabas, es que tu rol no tiene el permiso correspondiente, no un error de la aplicación — pídelo a quien administre los roles del centro. La miga de pan, sobre cada pantalla, marca dónde estás dentro de esa navegación.

## Menú de usuario, idioma y modo de color

El menú de usuario (arriba a la derecha, con tu nombre) reúne el acceso a tu contraseña, tus sesiones abiertas, la seguridad de tu cuenta, el selector de idioma, el control de modo de color y cerrar sesión.

**Idioma**: el selector ofrece los idiomas que el centro tiene activos (de los cuatro disponibles: español, inglés, alemán, francés), cada uno en su propia lengua. El cambio es inmediato, sin recargar la página. Si el centro retira mientras tanto el idioma que tenías elegido, la próxima vez que entres verás la interfaz en el idioma por defecto del centro.

**Modo de color**: tres opciones — seguir el sistema operativo (por defecto), claro fijo, oscuro fijo. Es una preferencia de tu navegador, no se guarda en el servidor ni se comparte entre tus dispositivos.


### Mis sesiones abiertas

En **Mis sesiones** (`/cuenta/sesiones`, desde el menú de usuario) ves los dispositivos con los que tienes la sesión abierta. Cada una tiene un botón **«Cerrar sesión»**, y arriba hay **«Cerrar todas las demás sesiones»** (conserva solo la actual). Ambas acciones piden confirmación en un diálogo (`Esc` lo cierra sin hacer nada). En una pantalla estrecha, como la de un móvil, cada sesión se muestra como una **tarjeta**; en una ancha, como una fila de tabla paginada.

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
| **Ver su actividad** | Solo si tu rol puede leer la auditoría. Abre el registro de auditoría filtrado por esta persona (véase «Consultar el registro de auditoría») |
| **Ver permisos efectivos** | Solo si tu rol tiene el permiso de ver permisos efectivos (por defecto, el Administrador de Centro). Abre qué puede hacer la persona y por qué (véase «Ver los permisos efectivos de una persona») |

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

## Configuración del centro

En **Administración → Centro** (`/administracion/centro`, requiere el permiso de leer la configuración) ves y, si tienes el permiso de modificarla, cambias los datos del centro. Está dividida en **cuatro bloques independientes**, cada uno con su propio botón de guardar: guardar uno no envía ni descarta lo que hayas escrito en otro. Sin el permiso de modificar, ves los mismos valores como texto, sin campos ni botones de guardar.

- **Regional**: los **idiomas activos** (de los cuatro disponibles; al menos uno), el **idioma por defecto** (solo puede ser uno de los activos: si desmarcas el que era el por defecto, tienes que elegir otro antes de poder guardar), la **zona horaria** (escribe parte del nombre en «Buscar zona horaria» para acotar la lista), la **moneda** (código de tres letras mayúsculas, por ejemplo `EUR`) y la **comunidad autónoma** (o «Sin indicar»). Si retiras el idioma en el que estás viendo la aplicación, un aviso te dice, antes de guardar, que pasarás a verla en el idioma por defecto del centro. Al guardar, el selector de idioma se actualiza sin recargar la página.
- **Fiscal**: razón social, NIF/CIF, dirección, código postal, municipio, provincia y país (código de dos letras mayúsculas, por ejemplo `ES`). Todos son opcionales; dejar uno vacío lo borra.
- **Paleta de colores**: el color primario y el secundario, en formato `#RRGGBB`. Mientras escribes ves una **vista previa** (el primario como fondo y el secundario como texto) y el **contraste** entre ambos, por ejemplo «Contraste: 3,1:1. No alcanza el mínimo de 4,5:1». Es una ayuda: quien decide es el servidor, que rechaza una paleta con contraste insuficiente y te dice qué contraste ha calculado. La vista previa no cambia los colores de la aplicación; al **guardar**, la aplicación adopta la nueva paleta sin recargar.
- **Seguridad**: el **cierre de sesión por inactividad** (en minutos, entre 5 y 480), los **métodos de segundo factor admitidos** (la aplicación de autenticación es obligatoria y no se puede quitar; el código por correo es opcional; el SMS no está disponible) y el **plazo de gracia** para activar el segundo factor (en días, entre 1 y 90). Lo que no está aquí —exigir MFA a un rol, las excepciones temporales y los restablecimientos— se gestiona en la pantalla de MFA, a la que este bloque enlaza si tienes permiso para abrirla.

Los campos marcados con * son obligatorios. Si el servidor rechaza un valor, el mensaje aparece bajo el campo y el foco se coloca en él.

### Activos de marca

Desde **Configuración del centro → Activos de marca** (`/administracion/centro/marca`) gestionas tres imágenes: el **logotipo** (SVG, PNG o WebP, hasta 1 MB), el **favicon** (PNG, ICO o SVG, hasta 256 KB) y el **fondo de la pantalla de acceso** (JPEG, PNG o WebP, hasta 3 MB; no se admite SVG). Cada bloque muestra la imagen que hay ahora guardada.

- **Sustituir**: elige el fichero. La aplicación avisa enseguida si supera el tamaño o no es de un tipo admitido, pero **quien decide es el servidor**, que comprueba el contenido real del fichero (una imagen renombrada no pasa) y sanea los SVG. Si lo rechaza, verás su mensaje. No hay vista previa del fichero antes de subirlo: la imagen que se muestra es siempre la ya guardada.
- **Eliminar**: pide confirmación y deja el centro sin esa imagen.
- Tras sustituir o eliminar, el logotipo y el favicon de la aplicación se actualizan sin recargar. Si una imagen no carga porque su enlace caducó, la pantalla vuelve a pedirlo una vez; si sigue sin cargar, muestra un aviso.
- Sin el permiso de modificar la configuración, ves las imágenes sin botones.

## Módulos contratados

En **Administración → Módulos** (`/administracion/modulos`, requiere el permiso de leer módulos) ves los módulos que el centro tiene **contratados**, de **solo lectura**: su nombre, su estado y la **fecha de alta**, que es el aviso de cuándo se activó cada uno (por ejemplo, cuando se incorpora un módulo nuevo). Los módulos no contratados no aparecen. No hay ningún botón de configuración, aunque tengas permiso para modificar módulos. Los nombres se muestran en el idioma de la interfaz; al cambiar de idioma la tabla se vuelve a cargar.

## Cursos académicos

En **Administración → Cursos académicos** (`/administracion/cursos`, requiere el permiso de leer cursos) ves los cursos del centro en una tabla —código, fechas de inicio y fin y **estado**— ordenada por defecto del más reciente al más antiguo; puedes filtrar por estado. Los estados se muestran siempre con su nombre, no solo con color. La entrada solo aparece en el menú si tu perfil tiene el permiso.

**Qué significa cada estado:**

- **En planificación**: el curso que se está preparando (el siguiente). Como mucho hay uno por centro. Es el único estado en el que se puede **editar** el código y las fechas.
- **Activo**: el curso en marcha. Como mucho hay uno por centro.
- **Cerrado**: curso terminado. **Sus datos son de solo lectura en todos los módulos del centro**: nadie —tenga el permiso que tenga— puede crear, modificar ni borrar datos de un curso cerrado. Si se intenta, la aplicación lo rechaza e indica el motivo («el curso 2025-2026 está cerrado y sus datos son de solo lectura»). Un cierre hecho por error puede deshacerse, pero solo en la ventana que se explica en «Reabrir un curso cerrado por error».
- **Archivado**: curso en almacenamiento frío. Todavía no se puede llegar a este estado.

**Crear un curso.** Con el permiso de crear cursos, **«Nuevo curso»** pide un código (texto libre, único en el centro, por ejemplo `2026-2027`), la fecha de inicio y la de fin. El curso nace siempre **en planificación**; el estado no se elige. Si ya hay un curso en planificación, la aplicación lo indica y enlaza con él; si las fechas se solapan con las de otro curso o el código ya existe, el mensaje aparece bajo el campo.

**Editar.** En la ficha de un curso en planificación, **«Editar»** (permiso de actualizar cursos). Un curso activo o cerrado ya no se edita.

**Activar el curso.** En la ficha de un curso en planificación, **«Activar curso»** (permiso propio de cambiar el estado de los cursos, que solo tiene por defecto el Administrador de Centro). Pide confirmación. **Solo puede haber un curso activo: si ya hay otro, hay que cerrarlo antes**; la aplicación te lo dice y te enlaza con el curso activo. Si la fecha de fin del curso ya ha pasado, el diálogo te lo advierte, pero te deja continuar. El curso no se activa solo por calendario.

**Cerrar el curso.** En la ficha del curso activo, **«Cerrar curso»**. El diálogo te advierte de que, a partir de ahí, todos los datos del curso quedan en solo lectura y de que el cierre **solo se puede deshacer mientras no actives otro curso**, por quien tenga el permiso de reabrir cursos y dejando constancia del motivo (véase «Reabrir un curso cerrado por error»); hay que confirmarlo explícitamente, y cancelar no hace nada. Tras cerrar un curso el centro se queda **sin curso activo** hasta que actives el siguiente: es un estado normal. Si algún módulo tiene una comprobación de cierre pendiente de cumplir, la aplicación lista cuáles y el curso sigue activo.

**Reabrir un curso cerrado por error.** En la ficha de un curso cerrado, **«Reabrir curso»** (permiso propio de reabrir cursos, distinto del de cerrarlos; por defecto solo lo tiene el Administrador de Centro). Sirve para deshacer un **cierre hecho antes de tiempo**, y solo es posible en esta ventana:

- **No hay ningún otro curso activo.** En cuanto actives el curso siguiente, el anterior ya no se puede reabrir.
- **Es el último curso cerrado** del centro. Un curso cerrado más antiguo no se reabre.
- Ningún módulo tiene una comprobación que impida la reapertura (hoy no hay ninguna). Si alguna no se cumpliera, la aplicación lista cuáles y el curso sigue cerrado.

No hay un plazo en días: la ventana la marcan esos hechos. Un curso archivado no se reabre nunca.

El diálogo te pide un **motivo, obligatorio** (hasta 1000 caracteres; sin motivo no se envía nada, y cancelar no hace nada), que queda guardado junto con tu nombre y la fecha. La aplicación no te muestra antes si el curso cumple la ventana: lo comprueba al confirmar y, si no la cumple, te explica por qué y el curso sigue cerrado. **No escribas datos personales en el motivo** (nombres de alumnos, datos de salud…): describe la situación, por ejemplo «Cerrado por error antes de las evaluaciones extraordinarias». Al confirmar, el curso vuelve a ser **el curso activo del centro** y **todos sus datos, en todos los módulos, vuelven a admitir cambios** de quien tenga permiso para hacerlos. Para volver a cerrarlo, usa «Cerrar curso» como siempre.

**No reabras un curso para corregir un dato concreto** (por ejemplo, una nota tras una reclamación): reabrir abre el curso entero a todo el centro, no solo ese dato. La corrección de datos de un curso cerrado tendrá su propio procedimiento en el módulo correspondiente. Si la aplicación no te deja reabrir (hay otro curso activo, o no es el último cerrado), no existe otra vía: **nadie debe intentar reabrirlo modificando la base de datos**, y soporte de la plataforma no lo hace por ti.

**Quién ve qué.** Los cursos cerrados se pueden consultar con el permiso de leer el histórico de cursos cerrados, **además** del permiso del módulo que guarda el dato. Por defecto lo tienen el Administrador de Centro, la Dirección y la Secretaría (que además pueden ver el listado y la ficha de los cursos, sin crearlos ni cambiarles el estado); un centro puede dárselo a otros roles creando un rol personalizado.

## Estructura académica

En **Administración → Estructura académica** (`/academico/estructura`) defines cómo se organiza el centro **en cada curso académico**: **etapas** (Infantil, Primaria, ESO…), **cursos de etapa** (1.º ESO, 2.º ESO…), **grupos** (A, B…), **subgrupos** (desdobles) y **asignaturas**. Es un módulo que el centro debe tener contratado (si no, la entrada del menú no aparece y la aplicación responde que el módulo no está disponible; pídelo a quien administra la plataforma). Necesitas el permiso de leer la estructura para verla, y los de crear, actualizar y borrar para cambiarla; por defecto los cuatro los tiene solo el Administrador de Centro; la Dirección, la Secretaría, el profesorado, las tutorías y la orientación solo pueden consultarla.

**El selector de curso académico.** En la barra superior, **«Curso académico»** muestra el curso que estás viendo (por defecto, el activo) con su estado. Al elegir otro, la pantalla se recarga con la estructura de ese curso y la dirección de la página incluye `?academic_year=…`: puedes guardar o compartir ese enlace, y recargar conserva la elección. No se guarda nada en tu perfil. Los cursos **cerrados** y **archivados** solo aparecen si tienes el permiso de leer el histórico de cursos cerrados; sin él, aunque tengas el enlace, la aplicación responde como si no existieran. Si el centro no tiene ningún curso activo, la pantalla te pide elegir uno.

**Un curso cerrado es de solo lectura.** Si seleccionas un curso cerrado, aparece un aviso permanente y desaparecen «Nuevo…», «Editar» y «Borrar». Nadie puede cambiar la estructura de un curso cerrado, tenga el permiso que tenga.

**Crear.** «Nueva etapa» pide: **código** (texto libre, una sola línea, único entre las etapas del curso; por ejemplo `ESO`), **nombre**, **tipo de etapa** (Infantil, Primaria, ESO, Bachillerato, Formación Profesional o Universidad), **régimen** (público, privado o concertado) y, opcionalmente, la **posición** (si se deja vacía, queda al final). Entrando en una etapa creas sus **cursos**; en un curso, sus **grupos** y **asignaturas**; en un grupo, sus **subgrupos**. El curso académico de cada elemento es el de su elemento superior y **no se puede cambiar después**, como tampoco el elemento superior.

**El nombre en varios idiomas.** El nombre de etapas, cursos y asignaturas se escribe en **una entrada por idioma activo del centro**: el **idioma por defecto del centro** es obligatorio (el centro puede tener otro distinto del castellano) y los demás son opcionales; si falta alguno, cada persona ve el nombre en su idioma si existe y, si no, en el idioma por defecto. Al editar, solo cambias los idiomas que modificas: los demás se conservan, incluso los de un idioma que el centro ya no tenga activo. Los **grupos y subgrupos** llevan un nombre simple, sin traducciones.

**Asignaturas de un grupo.** En la ficha de un grupo, **«Editar asignaturas»** muestra las asignaturas de su curso con casillas: marca las que cursa el grupo y guarda. Crear una asignatura después **no** la añade a los grupos existentes: hay que asignarla.

**Borrar.** **«Borrar»** pide confirmación y es un borrado lógico. **No se puede borrar un elemento que tiene elementos dentro**: una etapa con cursos, un curso con grupos o asignaturas, un grupo con subgrupos o con asignaturas asignadas, una asignatura asignada a algún grupo. La aplicación te lo dice junto al botón y lista lo que lo impide; hay que borrar antes lo de dentro.

**Si algo no cuadra.** «El código ya existe» = hay otro elemento vivo con ese código entre los hermanos (un elemento borrado libera su código). «Falta el nombre en el idioma por defecto» = rellena esa entrada. El código y el nombre simple no admiten caracteres de control; el código tampoco saltos de línea.

### Períodos lectivos de una etapa

Dentro de la ficha de una etapa, la sección **«Períodos lectivos»** lista los trimestres, cuatrimestres o semestres de esa etapa en el curso académico que estás viendo, ordenados por fecha de inicio (columnas: nombre, tipo de período, inicio, fin y posición). Es un recurso **distinto** de la estructura: la sección solo aparece si tienes el permiso de leer la **configuración académica**, además del de leer la estructura que necesitas para abrir la ficha. Por defecto, el Administrador de Centro tiene las cuatro acciones (leer, crear, actualizar y borrar) y la Dirección, la Secretaría, el profesorado, las tutorías y la orientación solo pueden consultarla. Si ves la estructura pero no esta sección, o la API responde «sin permiso», te falta el permiso de configuración académica, no el de estructura: pídeselo a quien administra el centro.

**Crear.** **«Nuevo período»** abre un diálogo con **tipo de período** (Trimestre, Cuatrimestre o Semestre), **nombre** (una entrada por idioma activo del centro; el idioma por defecto es obligatorio, como en el resto de la estructura), **inicio**, **fin** y **posición** (opcional; si se deja vacía, queda al final). Bajo los campos de fecha, el diálogo te recuerda el rango del curso: «Entre el … y el …». La etapa es la de la ficha y el curso académico es el de la etapa; ninguno se cambia después.

**Editar y borrar.** **«Editar»** abre el mismo diálogo con los datos actuales; solo se envía lo que cambias. **«Borrar»** pide confirmación («Borrar el período …») y es un borrado lógico. Tras guardar o borrar, la tabla se recarga y un aviso lo confirma («Período creado», «Período actualizado», «Período borrado»).

**Reglas que valida el servidor** (cada mensaje aparece junto al campo afectado):

- **Las fechas van en orden:** el fin debe ser posterior al inicio (aparece bajo «Fin»).
- **Dentro del curso académico:** el período no puede empezar antes ni acabar después de las fechas del curso (bajo «Inicio» y/o «Fin»).
- **Un solo tipo por etapa:** todos los períodos de una etapa son del mismo tipo. Si ya hay trimestres, un cuatrimestre se rechaza bajo «Tipo de período».
- **Sin solapes:** dos períodos de la misma etapa no pueden compartir ni un solo día (el fin de uno no puede coincidir con el inicio del siguiente). El mensaje nombra el período con el que solapa: «El período se solapa con «…», de la misma etapa» (bajo «Inicio»).

**Pasar de trimestres a semestres (o a cuatrimestres).** No se puede cambiar el tipo de un período mientras la etapa tenga otros de otro tipo. Borra los períodos actuales y crea los nuevos con el tipo y las fechas que quieras; el tipo de un período solo se puede cambiar si es el único de su etapa.

**Si cambias las fechas de un curso en planificación.** En **Cursos académicos**, un curso en planificación admite editar sus fechas. Si las nuevas dejaran algún período lectivo fuera del curso, el guardado se rechaza con el aviso, sobre el formulario, de que hay períodos lectivos que quedarían fuera y entre qué fechas debe estar el curso para incluirlos todos (error `409`). No se guarda nada: amplía las fechas del curso o, antes, ajusta o borra esos períodos (los de la etapa, en su ficha). Los períodos ya borrados no cuentan.

**Con un curso de solo lectura** (cerrado o archivado) la sección muestra los períodos pero con un aviso permanente («Este curso académico es de solo lectura: no se pueden crear, modificar ni borrar sus períodos ni sus escalas») y sin «Nuevo período», «Editar» ni «Borrar». También desaparecen las acciones si no tienes el permiso correspondiente.

**Borrar una etapa con períodos.** Una etapa que aún tiene períodos lectivos no se puede borrar: el aviso la lista bajo «Elementos que lo impiden» como «Períodos lectivos». Borra antes los períodos.

## Escalas de calificación

En **Administración → Escalas de calificación** (`/academico/escalas`) defines **con qué escalas se califica en cada curso académico**: una escala dice entre qué valores se mueve una nota y **cuál es el aprobado**. Es un recurso de la **configuración académica**, el mismo que los períodos lectivos y distinto de la estructura: la entrada del menú aparece solo con el permiso de leer la configuración académica, y para crear, editar o borrar necesitas además los de crear, actualizar y eliminar. Por defecto, el Administrador de Centro tiene las cuatro acciones; la Dirección, la Secretaría, el profesorado, las tutorías y la orientación solo pueden consultar. Como en el resto del módulo, el centro debe tener contratado el módulo de estructura académica.

**El curso académico.** Las escalas son **de un curso académico** y se ven y se crean en el que muestra el selector **«Curso académico»** de la barra superior (por defecto, el activo). Si el centro no tiene ningún curso activo, la pantalla te pide elegir uno. Los cursos cerrados y archivados solo se ven con el permiso de leer el histórico de cursos cerrados, y son de **solo lectura**: un aviso permanente lo dice y no hay «Nueva escala», «Editar» ni «Borrar».

**El listado.** Una tabla con una fila por escala: **código** (un enlace a su ficha), **nombre** (en tu idioma), **tipo de escala**, **rango** y **aprobado**, con un filtro por tipo. En la numérica y la mixta, el rango es «De 0 a 10» y el aprobado «Desde 5»; en la cualitativa, el rango es el número de valores y el aprobado, los **códigos** de los valores que cuentan como aprobado (por ejemplo «SU, BI, NT, SB»).

**Los tres tipos de escala.** El tipo se elige al crearla y **no se puede cambiar después** (la pantalla de edición lo muestra bloqueado con el motivo): para otro tipo, crea una escala nueva.

- **Numérica** (por ejemplo, de 0 a 10): pide **mínimo**, **máximo**, **decimales** (0, 1 o 2) y **nota mínima de aprobado**. No tiene lista de valores.
- **Cualitativa** (Insuficiente, Suficiente, Bien, Notable, Sobresaliente…): no tiene números; es una **lista de valores** con **código**, **etiqueta** (traducible) y la marca **«Es aprobado»** en cada uno. Al menos un valor debe ser aprobado.
- **Mixta** (nota numérica con una calificación asociada, por ejemplo «7,4 = Notable»): lleva los cuatro campos numéricos de la numérica y una lista de **tramos**: cada tramo tiene código, etiqueta, **«Desde»** y **«Hasta»**. En la mixta no marcas el aprobado a mano: un tramo es aprobado si empieza en la nota mínima de aprobado o por encima, y la columna **«Aprobado»** del editor se recalcula al cambiar ese umbral.

**Crear.** **«Nueva escala»** abre un formulario en una página propia (no en un diálogo, para que el editor de valores quepa en pantallas pequeñas). Pide el **código** (texto libre, una sola línea, único entre las escalas del curso; por ejemplo `NUM10`), el **nombre** (una entrada por idioma activo del centro; el idioma por defecto del centro es obligatorio), el **tipo** y, según el tipo, los campos numéricos y la lista de valores o tramos. Los números se escriben **con punto decimal** (`5.5`), de −999,99 a 999,99, y no pueden tener más decimales que los de la escala.

**Editar la lista de valores o tramos.** Cada fila es un grupo de campos con su leyenda («Valor 1», «Valor 2»…). **«Añadir valor»** (o **«Añadir tramo»**) agrega una fila; **«Quitar»** la elimina; **«Subir»** y **«Bajar»** la reordenan con el teclado, sin arrastrar, y la pantalla anuncia el nuevo puesto («Notable ahora en la posición 3»). El **orden de la lista es el orden de la escala**: la posición la fija el servidor a partir de él. Los tramos deben ir en orden, sin huecos ni solapes y cubriendo toda la escala: el primero empieza en el mínimo, cada uno empieza un paso (la unidad del último decimal) después del fin del anterior y el último acaba en el máximo. La nota mínima de aprobado debe coincidir con el inicio de un tramo. Una escala admite como máximo **100 valores o tramos**.

**Editar.** **«Editar»** (en la ficha de la escala) abre el mismo formulario con los datos actuales. Al guardar se envían solo los campos que cambias; la **lista de valores se envía siempre completa** y el servidor la sincroniza: los valores que mantienes conservan su identidad (y los idiomas de su etiqueta que no tocas), los que quitas se borran y los nuevos se crean. Si cambias el máximo de una mixta, ajusta en el mismo guardado los tramos para que sigan cubriéndola.

**La ficha.** Muestra los datos de la escala y, en orden, sus valores o tramos con su código, su etiqueta, su rango (en la mixta) y si son **«Aprobado»** o **«Suspenso»** escrito con texto, no solo con color. Desde ella, **«Editar»** y **«Borrar»**, con su permiso y solo si el curso es escribible.

**Borrar.** **«Borrar»** pide confirmación («La escala y todos sus valores se borran») y es un borrado lógico de la escala y de todos sus valores. Un curso de solo lectura no deja borrar.

**Reglas que valida el servidor** (cada mensaje aparece junto al campo afectado, o sobre la lista si afecta a la lista entera, y el foco va al primer error):

- **Código único** entre las escalas vivas del mismo curso académico («El código ya existe»); el mismo código en otro curso, o el de una escala borrada, sí se admite. Dentro de una escala, el código de cada valor también es único. Si intercambias entre sí los códigos de dos valores que ya existían en un mismo guardado, el servidor lo rechaza como «El código ya existe» sobre el código de la escala: cámbialos en dos guardados, pasando por un código provisional.
- **Campos numéricos:** el mínimo debe ser menor que el máximo; los decimales, 0, 1 o 2; la nota mínima de aprobado debe estar entre el mínimo y el máximo; ningún número puede tener más decimales de los que declara la escala.
- **Campos que no aplican al tipo:** una cualitativa no admite mínimo, máximo, decimales ni nota mínima; una numérica no admite lista de valores.
- **Cualitativa:** al menos un valor y al menos uno aprobado; cada valor debe indicar si es aprobado.
- **Mixta:** tramos dentro de la escala, con el inicio no mayor que el fin, contiguos y con el umbral de aprobado en el inicio de un tramo.
- **Nombre y etiquetas:** el idioma por defecto del centro es obligatorio; ni el código ni las etiquetas admiten caracteres de control, y el código tampoco saltos de línea.

**Si algo no cuadra.** Si ves la estructura pero no «Escalas de calificación» (o la API responde «sin permiso»), te falta el permiso de la configuración académica, no el de estructura: pídeselo a quien administra el centro.

## Horarios y profesorado

Estas pantallas (`REQ-ACAD-002`, pasos `1.12-a` y `1.12-b`) preparan y componen el horario semanal de cada curso académico y reservan aulas y recursos. Usan cuatro permisos distintos, que el Administrador de Centro tiene completos: **espacios**, **horarios** y **asignación docente** (leer, crear, actualizar y eliminar cada uno) y **reservas** (leer, crear y eliminar). Como en el resto del módulo, el centro debe tener contratado el módulo de estructura académica. El orden natural es: espacios, marco horario de cada etapa, profesorado de cada grupo y, por último, la composición del horario.

**Espacios.** En **Administración → Espacios** (`/academico/espacios`) das de alta las **aulas** (que pueden ocupar las sesiones y reservarse) y los **recursos** (un carro de portátiles, un proyector: solo reservables). Cada espacio tiene un **tipo** (que no se puede cambiar tras el alta), un **código** único entre los espacios del centro y un **nombre**. Los espacios son del centro, no de un curso académico: se pueden editar aunque el curso esté cerrado, y el cambio se ve también en los horarios de cursos cerrados. Un aula con sesiones en un curso en planificación o activo no se puede borrar («tiene elementos dependientes»); si solo la usan cursos cerrados, se da de baja y sus sesiones antiguas la muestran marcada como «aula dada de baja».

**Marco horario de una etapa.** En la ficha de cada **etapa**, la sección **«Marcos horarios»** crea el marco **ordinario** (como mucho uno por etapa y curso) con su nombre en los idiomas del centro; después, en su ficha, editas las **franjas**: cada una es un día de la semana y una hora de inicio y de fin. Las horas son del centro y el intervalo no incluye la hora de fin: una franja que acaba a las 09:25 y otra que empieza a las 09:25 no se solapan. Dos franjas del mismo día no pueden solaparse. «Guardar» envía siempre la lista completa. Una franja que ya tiene sesiones no se puede borrar ni cambiar de día u hora, y un marco con sesiones no se borra: mueve o borra antes las sesiones.

**Profesorado de un grupo.** En la ficha de cada **grupo**, la sección **«Profesorado»** muestra los **tutores** del grupo (uno o varios; **«Editar tutores»** sustituye la lista completa) y la **docencia por asignatura**. **«Asignar profesorado»** pide la asignatura del grupo, el **subgrupo** si es un desdoble (si no, «Todo el grupo») y el profesor, que se busca entre los usuarios activos del centro por nombre o correo (necesitas el permiso de ver usuarios). Cualquier usuario activo puede ser profesor o tutor: **asignar no concede permisos ni roles**. Una asignación no se edita: para cambiar de profesor, quítala y crea otra. No se puede repetir la misma asignación (mismo usuario, asignatura y subgrupo), y no se puede quitar una asignación que tiene sesiones en el horario ni quitar al grupo una asignatura con profesorado asignado.

**Componer el horario.** En **Administración → Horarios** (`/academico/horarios`) eliges **cómo verlo** (por grupo, profesor, aula o asignatura) y la opción concreta; la elección queda en la dirección de la página. La rejilla muestra los días en columnas y las franjas en filas, y en cada sesión, como texto, la asignatura, el grupo (y el subgrupo), el profesor y el aula. Se recorre con el teclado (flechas, Inicio y Fin entre celdas) y en pantallas estrechas pasa a una lista por día. En la **vista de grupo**, **«Añadir sesión»** en una celda libre abre un diálogo con las asignaciones del grupo y un aula opcional; en cualquier vista, **«Editar»** permite cambiar la franja (el selector sustituye al arrastre) o el aula, y **«Quitar sesión»** la borra. Una sesión no puede coincidir en el tiempo con otra del **mismo profesor**, la **misma aula** o el **mismo grupo** (salvo dos subgrupos distintos del grupo, un desdoble): si choca, el aviso, junto a los botones, dice con cuál (tipo de choque, asignatura, grupo, profesor y franja) y no se guarda nada. Los intervalos son semiabiertos: una sesión de 08:30 a 09:25 y otra de 09:25 a 10:20 no chocan, aunque sean de etapas distintas.

**Jornada especial (jornada intensiva).** Además del marco ordinario, una etapa puede tener varios marcos **especiales**, cada uno con su propia lista de franjas y una **vigencia** entre dos fechas (ambas incluidas) dentro del curso académico. En la sección **«Marcos horarios»** de la ficha de la etapa, **«Nuevo marco horario»** pide el **tipo** (el ordinario solo se ofrece si la etapa aún no lo tiene), el nombre y, en el especial, **«Vigencia desde»** y **«Vigencia hasta»**; después, en la ficha del marco, editas las franjas y, con **«Guardar»**, también las fechas. Dos marcos especiales de la misma etapa no pueden solaparse, y las fechas deben estar dentro del curso. **Mientras dura la vigencia, el marco especial sustituye al ordinario de su etapa**: la misma persona puede tener una sesión en el ordinario y otra, a la misma hora, en el especial. En cambio, el especial **sí coexiste** con los marcos de las demás etapas, así que un profesor que da clase en dos etapas no puede estar a la vez en una sesión de una y en otra (aunque las franjas sean distintas), y dos especiales de etapas distintas solo coexisten si sus fechas se cortan. Si cambias las fechas de un marco que ya tiene sesiones y eso haría que choquen con las de otro marco, se rechaza el cambio y el aviso, junto al campo «Vigencia desde», dice con cuál. Para componer un marco especial, en **Horarios → Ver por Grupo** elige el grupo y, en **«Marco horario»**, la jornada especial (por defecto se muestra el ordinario); en las demás vistas, las sesiones de una jornada especial llevan en texto su nombre y su vigencia.

**Reservas de aulas y recursos.** En **Administración → Reservas** (`/academico/reservas`) ves las reservas del curso académico seleccionado, ordenadas por fecha y hora, y puedes filtrarlas por espacio y por rango de fechas. **«Nueva reserva»** pide el espacio (cualquier aula o recurso del centro; necesitas poder ver los espacios), la fecha (dentro del curso) y la hora de inicio y de fin; la hora de fin no se incluye, así que dos reservas seguidas no chocan. No hay repetición ni aprobación: cada reserva es de un día. Una reserva no puede coincidir con otra del mismo espacio ni, si el espacio es un aula, con una **sesión del horario** de ese día de la semana en el marco que esté vigente esa fecha (durante una jornada especial cuenta el marco especial, no el ordinario). Si choca, el aviso, junto a los botones, dice con qué (la reserva de quién, o la sesión) y no se guarda nada. Las reservas no se editan: **«Cancelar»** (con confirmación) la borra y puedes reservar de nuevo. Quien tiene el permiso de cancelar solo para **sus propias** reservas (el docente por defecto) recibe «no existe» al intentar cancelar la de otra persona. Una sesión creada después de una reserva no se comprueba contra ella: la reserva es puntual y el horario es la planificación base. Un espacio con reservas en un curso en planificación o activo no se puede borrar hasta cancelarlas. Al cambiar las fechas de un curso académico en planificación, si alguna jornada especial o reserva quedara fuera, el cambio se rechaza y el aviso dice entre qué fechas debe estar el curso.

**Con un curso de solo lectura** (cerrado o archivado) las pantallas muestran un aviso permanente y no hay botones de escritura (los espacios siguen siendo editables). Los cursos cerrados solo se ven con el permiso de leer el histórico.

**Si algo no cuadra.** «Sin permiso» al abrir una pantalla = falta el permiso de ese recurso (espacios, horarios, asignación docente o reservas), que no se heredan de la estructura. Un profesor que existe pero «no existe o no está activo» está dado de baja o es de otro centro. Si una persona del profesorado ya no existe en el centro (se ha dado de baja), en las asignaciones, tutorías, sesiones y reservas aparece como **«Usuario eliminado»**: el historial se conserva, pero su nombre ya no se muestra. En la interfaz en alemán, cada sesión del horario se llama «Unterrichtsstunde» (plural «Unterrichtsstunden»); «Zeitfenster» es la franja y «Stundenplanrahmen» el marco horario.

## Mi perfil

En el menú de usuario, **Mi cuenta → Perfil** (`/cuenta/perfil`) está disponible para **cualquier persona con sesión**, sin ningún permiso especial. Muestra tu nombre completo y tu **correo de acceso** (que no se cambia desde aquí; si necesitas cambiarlo, contacta con el centro) y te deja modificar tu **correo de contacto** y tu **teléfono de contacto**. Dejar un campo vacío lo borra. Tu idioma no se cambia aquí sino con el selector de idioma del menú de usuario. Si el servidor rechaza un dato, el mensaje aparece bajo el campo.

## Roles del centro

En **Administración → Roles** (`/administracion/roles`, requiere el permiso de leer roles) ves los roles del centro en una tabla: su nombre (un enlace a su **ficha**), si es **del sistema** o **personalizado**, si exige **MFA** (segundo factor), si da **acceso a datos especiales** (salud, necesidades educativas especiales, convivencia) y **cuántas personas** lo tienen. Con el permiso de crear roles aparece **«Nuevo rol»**.

- Los nombres de los roles del sistema se muestran en el idioma de la interfaz: al cambiar de idioma la tabla se vuelve a cargar.
- Los roles **del sistema** no se modifican desde aquí: para tener una versión propia, **clónalo**. Un rol **personalizado** se crea, se edita y se elimina.

### La ficha de un rol

Muestra el código interno, el tipo, si exige MFA, si da acceso a datos de categoría especial, cuántas personas lo tienen y la tabla de sus **concesiones** (recurso, acción, efecto «Permitir» o «Denegar», ámbito; con permiso para ver el catálogo, también una marca de «Categoría especial» y un aviso de **inerte** si el rol no tiene ese acceso). Las acciones dependen de tus permisos:

| Acción | Cuándo está disponible |
|--------|------------------------|
| **Editar datos** | Permiso de actualizar roles. En un rol del sistema no se edita el nombre |
| **Editar concesiones** | Permiso de actualizar roles y rol **personalizado**. En uno del sistema, un texto te remite a «Clonar» |
| **Clonar** | Permiso de crear roles |
| **Eliminar** | Permiso de eliminar roles y rol **personalizado**. Si el rol tiene personas asignadas aparece **deshabilitado** con el motivo y un enlace a **«Ver usuarios con este rol»**; pide confirmación y no se deshace desde la interfaz |
| **Ver usuarios con este rol** | Permiso de ver usuarios |

### Crear, clonar y editar un rol

- **Nuevo rol**: escribe el **nombre** (se muestra igual en todos los idiomas) y el **código**, que se propone solo a partir del nombre y es un identificador interno que **no podrá cambiarse**. Puedes marcar **MFA obligatorio**. Al guardar vas directamente al editor de concesiones del rol nuevo.
- **Clonar**: copia las concesiones y la obligación de MFA, **no** a las personas que tienen el rol, y el rol nuevo queda desligado del original. Si el original da acceso a datos de categoría especial, solo podrás clonarlo si tú puedes activar ese acceso. **No puedes clonar un rol que conceda algo que tú no tienes**: el sistema te dice qué permiso y qué ámbito y no crea nada.
- **Acceso a datos de categoría especial**: el control solo aparece si tienes permiso para cambiarlo y solo se puede activar si **alguno de tus roles ya lo tiene** (por defecto, el Administrador de Centro no lo tiene). Activarlo o desactivarlo pide confirmación y dice a cuántas personas afecta. Las concesiones de datos de categoría especial solo surten efecto en roles con este acceso.
- **MFA obligatorio** de un rol existente se cambia en la administración de MFA, no aquí.

### Editar las concesiones de un rol

La pantalla muestra **una matriz por módulo**: una fila por recurso y una columna por acción. Cada celda indica «Sin conceder», «Permitir · ámbito» o «Denegar»; pulsa una celda para abrir su panel, elige el estado y, si es «Permitir», el **ámbito**, y pulsa **Aplicar**. **No se guarda nada hasta que pulses «Guardar»**; un contador te dice cuántos cambios llevas.

- **Qué necesitas para abrir el editor**: además del permiso de actualizar roles, tu perfil debe poder **ver roles** y **ver el catálogo de permisos**. Si te falta alguno, en lugar de la matriz la pantalla muestra «Para editar las concesiones necesitas además el permiso {permiso}», con el permiso que falta; no es un error, y no se guarda ni se pide nada. Lo mismo ocurre en el formulario de edición del rol si no puedes ver roles.
- **Qué puedes conceder**: no puedes conceder un permiso, ni con un ámbito, que tú no tengas. Esos ámbitos aparecen **deshabilitados con el motivo** («no puedes concederlo: tú no tienes este permiso con este ámbito» o «todavía no se puede conceder: lo aportará un módulo que aún no está disponible»). Si no tienes un permiso, solo puedes **denegarlo o dejarlo sin conceder**. Lo que el rol ya concede siempre puedes conservarlo.
- **Denegar** anula el permiso para todas las personas del rol, en cualquier ámbito y aunque otro de sus roles lo conceda.
- **Avisos**: una concesión de datos de categoría especial en un rol sin ese acceso, o de un módulo no contratado, se marca como **inerte** (no surte efecto) pero se puede guardar.
- **Guardar**: la pantalla comprueba antes que nadie más haya cambiado el rol y te pide confirmación con el resumen (concedidos, ámbito cambiado, denegados y retirados), la lista de cambios y a cuántas personas afecta **de inmediato**. Si tú eres titular del rol, te avisa de que los cambios también te afectan.
- **Si el sistema rechaza el guardado** no se guarda nada y tus cambios siguen en pantalla, con el motivo en la celda o en el resumen. Un rechazo especial: **el centro nunca puede quedarse sin nadie que conserve todos los permisos de administración**. Si lo que guardas lo provocaría, el sistema lo explica, lista los permisos afectados y te pide que los concedas primero a otra persona.
- Si sales con cambios sin guardar, la pantalla pide confirmación.
- Los roles del sistema se muestran en solo lectura.

### Ver los permisos efectivos de una persona

Desde la ficha de una persona, **«Ver permisos efectivos»** (permiso específico) abre qué puede hacer ahora mismo, calculado con las mismas reglas que aplica la plataforma en cada petición. Indica sus roles, cuándo se calculó (**«Recalcular»** vuelve a calcularlo) y, para cada permiso, el resultado (**Permitido** o **Denegado**), los ámbitos y la **procedencia**: qué roles lo conceden o lo deniegan. Explica los casos que confunden: un permiso **denegado por otro rol** («una denegación anula cualquier concesión») y uno **concedido pero sin efecto** (con el motivo). Por defecto solo se muestran los permisos con alguna concesión o denegación; marca «Incluir permisos sin ninguna concesión» para ver el catálogo completo. Se puede buscar sin importar tildes ni mayúsculas. **No se puede exportar ni imprimir.**

## Consultar el registro de auditoría

En **Administración → Auditoría** (`/administracion/auditoria`, requiere el permiso de leer la auditoría) consultas quién hizo qué y cuándo. Es el mismo registro que describe la sección «Registro de auditoría» de más abajo, con sus valores redactados.

**La tabla**: de la más reciente a la más antigua, con **fecha y hora**, **operación**, **usuario**, **entidad** y el botón **Ver cambios**. Muestra 50 entradas y se amplía con **Cargar más**; el máximo en pantalla es de **1.000 filas**: al llegar, el aviso te ofrece **exportar** (si tienes ese permiso) o refinar los filtros. No tiene búsqueda de texto ni columnas ordenables. Las columnas **Identificador**, **IP** y **Petición** están ocultas por defecto; las activas con el menú «Columnas». Si una acción la hizo el sistema, la consola, una importación, la plataforma o alguien sin sesión, en la columna de usuario verás ese tipo de actor.

**Filtros** (se combinan; la dirección de la pantalla los recuerda, así que puedes copiarla y compartirla):

| Filtro | Qué hace |
|--------|----------|
| Fecha | Un rango «desde» y «hasta», ambos días incluidos |
| Operación | Alta, modificación, baja, restauración, lectura, exportación, inicio y cierre de sesión, solicitud de restablecimiento de contraseña |
| Tipo de actor | Usuario, sistema, consola, importación, plataforma, anónimo |
| Usuario | Elige a una persona escribiendo parte de su nombre o correo. **Solo aparece si puedes ver usuarios** |
| Módulo y Entidad | Las opciones las da el propio sistema |

**Zona horaria**: las fechas se muestran, y el rango de fechas se interpreta, **en la zona horaria de tu navegador**, no en la del centro. «Desde el 1 de marzo hasta el 3 de marzo» significa desde las 00:00 del día 1 hasta las 23:59:59 del día 3 *en tu zona*. Si consultas desde otra zona horaria, el mismo rango abarcará instantes distintos.

**Ver la actividad de una persona**: en la ficha de un usuario, el botón **Ver su actividad** (si puedes leer la auditoría) abre esta pantalla ya filtrada por esa persona, con el texto «Filtrado por: nombre» y un botón para quitar el filtro.

**Ver cambios**: abre un panel con lo que cambió en esa entrada, atributo por atributo, como «antes → después». Un valor que el sistema no guarda por diseño se muestra como **«Valor no registrado»** con su motivo (dato secreto, categoría especial, dato identificativo o valor demasiado grande) y, cuando se sabe, si el campo estaba **vacío o con valor** antes y después; nunca se reconstruye el dato. Las entradas sin cambios (lecturas, inicios de sesión, exportaciones) indican «Sin cambios registrados». El panel se cierra con **Esc** o con el botón de cerrar, y el foco vuelve al botón con el que lo abriste.

**Exportar**: con el permiso de exportar la auditoría, el botón **Exportar** pide un CSV con **los mismos filtros** que tienes aplicados (no con el orden ni con las filas ya cargadas). El fichero se prepara en segundo plano; cuando está listo aparece el enlace de descarga, que caduca. Cada exportación queda registrada en la propia auditoría.

## Cuentas bloqueadas

### Qué es

Tras 5 intentos fallidos de inicio de sesión seguidos, el sistema bloquea la cuenta automáticamente durante 15 minutos (por defecto) para frenar un ataque por fuerza bruta. La persona recibe un correo con un enlace propio para desbloquearla antes de que pase ese tiempo. Como administrador del centro, también puedes desbloquearla tú directamente, sin esperar a que la propia persona lo haga ni a que pasen los 15 minutos.

### Cómo consultarlas y desbloquear una cuenta

El listado de cuentas bloqueadas se filtra por estado (vigente o ya levantado) y se puede buscar por correo. Cada fila muestra desde cuándo está bloqueada, cuántos intentos fallidos la provocaron, y si ya se levantó, cómo (por la propia persona, por caducidad del plazo, o por un administrador). Levantar un bloqueo desde aquí tiene efecto inmediato: la persona puede volver a intentar iniciar sesión con su contraseña de siempre en el momento en que lo haces, sin esperar ningún correo.

## Tiempo de sesión del centro

### Qué es

Cuánto tiempo puede estar una persona sin actividad en la aplicación antes de que su sesión se cierre sola por seguridad (entre 5 minutos y 8 horas). Es un valor único para todo el centro, no por persona ni por rol: se configura en el bloque «Seguridad» de **Administración → Centro** (véase «Configuración del centro»), y se aplica a toda sesión nueva que se abra después del cambio — no cierra de golpe las que ya estaban abiertas con el valor anterior.

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

La lista de excepciones es una tabla paginada (25 por página) con un **filtro de estado**: al entrar muestra solo las **vigentes**; elige «Todos» para ver también las caducadas y las revocadas, y «Limpiar filtros» para volver a las vigentes. Al **revocar**, el sistema abre un diálogo que **nombra a la persona** afectada y pide confirmación; puedes cancelarlo con el botón «Cancelar» o con la tecla `Esc`, y no se revoca nada hasta que confirmes. Tras conceder una excepción nueva, la tabla vuelve a la primera página con las vigentes, para que veas la que acabas de crear.

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

El catálogo de proveedores se muestra paginado **de 25 en 25**. «Editar» y «Eliminar» indican en su nombre a qué proveedor se refieren. Al eliminar, se abre el **mismo diálogo de confirmación** que en el resto de la administración (nombra al proveedor; `Esc` lo cierra sin borrar nada).

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
