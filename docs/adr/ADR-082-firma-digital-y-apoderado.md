# ADR-082 · Firma electrónica por niveles, evidencias, PDF firmado y firmante autorizado (apoderado) del centro

**Estado**: **PROPUESTA** (2026-11-12, agente A6 de la ola 1 de arquitectura, `docs/proceso/ola1-arquitectura-2026-11.md`). No está aceptada: la acepta el usuario. Ninguna de sus decisiones se implementa mientras siga en este estado.
**Fecha**: 2026-11-12
**Origen**: issue #82 (decisión del usuario del 2026-08-25: el firmante autorizado o apoderado es una capacidad general del núcleo, dentro de `REQ-DOC-002`) y encargo de la ola 1.
**Afecta a**: `REQ-DOC-002`, `REQ-AUT-001` a `-003`, `REQ-FAM-PORTAL-004` y los consumidores de la tabla de `§4.1` (`REQ-ALUM-002`, `REQ-CURSO-003`/`-004`, `REQ-CALIF-002`, `REQ-INF-002`, `REQ-EXTRA-003`, `REQ-SEC-001`, `REQ-GOB-002`, `REQ-FCT-001`, `REQ-NOM-001`, `REQ-CONV-002`, `REQ-NEAE-002`, `REQ-SALUD-002`, `REQ-ONB-003`), `REQ-API-003` (conector de firma), `INV-001`, `INV-002`, `INV-003`, `INV-007`, `INV-008`, `INV-009`, `INV-010`, `INV-011`, `INV-012`.
**Concreta**: `ADR-012` (firma electrónica) en todo lo que este no detalla: niveles, evidencias, custodia, sellado de tiempo, PDF y firmante.
**Sustituye en parte a** (propuesta, sujeta a `OPEN-FIRMA-01`): `ADR-012`, frase «integrar un prestador cualificado de servicios de confianza para las firmas con valor probatorio (contratos, actas, autorizaciones)». Pasa a leerse: el prestador es obligatorio para los niveles N2 y N3 de `§4.1`; el nivel N1 se registra en la propia plataforma. La frase «No desarrollar criptografía propia» **se mantiene íntegra**. Si el usuario rechaza `OPEN-FIRMA-01`, `ADR-012` queda sin tocar y rige la opción A de `§4.2`.
**Se apoya en**: `ADR-013` y `ADR-076` (almacén privado, URL firmada de caducidad corta), `ADR-029` (tipos e identificadores), `ADR-034 §1` (persona y facetas), `ADR-035` (auditoría y supresión), `ADR-039` (vocabulario de `audit_logs.event`), `ADR-044` (autorización), `ADR-048` (no se construye lo que no tiene consumidor), `ADR-051` (claves de catálogo declaradas en código), `ADR-062` (contenido traducible), `ADR-066` (estado calculado en origen, fallo en cerrado, texto versionado, canal `presencial`), `ADR-068` (personas sin cuenta, auditoría de lectura), `ADR-070` (texto legal como texto plano), `RNF-MANT-007`, `RARQ-ARC-005`, requisitos §12 punto 6 (tablas de solo anexar para firmas).
**Depende de** (borradores de la misma ola, sin aceptar): `ADR-078` (motor de PDF, `PROPUESTA`, leído en su rama `chore/adr-078-motor-de-renderizado-pdf` el 2026-11-12: WeasyPrint tras `PdfRenderer`, el motor no firma, perfil `archival` en PDF/A-2a o -3a; sus tres peticiones a este ADR se responden en `§4.6.1`), `ADR-079` (notificaciones salientes), `ADR-081` (pasarela y mandato SEPA). Ver `§7.4`.

> **Todo lo jurídico de este documento está pendiente de validación jurídica.** Las citas de normas describen el marco con el que se ha razonado, no un dictamen. Antes de cargar datos reales, la asesoría o el DPO del centro piloto validan la tabla de `§4.1`, la regla de tutores de `§4.7` y los plazos de `§4.5`.

---

## 1 · Contexto

### 1.1 Qué piden los requisitos

- `REQ-DOC-002` (módulo `REQ-DOC`, SHOULD, fase 2): firma electrónica de documentos (autorizaciones, contratos, actas), validez legal conforme a eIDAS y registro de firmas con sello de tiempo. Tres líneas; no dice quién firma, con qué nivel ni cómo se prueba.
- `REQ-AUT-001` (SHOULD, fase 2, depende de `FAM-UNIT` y `DOC`): el tutor acepta o rechaza, con «firma digital por parte del tutor con registro de fecha, hora, IP y dispositivo»; estados pendiente, aceptada, rechazada, caducada; control de versiones. `REQ-AUT-002`: caducidad configurable. `REQ-AUT-003`: historial y revocación.
- `ADR-012`: prestador cualificado para las firmas con valor probatorio, sin criptografía propia; el proveedor es encargado de tratamiento (`REQ-PRIV-005`). `REQ-API-003` nombra como conectores «DocuSign, Signaturit». `RNF-MANT-007` y `RARQ-ARC-005` exigen interfaz propia, cortacircuitos, tiempos de espera y reintentos.
- Otros dieciséis puntos de los requisitos mencionan «firma digital» sin más detalle (tabla de `§4.1`).
- Issue #82: ciertos tipos de documento solo pueden firmarlos apoderados del centro (representación legal, no cualquier administrador). Decidida la ubicación (núcleo, `REQ-DOC`); sin diseño.

### 1.2 Hechos verificados en el repositorio (`develop`, `b7995bc`)

1. **No existe módulo `doc` ni `aut`.** `docs/modulos/` tiene `REQ-ACAD`, `-ALUM`, `-AUTH`, `-BO`, `-CORE`, `-CURSO`, `-FAM-UNIT`, `-PERM`, `-PRL`. No hay ninguna tabla de firmas.
2. **La plataforma ya registra aceptaciones con evidencia, sin prestador.** `terms_acceptances`, `image_consent_decisions` y `pickup_authorizations` (`REQ-FAM-UNIT/datos.md §1.6`, `§1.9`, `§8.2`) son de solo anexar y guardan usuario, versión del texto, idioma mostrado, instante, `ip_address` (`inet`) y `user_agent` (≤512). `image_consent_decisions.channel` admite `portal` y `presencial`. Es, de hecho, el nivel N1 de este ADR. `REQ-FAM-UNIT/funcional.md` (tabla de exclusiones, l. 94) difería la «firma electrónica de consentimientos y condiciones» a `REQ-DOC`/`REQ-AUT` con «prestador cualificado sin integrar».
3. **La representación legal del menor ya está modelada**: `guardian_student_links.is_legal_guardian`, con `legal_guardian_verified_at`/`_by` (el centro afirma haberla verificado, `RN-FAM-UNIT-74`). `relationship` admite `'apoderado'` como parentesco **de la familia** (`REQ-FAM-UNIT-001`/`-002`), sin efecto de representación por sí mismo (`ADR-066 §5.4`: «un tutor sin representación legal (abuelo, apoderado) no decide»).
4. **`RPERM-003` ya prevé la acción `firmar`**, pero ningún permiso del código la usa (búsqueda de `firmar` en `apps/api/app`: solo aparece en textos de MFA, SAML y cierre de curso).
5. **El vocabulario de auditoría** (`Core\Domain\AuditCatalog::EVENTS`) es `created`, `updated`, `deleted`, `restored`, `read`, `exported`, `login`, `logout`, `password_reset_requested`. No hay evento de firma.
6. **No hay reautenticación para operaciones sensibles en el plano del centro.** `REQ-AUTH/funcional.md` (l. 1265) la excluyó por no estar en `REQ-AUTH-003`; solo `REQ-BO-007` la pide para el backoffice.
7. **La regla de 14 y 18 años no existe** (`OPEN-FAM-UNIT-18` abierta; `ADR-066 §5.6` fija no inventarla).
8. `custody_restrictions` sigue condicionada a `OPEN-FAM-UNIT-05`.
9. El almacén privado es `App\Support\Files\PrivateObjectStore` (`ADR-076`).

### 1.3 El problema real

Un centro de 1.200 alumnos emite del orden de decenas de miles de aceptaciones al año (salidas, autorizaciones generales, acuses de boletín, renovaciones). Tratarlas todas como «firma con valor probatorio por prestador» (lectura literal de `ADR-012`) tiene un coste por firma, saca a la familia del portal en cada salida y ata el producto a un proveedor para su operación diaria. Tratarlas todas como un clic sin más deja sin defensa los pocos documentos que sí acaban ante un juez o una inspección (contrato de matrícula, convenio de prácticas, sanción). La decisión de fondo es **dónde está la frontera** y qué se guarda a cada lado.

## 2 · Qué NO decide este ADR

