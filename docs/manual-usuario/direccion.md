# Manual de dirección

> Estructura académica (`REQ-ACAD-001`, pasos `1.11-b`, `1.11d-a` y `1.11d-b`). El resto de funciones de este perfil se documentan con cada módulo.

## Estructura académica del centro

**Qué ves.** En **Estructura académica** (`/academico/estructura`, en el menú lateral) consultas cómo se organiza el centro en cada curso académico: **etapas**, **cursos de etapa**, **grupos**, **subgrupos** y **asignaturas**, y qué asignaturas cursa cada grupo. Por defecto, la Dirección tiene el permiso de leer la estructura. Es solo consulta: ver la estructura no es ver alumnado; los datos de alumnos tendrán su propio permiso y pantallas.

**Cómo navegar.** La tabla de **etapas** (filtrable por tipo de etapa y por régimen) lleva a la ficha de cada una; la etapa muestra sus cursos, el curso sus grupos y asignaturas, y el grupo sus subgrupos y las asignaturas que cursa. Las migas de pan y los enlaces «Volver a…» te devuelven al nivel anterior.

**El selector de curso académico.** En la barra superior, **«Curso académico»** muestra el curso que estás viendo (por defecto, el activo). Al elegir otro, la dirección de la página incluye `?academic_year=…`, que puedes guardar o compartir. Los cursos **cerrados** solo se pueden consultar si tienes el permiso de leer el histórico de cursos cerrados (por defecto la Dirección lo tiene); sin él no aparecen en el selector y, aunque tengas el enlace, la aplicación responde como si no existieran. Un curso cerrado muestra un aviso permanente de solo lectura.

**Nombres en tu idioma.** Los nombres de etapas, cursos y asignaturas se muestran en el idioma de tu perfil; si el centro no los ha traducido a ese idioma, ves el del idioma por defecto del centro.

**Períodos lectivos de una etapa.** En la ficha de una etapa, la sección **«Períodos lectivos»** lista, de solo lectura, los trimestres, cuatrimestres o semestres de esa etapa en el curso que estás viendo (nombre en tu idioma, tipo, inicio, fin y posición). Por defecto, la Dirección tiene el permiso de leer la configuración académica, que es el que muestra esta sección. No hay «Nuevo período», «Editar» ni «Borrar»: los períodos los gestiona la administración del centro. Si ves la estructura pero no esta sección, te falta el permiso de leer la configuración académica; díselo a quien administra el centro. En un curso cerrado, la sección sigue siendo de consulta.

**Escalas de calificación.** En **Escalas de calificación** (`/academico/escalas`, en el menú lateral) consultas, de solo lectura, las escalas con las que se califica en el curso que estás viendo en el selector: código, nombre en tu idioma, tipo (numérica, cualitativa o mixta), rango y aprobado (en la numérica y la mixta, «Desde 5»; en la cualitativa, los códigos de los valores aprobados). La ficha de cada escala muestra sus valores o tramos en orden, con «Aprobado» o «Suspenso» escrito. Por defecto, la Dirección tiene el permiso de leer la configuración académica, que es el que muestra esta entrada del menú. No hay «Nueva escala», «Editar» ni «Borrar»: las escalas las gestiona la administración del centro. Si no ves la entrada, te falta el permiso de leer la configuración académica; díselo a quien administra el centro. En un curso cerrado, las escalas siguen siendo de consulta (y solo se ven con el permiso de leer el histórico de cursos cerrados).

**Qué no puedes hacer.** Crear, editar o borrar elementos de la estructura (no hay botones) ni asignar asignaturas a un grupo: lo hace la administración del centro. Si crees que te falta algún permiso, díselo a quien administra el centro.

