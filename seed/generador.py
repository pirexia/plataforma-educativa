#!/usr/bin/env python3
"""
Generador de datos sintéticos · REQ-SEED

Genera tres centros ficticios completos con alumnado, familias, plantilla,
estructura académica, datos operativos y rutas de transporte.

    python3 generador.py --semilla 42 --salida ./salida

REGLA INNEGOCIABLE (ADR-030, REQ-SEED-006)
Este generador produce EXCLUSIVAMENTE datos sintéticos. Nunca debe ejecutarse
contra una base de datos de producción ni mezclarse con datos reales. Todo
registro sale marcado con "sintetico": true.
"""

import argparse
import json
import random
import unicodedata
from datetime import date, timedelta
from pathlib import Path

from catalogos import (
    APELLIDOS, EMPRESAS_BUS, ETAPAS, IDIOMAS, JORNADAS, LETRAS_GRUPO,
    MATERIAS, MOTIVOS_FALTA, MUNICIPIOS, NOMBRE_CURSO, NOMBRES_H,
    NOMBRES_M, TIPOS_CONTRATO, TIPOS_NEAE, VIAS,
)

CURSO_ACADEMICO = "2026/2027"
INICIO_CURSO = date(2026, 9, 7)
FIN_TRIMESTRE_1 = date(2026, 12, 22)

DOMINIO_CORREO = "example.com"


# ══════════════════════════════════════════════════════════ utilidades

class Gen:
    """Generador con estado. Toda aleatoriedad pasa por aquí para que la
    semilla sea reproducible (REQ-SEED-006)."""

    def __init__(self, semilla: int):
        self.r = random.Random(semilla)
        self._contador = {}

    def id(self, prefijo: str) -> str:
        self._contador[prefijo] = self._contador.get(prefijo, 0) + 1
        return f"{prefijo}-{self._contador[prefijo]:06d}"

    # ---- personas

    def nombre(self, sexo: str) -> str:
        return self.r.choice(NOMBRES_H if sexo == "H" else NOMBRES_M)

    def apellidos(self) -> tuple:
        return self.r.choice(APELLIDOS), self.r.choice(APELLIDOS)

    def documento(self) -> str:
        """DNI con formato válido y letra DELIBERADAMENTE INCORRECTA.

        Pasa una validación de formato pero falla la de dígito de control,
        de modo que es inutilizable como identificador real y sirve para
        probar la validación (REQ-SEED-005).
        """
        numero = self.r.randint(10_000_000, 99_999_999)
        correcta = "TRWAGMYFPDXBNJZSQVHLCKE"[numero % 23]
        incorrecta = self.r.choice([c for c in "TRWAGMYFPDXBNJZSQVHLCKE" if c != correcta])
        return f"{numero}{incorrecta}"

    def telefono(self) -> str:
        """Rango 6/7 no asignado a operadores comerciales."""
        return f"6{self.r.randint(0, 9)}9{self.r.randint(100000, 999999)}"

    def correo(self, nombre: str, ap1: str, sufijo: str = "") -> str:
        base = f"{_slug(nombre)}.{_slug(ap1)}{sufijo}"
        return f"{base}@{DOMINIO_CORREO}"

    def fecha_nac(self, edad: int, ref: date = INICIO_CURSO) -> str:
        inicio = date(ref.year - edad - 1, 9, 1)
        dias = self.r.randint(0, 364)
        return (inicio + timedelta(days=dias)).isoformat()

    def direccion(self) -> dict:
        return {
            "via": self.r.choice(VIAS),
            "numero": self.r.randint(1, 120),
            "municipio": self.r.choice(MUNICIPIOS),
            "codigo_postal": f"28{self.r.randint(800, 899)}",
        }


def _slug(texto: str) -> str:
    n = unicodedata.normalize("NFKD", texto)
    return "".join(c for c in n if not unicodedata.combining(c)).lower().replace(" ", "")


# ══════════════════════════════════════════════════════════ perfiles de centro