- **El esquema de tablas, los *endpoints* y los permisos concretos**: son de `spec-writer` al abrir `REQ-DOC` y `REQ-AUT`. `§5` da el modelo conceptual y las reglas que esa especificación no puede romper.
- **El proveedor concreto ni su precio** (`OPEN-FIRMA-03`): `§4.2.4` fija los criterios.
- **El consentimiento de imagen** (`ADR-066`), las condiciones de uso y las autorizaciones de recogida (`ADR-068`): no se migran, no se «refirman» y sus reglas no cambian.
- **La regla de edad** (14 y 18 años): es `OPEN-FAM-UNIT-18`; aquí solo se dice dónde iría (`§4.7.4`).
- **El mandato SEPA**: su forma de firma la decide `ADR-081`; este ADR le ofrece los niveles.
- **La notificación fehaciente** de `REQ-CONV-002`: es un servicio de entrega electrónica certificada, no una firma; `ADR-079` y `OPEN-FIRMA-17`.
- **Los documentos oficiales de etapas regladas**: se generan y firman en Raíces (`ADR-016`).
- **El contrato entre la plataforma y el centro** (encargo de tratamiento, `OPEN-07`): es gestión comercial fuera del producto.
- **El modelo de asociaciones internas** (issue #78) como entidad representada: `OPEN-FIRMA-13`.
- Nada marcado `FUTURO`. La cartera europea de identidad digital (`§4.3`) se menciona como horizonte y no se diseña.

## 3 · Marco jurídico de partida (pendiente de validación jurídica)

| Norma | Lo que se toma de ella | Verificación |
|---|---|---|
| Reglamento (UE) 910/2014 (eIDAS), art. 25 | A una firma electrónica no se le niegan efectos jurídicos ni admisibilidad como prueba por ser electrónica o por no ser cualificada; solo la cualificada equivale a la manuscrita. | Fuentes secundarias (`§11`); texto de EUR-Lex **sin verificar** en esta sesión |
| Ídem, art. 26 | La firma avanzada exige vinculación única al firmante, capacidad de identificarlo, control exclusivo de los datos de creación y detección de cambios posteriores. | **Sin verificar** el literal |
| Ley 6/2020, art. 3 | Los documentos electrónicos privados tienen el valor que corresponda a su naturaleza; su prueba se rige por el art. 326.3 LEC si el servicio de confianza no es cualificado y por el 326.4 si lo es (presunción a favor del servicio cualificado). | BOE, texto consolidado (última actualización 2023-05-09), consultado 2026-11-12 |
| Ley 6/2020, art. 7.4 | En certificados de sello y de firma con atributo de representante, el prestador comprueba la personalidad jurídica y las facultades de representación. | Ídem |
| Código Civil, art. 156 | Patria potestad conjunta; validez de los actos de uno solo conforme al uso social o en urgencia. De aquí sale la distinción «acto usual» / «acto que exige a ambos» de `§4.7`. | **Sin verificar**; la búsqueda no encontró criterio aplicable a salidas escolares |
| LOPDGDD (LO 3/2018), art. 7 | Edad de consentimiento en protección de datos: 14 años. | Citada por `ADR-066 §5.6`; no reverificada |
| Reglamento (UE) 2024/1183 (eIDAS 2) | Cartera europea de identidad digital; fechas de disponibilidad (finales de 2026) y de aceptación por el sector privado (finales de 2027) **discrepan entre fuentes**. | **Sin verificar** |

Consecuencia de trabajo: **el nivel de firma no determina la validez, determina quién carga con la prueba y cuánto cuesta sostenerla**. Una aceptación con buenas evidencias vale; lo que cambia con un servicio cualificado es la presunción. Por eso la frontera se pone por riesgo del documento, no por tipo de usuario. `ADR-012` afirma en su motivo que «la validez eIDAS exige sello de tiempo cualificado y evidencia custodiada por un tercero»: con el marco anterior esa frase es más estricta que la norma; se señala para la validación jurídica y no se corrige aquí.

## 4 · Decisiones

Criterios de evaluación, los del proyecto: coste de implementación en solitario, mantenimiento a tres años, impacto en las invariantes y reversibilidad.

### 4.1 D1 · Nivel de firma por caso de uso

**Vocabulario del producto** (cuatro niveles, lista cerrada):

| Nivel | Qué es | Correspondencia eIDAS | Quién lo ejecuta |
|---|---|---|---|
| `N1` · aceptación con evidencia | Acción explícita de un usuario autenticado sobre un documento inmutable, con registro de evidencias de `§4.5` | Firma electrónica (simple) | La plataforma |
| `N2` · firma avanzada | Firma con verificación adicional del firmante (código de un solo uso u otro medio del prestador) y evidencia custodiada por un tercero | Firma electrónica avanzada | Prestador, tras `SignatureProvider` |
| `N3` · firma con certificado cualificado | Firma con certificado cualificado del firmante (persona física o representante) | Firma electrónica cualificada | Prestador |
| `SELLO` · sello del centro | Sello electrónico de la persona jurídica titular del centro sobre documentos emitidos en serie | Sello electrónico (avanzado o cualificado) | Prestador; **no se construye ahora** (`§4.6`, `OPEN-FIRMA-12`) |

**Opciones para repartir los casos de uso**

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Todo N2 o superior (lectura literal de `ADR-012`) | Una sola vía; prueba fuerte en todo | Coste por firma multiplicado por decenas de miles; fricción en cada salida; el día a día depende de un tercero; `REQ-AUT-001` pide «IP y dispositivo», que es evidencia de N1 | Bajo hacia B (se añade N1) |
| B | **Nivel mínimo por tipo de documento, fijado en catálogo; el centro puede subirlo, nunca bajarlo** | Coste proporcional al riesgo; el portal resuelve lo cotidiano; lo delicado va al prestador | Hay que sostener dos vías; la tabla necesita validación jurídica | Bajo: subir un tipo de nivel es cambiar una fila del catálogo; las firmas ya hechas conservan su nivel |
| C | Todo N1 | Coste mínimo, sin proveedor | Incumple `ADR-012` entero; contratos y convenios con terceros sin cuenta no tienen cómo firmarse; prueba débil donde más importa | Alto: reforzar a posteriori un contrato ya firmado exige volver a firmarlo |
| D | El centro elige libremente el nivel de cada tipo | Flexibilidad | Un centro puede bajar un contrato a N1 sin saber lo que hace; soporte y responsabilidad difusos | Medio |

**Recomendación: B.** Es la única que respeta a la vez el volumen real y la intención de `ADR-012`. C y D se descartan por riesgo jurídico trasladado al centro; A por coste y dependencia.

**Tabla propuesta** (nivel mínimo; «firmante» según `§4.7` y `§4.8`):

| Documento | Requisito | Firmante | Nivel mínimo | Nota |
|---|---|---|---|---|
| Autorización de salida o actividad | `REQ-AUT-001`, `REQ-FAM-PORTAL-004` | Tutor legal | N1 | La plantilla puede marcarse N2 (p. ej. viaje con pernocta o al extranjero; `OPEN-FIRMA-02`) |
| Autorizaciones generales (salir solo, deporte, transporte) | `REQ-AUT-002` | Tutor legal | N1 | Revocables (`REQ-AUT-003`) |
| Autorización de medicación | `REQ-AUT-002`, `REQ-SALUD-002` | Tutor legal | N1 con reautenticación | Dato de salud: tabla separada y cifrada (`CLAUDE.md §8`); nivel a validar (`OPEN-FIRMA-02`) |
| Uso de imagen | `REQ-FAM-UNIT-004` | — | Sin cambio | `ADR-066` |
| Confirmación o baja en la renovación | `REQ-CURSO-004` | Tutor legal | N1 con reautenticación | |
| Contrato o ficha de matrícula; inscripción en extraescolar con contrato | `REQ-ALUM-002`, `REQ-EXTRA-003` | Tutores legales y firmante del centro | N2 | Tiene contenido económico |
| Mandato SEPA | (`ADR-081`) | Titular de la cuenta | Lo fija `ADR-081` | Se recomienda N2 |
| Boletín e informe de desarrollo | `REQ-CALIF-002`, `REQ-INF-002` | Docente tutor (y dirección, en `REQ-INF-002`) | N1 | Es una validación interna previa a publicar, no un contrato |
| Acta de evaluación interna | `REQ-CURSO-003`, `REQ-SEC-002` | Equipo docente | N1, varios firmantes | Las oficiales están en Raíces (`ADR-016`) |
| Acta de órgano colegiado | `REQ-GOB-002` | Secretario y presidente | N1 | Elevable a N3 por el centro |
| Certificado propio del centro | `REQ-SEC-001` | Firmante autorizado | N1 más código de verificación | `SELLO` si el centro lo contrata (`OPEN-FIRMA-12`) |
| Informe psicopedagógico interno | `REQ-NEAE-002` | Orientador | N1 con reautenticación | Categoría especial; el oficial vive en Raíces |
| Convenio de prácticas | `REQ-FCT-001` | Firmante autorizado y empresa | N2 | La empresa no tiene cuenta: solo cabe por prestador |
| Informe de reconciliación de la migración | `REQ-ONB-003` | Firmante autorizado | N1 con reautenticación | |
| Nómina | `REQ-NOM-001` | Centro | `SELLO` | Sin sello contratado, se emite sin firma criptográfica y se dice |
| Expediente disciplinario: notificación | `REQ-CONV-002` | — | Fuera de este ADR | Entrega certificada (`OPEN-FIRMA-17`) |

Ninguna fila es un requisito nuevo: cada una asigna nivel a una «firma digital» que el documento de requisitos ya pide. La asignación es **propuesta** hasta `OPEN-FIRMA-02`.

### 4.2 D2 · Prestador o firma propia

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Todo por un prestador cualificado (`ADR-012` literal) | Nada que custodiar; evidencia de tercero | Los de `§4.1` A; además, ningún prestador publica precio de API (hay que pedir presupuesto) | Bajo |
| B | **Híbrido: N1 en la plataforma; N2 y N3 por prestador tras una interfaz propia** | Coste y dependencia proporcionados; N1 reutiliza un patrón ya implementado tres veces (`§1.2` hecho 2) | Dos vías; N1 hace a la plataforma custodio de su propia evidencia (se mitiga con `§4.4`) | Bajo en los dos sentidos: subir un tipo a N2 es configuración; quitar el prestador deja N1 intacto |
| C | Servicio de firma de código abierto autoalojado (DocuSeal, Documenso) | Sin coste por firma; flujo de firma ya hecho | Ambos AGPL-3.0; un contenedor más con su base de datos, sus copias y sus parches; **no son prestadores de confianza**: jurídicamente no dan más que N1 y cuestan mucho más de operar; soporte de PAdES y de sello cualificado sin verificar | Medio |
| D | Firma propia completa (PAdES con certificados y claves gestionados por la plataforma) | Independencia total | Contradice `ADR-012`; custodia de claves (dispositivo cualificado o HSM), políticas de certificación y validación a largo plazo para una sola persona; riesgo máximo | Alto |

**Recomendación: B.** C se descarta porque añade operación sin añadir valor probatorio sobre N1. D se descarta por `ADR-012` y por coste. La diferencia con A es exactamente el alcance de `OPEN-FIRMA-01`.

#### 4.2.1 Qué es y qué no es «criptografía propia»

Se mantiene `ADR-012`: la plataforma **no** implementa primitivas, no emite certificados, no custodia claves de firma de personas ni del centro y no construye firmas PAdES. Lo que sí hace en N1: calcular resúmenes SHA-256 con la biblioteca estándar del lenguaje y, si se aprueba `OPEN-FIRMA-07`, pedir sellos de tiempo a un tercero cualificado por un protocolo estándar (RFC 3161). Guardar un resumen no es firmar.

#### 4.2.2 Interfaz propia (`RNF-MANT-007`, `INV-007`)

Un contrato `SignatureProvider` en el módulo dueño (`§5.1`), con operaciones de: crear un proceso de firma sobre un documento ya almacenado, consultar su estado, cancelarlo, y descargar el PDF firmado y el documento de evidencias. Reglas:

- Implementación falsa determinista para desarrollo, CI y E2E (ningún dato sale del entorno; `ADR-030`).
- Toda llamada, en cola (`INV-012`), con tiempo de espera, reintentos y cortacircuitos (`RARQ-ARC-005`), y clave de idempotencia (`INV-011`).
- Las notificaciones entrantes del prestador se verifican (firma del mensaje o secreto), son idempotentes y **nunca son la fuente de verdad**: disparan una consulta de estado al prestador.
- Al prestador viaja el mínimo: el PDF, el nombre y el medio de contacto del firmante. Nunca identificadores internos distintos del `public_id` del proceso.
- Si el prestador no está disponible, los procesos N2/N3 quedan `pendiente`; **nunca se degradan a N1 automáticamente** (fallo en cerrado).

#### 4.2.3 Quién contrata al prestador

Opciones: (a) la plataforma contrata una cuenta y el prestador es subencargado de todos los centros; (b) cada centro aporta su propia cuenta; (c) ambas. Recomendación: **(a)**, una sola integración y un solo contrato que vigilar; (b) multiplica credenciales por centro y soporte. Es decisión de negocio (`OPEN-FIRMA-03`) y arrastra cómo se repercute el coste (`OPEN-FIRMA-14`).

#### 4.2.4 Criterios de selección (no se elige proveedor en este ADR)

Obligatorios: figurar como prestador cualificado en la lista de confianza de la UE **para el servicio concreto que se use**; tratamiento y alojamiento en el EEE; contrato de encargado (`REQ-PRIV-005`); API con entorno de pruebas; PDF devuelto con firma PAdES de validación a largo plazo **que conserve la conformidad PDF/A del original** (`§4.6.1` punto 3); documento de evidencias descargable (portabilidad); interfaz del firmante en es, en, de y fr; firma de terceros sin cuenta; precio por firma sin compromiso mínimo desproporcionado. Valorables: sello electrónico remoto del centro; entrega certificada; aceptación de certificados cualificados y DNI electrónico para N3.

Candidatos a pedir presupuesto (**sin verificar** ninguno contra los criterios): Signaturit (nombrado en `REQ-API-003`; según su web, en integración en el grupo Namirial, y su sello de tiempo lo presta un tercero), Docusign (nombrado en `REQ-API-003`), Viafirma, Lleida.net, Logalty. Los precios públicos encontrados son de terceros y contradictorios: no se anota ninguno.

### 4.3 D3 · Cl@ve e identidad del firmante

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Integrar Cl@ve como medio de identificación o firma | Identidad fuerte y gratuita para la familia | Cl@ve es la plataforma común del sector público; **no se ha encontrado vía de adhesión para una empresa privada** (fuente oficial sin confirmar; `§11`). No es una opción ejecutable hoy | — |
| B | **Identidad = cuenta de la plataforma verificada por el centro, más reautenticación en el acto de firma (N1); la identidad reforzada se delega en el prestador (N2, N3)** | Sin dependencia nueva; la verificación presencial de la representación legal ya existe (`§1.2` hecho 3) | La fuerza de N1 depende de la higiene de cuentas del centro; la reautenticación es funcionalidad nueva (`OPEN-FIRMA-06`) | Bajo |
| C | Firma en el navegador con certificado del usuario (DNI electrónico, AutoFirma) integrada por la plataforma | N3 sin prestador | Aplicación de escritorio y lector; inviable en móvil, que es el canal de las familias; validar certificados y revocación es criptografía propia de hecho | Alto |
| D | Esperar a la cartera europea de identidad digital | Firma cualificada para particulares | Fechas inciertas y discrepantes; nada que integrar todavía | — |

**Recomendación: B.** No se integra Cl@ve. C solo llega por el prestador, si este la ofrece. D no se diseña (`CLAUDE.md §11`); lo único que se le concede es que la evidencia guarde el **método de identificación** como valor de catálogo (`§4.5`), de modo que añadir uno nuevo no cambie el modelo.

Reautenticación en N1 (propuesta, `OPEN-FIRMA-06`): en el acto de firmar, el usuario vuelve a presentar un factor (contraseña, o segundo factor si lo tiene activo; con SSO, nueva aserción del proveedor de identidad). Se propone obligatoria en las filas marcadas «con reautenticación» de `§4.1` y configurable por el centro en el resto. No está en `REQ-AUTH-003`: es funcionalidad nueva que necesita aprobación.

### 4.4 D4 · Sellado de tiempo

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Solo la hora del servidor (`TIMESTAMPTZ`) | Coste cero; cumple la letra de `REQ-DOC-002` («registro de firmas con sello de tiempo») | La plataforma es juez y parte: quien controla la base puede alterar fila y hora | Bajo |
| B | Sello de tiempo cualificado por cada firma N1 | Prueba individual fuerte | Una llamada externa por aceptación (decenas de miles), coste por sello, y la firma depende de la disponibilidad del tercero | Bajo |
| C | **Hora del servidor en cada evidencia y, además, un manifiesto diario por centro (lista ordenada de los resúmenes de las evidencias del día más el resumen del manifiesto anterior) sellado con un único sello de tiempo cualificado** | Un sello por centro y día; cualquier alteración posterior de una evidencia rompe el manifiesto; el acto de firmar no depende del tercero | La hora individual sigue siendo la del servidor (el sello prueba «existía antes de»); hay que conservar manifiestos y verificar sellos | Bajo: los manifiestos son aditivos; dejar de sellar no invalida lo sellado |
| D | Solo el sello que incluya el prestador (N2, N3) | Sin trabajo propio | N1, la mayoría del volumen, queda como A | — |

**Recomendación: C para N1 y D para N2/N3**, en dos entregas: N1 nace con hora de servidor y con el resumen de cada evidencia calculado y guardado (requisito de esquema desde el primer día); el manifiesto sellado entra en una entrega posterior sin cambio de esquema en las evidencias (`OPEN-FIRMA-07`: qué autoridad de sellado y cuándo). El manifiesto es un trabajo programado en cola (`INV-012`) que fija el centro explícitamente (`INV-001`); si la autoridad no responde, reintenta y avisa, sin afectar a las firmas.

### 4.5 D5 · Evidencias y custodia

**Qué guarda una evidencia** (toda firma, de cualquier nivel; tabla de solo anexar, requisitos §12 punto 6):

- **Quién**: usuario y persona; **en calidad de qué** (vínculo de tutor legal, apoderamiento del centro, cargo, o el propio interesado), con referencia a la fila que acredita esa calidad en el instante de firmar.
- **Qué**: versión exacta del documento, resumen SHA-256 de los **bytes almacenados** (nunca de una regeneración), idioma en que se mostró (`ADR-062`), y versión del texto de la acción («He leído y autorizo…»), que es texto legal: lo redacta el centro o la plataforma, no se traduce automáticamente (`CLAUDE.md §2` punto 9, `ADR-070`).
- **Sentido**: `firmado` o `rechazado`. Rechazar también es evidencia (`REQ-AUT-001`).
- **Cómo**: nivel, método de identificación (catálogo cerrado), si hubo reautenticación y con qué factor, canal (`portal` o `presencial`, `OPEN-FIRMA-09`).
- **Cuándo y desde dónde**: instante del servidor, `ip_address`, `user_agent` (≤512) y `request_id` (`INV-013`). El cliente nunca aporta la hora (`INV-010`).
- **Con el prestador** (N2, N3): identificador del proceso, y claves de objeto del PDF firmado y del documento de evidencias.
- **Resumen de la propia evidencia**, para el manifiesto de `§4.4`.

**Custodia**

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Solo el prestador custodia (N2, N3) | Nada que guardar | Cambiar de prestador o su cierre deja al centro sin sus documentos; la purga y el derecho de acceso dependen de un tercero | Alto |
| B | Solo la plataforma | Independencia | En N2/N3 se pierde el tercero de confianza que justifica pagar el nivel | Medio |
| C | **Doble: el prestador custodia según su política y la plataforma descarga y guarda copia del PDF firmado y del documento de evidencias en el almacén privado del centro** | Portabilidad; la plataforma responde por sí misma al derecho de acceso y a la retención | Dos copias de datos personales: el contrato debe fijar el plazo del prestador | Bajo |

**Recomendación: C.** Los objetos van por `PrivateObjectStore` con el prefijo del centro (`ADR-076`), sin acceso público, con URL firmada de caducidad corta tras comprobar permiso (`ADR-013`, `INV-002`).

**Retención** (`REQ-PRIV-006`, `REQ-DOC-003`): la evidencia vive lo que viva el documento que firma, más el plazo de prescripción que fije la asesoría (`OPEN-FIRMA-08`). `ip_address` y `user_agent` son datos personales en una tabla sin `UPDATE`: su supresión sigue el procedimiento pendiente de `1.23b` (issue #371), el mismo problema ya abierto para `image_consent_decisions` (issue #480). No se inventa otro. La purga física de un centro (`ADR-077`, borrador) debe incluir evidencias, manifiestos y objetos, y pedir al prestador el borrado de sus copias.

### 4.6 D6 · PDF firmado (PAdES)

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | **N1: el PDF no lleva firma criptográfica; se acompaña de un justificante de firma (página o documento generado por el motor de `ADR-078`) con firmantes, instantes, resumen del documento y código de verificación. N2/N3: el PAdES lo aplica el prestador y la plataforma guarda el resultado** | Ninguna clave que custodiar; coherente con `ADR-012`; el justificante es un PDF más | Un PDF N1 fuera de la plataforma no se autovalida en un lector: se valida contra la plataforma por su código | Bajo: añadir un sello después no cambia las evidencias |
| B | La plataforma sella cada PDF con un certificado de sello propio (del operador de la plataforma) mediante un servicio auxiliar (biblioteca DSS de la Comisión Europea, LGPL 2.1, Java; o pyHanko, **sin verificar**) | PDF autovalidable | Contenedor nuevo; custodia de la clave del sello; el sello sería del operador, no del centro, y el operador aún no tiene entidad jurídica decidida (`OPEN-07`); roza `ADR-012` | Medio |
| C | Sello remoto del centro por el prestador en todos los PDF emitidos | PDF autovalidable a nombre del centro | Coste por documento en emisiones masivas (boletines, nóminas); cada centro necesita su certificado de sello | Bajo |

**Recomendación: A ahora; C como opción contratable por centro cuando haya demanda (`OPEN-FIRMA-12`); B descartada.** No se construye sello hasta que un centro lo pida (`ADR-048`).

**Código de verificación.** `REQ-SEC-001` ya exige verificación pública del certificado por código o QR. Se propone un único mecanismo para todo justificante: código aleatorio de alta entropía, no derivable del contenido, que resuelve a una página que muestra el **mínimo** (tipo de documento, centro, fecha, estado vigente o revocado, resumen) y nunca el documento ni datos del menor. Si esa página es pública para todos los tipos o solo para los certificados de `REQ-SEC-001` es `OPEN-FIRMA-11` (recomendación: pública solo donde el requisito lo pide; el resto, tras sesión). Un *endpoint* público es una excepción declarada a «denegar por defecto» (`INV-002`): límite de peticiones y respuesta idéntica para «no existe» y «revocado sin derecho a verlo».

#### 4.6.1 Respuesta a las tres peticiones de `ADR-078 §8` y `§12` (`OPEN-PDF-05`)

Este ADR asume íntegras las cuatro decisiones de `ADR-078 §8` (el motor no firma; orden maquetar, guardar, firmar, guardar como objeto nuevo; un PDF firmado no se reprocesa; la imagen de firma del boletín es contenido de plantilla y no una firma electrónica).

1. **El contrato de firma recibe un PDF ya guardado y devuelve otro objeto: sí.** `SignatureProvider` (`§4.2.2`) recibe la clave de objeto y la huella del PDF original y entrega el PDF firmado y el documento de evidencias como **objetos nuevos**; el original no se sobrescribe ni se borra. En N1 no hay PDF firmado: el original queda intacto y el justificante es **otro objeto**, generado por `PdfRenderer` con el perfil `archival`; no se anexa ni se fusiona con el original (sería reprocesarlo). La evidencia guarda la huella del original (`§4.5`).
2. **Firma visible: no se exige campo de firma, y por defecto no hay firma visible.** Opciones: (a) campo de firma creado por el motor (que WeasyPrint pueda hacerlo está sin verificar según `ADR-078`); (b) zona en blanco reservada en una posición conocida y campo creado por el prestador; (c) firma invisible y evidencia en documento aparte. Recomendación: **(c)** para todo, porque no ata la plantilla al prestador ni al motor; y **(b)** solo si el prestador elegido exige apariencia visible, en cuyo caso los tipos de nivel N2/N3 reservan una zona en blanco al final de la última página, declarada en la plantilla. (a) se descarta. Lo que identifica a los firmantes ante el lector es el justificante (N1) o el documento de evidencias del prestador (N2, N3). El código o QR de verificación es un dato más de la plantilla, como dice `ADR-078`.
3. **Compatibilidad PAdES y PDF/A: se exige al prestador como criterio obligatorio y se comprueba antes de contratar.** Condición añadida a `§4.2.4`: el PDF devuelto, firmado en PAdES con sello de tiempo y validación a largo plazo, debe **seguir validando contra la misma parte y nivel de PDF/A que el original** (PDF/A-2a o -3a), incluida la apariencia visible si la hay (fuentes incrustadas, sin transparencias no permitidas). Se prueba en el entorno de pruebas del prestador con un PDF real del motor y un validador independiente de PDF/A y de firma, y el resultado se anota al resolver `OPEN-FIRMA-03`. **Sin verificar** en esta sesión; lo que se sabe de partida es que la firma PAdES es una actualización incremental y que las partes 2 y 3 de PDF/A se diseñaron para admitirla, mientras que la parte 1 es más restrictiva (coincide con que `ADR-078` la descarte). Desde el lado de la firma **no hay preferencia entre PDF/A-2a y -3a**; si la prueba fallara con una y no con la otra, manda la que valide, y eso es lo que podría obligar a ajustar `ADR-078 §7.2`. La declaración PDF/UA no afecta a la firma.

**Lo que este ADR pide a `ADR-078`** (`OPEN-FIRMA-15`; todo ello ya lo recoge su borrador):

1. Todo tipo de documento firmable usa el perfil `archival`, incluido el justificante.
2. El PDF que va a un prestador no lleva contenido activo ni recursos externos (`ADR-078 §3.3`).
3. El justificante de firma es una plantilla del motor en los cuatro idiomas (`INV-009`).
4. Que la salida no sea idéntica byte a byte entre dos ejecuciones no importa: se resume el objeto almacenado, nunca una regeneración.

### 4.7 D7 · Tutores legales y menores

#### 4.7.1 Quién puede firmar por un menor

Solo quien tenga sobre ese alumno un vínculo **vivo** con `is_legal_guardian = true`. Se consulta **en origen y en el instante de firmar** a través de un contrato publicado por `fam_unit` (`INV-007`), nunca con una copia; si la consulta falla, no se firma (fallo en cerrado; `ADR-066 §5.2`, `§5.3`, `§5.7`). La comprobación es de servidor (`INV-010`) y se suma al permiso (`INV-002`): ver el documento no da derecho a firmarlo. `relationship = 'apoderado'` no habilita por sí mismo, igual que en `ADR-066 §5.4`. La evidencia guarda la referencia al vínculo (`§4.5`, `INV-008`).

#### 4.7.2 Uno o todos

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Siempre basta un tutor legal | Simple; es la práctica habitual en papel | En familias en conflicto, uno decide contra el otro en actos que exigen a ambos | Medio |
| B | Siempre todos (unanimidad, como `ADR-066 §5.4`) | Máxima protección | Una salida al teatro bloqueada porque un progenitor no contesta; inoperable a escala | Medio |
| C | **Política por tipo de documento (`uno` o `todos`), declarada en el catálogo; con `uno`, se notifica a los demás tutores legales y un rechazo expreso de cualquiera de ellos deja el documento en `rechazado`** | Proporcional; coherente con la distinción entre actos usuales y los demás; el desacuerdo expreso nunca se pisa | La asignación `uno`/`todos` de cada tipo es jurídica | Bajo: es una columna del catálogo |
| D | El centro decide en cada autorización | Flexible | Mismo problema que `§4.1` D | Medio |

**Recomendación: C** (`OPEN-FIRMA-04`). Propuesta de partida: `uno` para salidas, autorizaciones generales y acuses; `todos` para el contrato de matrícula y lo que cambie la situación escolar del menor. Estados con `uno`: `aceptada` en cuanto firma uno y nadie ha rechazado; un rechazo posterior de otro tutor legal dentro del plazo pasa a `rechazada` y avisa al centro. Con `todos`: `aceptada` solo cuando han firmado todos los que deciden; `pendiente` mientras falte alguno. La notificación a todos los tutores ya la pide `REQ-FAM-UNIT-002`.

Un tutor legal con acceso revocado sigue teniendo representación (`RN-FAM-UNIT-37`): cuenta para `todos` y su firma la registra el centro por el canal `presencial`, con el mismo criterio que `ADR-066 §5.4`. Las restricciones de custodia (`custody_restrictions`) no existen todavía (`OPEN-FAM-UNIT-05`): cuando existan, prevalecen sobre esta regla y se integran por el mismo contrato de `fam_unit`.

#### 4.7.3 Canal presencial

Propuesta (`OPEN-FIRMA-09`): el centro puede registrar una firma en papel en nombre de un tutor, con permiso propio, adjuntando el documento firmado escaneado al almacén privado; la evidencia queda con canal `presencial` y con el usuario del centro que la registró. Precedente: `image_consent_decisions.channel`. Sin esto, una familia sin medios digitales bloquea cualquier documento `todos`.

#### 4.7.4 Edad del alumno

No se fija ninguna regla (`OPEN-FAM-UNIT-18`, `OPEN-FIRMA-10`). Mientras no la haya, por un alumno firman sus tutores legales sea cual sea su edad, y el alumno no firma. Se advierte lo mismo que `ADR-066 §5.6`: con alumnado mayor de edad (Bachillerato, FP) esto puede no ser conforme; debe resolverse antes de datos reales. El lugar de la regla sería la definición de «quién decide» del contrato de `fam_unit`, y la evidencia ya admite la calidad «el propio interesado» (`§4.5`) para no tener que cambiar el modelo.

### 4.8 D8 · Firmante autorizado o apoderado del centro (issue #82)

**Terminología.** «Apoderado» ya significa en el producto un parentesco de la familia (`§1.2` hecho 3). Para el lado del centro se propone el término **firmante autorizado** (clave `signatory`), del que «apoderado» es una de las calidades (`OPEN-FIRMA-18`).

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Solo un permiso (`<recurso>.firmar`, acción ya prevista en `RPERM-003`) asignado a roles | Cero modelo nuevo | Es lo que el issue #82 rechaza: cualquier administrador se lo puede dar a cualquier rol; sin vigencia, sin documento que acredite el poder, sin alcance por tipo | Bajo |
| B | Atributo en `Person` o `User` (`es_apoderado`) | Simple | Ensucia el núcleo de personas (`ADR-034`); sin vigencia, alcance ni acreditación; un booleano no se audita bien | Medio |
| C | **Entidad propia de apoderamientos en el módulo dueño de `REQ-DOC`, exigida además del permiso** | Vigencia, alcance, acreditación y revocación; histórico; responde a #82 tal como está escrito | Una tabla y una pantalla de gestión más | Bajo: si sobra, se deja de exigir en el catálogo |
| D | Delegar en el certificado de representante (N3): quien tiene el certificado, tiene el poder | La comprobación de facultades la hace el prestador (Ley 6/2020 art. 7.4) | Obliga a N3 para todo documento de apoderado; coste y fricción; la disponibilidad actual de esos certificados en la FNMT es confusa según su propia sede (**sin verificar**) | Medio |

**Recomendación: C.** A se mantiene como condición necesaria, no suficiente: firmar en nombre del centro exige **las dos llaves**, el permiso (puede operar) y un apoderamiento vigente que cubra el tipo de documento (tiene la capacidad). D queda como refuerzo opcional cuando el centro eleve un tipo a N3.

**Reglas del apoderamiento** (el esquema lo detalla `spec-writer`):

1. Un apoderamiento une una **persona** (`ADR-034`, con cuenta) con: una **calidad** de lista cerrada (`representante_legal`, `apoderado`, `cargo`, `delegado`); un **alcance** (lista de claves de tipo de documento del catálogo, o todos); **vigencia** (`valid_from` obligatorio, `valid_until` opcional); el **documento acreditativo** en el almacén privado (escritura de poder, acta de nombramiento), obligatorio para `representante_legal` y `apoderado`; quién y cuándo lo verificó; y, en su caso, revocación con motivo.
2. **El estado se calcula** (vigente si no está revocado y el instante cae en la vigencia); no hay columna de estado que un trabajo tenga que mantener (`ADR-066 §5.2`).
3. **Nadie se apodera a sí mismo.** Registrar y revocar apoderamientos exige un permiso propio, no sembrado en ningún rol salvo el administrador del centro, y quien lo registra no puede ser la persona apoderada. Si además debe exigirse doble autorización es `OPEN-FIRMA-05`.
4. Cada tipo de documento del catálogo declara qué calidad mínima exige para la firma del centro (ninguna, `cargo`, `apoderado`, `representante_legal`). El centro puede endurecerla, no relajarla.
5. La comprobación se hace en servidor en el instante de firmar; la evidencia guarda la referencia al apoderamiento (`§4.5`). Un apoderamiento que caduca o se revoca **no invalida** lo ya firmado.
6. Los consumidores (`REQ-GOB-002`, `REQ-SEC-001`, `REQ-FCT-001`, `REQ-ALUM-002`, asociaciones de #78) no leen la tabla: preguntan al contrato del módulo dueño (`INV-007`).
7. Alta, cambio, revocación y lectura del documento acreditativo, auditados (`INV-003`).
8. **Entidad representada**: en esta versión, solo el centro. Una asociación con personalidad propia (AMPA, #78) necesitaría sus propios firmantes; no se diseña hasta que #78 tenga especificación (`OPEN-FIRMA-13`, `ADR-048`).

La plataforma **registra** que el centro afirma haber verificado el poder; no verifica el poder. Es el mismo criterio que `legal_guardian_verified_at` (`RN-FAM-UNIT-74`).

### 4.9 D9 · Validez y caducidad

Son cuatro cosas distintas y no se mezclan:

1. **Plazo para firmar** (del proceso): fecha límite por documento; vencida, pasa a `caducada` (`REQ-AUT-001`). Estado calculado desde la fecha, sin trabajo que lo «cierre». Recordatorios por el canal de `ADR-079`.
2. **Vigencia de lo firmado** (del acto): por evento o por curso (`REQ-AUT-002`). La **revocación** (`REQ-AUT-003`) es un registro nuevo de solo anexar que referencia la firma; nunca la borra ni la modifica. No hay revocación de documentos de un solo acto (un contrato se resuelve por su vía, no «desfirmando»).
3. **Cambio del documento**: cualquier cambio del contenido crea una versión nueva (`REQ-AUT-001`, «control de versiones»); las firmas son de una versión y **no se heredan**. El centro decide si la versión nueva exige volver a firmar; por defecto, sí.
4. **Pérdida posterior de la calidad del firmante**: en documentos de un solo acto, sin efecto retroactivo. En autorizaciones de vigencia continuada, se propone el criterio de `ADR-066 §5.4`: el estado vigente se calcula con las decisiones de quienes **hoy** son tutores legales; la firma de quien dejó de serlo se conserva como evidencia y deja de contar (`OPEN-FIRMA-16`, jurídica).

**Validez de la evidencia en el tiempo**: los sellos y certificados envejecen. En N2/N3 se exige al prestador PDF con validación a largo plazo (`§4.2.4`). En N1, los manifiestos de `§4.4` se vuelven a sellar antes de que caduque el certificado de la autoridad de sellado; el procedimiento se define con `OPEN-FIRMA-07` y no bloquea.

### 4.10 D10 · Auditoría

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | Solo la tabla de evidencias | Sin duplicar | `INV-003` pide auditoría uniforme; el visor de auditoría no vería las firmas | Bajo |
| B | **Evidencia como registro de la firma y, además, `audit_logs` con el vocabulario existente** | Cumple `INV-003` sin ampliar listas cerradas; un solo sitio para consultar actividad | Dos escrituras en la misma transacción | Bajo |
| C | Evento nuevo `signed` en `AuditCatalog::EVENTS` | Más expresivo | Amplía una lista cerrada y su `CHECK` (`ADR-039`) sin necesidad: el `created` de una evidencia ya es la firma | Medio |

**Recomendación: B.** Sin eventos nuevos:

- Firmar o rechazar: `created` sobre la evidencia, política selectiva (nivel, sentido, canal, método, versión; no IP ni agente, que ya están en la evidencia), en la **misma transacción** que la evidencia.
- Revocar: `created` sobre el registro de revocación. Apoderamientos: `created`/`updated`/`deleted`.
- Registrar una firma `presencial` en nombre de otro: el actor es el usuario del centro; la persona representada va en el `context` con la forma de `ADR-035 §5`.
- **Lectura** auditada (`read`) del documento firmado y de sus evidencias cuando el tipo es de categoría especial (medicación, informe psicopedagógico) y siempre que se lea el documento acreditativo de un apoderamiento; mecanismo de `ADR-068 §5.4`.
- Exportar el historial (`REQ-AUT-003`): `exported`.
- Llamadas al prestador: registro técnico con `request_id` (`INV-013`), sin datos personales en el log.

## 5 · Modelo conceptual y contrato

No es un esquema: es lo que la especificación de `REQ-DOC`/`REQ-AUT` debe respetar. Los nombres son orientativos.

### 5.1 Dueño

| | Opción | Ventajas | Riesgos | Coste de revertir |
|---|---|---|---|---|
| A | **El módulo de `REQ-DOC` (`doc`) es dueño único de procesos de firma, evidencias, manifiestos, catálogo de tipos firmables, apoderamientos y del adaptador del prestador; los demás módulos consumen contratos** | Es donde el usuario situó la capacidad (issue #82); una sola tabla de evidencias; `REQ-AUT` ya declara que depende de `DOC` | `doc` debe existir, al menos en esta parte, antes que cualquier consumidor | Bajo |
| B | `App\Support\Signing` como primitiva compartida y tablas en cada módulo consumidor | Sin módulo nuevo | Evidencias repartidas en N tablas, N historiales y N purgas; `App\Support` no puede tener tablas de negocio ni importar módulos (`AR-02`) | Alto |
| C | En `Core` | Disponible para todos desde ya | Engorda el núcleo con un dominio que tiene proveedor externo y ciclo propio; `ADR-034` | Alto |

**Recomendación: A.** Consecuencia de planificación: la parte de firma de `REQ-DOC-002` puede entregarse antes que el repositorio documental de `REQ-DOC-001` (carpetas, búsqueda), del que no depende. Partirlo así es decisión de plan, no de este ADR.

### 5.2 Conceptos

| Concepto | Qué es | Reglas |
|---|---|---|
| Tipo de documento firmable | Entrada de catálogo declarada en código (`ADR-051`) por el módulo que lo emite | Declara: lado que firma (familia, centro, ambos, tercero), nivel mínimo (`§4.1`), política de tutores (`uno`/`todos`, `§4.7.2`), calidad mínima del firmante del centro (`§4.8`), si exige reautenticación, si es de categoría especial, y si admite revocación. El centro solo puede endurecer |
| Documento firmable | Objeto inmutable en el almacén privado con su huella | Lo crea el módulo emisor; `doc` no interpreta su contenido. Un cambio es otra versión |
| Proceso de firma | Petición de que ciertos firmantes firmen una versión | Estado **calculado** desde evidencias, plazo y política; nivel efectivo; plazo; referencia al prestador si es N2/N3 |
| Firmante requerido | Quién debe firmar y en calidad de qué | Para tutores se resuelve en el instante contra `fam_unit`, no se congela al crear el proceso; un tercero sin cuenta solo existe en N2/N3 |
| Evidencia | `§4.5` | Solo anexar; sin `updated_at` ni `deleted_at` |
| Revocación | Registro que deja sin efecto una firma de vigencia continuada | Solo anexar; referencia la evidencia |
| Manifiesto | `§4.4` | Solo anexar; uno por centro y día con evidencias |
| Apoderamiento | `§4.8` | Ordinaria con borrado lógico (`INV-004`); estado calculado |

### 5.3 Contratos hacia otros módulos (`INV-007`)

- **`SignatureProvider`** (salida hacia el prestador): `§4.2.2`.
- **Solicitud y consulta de firmas** (entrada para consumidores): un módulo pide firmar un documento de un tipo que él mismo declaró y pregunta el estado vigente por lotes (una consulta para un grupo entero, no una por alumno). No recibe evidencias ni datos del firmante más allá de lo que su permiso permita.
- **Firmantes autorizados**: «¿puede esta persona firmar este tipo de documento en nombre del centro ahora?». Respuesta sí o no; nunca el documento acreditativo.
- **Desde `fam_unit`** (contrato que `fam_unit` debe publicar; hoy no existe): quiénes deciden por un alumno ahora, con la referencia al vínculo. Es la misma definición que usa `ADR-066 §5.4`, para que no haya dos.
- **Eventos**: no se define ninguno. Se publicarán los que tengan consumidor cuando lo tengan (`ADR-048`).

## 6 · Invariantes y seguridad

| Regla | Cómo se cumple |
|---|---|
| `INV-001` | Todas las tablas con `tenant_id` y política de aislamiento del proyecto; objetos bajo el prefijo del centro; el manifiesto diario y la recepción de notificaciones del prestador fijan el centro explícitamente (no hay petición con sesión). El identificador del proceso en el prestador no basta para localizar un centro: se resuelve por una tabla propia, nunca confiando en un dato del mensaje |
| `INV-002` | Permisos propios por acción (`firmar`, de `RPERM-003`; gestionar apoderamientos; registrar firma presencial; leer evidencias). Firmar exige permiso **y** calidad (`§4.7.1`, `§4.8`). Único acceso sin sesión: la verificación por código de `§4.6`, si se aprueba |
| `INV-003` | `§4.10` |
| `INV-004` | Evidencias, revocaciones y manifiestos son de solo anexar (más fuerte que el borrado lógico); apoderamientos y procesos, con borrado lógico |
| `INV-006` | Todo por API; el portal es un cliente. La firma presencial y la del prestador usan los mismos servicios |
| `INV-008` | La evidencia de una firma por un menor referencia el vínculo de tutor legal que la habilita |
| `INV-009` | Interfaz y justificante en es, en, de, fr. El texto de la acción de firmar y las plantillas legales son contenido del centro o texto legal: no se traducen automáticamente; se registra el idioma mostrado |
| `INV-010` | Nivel, política, calidad, plazo e instante se resuelven en servidor; del cliente solo llega la intención |
| `INV-011` | Firmar, rechazar, revocar y crear procesos en lote aceptan clave de idempotencia; un doble clic no produce dos evidencias |
| `INV-012` | Envíos masivos, llamadas al prestador, descargas de PDF firmado, justificantes y manifiestos, en cola. Un proceso N2/N3 puede durar días: los trabajos deben poder procesarse tras un despliegue (`CLAUDE.md §9`) |
| `INV-015` | Cada regla de `§4` tendrá test con su identificador en la especificación |
| `CLAUDE.md §8` | Sin JWT; el enlace de firma del prestador no sustituye a la sesión para nada dentro de la plataforma. Secretos del prestador y de la autoridad de sellado en el gestor de secretos, por plataforma (`§4.2.3`). Documentos acreditativos y PDF firmados fuera de la raíz web, con URL firmada corta. Los documentos de categoría especial siguen en sus tablas separadas y cifradas; `doc` guarda la huella y la clave de objeto, no el contenido |
| Suplantación | La reautenticación (`OPEN-FIRMA-06`) cubre la sesión abierta en un dispositivo compartido, que es el riesgo realista en N1 |
| Enumeración | El código de verificación es aleatorio, largo y con límite de peticiones; respuesta indistinguible entre inexistente y no visible |
| Prestador caído o comprometido | Fallo en cerrado (`§4.2.2`); las notificaciones entrantes no cambian estado por sí solas |

## 7 · Consecuencias, riesgos y reversión

### 7.1 Consecuencias

- `REQ-AUT` puede implementarse entero en N1 **sin contratar ningún prestador**. El prestador solo bloquea los tipos N2/N3 (matrícula con contrato, convenios).
- Aparece trabajo que los requisitos no enumeran y que por tanto necesita aprobación: reautenticación (`OPEN-FIRMA-06`), canal presencial (`OPEN-FIRMA-09`), manifiesto sellado (`OPEN-FIRMA-07`), gestión de apoderamientos (ya pedida por #82).
- `fam_unit` debe publicar el contrato «quiénes deciden» (`§5.3`); es un cambio pequeño y aditivo, pero es de otro módulo.
- Los boletines de `1.17` salen sin firma electrónica (coincide con `ADR-078 §8`); la validación del docente tutor en N1 se añade cuando exista `doc`.
- El prestador pasa a ser subencargado: inventario de `REQ-PRIV-005`, y evaluación de impacto si procede.

### 7.2 Riesgos

| Riesgo | Mitigación |
|---|---|
| La tabla de niveles no supera la validación jurídica | El nivel es una fila de catálogo; subir un tipo no toca datos. **Bajar no es posible ni hace falta** |
| N1 se impugna por «juez y parte» | Manifiesto sellado por tercero (`§4.4`); reautenticación; verificación presencial previa de la representación |
| Coste del prestador imprevisible (sin precios públicos) | Solo N2/N3; presupuestos antes de decidir (`OPEN-FIRMA-03`); contador por centro (`OPEN-FIRMA-14`) |
| Regla de tutores mal calibrada en familias en conflicto | Rechazo expreso siempre gana; `custody_restrictions` prevalece cuando exista; canal presencial |
| Alumnado mayor de edad firmado por sus tutores | Advertido (`§4.7.4`); condición previa a datos reales |
| Dos vías de firma duplican mantenimiento | Mismo modelo de proceso y evidencia para todos los niveles; el prestador es un adaptador |
| Depender de tres ADR sin aceptar | `§7.4` |

### 7.3 Reversión

- **Rechazo de `OPEN-FIRMA-01`**: rige `ADR-012` literal; el modelo de `§5` sirve igual con todos los tipos en N2. Lo que cambia es coste y calendario (nada de `REQ-AUT` sin prestador).
- **Cambio de prestador**: nuevo adaptador; los procesos cerrados conservan su copia local (`§4.5`); los abiertos se cancelan y se relanzan.
- **Retirada del manifiesto**: se deja de emitir; los existentes siguen siendo verificables.
- **Retirada de los apoderamientos**: los tipos dejan de exigir calidad; la tabla queda como histórico.
- Lo único no reversible es lo ya firmado: por eso las evidencias guardan el nivel y la calidad con que se firmó y nunca se reinterpretan.

### 7.4 Dependencias entre borradores

| ADR | Qué necesita este de él | Si cambia |
|---|---|---|
| `ADR-078` | `PdfRenderer`, perfil `archival`, objeto inmutable con huella | Si el nivel de PDF/A cambia, se repite la prueba de `§4.6.1` punto 3 |
| `ADR-079` | Canal de envío de peticiones y recordatorios; entrega certificada | Sin él, las peticiones solo se ven en el portal |
| `ADR-081` | Decide el nivel del mandato SEPA | Fila de `§4.1` |
| `ADR-077` | Purga de evidencias, manifiestos, objetos y copias del prestador | `§4.5` |
| `ADR-083` | Proveedor S3 de producción y expediente de cumplimiento, donde debe constar el prestador de firma | — |

## 8 · Alternativas descartadas

Además de las opciones descartadas en cada tabla de `§4` y `§5.1`:

- **Firma manuscrita digitalizada (trazo en pantalla) como mecanismo propio.** No añade identificación sobre la sesión autenticada, da una falsa apariencia de solemnidad y la biometría del trazo sería dato de categoría especial. Si un prestador la ofrece dentro de N2, es asunto suyo.
- **Código de un solo uso por SMS gestionado por la plataforma para «subir» N1 a avanzada.** Construir una firma avanzada casera es justo lo que `ADR-012` evita; el segundo factor propio se usa como reautenticación, sin cambiar el nivel declarado.
- **Cadena de bloques o registro distribuido para las evidencias.** Mismo efecto que el manifiesto sellado, con una dependencia mayor y sin reconocimiento como servicio cualificado.
- **Migrar a evidencias de `doc` las aceptaciones ya existentes** (`terms_acceptances`, `image_consent_decisions`, `pickup_authorizations`). Trabajo sin beneficio: ya son de solo anexar y tienen dueño (`ADR-066`, `ADR-068`). Unificar tablas se podrá plantear con un ADR propio si algún día hay motivo.
- **Un nivel por rol** (familias N1, personal N2). El riesgo es del documento, no de quien lo firma.
- **Congelar la lista de firmantes al crear el proceso.** Se desfasa cuando cambia un vínculo; mismo argumento que `ADR-066 §5.2`.

## 9 · Preguntas abiertas

**Decidida por el usuario (2026-11-12)**: `OPEN-FIRMA-01`, según la recomendación de este ADR: se sustituye en parte `ADR-012`. La firma simple con evidencias (N1: salidas, autorizaciones, acuses) se registra en la plataforma, y el prestador cualificado queda solo para firma avanzada y cualificada. Sigue **pendiente de validación jurídica** antes de usarse con datos reales (el texto literal de eIDAS arts. 25-26 no se pudo leer). El ADR continúa en `PROPUESTA` mientras queden abiertas las demás; el sustituto formal de `ADR-012` se escribe al aceptarlo.

**Decidida también el 2026-11-12**: `OPEN-FIRMA-05` (issue #82), según la recomendación de este ADR: el firmante autorizado del centro es una entidad propia de apoderamientos (calidad, alcance por tipo de documento, vigencia, documento acreditativo, revocación), exigida además del permiso `firmar`, sin doble autorización y con la regla «nadie se apodera a sí mismo». Sigue pendiente de validación jurídica.

**Decididas por el usuario (2026-11-12), según la recomendación**: `OPEN-FIRMA-04` (cada tipo de documento fija si basta `uno` de los tutores legales o se exigen `todos`, y un rechazo expreso de cualquiera prevalece siempre) y `OPEN-FIRMA-06` (reautenticación en el acto de firma, funcionalidad nueva que no está en `REQ-AUTH-003`). Ambas pendientes de validación jurídica.

Ninguna se da por resuelta. «Decide» indica quién; todo lo marcado legal necesita además la validación jurídica de la cabecera.

### 9.1 Bloquean la implementación de `REQ-DOC-002` y `REQ-AUT`

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-FIRMA-01` | ¿Se acepta sustituir en parte `ADR-012` para que el nivel N1 se registre en la plataforma sin prestador? | **Sí** (`§4.2` B) | Si no: todo por prestador; `REQ-AUT` no puede empezar sin contrato y hay coste por cada aceptación | Usuario |
| `OPEN-FIRMA-02` | ¿Es correcta la tabla de niveles de `§4.1`? En particular: medicación (N1 con reautenticación o N2), salidas con pernocta o al extranjero, actas de órganos colegiados | La tabla tal cual; medicación en N1 con reautenticación | Cada tipo que suba a N2 añade coste y dependencia del prestador | Legal y usuario |
| `OPEN-FIRMA-04` | Regla de tutores: política `uno`/`todos` por tipo, y que un rechazo expreso prevalezca | **`§4.7.2` C**, con el reparto propuesto | Define los estados de toda autorización; cambiarla después altera cómo se leen las ya firmadas | Legal |
| `OPEN-FIRMA-05` | Modelo del firmante autorizado (`§4.8` C) y control de su alta: ¿basta «nadie se apodera a sí mismo» o hace falta doble autorización? | **C; sin doble autorización**, con permiso propio, documento acreditativo obligatorio y auditoría | La doble autorización exige dos administradores por centro, que muchos centros pequeños no tienen | Usuario |
| `OPEN-FIRMA-06` | ¿Se aprueba la reautenticación en el acto de firma? No está en `REQ-AUTH-003` | **Sí**: obligatoria en las filas marcadas de `§4.1`, configurable en el resto | Sin ella, N1 no protege frente a la sesión abierta; con ella, hay trabajo nuevo en `auth` (contraseña, segundo factor, SSO) | Usuario |
| `OPEN-FIRMA-15` | Dependencia de `ADR-078`: perfil `archival`, objeto inmutable, justificante como plantilla | Aceptar ambos ADR de forma coordinada | Sin motor no hay justificante ni documento que firmar | Usuario (al aceptar `ADR-078`) |

### 9.2 Bloquean solo una parte

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-FIRMA-03` | Prestador para N2/N3 y quién lo contrata | **La plataforma contrata uno**, elegido con los criterios de `§4.2.4` tras presupuestos y la prueba de PDF/A de `§4.6.1` | Bloquea solo los tipos N2/N3 | Usuario y negocio |
| `OPEN-FIRMA-14` | ¿Cómo se repercute el coste por firma N2/N3 (incluido en la cuota, o medido por centro)? | **Medido por centro** y visible en el backoffice | Afecta a `REQ-BO` y a la oferta comercial | Negocio |
| `OPEN-FIRMA-09` | ¿Se admite el canal presencial (firma en papel registrada por el centro, con escaneo)? | **Sí**, con permiso propio | Sin él, los tipos `todos` se bloquean con familias sin medios digitales o con acceso revocado | Usuario |
| `OPEN-FIRMA-10` | Alumnado de 14 años o más y mayor de edad: ¿quién firma? (es `OPEN-FAM-UNIT-18`) | No inventar regla; **resolver antes de datos reales** | Puede hacer no conforme toda firma de tutores sobre alumnado adulto | Legal |
| `OPEN-FIRMA-16` | En autorizaciones de vigencia continuada, ¿deja de contar la firma de quien pierde la representación legal? | **Sí**, estado calculado como en `ADR-066 §5.4` | Define el cálculo de vigencia | Legal |
| `OPEN-FIRMA-08` | Plazo de conservación de evidencias y de IP y agente de usuario | Ligado al documento más la prescripción que fije la asesoría; supresión según `1.23b` | Catálogo de retención (`REQ-PRIV-006`) | Legal |
| `OPEN-FIRMA-13` | ¿Las asociaciones internas (#78) son entidad representada con firmantes propios? | **Aplazar** a la especificación de #78; ahora solo el centro | Si se decide después, es un cambio aditivo en apoderamientos | Usuario |

### 9.3 No bloquean

| ID | Pregunta | Recomendación | Impacto | Decide |
|---|---|---|---|---|
| `OPEN-FIRMA-07` | Manifiesto diario con sello de tiempo cualificado: ¿en la primera entrega o después?, ¿qué autoridad de sellado? | **Segunda entrega**; la huella de cada evidencia, desde la primera. Autoridad: la del prestador elegido si la ofrece | Refuerza la prueba de N1; dependencia externa nueva y su renovación de sellos | Usuario y técnico |
| `OPEN-FIRMA-11` | ¿Verificación pública por código para todos los tipos o solo para certificados (`REQ-SEC-001`)? | **Solo certificados**; el resto, con sesión | Superficie pública sin sesión | Usuario |
| `OPEN-FIRMA-12` | Sello electrónico del centro en PDF emitidos en serie | **No construir** hasta que un centro lo pida; entonces, sello remoto del prestador | Nóminas y certificados salen sin firma criptográfica mientras tanto | Usuario |
| `OPEN-FIRMA-17` | Notificación fehaciente de `REQ-CONV-002` | Tratarla en `ADR-079` como entrega certificada del mismo prestador | Criterio valorable de `§4.2.4` | Usuario (con `ADR-079`) |
| `OPEN-FIRMA-18` | Nombre del concepto del lado del centro | **«Firmante autorizado»**; «apoderado» queda como una calidad y como parentesco familiar | Textos de interfaz y manuales | Usuario |

Total: **18** preguntas (`OPEN-FIRMA-01` a `-18`); **6** bloquean la implementación, **7** bloquean una parte, **5** no bloquean.

## 10 · Cambios propuestos en ficheros compartidos

No se ha tocado ninguno. Los aplica la sesión orquestadora al integrar, y solo si el ADR se acepta (la fila del índice, ya en `PROPUESTA`).

| Fichero | Cambio propuesto |
|---|---|
| `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` §18 (índice) | Fila `ADR-082`: «Firma electrónica por niveles (N1 en la plataforma, N2 y N3 por prestador tras `SignatureProvider`), evidencias de solo anexar con custodia doble, justificante y PDF firmado como objetos nuevos, regla de tutores legales por tipo de documento y firmante autorizado del centro (issue #82). Concreta `ADR-012` y **lo sustituye en parte** (el prestador deja de ser obligatorio para N1). **Estado: `PROPUESTA`**». Entrada en el historial de versiones |
| Ídem, texto de `ADR-012` | Al aceptar: nota «sustituido en parte por `ADR-082`» (el texto de un ADR no se reescribe) |
| Ídem, `REQ-DOC-002` | Al aceptar: referencia a `ADR-082` y al issue #82. Sin cambiar el requisito |
| Ídem, glosario | Entradas «firmante autorizado» y «evidencia de firma», si se acepta `OPEN-FIRMA-18` |
| `PLAN-IMPLEMENTACION.md` | Nota de fase 2 sobre #82 (l. ~293): sustituir «sin diseño todavía» por la referencia a `ADR-082`. Al planificar `REQ-DOC`: firma (`REQ-DOC-002`) como entrega separable y anterior a `REQ-AUT`; contrato «quiénes deciden» de `fam_unit` como prerrequisito; tipos N2/N3 condicionados a `OPEN-FIRMA-03` |
| `ARCHITECTURE.md` | Al implementar: contrato `SignatureProvider` en la lista de dependencias externas envueltas (`RNF-MANT-007`) y el prestador en el diagrama de servicios externos |
| `SECURITY.md`, `PRIVACY.md` | Al implementar: prestador de firma como subencargado; evidencias (IP, agente de usuario) en el registro de tratamientos; verificación pública por código, si se aprueba |
| `SYSADMIN.md`, `RUNBOOK.md` | Al implementar: secretos del prestador y de la autoridad de sellado; «el prestador no responde» (los procesos quedan pendientes, no se degradan); «falla el manifiesto diario» |
| `CLAUDE.md §1` | Al elegir prestador: fila «Firma electrónica» con «elegido y no instalado» mientras no exista el adaptador |
| `CHANGELOG.md`, `README.md`, `memory.md` | Lo habitual al integrar; nada antes |
| `docs/modulos/REQ-FAM-UNIT/funcional.md` (l. 94, tabla de exclusiones) | No es fichero compartido del guion ni de este encargo: donde dice «prestador cualificado sin integrar», referenciar `ADR-082` cuando se acepte |
| Issue #82 | Comentario con el enlace al ADR; se cierra cuando se acepte o se sustituya |

**Para `ADR-078` (agente A2)**: sus tres peticiones están respondidas en `§4.6.1`; `OPEN-PDF-05` puede cerrarse contra ese apartado, salvo la prueba de compatibilidad PAdES y PDF/A, que queda ligada a `OPEN-FIRMA-03`.

## 11 · Fuentes y lo no verificado

### 11.1 Fuentes consultadas (2026-11-12)

| Tema | Fuente | Qué se tomó |
|---|---|---|
| Ley 6/2020, arts. 3 y 7 | BOE, texto consolidado, `https://www.boe.es/buscar/act.php?id=BOE-A-2020-14046` | Resumen de los artículos (no el literal); última actualización 2023-05-09 |
| eIDAS, art. 25 | Fuentes secundarias: `https://focus.namirial.com/es/firma-electronica/`, `https://www.gtt.es/boletinjuridico/la-regulacion-de-la-identificacion-la-firma-y-sello-electronicos-en-la-ley-392015`, `https://www.notariosyregistradores.com/web/?p=77723` | Efectos de la firma no cualificada |
| Cl@ve | `https://laadministracionaldia.inap.es/noticia.asp?id=1148580` (prescripciones técnicas); blog comercial `https://didit.me/es/blog/clave-integration-spain` | Ámbito de sector público; ausencia de vía para privados (solo fuente comercial) |
| eIDAS 2 y cartera | `https://www.garrigues.com/es_ES/garrigues-digital/cartera-europea-identidad-digital-ya-tiene-calendario-tareas-completar-antes` y otras secundarias | Fechas discrepantes |
| Signaturit | `https://www.signaturit.com/legalidad/`, `https://www.signaturit.com/products/digital-certification/time-stamp/` | Integración en Namirial; sello de tiempo prestado por un tercero; sin precios públicos de API |
| DocuSeal y Documenso | `https://verdocs.com/blog/docuseal-vs-documenso`, `https://openalternative.co/compare/documenso/vs/docuseal` | Licencia AGPL-3.0 (fuentes de terceros) |
| Biblioteca DSS | `https://ec.europa.eu/digital-building-blocks/wikis/display/DIGITAL/Digital+Signature+Service+-++DSS` | Licencia LGPL 2.1; proyecto activo en 2026 |
| Certificado de representante | `https://www.sede.fnmt.gob.es/registro-inicio` y páginas de la Agencia Tributaria | Requisitos de acreditación registral; información contradictoria sobre disponibilidad |
| Motor de PDF | `ADR-078` en su rama (`§7.2`, `§8`, `§12`) | Perfil `archival`, orden de firma, peticiones |

### 11.2 Sin verificar

- El texto literal de los arts. 25 y 26 de eIDAS, del art. 326 LEC, del art. 156 del Código Civil y del art. 7 de la LOPDGDD. Todo `§3` es marco de trabajo, **pendiente de validación jurídica**.
- Que alguno de los candidatos de `§4.2.4` cumpla los criterios: no se ha consultado la lista de confianza de la UE, ni condiciones, ni precios, ni idiomas de la interfaz del firmante.
- Que una empresa privada no pueda integrarse con Cl@ve: no se ha encontrado fuente oficial que lo afirme o lo niegue.
- Fechas de la cartera europea de identidad digital.
- Que un PDF/A-2a o -3a de WeasyPrint siga siendo conforme tras una firma PAdES con validación a largo plazo de un prestador concreto (`§4.6.1` punto 3).
- pyHanko (licencia, mantenimiento) y soporte de PAdES en DocuSeal y Documenso: sin resultados; no afecta, porque esas opciones se descartan por otros motivos.
- Que exista una autoridad de sellado cualificada con precio razonable para un sello por centro y día (`OPEN-FIRMA-07`).
- Volumen real de aceptaciones por centro: la cifra de `§1.3` es un orden de magnitud razonado, no una medida.
- No se ha ejecutado ningún test ni se ha tocado código: este ADR es solo documentación.
