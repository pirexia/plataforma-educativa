"""
Catálogos de apoyo para el generador de datos sintéticos.

CONVENCIÓN DE DATOS SINTÉTICOS (REQ-SEED-005):
- Nombres de persona con localización española, pero los CENTROS llevan nombre
  explícitamente ficticio.
- Correos siempre en dominios reservados para documentación.
- Documentos de identidad con formato válido y dígito de control DELIBERADAMENTE
  incorrecto: sirven para probar validación y son inutilizables como identificador real.
- Teléfonos en rangos no asignables.
"""

# ---------------------------------------------------------------- personas

NOMBRES_H = [
    "Adrián", "Álvaro", "Andrés", "Antonio", "Bruno", "Carlos", "César", "Daniel",
    "David", "Diego", "Eduardo", "Enrique", "Fernando", "Francisco", "Gabriel",
    "Gonzalo", "Guillermo", "Héctor", "Hugo", "Ignacio", "Iván", "Jaime", "Javier",
    "Jorge", "José", "Juan", "Leo", "Lucas", "Luis", "Manuel", "Marcos", "Mario",
    "Martín", "Mateo", "Miguel", "Nicolás", "Óscar", "Pablo", "Pedro", "Rafael",
    "Raúl", "Rodrigo", "Rubén", "Samuel", "Santiago", "Sergio", "Tomás", "Víctor",
]

NOMBRES_M = [
    "Adriana", "Alba", "Alejandra", "Alicia", "Ana", "Andrea", "Beatriz", "Blanca",
    "Carla", "Carlota", "Carmen", "Celia", "Clara", "Claudia", "Cristina", "Daniela",
    "Elena", "Elsa", "Emma", "Eva", "Gema", "Inés", "Irene", "Isabel", "Julia",
    "Laura", "Leire", "Lucía", "Luna", "Manuela", "Marina", "Marta", "Martina",
    "Mónica", "Natalia", "Noa", "Nuria", "Olivia", "Paula", "Pilar", "Raquel",
    "Rocío", "Rosa", "Sandra", "Sara", "Silvia", "Sofía", "Teresa", "Valeria", "Vega",
]

APELLIDOS = [
    "Álvarez", "Arias", "Blanco", "Bravo", "Cabrera", "Calvo", "Campos", "Cano",
    "Carrasco", "Castillo", "Castro", "Crespo", "Delgado", "Díaz", "Domínguez",
    "Durán", "Esteban", "Fernández", "Ferrer", "Flores", "Gallego", "García",
    "Gil", "Giménez", "Gómez", "González", "Guerrero", "Gutiérrez", "Hernández",
    "Herrera", "Ibáñez", "Iglesias", "Jiménez", "León", "Lorenzo", "Lozano",
    "Lucas", "Marín", "Márquez", "Martín", "Martínez", "Medina", "Méndez", "Molina",
    "Montero", "Morales", "Moreno", "Muñoz", "Navarro", "Nieto", "Núñez", "Ortega",
    "Ortiz", "Parra", "Pascual", "Pastor", "Peña", "Pérez", "Prieto", "Ramírez",
    "Ramos", "Reyes", "Rey", "Rodríguez", "Rojas", "Román", "Romero", "Rubio",
    "Ruiz", "Sáez", "Sánchez", "Santana", "Santos", "Serrano", "Soler", "Soto",
    "Suárez", "Torres", "Vargas", "Vázquez", "Vega", "Velasco", "Vicente", "Vidal",
]

# ---------------------------------------------------------------- estructura educativa

# (código, nombre, edad de inicio, nº de cursos)
ETAPAS = {
    "INF1": ("Educación Infantil · Primer ciclo", 0, 3),
    "INF2": ("Educación Infantil · Segundo ciclo", 3, 3),
    "PRI":  ("Educación Primaria", 6, 6),
    "ESO":  ("Educación Secundaria Obligatoria", 12, 4),
    "BAC":  ("Bachillerato", 16, 2),
}

NOMBRE_CURSO = {
    "INF1": ["0-1 años", "1-2 años", "2-3 años"],
    "INF2": ["1º Infantil", "2º Infantil", "3º Infantil"],
    "PRI":  [f"{n}º Primaria" for n in range(1, 7)],
    "ESO":  [f"{n}º ESO" for n in range(1, 5)],
    "BAC":  ["1º Bachillerato", "2º Bachillerato"],
}

LETRAS_GRUPO = ["A", "B", "C", "D"]

