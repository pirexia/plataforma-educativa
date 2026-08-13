#!/usr/bin/env python3
"""Comprobaciones de coherencia sobre los datos generados."""
import json, sys
from pathlib import Path
from collections import Counter

fallos, avisos = [], []
def check(cond, msg):
    (fallos if not cond else avisos).append(msg) if not cond else None

for f in sorted(Path("salida").glob("demo-*.json")):
    d = json.loads(f.read_text(encoding="utf-8"))
    c, nombre = d["centro"], d["centro"]["nombre"]
    print(f"\n{'='*62}\n{nombre}  ({c['regimen_general']})  ·  {c['resumen']['alumnos']} alumnos")

    # 1 · todo marcado como sintético
    def sint(lista, etiq):
        malos = [x for x in lista if not x.get("sintetico")]
        if malos: fallos.append(f"{nombre}: {len(malos)} {etiq} sin marca sintética")
    for k in ("alumnos","familias","personal","grupos","calificaciones"):
        sint(d[k], k)

    # 2 · rango de alumnos
    n = len(d["alumnos"])
    if not 300 <= n <= 1200: fallos.append(f"{nombre}: {n} alumnos fuera del rango 300-1200")

    # 3 · correos y documentos
    from collections import Counter as C
    dominios = C(a.get("correo","@x").split("@")[-1] for fam in d["familias"] for a in fam["tutores"])
    if set(dominios) - {"example.com"}: fallos.append(f"{nombre}: dominios no reservados {set(dominios)}")

    # 4 · DNI con letra deliberadamente incorrecta
    L="TRWAGMYFPDXBNJZSQVHLCKE"
    validos=[a["documento"] for a in d["alumnos"] if L[int(a["documento"][:-1])%23]==a["documento"][-1]]
    if validos: fallos.append(f"{nombre}: {len(validos)} DNI con dígito de control VÁLIDO")

    # 5 · régimen por etapa
    reg = {g["etapa"]: g["regimen"] for g in d["grupos"]}
    print(f"  Régimen por etapa: {reg}")
    if "INF1" in reg and c["regimen_general"]=="CONCERTADO":
        check(reg["INF1"]=="PRIVADO", f"{nombre}: INF1 debería ser PRIVADO")

    # 6 · consentimiento de imagen en tres estados
    est = Counter(a["consentimiento_imagen"]["redes_sociales"] for a in d["alumnos"])
    print(f"  Consentimiento redes: {dict(est)}")
    if len(est)<3: fallos.append(f"{nombre}: faltan estados de consentimiento")

    # 7 · custodia con restricción
    restr = sum(1 for fam in d["familias"] for t in fam["tutores"] if t.get("acceso_restringido"))
    print(f"  Tutores con restricción judicial: {restr}")

    # 8 · NEAE separado
    neae = sum(1 for a in d["alumnos"] if "neae" in a)
    print(f"  Alumnos con NEAE: {neae} ({neae*100//n}%)")

    # 9 · transporte: capacidad NUNCA superada
    t = d["transporte"]
    if t["rutas"]:
        ocup = Counter(s["ruta_id"] for s in t["suscripciones"])
        for r in t["rutas"]:
            if ocup[r["id"]] > r["plazas"]:
                fallos.append(f"{nombre}: ruta {r['nombre']} con {ocup[r['id']]}/{r['plazas']} plazas")
        ocupacion = ", ".join(f"{ocup[r['id']]}/{r['plazas']}" for r in t["rutas"])
        print(f"  Rutas: {len(t['rutas'])} · ocupación {ocupacion}")
        # 10 · alerta de subida sin bajada
        alertas = [e for e in t["registros_embarque"] if e["alerta_discrepancia"]]
        print(f"  Embarques: {len(t['registros_embarque'])} · alertas subida-sin-bajada: {len(alertas)}")
        # 11 · RCDS vigente en todos los conductores
        sin = [r for r in t["rutas"] if not r["conductor"]["certificacion_negativa_rcds"]["aportada"]]
        if sin: fallos.append(f"{nombre}: conductores sin certificación RCDS")
        # 12 · contrato de encargado de tratamiento
        for e in t["empresas"]:
            check(e["contrato_encargado_tratamiento"]["firmado"], f"{nombre}: empresa sin contrato")

    # 13 · facturación con línea de transporte
    con_tran = sum(1 for fa in d["facturacion"] for l in fa["lineas"] if l["concepto"]=="Transporte escolar")
    print(f"  Facturas: {len(d['facturacion'])} · con línea de transporte: {con_tran}")
    check(con_tran == len(t["suscripciones"]) if t["rutas"] else True,
          f"{nombre}: descuadre transporte-factura")

    # 14 · plantilla completa
    amb = Counter(p["ambito"] for p in d["personal"])
    print(f"  Plantilla ({len(d['personal'])}): {dict(amb)}")
    docentes = sum(1 for p in d["personal"] if p["ambito"]=="Docencia")
    print(f"  Ratio alumnos/docente: {n/docentes:.1f}")

print(f"\n{'='*62}")
if fallos:
    print("FALLOS:"); [print(" ·", x) for x in fallos]; sys.exit(1)
print("Todas las comprobaciones pasan.")
