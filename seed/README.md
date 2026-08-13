# Generador de datos sintéticos · `REQ-SEED`

Genera tres centros ficticios completos para desarrollo, pruebas de rendimiento y demostración comercial.

> **Regla innegociable** (`ADR-030`): en desarrollo **solo se usan datos sintéticos**. Nunca una exportación real del centro, ni una copia de producción para depurar. Este generador existe para que esa regla sea cumplible.

## Uso

```bash
python3 generador.py --semilla 2026 --salida ./salida
python3 generador.py --centros demo-concertado --semilla 7
python3 verificar.py
```

Sin dependencias externas: solo biblioteca estándar.

La **semilla es reproducible**: la misma semilla produce exactamente el mismo conjunto, lo que permite reproducir un fallo (`REQ-SEED-006`).

## Los tres centros

| Clave | Centro | Régimen | Particularidad |
|---|---|---|---|
| `demo-concertado` | Colegio Demo Uno | Concertado | **0-3 en régimen privado**, con becas de Madrid. Transporte y comedor |
| `demo-publico` | CEIP Demo Dos | Público | Sin facturación de enseñanza. **Sin transporte**: prueba de módulo desactivado |
| `demo-privado` | Colegio Demo Tres | Privado | Todas las etapas, facturación completa |

El régimen es **por etapa, no por centro** (`ADR-020`): el concertado tiene INF1 y Bachillerato privados y el resto concertado.

## Qué genera

- **Alumnado**: entre 300 y 1.200 por centro, repartido por etapas y líneas con ratios realistas. Repetidores, altas y bajas a mitad de curso, NEAE en estructura separada, idioma preferido entre los cuatro soportados.
- **Familias**: biparentales, monoparentales, custodia compartida y otras tutelas. Hermanos en el centro. **Casos con restricción judicial de acceso**, que es lo que permite probar `REQ-FAM-UNIT-002` y `REQ-TRAN-005`.
- **Consentimiento de imagen**: tres estados independientes para web y redes, incluido "pendiente".
- **Plantilla completa**: equipo directivo, tutores, especialistas, PT, AL, orientación, educadores de 0-3, TSEI, administración, conserjería, mantenimiento, limpieza, cocina, monitores de comedor y extraescolares, acompañantes de ruta y enfermería. Con distinción entre plantilla propia y empresa externa.
- **Datos operativos**: horarios, un trimestre de asistencia con absentismo verosímil, calificaciones de la primera evaluación (cualitativas en Infantil).
- **Transporte**: empresas con contrato de encargado de tratamiento, rutas, paradas ordenadas, vehículos, conductores con certificación RCDS, suscripciones con modalidad y autorizaciones de recogida, y registros de embarque **incluyendo casos de subida sin bajada** para probar la alerta de `REQ-TRAN-006`.
- **Facturación**: factura mensual con líneas de enseñanza, comedor y transporte, y descuentos por beca de primer ciclo.

## Convención de datos sintéticos (`REQ-SEED-005`)

| Elemento | Regla |
|---|---|
| Centros | Nombre explícitamente ficticio (`Colegio Demo Uno`) |
| Correos | Siempre `@example.com`, dominio reservado para documentación |
| Documentos | Formato válido, **dígito de control deliberadamente incorrecto**: sirven para probar la validación y son inutilizables como identificador real |
| Teléfonos | Rangos no asignables |
| Direcciones | Vías inventadas en municipios reales |
| Fotografías | Ninguna. Nunca imágenes de personas reales |
| Marcado | Todo registro lleva `"sintetico": true` |

## Verificación

`verificar.py` comprueba las invariantes que importan:

- Todo registro marcado como sintético
- Ningún documento con dígito de control válido
- Ningún correo fuera del dominio reservado
- Régimen por etapa coherente con el perfil
- Los tres estados de consentimiento presentes
- **Capacidad de ruta nunca superada** (`REQ-TRAN-002`)
- Conductores con certificación RCDS
- Empresas con contrato de encargado de tratamiento firmado
- Cuadre entre suscripciones de transporte y líneas de factura

## Paso a Laravel

Este generador contiene la **lógica de dominio**, que es la parte que no depende del ORM. Cuando exista el esquema (paso 0.8), se traslada a un comando de Artisan:

1. Portar las funciones `_grupos`, `_alumnado`, `_familia`, `_plantilla` y `_transporte` a clases de dominio.
2. Sustituir los diccionarios por modelos de Eloquent, respetando `ADR-029`: clave interna `bigint` y `public_id` con ULID.
3. Ejecución en cola por lotes con progreso (`INV-012`): 1.200 alumnos con historial no es instantáneo.
4. **Bloqueo en producción sin parámetro para saltarlo** (`REQ-SEED-006`).
5. Comando complementario de purga de datos sintéticos.

Mientras tanto, el JSON generado sirve para prototipar interfaces y para la demostración comercial del hito H0.
