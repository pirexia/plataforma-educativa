# Expediente de cumplimiento

> **Estado**: borrador de arquitectura (agente A7 de la ola 1), 2026-11-12. Pendiente de revisión (`C1`) y de aceptación por el usuario.
> **Origen**: decisión del usuario del 2026-11-11 (`memory.md`): expediente `docs/cumplimiento/` con la matriz de normas con fuentes primarias verificadas, que nunca declara «cumple», con las evidencias de proveedores fuera de Git y solo su índice dentro.

## 1. Qué es y qué no es

**Es** el sitio único donde el proyecto anota qué normas le afectan, qué exige cada una a la plataforma y a sus proveedores, qué habría que poder demostrar y qué queda por decidir.

**No es**:

- **Una declaración de cumplimiento.** Ningún documento de esta carpeta afirma que la plataforma cumple una norma. Quien lo necesite afirmar tendrá que apoyarse en una validación jurídica (`OPEN-STO-12`) y en evidencias reales, que hoy no existen.
- **Asesoramiento legal.** Lo redacta un agente de arquitectura. Toda lectura jurídica lleva la marca **[PVJ]**, *pendiente de validación jurídica*.
- **Un archivo de evidencias.** El repositorio es público. Certificados, contratos e informes viven fuera de Git.
- **Una fuente de requisitos.** No añade requisitos a `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (`CLAUDE.md §11`).

## 2. Índice

| Documento | Contenido |
|---|---|
| [`matriz-normas.md`](matriz-normas.md) | RGPD, LOPDGDD, LOE DA 23.ª, ENS, NIS2, Reglamento de Datos, ISO 27001 y 9001, guías de la AEPD: precepto, fuente primaria, estado de verificación, qué exige a la plataforma y al proveedor, y evidencia asociada |
| [`preguntas-abiertas.md`](preguntas-abiertas.md) | `OPEN-STO-01` a `-12`: definición, opciones, recomendación, qué bloquean y quién decide |
| [`indice-evidencias.md`](indice-evidencias.md) | `EV-01` a `EV-22`: qué evidencia hace falta, quién la emite y su estado. Sin contenido ni ubicación |
| [`../adr/ADR-083-proveedor-s3-en-produccion.md`](../adr/ADR-083-proveedor-s3-en-produccion.md) | Proveedor S3 de staging y producción (`PROPUESTA`) y lo que hay que pedirle por escrito |

## 3. Reglas de mantenimiento

1. **Fuente primaria y fecha.** Cada afirmación sobre una norma cita el texto oficial y la fecha de consulta. Tres estados: «verificada» (texto leído en la fuente oficial), «existencia verificada, contenido no leído» y «sin verificar». No se asciende de estado sin haber leído.
2. **Nunca de memoria** versiones de normas, certificaciones, precios ni plazos.
3. **Verbos permitidos**: «exige», «habría que acreditar», «pendiente», «declarado por el proveedor». **Verbos prohibidos** sobre la plataforma o un proveedor: «cumple», «es conforme», «está certificada», salvo cita literal de un certificado archivado y vigente, con su identificador `EV-NN`.
4. **Evidencias fuera de Git**; aquí solo la fila del índice (reglas en `indice-evidencias.md §1`).
5. **Ningún dato personal** ni dato real de un centro. Ni el NIF ni el domicilio del titular (`CLAUDE.md §11`).
6. **Caducidad.** Las fuentes se vuelven a comprobar al contratar a un proveedor, al cerrar cada fase y, como máximo, al año de su fecha de consulta.
7. **Una decisión estructural** que salga de aquí va a un ADR (`docs/adr/`), no se toma en la matriz.

## 4. Estado a 2026-11-12

- Normas con el precepto leído en fuente oficial: RGPD (arts. 28, 32.1, 33.1, 35, 44), LOPDGDD (arts. 7, 28.2, 32, 33, 34 y DA 1.ª), LOE DA 23.ª, ENS (arts. 2.3 y 38), NIS2 (arts. 2, 21.2.d, 41 y anexo I §8), Reglamento de Datos (arts. 25, 29, 32.1 y 50).
- **Sin leer**: las ocho guías de la AEPD (solo se comprobó que existen), las normas ISO, y el estado oficial de la transposición española de NIS2.
- Evidencias archivadas: **ninguna**.
- Preguntas: 12 definidas, 1 cerrada, 11 abiertas.
- Proveedor: propuesto y **condicionado** (`ADR-083`); nada contratado ni probado.

## 5. Qué va después (propuesta, no decidido)

1. Aceptación o rechazo de `ADR-083` y respuesta a las preguntas que bloquean staging (`OPEN-STO-01`, `-03`, `-08`, `-11`).
2. Envío de las peticiones de `ADR-083 §6` al proveedor.
3. Una persona lee las guías de la AEPD G-02 y G-05 de la matriz; son las que más pueden cambiar las respuestas a `OPEN-STO-05` y `-07`.
4. Skill `cumplimiento-normativo` que consuma este expediente (decisión del usuario del 2026-11-11, como paso propio del plan). **No se escribe aquí**: `.claude/**` es fichero compartido.

## 6. Cambios propuestos en ficheros compartidos

Este agente no los toca; los aplica la sesión orquestadora al integrar (guion de la ola, `§8`).

| Fichero | Cambio propuesto |
|---|---|
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18 (índice de ADR) | Fila `ADR-083`: «**Proveedor de almacenamiento S3 en staging y producción y expediente de cumplimiento** (OVHcloud Object Storage Standard 3-AZ condicionado a confirmaciones por escrito; Scaleway como alternativa preparada; resuelve la pregunta 1 de `ADR-076 §10`; define `OPEN-STO-01` a `-12` en `docs/cumplimiento/`). **Estado: `PROPUESTA`**. Concreta `ADR-013` y `ADR-076`; no sustituye ninguno.» Y entrada en el historial de versiones |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §6 (tabla de `OPEN-*`) | Nota junto a `OPEN-10`: «relacionada con `ADR-083 §7`». Remitir los `OPEN-STO-*` a `docs/cumplimiento/preguntas-abiertas.md` en vez de copiarlos |
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §4.3 (normativa aplicable) | **Decisión del usuario, no un cambio automático**: la tabla no cita ENS, NIS2 ni el Reglamento de Datos. Si deben figurar, hay que añadirlos con su aplicación; este expediente no lo hace por no inventar requisitos |
| `PLAN-IMPLEMENTACION.md` | Marcar el expediente de cumplimiento como redactado en borrador; añadir el paso de la skill `cumplimiento-normativo`; anotar que staging (`ADR-080`) depende de `OPEN-STO-01`, `-03`, `-08` y `-11`, y que la entrada de datos reales depende además de `-04`, `-05`, `-07`, `-09` y `-12` |
| `memory.md` | En «`ADR-076` ACEPTADA…»: la pregunta 1 de `§10` queda contestada por `ADR-083`; los `OPEN-STO-*` ya existen y son una **reconstrucción** (la lista original del 2026-11-11 no se guardó). La recomendación «OVHcloud 3-AZ o Scaleway» pasa de «sin verificar» a «contrastada en parte» con lo que `ADR-083 §9` deja sin comprobar |
| `CHANGELOG.md` | Dentro de la entrada de la ola 1: expediente `docs/cumplimiento/` y `ADR-083` en `PROPUESTA` |
| `CLAUDE.md §6` (árbol de documentación) | Añadir `/docs/cumplimiento/{README,matriz-normas,preguntas-abiertas,indice-evidencias}.md`. Requiere aprobación del usuario |
| `PRIVACY.md` | En la sección de encargados y subencargados (hoy bloqueada por `OPEN-07`): remitir a `docs/cumplimiento/` y a `ADR-083`; **sin** afirmar que haya proveedor contratado |
| `ARCHITECTURE.md`, `SYSADMIN.md`, `RUNBOOK.md` | Nada hasta que `ADR-083` se acepte. Entonces: proveedor y región en `ARCHITECTURE.md`; variables del disco `s3` por entorno en `SYSADMIN.md`; procedimiento de salida ensayado en `RUNBOOK.md` (`OPEN-STO-09`) |
| `.claude/agents/architect.md` | **Incoherencia a resolver**: la definición del agente limita su escritura a `docs/adr/` y al índice de `§18`, y el guion de la ola le encarga `docs/cumplimiento/`. Se escribió en `docs/cumplimiento/` porque así lo decidió el usuario el 2026-11-11 (`memory.md`). Conviene que la definición lo recoja o que el expediente pase a otro agente |
| `ADR-076` | **No se edita** (inmutable). Su `§10` pregunta 1 queda contestada por `ADR-083`; la 6 pasa a `OPEN-STO-03` |

## 7. Hallazgos fuera de ámbito (no corregidos)

- `REQUISITOS §1.3` y `§2` (l. 195 y 273) dicen «cumplir» ISO 27001 y lo citan como «requisito de go-live» sin decir si es certificación o alineamiento: `OPEN-STO-06`.
- `ADR-076 §2.2` afirma, «según su documentación (no comprobado)», que Garage carece de versionado, Object Lock y cifrado gestionado. Este expediente se apoya en esa afirmación sin haberla comprobado tampoco.