MATERIAS = {
    "INF2": ["Conocimiento de sí mismo", "Conocimiento del entorno",
             "Comunicación y representación", "Inglés", "Psicomotricidad", "Religión/Valores"],
    "PRI":  ["Lengua Castellana", "Matemáticas", "Conocimiento del Medio",
             "Inglés", "Educación Artística", "Educación Física", "Religión/Valores"],
    "ESO":  ["Lengua Castellana y Literatura", "Matemáticas", "Geografía e Historia",
             "Biología y Geología", "Física y Química", "Inglés", "Tecnología",
             "Educación Física", "Educación Plástica", "Religión/Valores"],
    "BAC":  ["Lengua Castellana y Literatura", "Historia de España", "Inglés",
             "Matemáticas", "Filosofía", "Física", "Química", "Biología",
             "Economía", "Latín"],
}

# ---------------------------------------------------------------- plantilla del centro

# (código de puesto, denominación, ámbito, ¿es plantilla propia?)
PUESTOS = [
    ("DIR",      "Dirección",                              "Equipo directivo",     True),
    ("JEF",      "Jefatura de Estudios",                   "Equipo directivo",     True),
    ("SEC",      "Secretaría",                             "Equipo directivo",     True),
    ("TUT",      "Docente tutor",                          "Docencia",             True),
    ("ESP_ING",  "Especialista de Inglés",                 "Docencia",             True),
    ("ESP_EF",   "Especialista de Educación Física",       "Docencia",             True),
    ("ESP_MUS",  "Especialista de Música",                 "Docencia",             True),
    ("ESP_REL",  "Profesor de Religión",                   "Docencia",             True),
    ("PT",       "Pedagogía Terapéutica",                  "Atención a la diversidad", True),
    ("AL",       "Audición y Lenguaje",                    "Atención a la diversidad", True),
    ("ORI",      "Orientación",                            "Atención a la diversidad", True),
    ("EDU_INF",  "Educador de Educación Infantil",         "Infantil 0-3",         True),
    ("TSEI",     "Técnico Superior en Educación Infantil", "Infantil 0-3",         True),
    ("AUX_AULA", "Auxiliar de aula",                       "Infantil 0-3",         True),
    ("ADM",      "Administrativo",                         "Administración",       True),
    ("REC",      "Recepción y secretaría de familias",     "Administración",       True),
    ("CONS",     "Conserjería",                            "Servicios",            True),
    ("MANT",     "Mantenimiento",                          "Servicios",            True),
    ("LIMP",     "Limpieza",                               "Servicios",            False),
    ("COC_JEF",  "Jefatura de cocina",                     "Cocina y comedor",     False),
    ("COC_AYU",  "Ayudante de cocina",                     "Cocina y comedor",     False),
    ("MON_COM",  "Monitor de comedor",                     "Cocina y comedor",     False),
    ("MON_EXT",  "Monitor de actividades extraescolares",  "Complementarios",      False),
    ("ACOMP",    "Acompañante de ruta",                    "Complementarios",      False),
    ("ENF",      "Enfermería",                             "Sanitario",            True),
    ("INF_TIC",  "Técnico de informática",                 "Servicios",            True),
]

TIPOS_CONTRATO = ["Indefinido", "Indefinido", "Indefinido", "Temporal", "Interinidad"]
JORNADAS = ["Completa", "Completa", "Completa", "Parcial 75%", "Parcial 50%", "Por horas"]

# ---------------------------------------------------------------- transporte

EMPRESAS_BUS = [
    ("Autocares Demo Norte, S.L.", "B00000001"),
    ("Transportes Escolares Ficticios, S.A.", "A00000002"),
    ("Buses Ejemplo Levante, S.L.", "B00000003"),
]

VIAS = [
    "Avenida de los Almendros", "Calle del Mirador", "Plaza de las Acacias",
    "Calle de la Fuente Vieja", "Avenida del Parque Sur", "Calle de los Tilos",
    "Paseo de la Alameda", "Calle del Molino", "Avenida de la Estación",
    "Calle de las Encinas", "Plaza del Olivar", "Calle de la Vega Baja",
    "Camino de los Robles", "Calle del Arroyo", "Avenida de las Viñas",
    "Calle de la Ermita", "Paseo de los Cerezos", "Calle del Horno",
]

MUNICIPIOS = [
    "Torrejón de Ardoz", "Alcalá de Henares", "Coslada", "San Fernando de Henares",
    "Paracuellos del Jarama", "Ajalvir", "Daganzo de Arriba", "Meco",
]

# ---------------------------------------------------------------- otros

IDIOMAS = ["es-ES", "es-ES", "es-ES", "es-ES", "es-ES", "es-ES", "en", "de", "fr"]

TIPOS_NEAE = [
    "Dificultades específicas de aprendizaje",
    "TDAH",
    "Altas capacidades intelectuales",
    "Retraso en el lenguaje",
    "Trastorno del espectro autista",
    "Incorporación tardía al sistema educativo",
]

MOTIVOS_FALTA = [
    "Enfermedad", "Cita médica", "Motivos familiares",
    "Sin justificar", "Actividad deportiva", "Trámite administrativo",
]
