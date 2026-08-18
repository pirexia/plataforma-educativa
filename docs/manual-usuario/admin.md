# Manual de administración

> Documento vivo, se amplía en cada fase con las pantallas que existan. Hoy cubre solo lo que ya tiene código detrás: el registro de auditoría (paso 0.9). El resto de secciones del manual de Administrador de Centro (usuarios, roles, módulos contratados) llegan con `REQ-BO` (paso 1.6) y `REQ-CORE` (1.1).

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