PERFILES = [
    {
        "clave": "demo-concertado",
        "nombre": "Colegio Demo Uno",
        "subdominio": "demo-uno",
        "regimen_general": "CONCERTADO",
        # Régimen POR ETAPA, no por centro: ADR-020
        "etapas": {
            "INF1": "PRIVADO",       # primer ciclo 0-3 en régimen privado
            "INF2": "CONCERTADO",
            "PRI": "CONCERTADO",
            "ESO": "CONCERTADO",
            "BAC": "PRIVADO",
        },
        "comedor": True,
        "transporte": True,
        "color": "#1d4ed8",
    },
    {
        "clave": "demo-publico",
        "nombre": "CEIP Demo Dos",
        "subdominio": "demo-dos",
        "regimen_general": "PUBLICO",
        "etapas": {
            "INF2": "PUBLICO",
            "PRI": "PUBLICO",
        },
        "comedor": True,
        "transporte": False,   # sin ruta propia: prueba de módulo desactivado
        "color": "#15803d",
    },
    {
        "clave": "demo-privado",
        "nombre": "Colegio Demo Tres",
        "subdominio": "demo-tres",
        "regimen_general": "PRIVADO",
        "etapas": {
            "INF1": "PRIVADO",
            "INF2": "PRIVADO",
            "PRI": "PRIVADO",
            "ESO": "PRIVADO",
            "BAC": "PRIVADO",
        },
        "comedor": True,
        "transporte": True,
        "color": "#b45309",
    },
]


# ══════════════════════════════════════════════════════════ generación

def generar_centro(g: Gen, perfil: dict) -> dict:
    total_alumnos = g.r.randint(300, 1200)
    centro = {
        "id": g.id("CEN"),
        "sintetico": True,
        "clave": perfil["clave"],
        "nombre": perfil["nombre"],
        "subdominio": perfil["subdominio"],
        "regimen_general": perfil["regimen_general"],
        "curso_academico": CURSO_ACADEMICO,
        "direccion": g.direccion(),
        "telefono": g.telefono(),
        "correo": f"secretaria@{perfil['subdominio']}.{DOMINIO_CORREO}",
        "branding": {"color_primario": perfil["color"]},
        "modulos_activos": _modulos(perfil),
        "objetivo_alumnos": total_alumnos,
    }

    grupos = _grupos(g, perfil, total_alumnos)
    alumnos, familias = _alumnado(g, centro, grupos, perfil)
    personal = _plantilla(g, centro, grupos, perfil, len(alumnos))
    horarios = _horarios(g, grupos, personal)
    asistencia = _asistencia(g, alumnos)
    calificaciones = _calificaciones(g, alumnos, grupos)
    transporte = _transporte(g, centro, alumnos, personal, perfil)
    facturacion = _facturacion(g, centro, alumnos, perfil, transporte)

    centro["resumen"] = {
        "grupos": len(grupos),
        "alumnos": len(alumnos),
        "familias": len(familias),
        "personal": len(personal),
        "rutas": len(transporte["rutas"]),
        "alumnos_con_transporte": len(transporte["suscripciones"]),
        "facturas_mes": len(facturacion),
    }

    return {
        "centro": centro,
        "grupos": grupos,
        "alumnos": alumnos,
        "familias": familias,
        "personal": personal,
        "horarios": horarios,
        "asistencia": asistencia,
        "calificaciones": calificaciones,
        "transporte": transporte,
        "facturacion": facturacion,
    }


def _modulos(perfil: dict) -> list:
    base = ["REQ-CORE", "REQ-AUTH", "REQ-CURSO", "REQ-ACAD", "REQ-ALUM",
            "REQ-FAM-UNIT", "REQ-CALIF", "REQ-COM", "REQ-AGENDA", "REQ-PROF",
            "REQ-FAM-PORTAL", "REQ-EST", "REQ-BKP"]
    if perfil["regimen_general"] != "PUBLICO":
        base.append("REQ-FIN")
    if "INF1" in perfil["etapas"]:
        base += ["REQ-INF", "REQ-BEC"]
    if perfil["comedor"]:
        base.append("REQ-COMED")
    if perfil["transporte"]:
        base.append("REQ-TRAN")
    return base


