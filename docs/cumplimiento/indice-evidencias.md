# Expediente de cumplimiento · Índice de evidencias

> **Estado**: borrador de arquitectura (agente A7, ola 1), 2026-11-12.
> **Este repositorio es público. Aquí no se guarda ninguna evidencia: solo su índice.** A la fecha, **ninguna evidencia está obtenida ni archivada**: todas las filas están en «Pendiente».

## 1. Reglas

1. **Nunca en Git**: certificados, contratos firmados, informes de auditoría, correos con el proveedor, la EIPD, análisis de riesgos, ni capturas que los reproduzcan. Tampoco en *issues*, PR ni mensajes de commit.
2. **Nunca en este índice**: nombres de personas, NIF, domicilios, números de contrato o de cliente, importes pactados, ni la ruta o URL donde se guardan las evidencias (`CLAUDE.md §11`).
3. Lo que **sí** se anota por evidencia: identificador, descripción genérica, quién la emite, fecha de obtención, fecha de caducidad, estado y huella SHA-256 del fichero archivado (permite comprobar que no ha cambiado sin revelar su contenido).
4. El lugar de archivo y su custodia son `OPEN-STO-10` (sin decidir).
5. Una evidencia caducada equivale a no tenerla. La columna «Caduca» se revisa al cerrar cada fase.
6. Tener una evidencia **no equivale a cumplir** la norma a la que apunta: es un elemento para que quien valide (`OPEN-STO-12`) pueda pronunciarse.

## 2. Estados

`Pendiente` (no pedida) · `Pedida` (con fecha) · `Recibida` (archivada, con huella) · `Caducada` · `No procede` (con motivo y quién lo decidió).

## 3. Índice

| ID | Evidencia | Emite | Para (matriz) | Petición (`ADR-083 §6`) | Obtenida | Caduca | SHA-256 | Estado |
|---|---|---|---|---|---|---|---|---|
| `EV-01` | Certificado ISO/IEC 27001 del proveedor S3 | Entidad certificadora | N-01, N-21 | P-01 | — | — | — | Pendiente |
| `EV-02` | Declaración de alcance o de aplicabilidad que nombre el servicio de objetos y los centros de datos | Proveedor | N-01, N-04, N-21 | P-01 | — | — | — | Pendiente |
| `EV-03` | Otros informes del proveedor (SOC 2 tipo II u otros) | Auditor del proveedor | N-01 | P-03 | — | — | — | Pendiente |
| `EV-04` | Contrato de encargo entre cada centro y la plataforma (modelo y firmados) | Plataforma y centro | N-02, N-03 | — | — | — | — | Pendiente (depende de `OPEN-07`) |
| `EV-05` | Lista de subencargados del proveedor aplicable al servicio, con fecha | Proveedor | N-02, N-07 | P-05 | — | — | — | Pendiente |
| `EV-06` | Contrato de tratamiento de datos con el proveedor S3, versión aceptada | Proveedor | N-02, N-03, N-05, N-12, N-16, N-23 | P-04 | — | — | — | Pendiente (depende de `OPEN-07`) |
| `EV-07` | Análisis de riesgos del tratamiento | Plataforma | N-04 | — | — | — | — | Pendiente |
| `EV-08` | Actas de pruebas de restauración | Plataforma | N-04 | — | — | — | — | Pendiente (`REQ-BKP-004`) |
| `EV-09` | Procedimiento de brechas y plazo de aviso del proveedor | Plataforma y proveedor | N-05 | P-10 | — | — | — | Pendiente |
| `EV-10` | Evaluación de impacto (modelo y adoptadas por cada centro) | Centro, con la plataforma | N-06, N-10, N-16 | — | — | — | — | Pendiente (`OPEN-STO-05`) |
| `EV-11` | Declaración de ubicación de datos y réplicas, y posición ante requerimientos de terceros países | Proveedor | N-07, N-25 | P-06, P-07 | — | — | — | Pendiente |
| `EV-12` | Confirmación del cifrado en reposo (activo por defecto, algoritmo, custodia de claves) y comprobación en staging | Proveedor y plataforma | N-04 | P-08 | — | — | — | Pendiente |
| `EV-13` | Plazos de borrado efectivo del proveedor y política de conservación de la plataforma | Proveedor y plataforma | N-08, N-11 | P-09, P-15, P-16 | — | — | — | Pendiente (`OPEN-STO-08`) |
| `EV-14` | Condiciones de salida del proveedor y acta del ensayo de salida | Proveedor y plataforma | N-12, N-23, N-24 | P-11 | — | — | — | Pendiente (`OPEN-STO-09`) |
| `EV-15` | Designación de delegado de protección de datos y su comunicación, si procede | Plataforma | N-13 | — | — | — | — | Pendiente (`OPEN-07`) |
| `EV-16` | Dictamen sobre la aplicabilidad del ENS y de NIS2 a la plataforma | Asesor | N-14, N-17 | — | — | — | — | Pendiente (`OPEN-STO-04`) |
| `EV-17` | Certificado de conformidad con el ENS del proveedor (categoría, alcance, vigencia) | Entidad certificadora | N-17, N-18 | P-02 | — | — | — | Pendiente |
| `EV-18` | Seguimiento de la transposición española de NIS2 (referencia del BOE cuando exista) | — | N-19, N-20 | — | — | — | — | Pendiente |
| `EV-19` | Oferta o tarifa vigente del proveedor y regiones disponibles | Proveedor | `ADR-083 §3` | P-12, P-14 | — | — | — | Pendiente |
| `EV-20` | Respuesta sobre políticas por prefijo y claves múltiples | Proveedor | `OPEN-STO-03` | P-13 | — | — | — | Pendiente |
| `EV-21` | Resultado del test de conformidad de `ADR-076 §5` contra el proveedor real | Plataforma | `ADR-076 §2.3` | — | — | — | — | Pendiente (`ADR-080`) |
| `EV-22` | Validación jurídica del expediente | Asesor | Todo lo marcado [PVJ] | — | — | — | — | Pendiente (`OPEN-STO-12`) |

`EV-21` es la única que puede vivir en el repositorio (es salida de un test con datos sintéticos); se referencia por su commit o su ejecución de CI.

## 4. Cómo se actualiza

Quien obtiene una evidencia la archiva fuera de Git, calcula su huella y cambia aquí la fila en un PR normal (`docs(cumplimiento): … [ADR-083]`). El PR no adjunta el fichero ni lo describe más allá de la fila.