def _grupos(g: Gen, perfil: dict, objetivo: int) -> list:
    """Reparte el objetivo de alumnos en etapas, cursos y líneas."""
    etapas = list(perfil["etapas"].keys())
    # peso relativo de cada etapa dentro del centro
    pesos = {"INF1": 0.10, "INF2": 0.18, "PRI": 0.38, "ESO": 0.24, "BAC": 0.10}
    total_peso = sum(pesos[e] for e in etapas)

    grupos = []
    for etapa in etapas:
        alumnos_etapa = int(objetivo * pesos[etapa] / total_peso)
        n_cursos = ETAPAS[etapa][2]
        por_curso = max(1, alumnos_etapa // n_cursos)
        # ratio orientativa por línea según etapa
        ratio = {"INF1": 14, "INF2": 22, "PRI": 24, "ESO": 27, "BAC": 30}[etapa]
        lineas = max(1, min(len(LETRAS_GRUPO), round(por_curso / ratio)))

        for idx_curso in range(n_cursos):
            for linea in range(lineas):
                plazas = por_curso // lineas
                variacion = g.r.randint(-2, 2)
                grupos.append({
                    "id": g.id("GRP"),
                    "sintetico": True,
                    "etapa": etapa,
                    "etapa_nombre": ETAPAS[etapa][0],
                    "regimen": perfil["etapas"][etapa],
                    "curso": NOMBRE_CURSO[etapa][idx_curso],
                    "nivel": idx_curso + 1,
                    "linea": LETRAS_GRUPO[linea],
                    "edad_teorica": ETAPAS[etapa][1] + idx_curso,
                    "plazas": max(6, plazas + variacion),
                })
    return grupos


def _alumnado(g: Gen, centro: dict, grupos: list, perfil: dict):
    alumnos, familias = [], []

    for grupo in grupos:
        n = grupo["plazas"]
        for _ in range(n):
            sexo = g.r.choice(["H", "M"])
            nombre = g.nombre(sexo)
            ap1, ap2 = g.apellidos()

            # repetición: un pequeño porcentaje tiene un año más
            edad = grupo["edad_teorica"]
            repetidor = grupo["etapa"] in ("PRI", "ESO") and g.r.random() < 0.05
            if repetidor:
                edad += 1

            alumno = {
                "id": g.id("ALU"),
                "sintetico": True,
                "centro_id": centro["id"],
                "grupo_id": grupo["id"],
                "nombre": nombre,
                "apellido1": ap1,
                "apellido2": ap2,
                "sexo": sexo,
                "fecha_nacimiento": g.fecha_nac(edad),
                "documento": g.documento(),
                "idioma_preferido": g.r.choice(IDIOMAS),
                "repetidor": repetidor,
                "estado": "ACTIVO",
            }

            # alta o baja a mitad de curso: prueba de prorrateo
            if g.r.random() < 0.04:
                dia = g.r.randint(20, 110)
                alumno["fecha_alta"] = (INICIO_CURSO + timedelta(days=dia)).isoformat()
            if g.r.random() < 0.02:
                dia = g.r.randint(60, 150)
                alumno["fecha_baja"] = (INICIO_CURSO + timedelta(days=dia)).isoformat()
                alumno["estado"] = "BAJA"

            # NEAE: datos de categoría especial, en estructura aparte
            if g.r.random() < 0.08:
                alumno["neae"] = {
                    "categoria_especial": True,
                    "tipo": g.r.choice(TIPOS_NEAE),
                    "requiere_permiso_propio": True,
                }

            # consentimiento de imagen: TRES estados, pendiente = no autorizado
            alumno["consentimiento_imagen"] = {
                "web_centro": g.r.choices(
                    ["AUTORIZADO", "NO_AUTORIZADO", "PENDIENTE"], [60, 25, 15])[0],
                "redes_sociales": g.r.choices(
                    ["AUTORIZADO", "NO_AUTORIZADO", "PENDIENTE"], [40, 40, 20])[0],
            }

            familia = _familia(g, centro, alumno, ap1, ap2)
            alumno["familia_id"] = familia["id"]
            familias.append(familia)
            alumnos.append(alumno)

    _hermanos(g, alumnos, familias)
    return alumnos, familias


def _familia(g: Gen, centro: dict, alumno: dict, ap1: str, ap2: str) -> dict:
    tipo = g.r.choices(
        ["BIPARENTAL", "MONOPARENTAL", "CUSTODIA_COMPARTIDA", "OTRA_TUTELA"],
        [62, 20, 15, 3])[0]

    tutores = []
    n_tutores = 1 if tipo == "MONOPARENTAL" else 2

    for i in range(n_tutores):
        sexo_t = "M" if i == 0 and tipo != "BIPARENTAL" else g.r.choice(["H", "M"])
        nombre_t = g.nombre(sexo_t)
        # el primer tutor comparte apellido con el alumno
        apellido_t = ap1 if i == 0 else ap2
        tutor = {
            "id": g.id("TUT"),
            "sintetico": True,
            "nombre": nombre_t,
            "apellido1": apellido_t,
            "apellido2": g.r.choice(APELLIDOS),
            "documento": g.documento(),
            "telefono": g.telefono(),
            "correo": g.correo(nombre_t, apellido_t, str(g.r.randint(1, 999))),
            "relacion": "PROGENITOR" if tipo != "OTRA_TUTELA" else "TUTOR_LEGAL",
            "idioma_preferido": g.r.choice(IDIOMAS),
            "acceso_restringido": False,
            "puede_recoger": True,
        }
        tutores.append(tutor)

    # ~2% de las familias con custodia compartida: restricción judicial de acceso.
    # Es el caso que permite probar REQ-FAM-UNIT-002 y REQ-TRAN-005.
    if tipo == "CUSTODIA_COMPARTIDA" and g.r.random() < 0.12:
        tutores[-1]["acceso_restringido"] = True
        tutores[-1]["motivo_restriccion"] = "Resolución judicial (dato sintético)"
        tutores[-1]["puede_recoger"] = False   # ADR-032: excluido de TODOS los servicios

    # ADR-032: lista MAESTRA de autorizados a recoger, en la unidad familiar.
    # Ningún servicio (transporte, comedor, extraescolares) mantiene la suya.
    autorizados = [
        {
            "id": g.id("AUT"),
            "sintetico": True,
            "origen": "TUTOR",
            "tutor_id": t["id"],
            "nombre": f"{t['nombre']} {t['apellido1']}",
            "documento": t["documento"],
            "relacion": "Tutor legal",
            "telefono": t["telefono"],
            "fotografia": None,          # nunca fotos de personas reales
            "activo": t["puede_recoger"],
        }
        for t in tutores
    ]
    # terceros autorizados: abuelos, personas de apoyo
    for _ in range(g.r.choices([0, 1, 2, 3], [20, 45, 25, 10])[0]):
        sexo_a = g.r.choice(["H", "M"])
        nombre_a = g.nombre(sexo_a)
        autorizados.append({
            "id": g.id("AUT"),
            "sintetico": True,
            "origen": "TERCERO",
            "tutor_id": None,
            "nombre": f"{nombre_a} {g.r.choice(APELLIDOS)}",
            "documento": g.documento(),
            "relacion": g.r.choice(["Abuelo/a", "Tío/a", "Persona de apoyo",
                                    "Vecino/a autorizado", "Hermano/a mayor"]),
            "telefono": g.telefono(),
            "fotografia": None,
            "activo": True,
            "autorizado_por": tutores[0]["id"],
        })

    return {
        "id": g.id("FAM"),
        "sintetico": True,
        "centro_id": centro["id"],
        "tipo": tipo,
        "direccion": g.direccion(),
        "tutores": tutores,
        "autorizados_recogida": autorizados,
        "alumnos": [alumno["id"]],
    }


def _hermanos(g: Gen, alumnos: list, familias: list):
    """Fusiona ~14% de las familias en unidades con hermanos."""
    porf = {f["id"]: f for f in familias}
    candidatos = [a for a in alumnos if a["estado"] == "ACTIVO"]
    g.r.shuffle(candidatos)

    for i in range(0, len(candidatos) - 1, 2):
        if g.r.random() > 0.14:
            continue
        a, b = candidatos[i], candidatos[i + 1]
        if a["grupo_id"] == b["grupo_id"]:
            continue
        fam_a, fam_b = porf[a["familia_id"]], porf[b["familia_id"]]
        if len(fam_a["alumnos"]) > 1 or len(fam_b["alumnos"]) > 1:
            continue
        # b se une a la familia de a
        fam_a["alumnos"].append(b["id"])
        b["familia_id"] = fam_a["id"]
        b["apellido1"] = a["apellido1"]
        fam_b["fusionada_en"] = fam_a["id"]

    familias[:] = [f for f in familias if "fusionada_en" not in f]


def _plantilla(g: Gen, centro: dict, grupos: list, perfil: dict, n_alumnos: int) -> list:
    """Plantilla completa: no solo docentes."""
    personal = []

    def alta(codigo, denominacion, ambito, propia, veces=1):
        for _ in range(veces):
            sexo = g.r.choice(["H", "M"])
            nombre = g.nombre(sexo)
            ap1, ap2 = g.apellidos()
            personal.append({
                "id": g.id("PER"),
                "sintetico": True,
                "centro_id": centro["id"],
                "puesto_codigo": codigo,
                "puesto": denominacion,
                "ambito": ambito,
                "plantilla_propia": propia,
                "empresa_externa": None if propia else f"Servicios Demo {ambito}, S.L.",
                "nombre": nombre,
                "apellido1": ap1,
                "apellido2": ap2,
                "documento": g.documento(),
                "correo": g.correo(nombre, ap1, str(g.r.randint(1, 999))),
                "telefono": g.telefono(),
                "tipo_contrato": g.r.choice(TIPOS_CONTRATO),
                "jornada": g.r.choice(JORNADAS),
                "idioma_preferido": g.r.choice(IDIOMAS),
            })

    tiene = lambda e: any(gr["etapa"] == e for gr in grupos)
    n_grupos = len(grupos)
    n_grupos_inf1 = sum(1 for gr in grupos if gr["etapa"] == "INF1")

    # equipo directivo
    alta("DIR", "Dirección", "Equipo directivo", True)
    alta("JEF", "Jefatura de Estudios", "Equipo directivo", True,
         1 if n_grupos < 20 else 2)
    alta("SEC", "Secretaría", "Equipo directivo", True)

    # docencia: un tutor por grupo (salvo 0-3, que lleva educadores)
    grupos_docencia = n_grupos - n_grupos_inf1
    alta("TUT", "Docente tutor", "Docencia", True, max(1, grupos_docencia))
    alta("ESP_ING", "Especialista de Inglés", "Docencia", True, max(1, n_grupos // 6))
    alta("ESP_EF", "Especialista de Educación Física", "Docencia", True, max(1, n_grupos // 8))
    alta("ESP_MUS", "Especialista de Música", "Docencia", True, max(1, n_grupos // 10))
    alta("ESP_REL", "Profesor de Religión", "Docencia", True, max(1, n_grupos // 10))

    # atención a la diversidad
    alta("PT", "Pedagogía Terapéutica", "Atención a la diversidad", True, max(1, n_alumnos // 400))
    alta("AL", "Audición y Lenguaje", "Atención a la diversidad", True, max(1, n_alumnos // 600))
    alta("ORI", "Orientación", "Atención a la diversidad", True)

    # primer ciclo de infantil
    if tiene("INF1"):
        alta("EDU_INF", "Educador de Educación Infantil", "Infantil 0-3", True, n_grupos_inf1)
        alta("TSEI", "Técnico Superior en Educación Infantil", "Infantil 0-3", True, n_grupos_inf1)
        alta("AUX_AULA", "Auxiliar de aula", "Infantil 0-3", True, max(1, n_grupos_inf1 // 2))

    # administración y servicios
    alta("ADM", "Administrativo", "Administración", True, max(1, n_alumnos // 500))
    alta("REC", "Recepción y secretaría de familias", "Administración", True)
    alta("CONS", "Conserjería", "Servicios", True, 1 if n_alumnos < 700 else 2)
    alta("MANT", "Mantenimiento", "Servicios", True)
    alta("INF_TIC", "Técnico de informática", "Servicios", True)
    alta("LIMP", "Limpieza", "Servicios", False, max(2, n_alumnos // 250))

    # cocina y comedor
    if perfil["comedor"]:
        alta("COC_JEF", "Jefatura de cocina", "Cocina y comedor", False)
        alta("COC_AYU", "Ayudante de cocina", "Cocina y comedor", False, max(1, n_alumnos // 400))
        alta("MON_COM", "Monitor de comedor", "Cocina y comedor", False, max(2, n_alumnos // 120))

    # complementarios
    alta("MON_EXT", "Monitor de actividades extraescolares", "Complementarios", False,
         max(2, n_alumnos // 200))

    # sanitario: solo centros grandes
    if n_alumnos > 600:
        alta("ENF", "Enfermería", "Sanitario", True)

    return personal


def _horarios(g: Gen, grupos: list, personal: list) -> list:
    docentes = [p for p in personal if p["ambito"] in ("Docencia", "Infantil 0-3")]
    if not docentes:
        return []
    horarios = []
    for grupo in grupos:
        materias = MATERIAS.get(grupo["etapa"], ["Jornada de aula"])
        for dia in range(1, 6):
            for tramo in range(1, 7):
                horarios.append({
                    "id": g.id("HOR"),
                    "sintetico": True,
                    "grupo_id": grupo["id"],
                    "dia_semana": dia,
                    "tramo": tramo,
                    "materia": g.r.choice(materias),
                    "docente_id": g.r.choice(docentes)["id"],
                })
    return horarios


def _asistencia(g: Gen, alumnos: list) -> list:
    """Un trimestre de asistencia con faltas verosímiles."""
    registros = []
    dia = INICIO_CURSO
    dias_lectivos = []
    while dia <= FIN_TRIMESTRE_1:
        if dia.weekday() < 5:
            dias_lectivos.append(dia)
        dia += timedelta(days=1)

    activos = [a for a in alumnos if a["estado"] == "ACTIVO"]
    for alumno in activos:
        # tasa de absentismo individual, con algunos casos altos
        tasa = g.r.choices([0.01, 0.03, 0.06, 0.15], [50, 30, 15, 5])[0]
        for fecha in dias_lectivos:
            if g.r.random() >= tasa:
                continue
            justificada = g.r.random() < 0.75
            registros.append({
                "id": g.id("ASI"),
                "sintetico": True,
                "alumno_id": alumno["id"],
                "fecha": fecha.isoformat(),
                "tipo": g.r.choices(["FALTA", "RETRASO"], [80, 20])[0],
                "justificada": justificada,
                "motivo": g.r.choice(MOTIVOS_FALTA) if justificada else "Sin justificar",
            })
    return registros


def _calificaciones(g: Gen, alumnos: list, grupos: list) -> list:
    por_grupo = {gr["id"]: gr for gr in grupos}
    calif = []
    for alumno in alumnos:
        grupo = por_grupo[alumno["grupo_id"]]
        etapa = grupo["etapa"]
        if etapa == "INF1":
            continue  # evaluación cualitativa, va por REQ-INF
        materias = MATERIAS.get(etapa, [])
        # perfil académico del alumno
        media = g.r.choices([4.5, 6.0, 7.5, 8.8], [15, 35, 35, 15])[0]
        for materia in materias:
            nota = max(1, min(10, round(g.r.gauss(media, 1.3), 1)))
            registro = {
                "id": g.id("CAL"),
                "sintetico": True,
                "alumno_id": alumno["id"],
                "grupo_id": grupo["id"],
                "evaluacion": "1ª Evaluación",
                "materia": materia,
                "publicada": True,
            }
            if etapa == "INF2":
                registro["calificacion_cualitativa"] = g.r.choice(
                    ["Iniciado", "En proceso", "Conseguido"])
            else:
                registro["nota"] = nota
                registro["calificacion"] = _literal(nota)
            calif.append(registro)
    return calif


def _literal(nota: float) -> str:
    if nota < 5:
        return "Insuficiente"
    if nota < 6:
        return "Suficiente"
    if nota < 7:
        return "Bien"
    if nota < 9:
        return "Notable"
    return "Sobresaliente"


def _transporte(g: Gen, centro: dict, alumnos: list, personal: list, perfil: dict) -> dict:
    if not perfil["transporte"]:
        return {"empresas": [], "rutas": [], "paradas": [], "vehiculos": [],
                "suscripciones": [], "registros_embarque": []}

    razon, cif = g.r.choice(EMPRESAS_BUS)
    empresa = {
        "id": g.id("EMP"),
        "sintetico": True,
        "centro_id": centro["id"],
        "razon_social": razon,
        "cif": cif,
        "contacto": g.telefono(),
        "correo": f"contacto@{_slug(razon.split(',')[0])}.{DOMINIO_CORREO}",
        # REQ-TRAN-001: sin contrato firmado no se comparte ni un dato
        "contrato_encargado_tratamiento": {
            "firmado": True,
            "fecha": "2026-07-15",
            "datos_compartidos": ["nombre", "apellidos", "parada", "contacto_emergencia"],
        },
        "seguro_viajeros_vigente_hasta": "2027-06-30",
    }

    acompanantes = [p for p in personal if p["puesto_codigo"] == "ACOMP"]
    n_rutas = g.r.randint(2, 5)
    rutas, paradas, vehiculos = [], [], []

    for i in range(n_rutas):
        plazas = g.r.choice([30, 35, 45, 55])
        vehiculo = {
            "id": g.id("VEH"),
            "sintetico": True,
            "empresa_id": empresa["id"],
            "matricula": f"{g.r.randint(1000, 9999)} ZZZ",
            "plazas_homologadas": plazas,
            "itv_vigente_hasta": "2027-03-15",
            "adaptado_movilidad_reducida": g.r.random() < 0.25,
        }
        vehiculos.append(vehiculo)

        # conductor y acompañante con certificación negativa del RCDS
        sexo = g.r.choice(["H", "M"])
        nombre_c = g.nombre(sexo)
        ap_c = g.r.choice(APELLIDOS)
        conductor = {
            "id": g.id("CON"),
            "sintetico": True,
            "empresa_id": empresa["id"],
            "nombre": nombre_c,
            "apellido1": ap_c,
            "documento": g.documento(),
            "cap_vigente_hasta": "2028-01-31",
            # REQ-TRAN-003: sin esto vigente el sistema BLOQUEA la asignación
            "certificacion_negativa_rcds": {
                "aportada": True,
                "vigente_hasta": "2027-09-01",
            },
        }

        ruta = {
            "id": g.id("RUT"),
            "sintetico": True,
            "centro_id": centro["id"],
            "empresa_id": empresa["id"],
            "vehiculo_id": vehiculo["id"],
            "nombre": f"Ruta {i + 1} · {g.r.choice(MUNICIPIOS)}",
            "conductor": conductor,
            "acompanante_id": acompanantes[i % len(acompanantes)]["id"] if acompanantes else None,
            "plazas": plazas,
            "sentidos": ["IDA", "VUELTA"],
            "tarifa_mensual_centimos": g.r.choice([4500, 5200, 6000, 6800]),
        }
        rutas.append(ruta)

        n_paradas = g.r.randint(4, 9)
        hora = 7 * 60 + 30
        for orden in range(n_paradas):
            hora += g.r.randint(4, 9)
            paradas.append({
                "id": g.id("PAR"),
                "sintetico": True,
                "ruta_id": ruta["id"],
                "orden": orden + 1,
                "nombre": f"{g.r.choice(VIAS)}, {g.r.randint(1, 90)}",
                "municipio": g.r.choice(MUNICIPIOS),
                "hora_ida": f"{hora // 60:02d}:{hora % 60:02d}",
                "hora_vuelta": f"{(hora + 540) // 60:02d}:{(hora + 540) % 60:02d}",
            })

    # suscripciones: ~22% del alumnado activo
    activos = [a for a in alumnos if a["estado"] == "ACTIVO"]
    n_suscritos = int(len(activos) * g.r.uniform(0.15, 0.28))
    capacidad = sum(r["plazas"] for r in rutas)
    n_suscritos = min(n_suscritos, capacidad)   # REQ-TRAN-002: nunca superar plazas

    suscripciones, embarques = [], []
    por_ruta = {r["id"]: 0 for r in rutas}
    # ocupación objetivo por ruta: una demo con todas las rutas llenas no es
    # verosímil ni permite demostrar el alta de un alumno en ruta
    tope = {r["id"]: max(4, int(r["plazas"] * g.r.uniform(0.55, 0.88))) for r in rutas}
    paradas_por_ruta = {}
    for p in paradas:
        paradas_por_ruta.setdefault(p["ruta_id"], []).append(p)

    for alumno in g.r.sample(activos, n_suscritos):
        disponibles = [r for r in rutas if por_ruta[r["id"]] < tope[r["id"]]]
        if not disponibles:
            break
        ruta = g.r.choice(disponibles)
        por_ruta[ruta["id"]] += 1
        ps = paradas_por_ruta[ruta["id"]]
        parada_subida = g.r.choice(ps)
        # la parada de vuelta puede no ser la de ida
        parada_bajada = parada_subida if g.r.random() < 0.85 else g.r.choice(ps)

        modalidad = g.r.choices(
            ["IDA_Y_VUELTA", "SOLO_IDA", "SOLO_VUELTA", "DIAS_SUELTOS"],
            [70, 12, 10, 8])[0]

        # REQ-TRAN-005: quién puede recoger al alumno
        baja_solo = g.r.random() < 0.18

        sus = {
            "id": g.id("SUS"),
            "sintetico": True,
            "alumno_id": alumno["id"],
            "ruta_id": ruta["id"],
            "parada_subida_id": parada_subida["id"],
            "parada_bajada_id": parada_bajada["id"],
            "modalidad": modalidad,
            "dias": ["L", "M", "X", "J", "V"] if modalidad != "DIAS_SUELTOS"
                    else g.r.sample(["L", "M", "X", "J", "V"], g.r.randint(2, 3)),
            # ADR-032: se REFERENCIA la lista maestra de la familia, no se copia
            "autorizacion_recogida": {
                "puede_bajar_solo": baja_solo,
                "fuente": "REQ-FAM-UNIT-005",
                "autorizados_ref": [] if baja_solo else "familia.autorizados_recogida",
            },
            "importe_mensual_centimos": ruta["tarifa_mensual_centimos"] if modalidad == "IDA_Y_VUELTA"
                                        else int(ruta["tarifa_mensual_centimos"] * 0.6),
        }
        suscripciones.append(sus)

        # registros de embarque de una semana, con una discrepancia deliberada
        for d in range(5):
            fecha = (INICIO_CURSO + timedelta(days=60 + d)).isoformat()
            subio = g.r.random() > 0.06
            if not subio:
                continue
            # ~0.5%: sube y no consta bajada → REQ-TRAN-006 debe alertar
            bajo = g.r.random() > 0.005
            embarques.append({
                "id": g.id("EMB"),
                "sintetico": True,
                "suscripcion_id": sus["id"],
                "alumno_id": alumno["id"],
                "fecha": fecha,
                "sentido": "IDA",
                "subida_parada_id": parada_subida["id"],
                "subida_hora": parada_subida["hora_ida"],
                "bajada_registrada": bajo,
                "alerta_discrepancia": not bajo,
            })

    return {
        "empresas": [empresa],
        "rutas": rutas,
        "paradas": paradas,
        "vehiculos": vehiculos,
        "suscripciones": suscripciones,
        "registros_embarque": embarques,
    }


def _facturacion(g: Gen, centro: dict, alumnos: list, perfil: dict, transporte: dict) -> list:
    """Factura mensual con líneas de enseñanza, comedor y transporte."""
    if perfil["regimen_general"] == "PUBLICO":
        conceptos_ensenanza = False
    else:
        conceptos_ensenanza = True

    sus_por_alumno = {s["alumno_id"]: s for s in transporte["suscripciones"]}
    facturas = []

    for alumno in alumnos:
        if alumno["estado"] != "ACTIVO":
            continue
        lineas = []

        if conceptos_ensenanza:
            # el importe depende del régimen DE LA ETAPA, no del centro (ADR-020)
            lineas.append({
                "concepto": "Enseñanza",
                "importe_centimos": g.r.choice([0, 0, 12000, 18000, 25000]),
                "iva_aplicable": False,
            })

        if perfil["comedor"] and g.r.random() < 0.55:
            lineas.append({
                "concepto": "Comedor escolar",
                "importe_centimos": g.r.choice([9500, 11000, 12500]),
                "iva_aplicable": True,
            })

        sus = sus_por_alumno.get(alumno["id"])
        if sus:
            lineas.append({
                "concepto": "Transporte escolar",
                "importe_centimos": sus["importe_mensual_centimos"],
                "iva_aplicable": True,
                "referencia": sus["id"],
            })

        if not lineas:
            continue

        # beca 0-3 de la Comunidad de Madrid, solo en primer ciclo privado
        descuentos = []
        if "INF1" in perfil["etapas"] and g.r.random() < 0.12:
            descuentos.append({
                "concepto": "Beca primer ciclo de Infantil",
                "importe_centimos": g.r.choice([17700, 28300]),
                "condicionado_asistencia": True,
            })

        bruto = sum(l["importe_centimos"] for l in lineas)
        desc = sum(d["importe_centimos"] for d in descuentos)
        facturas.append({
            "id": g.id("FAC"),
            "sintetico": True,
            "centro_id": centro["id"],
            "alumno_id": alumno["id"],
            "periodo": "2026-10",
            "lineas": lineas,
            "descuentos": descuentos,
            "total_centimos": max(0, bruto - desc),
            "estado": g.r.choices(["PAGADA", "PENDIENTE", "IMPAGADA"], [80, 15, 5])[0],
        })

    return facturas


# ══════════════════════════════════════════════════════════ salida

def main():
    ap = argparse.ArgumentParser(description="Generador de datos sintéticos · REQ-SEED")
    ap.add_argument("--semilla", type=int, default=2026,
                    help="semilla reproducible (REQ-SEED-006)")
    ap.add_argument("--salida", default="./salida", help="directorio de salida")
    ap.add_argument("--centros", default="todos",
                    help="claves separadas por coma, o 'todos'")
    args = ap.parse_args()

    destino = Path(args.salida)
    destino.mkdir(parents=True, exist_ok=True)

    perfiles = PERFILES if args.centros == "todos" else [
        p for p in PERFILES if p["clave"] in args.centros.split(",")]

    resumen_global = []
    for perfil in perfiles:
        # semilla derivada: cada centro es reproducible por separado
        g = Gen(args.semilla + sum(ord(c) for c in perfil["clave"]))
        datos = generar_centro(g, perfil)

        fichero = destino / f"{perfil['clave']}.json"
        fichero.write_text(json.dumps(datos, ensure_ascii=False, indent=2),
                           encoding="utf-8")
        resumen_global.append({
            "clave": perfil["clave"],
            "nombre": perfil["nombre"],
            "regimen": perfil["regimen_general"],
            **datos["centro"]["resumen"],
        })
        print(f"  {perfil['nombre']:<22} {datos['centro']['resumen']}")

    (destino / "resumen.json").write_text(
        json.dumps({"semilla": args.semilla, "centros": resumen_global},
                   ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"\nGenerado en {destino.resolve()} · semilla {args.semilla}")


if __name__ == "__main__":
    main()
