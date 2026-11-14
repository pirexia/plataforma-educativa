# ADR-062 · Contenido del centro traducible: una columna `jsonb` `<campo>_translations` por campo, resuelta en servidor

**Estado**: **ACEPTADA** (2026-10-10; ratificada por el usuario con `OPEN-062-02` = sí). La opción (A) la aprobó el usuario al aceptar las recomendaciones de la especificación de `1.11` (`OPEN-ACAD-05`). El usuario decidió el 2026-10-10 `OPEN-062-01` (idioma por defecto del centro al escribir). Lo que este ADR somete a ratificación es el **contrato** que la opción no fijaba (`§5`) y las dos precisiones de `§10`, que se apartan de la letra del Anexo A de la especificación.
**Fecha**: 2026-10-10
**Paso**: entrada de `1.11` (`REQ-ACAD`). Resuelve `OPEN-ACAD-05` de `docs/modulos/REQ-ACAD/funcional.md §15` (bloqueante de la primera migración). El Anexo A de esa especificación es el material de partida.
**Se apoya en**: `INV-009`, `INV-010`, `INV-003`, `INV-007`, `INV-006`; `ADR-021` (tres capas de i18n, «desde el primer día»); `ADR-029` (tipos); `ADR-035 §2`, `§4`, `§5`; `ADR-038 §3.2`, `§5.2`, `§5.3`, `§9.2`, `§11`; `ADR-054 §8.1`; `ADR-055 §2`; `ADR-056 §3.3`; `RN-CORE-13`.
**No sustituye** ningún ADR. **Amplía** `ADR-029` con una convención de tipo para el contenido traducible, **amplía** `ADR-035 §4` con una regla de desglose por idioma de las columnas `*_translations` en `changes`, y **amplía** el catálogo de reglas de `ADR-056 §3.3` con `AR-16`.
**Afecta a**: todo módulo con contenido del centro que se muestre o se emita en más de un idioma (`ACAD` el primero; `CALIF`, `COM`, `OFE`, `FIN`, `INF` y los demás después), al constructor de `changes` de auditoría, a `TenantMigration` y a la SPA (componente de edición compartido).

---

## 1 · Contexto

`ADR-021` extiende la internacionalización a tres capas —interfaz, documentos generados y **contenido del centro**— y exige que boletines, facturas y notificaciones salgan en el idioma del destinatario desde el primer día. Las dos primeras capas existen (catálogos `lang/` y `locales/`, `ResolveApiLocale`). La tercera no: `docs/i18n.md` («Qué falta») la deja a cada módulo, y ninguno la ha necesitado hasta ahora.

`REQ-ACAD` (`1.11`) es el primero: los nombres de etapas, cursos de etapa y asignaturas acabarán en los boletines de `1.17`, que se emiten en el idioma de cada familia. Si cada módulo resuelve esto a su manera, el producto tendrá 53 formas de guardar «el mismo texto en cuatro idiomas», y el generador de documentos tendrá que conocerlas todas.

Cambiar la forma después es migración de datos de **todas** las tablas con contenido traducible. Por eso se decide antes de la primera.

### 1.1 Hechos verificados contra el código (rama `feature/REQ-ACAD-1-11-estructura-academica`, sobre `3cbe5b0`)

| Premisa | Dato |
|---|---|
| Vocabulario de idiomas | **`es-ES`, `en`, `de`, `fr`**, no `es`. `tenant_settings.default_locale` tiene `CHECK (default_locale IN ('es-ES','en','de','fr'))` (migración `2026_08_19_100100`); `StoreTenantRequest` y `PatchTenantSettingsRequest` validan con la misma lista. La correspondencia con las carpetas de Laravel (`es-ES → es`) vive en `ResolveApiLocale::TO_LARAVEL_LOCALE` |
| Idioma por defecto del centro | Configurable por centro (`default_locale`, por omisión `es-ES`), y debe pertenecer a `active_locales` (`RN-CORE-13`, `CA-CORE-002`). **Un centro puede tener `en` como idioma por defecto y no tener `es-ES` activo** |
| Resolución del idioma de la petición | `ResolveApiLocale` (`ADR-038 §11`): `person.locale` si está activo → `Accept-Language` ∩ activos → `default_locale`. Devuelve `Content-Language` |
| Lectura de la configuración | `Core\Domain\TenantSettingsReader::defaultLocale()` / `activeLocales()`, memoizado por `TenantSettingsCache` |
| Contenido traducible existente | Ninguna columna `*_translations` en el repositorio |
| Tope de auditoría | `ADR-035 §5`: un valor cuya codificación JSON supere 256 caracteres se redacta `oversized`. Un nombre de asignatura largo en cuatro idiomas lo supera (≈ 4 × 60 caracteres más claves y comillas) |

---

## 2 · Qué NO decide este ADR

- **Qué campos son traducibles en cada módulo.** Lo decide la especificación de cada uno. Para `acad`, la de `1.11` (nombres de etapa, curso de etapa y asignatura; no grupo, subgrupo ni `code`).
- **Contenido largo con formato** (cuerpo de una circular, plantilla de documento). Este ADR cubre textos cortos y medios de una línea o un párrafo. Si `REQ-COM` necesita versiones por idioma de un documento con estructura, adjuntos o flujo de aprobación por idioma, lo decide su especificación; puede que esa forma no sea una columna, y no se presupone.
- **Traducción automática.** Ningún requisito la pide.
- **Lenguas cooficiales.** `ADR-021` las prevé en arquitectura y no las implementa en fases 1-3. Añadir una es ampliar el vocabulario cerrado (`§5.1`): un `CHECK` y una lista en código, sin migración de datos.
- **Colación por idioma** en la ordenación. Ver `§5.6`.

---

## 3 · Opciones reales

| | Opción | Forma |
|---|---|---|
| **A** | Columna `jsonb` por campo traducible | `name_translations jsonb NOT NULL` = `{"es-ES": "…", "en": "…"}` en la propia fila |
| **B** | Tabla de traducciones común, polimórfica | `translations (translatable_type, translatable_id, field, locale, value)` |
| **C** | Solo el idioma por defecto ahora | `name text`; la traducción se añade cuando haga falta (*expand*) |

Una cuarta forma —una columna por idioma (`name_es`, `name_en`…)— no es real: añade cuatro columnas por campo, multiplica las del esquema por cuatro y cambia el esquema de los 53 módulos cada vez que se añade un idioma (`ADR-021` prevé las cooficiales).

---

## 4 · Evaluación

| Criterio | A | B | C |
|---|---|---|---|
| Coste en solitario | Bajo: una columna, un `CHECK` generado por ayudante, una regla de validación y un resolutor compartidos | Medio: tabla nueva con RLS, *morph map*, carga ansiosa en cada listado, escritura en dos tablas por transacción | Mínimo hoy |
| Mantenimiento a 3 años | Bueno: el texto vive con su fila; borrado lógico, disparador de curso cerrado (`ADR-057`), RLS y copia por tenant lo cubren sin código extra | Malo: un `JOIN` por campo traducible en cada listado; la traducción huérfana no la impide el motor (FK polimórfica imposible); el disparador de curso cerrado **no** protege la tabla común (no tiene `academic_year_id`) | Malo: el primer boletín (`1.17`) obliga a migrar todas las tablas con nombres |
| Invariantes | `INV-003`: el cambio queda en la auditoría de la propia entidad. `INV-001`: RLS de la tabla | `INV-003`: la auditoría del cambio de nombre queda en otra entidad. `RN-CURSO-20`: un nombre de un curso cerrado se podría cambiar | Contradice `ADR-021` («desde el primer día») |
| Reversibilidad | Media: pasar a B después es migración de datos, pero mecánica | Media | Alta hoy, nula cuando haya datos de centros |

**Se elige A.** El argumento decisivo frente a B no es el rendimiento, es el **bloqueo de cursos cerrados**: `ADR-057` protege los datos por curso con un disparador sobre las tablas con `academic_year_id`. Una tabla de traducciones común no lo tiene, de modo que con B el nombre de una asignatura de un curso cerrado —el que figura en un boletín ya emitido— sería modificable. Arreglarlo exigiría `academic_year_id` en la tabla común (no aplica a contenido que no es por curso) o un segundo disparador que consultase la fila dueña: complejidad para recuperar lo que A da gratis.

Frente a C: el coste de A sobre C es una columna `jsonb` en vez de `text` y una regla de validación compartida. No hay complejidad que ahorrar.

---

## 5 · Decisión

### 5.1 Columna

1. Cada campo traducible es **una** columna `jsonb NOT NULL` llamada **`<campo>_translations`** (`name_translations`, `description_translations`, `label_translations`). No existe a la vez una columna `<campo>` en la tabla: el valor resuelto es representación, no dato.
2. Contenido: objeto cuyas claves son códigos del **vocabulario cerrado de `ADR-021`, con la misma grafía que `tenant_settings`: `es-ES`, `en`, `de`, `fr`**, y cuyos valores son cadenas no vacías.
3. `CHECK` en el motor, generado por un ayudante de `TenantMigration` (p. ej. `translatable('name')`, nombre final en implementación) para que nadie lo escriba a mano:
   - `jsonb_typeof(col) = 'object'`;
   - `col <> '{}'::jsonb` (al menos un idioma);
   - `(col - ARRAY['es-ES','en','de','fr']) = '{}'::jsonb` (ninguna clave fuera del vocabulario);
   - ningún valor que no sea cadena (expresión `jsonb_path` inmutable; texto exacto en implementación, lo revisa `db-reviewer`).
   La expresión va en línea, sin función SQL compartida: una función de la que dependen `CHECK` de 53 módulos complica restauraciones parciales y su propio cambio.
4. Lo que depende del centro (idioma por defecto, idiomas activos) **no** va al motor: varía por centro y en el tiempo. Es validación de servidor (`§5.2`).

### 5.2 Validación de servidor (`INV-010`)

Una regla de validación compartida (`App\Support\I18n`, nombre final en implementación) que lee `TenantSettingsReader`:

1. **Alta (`POST`)**: el objeto es obligatorio; contiene la clave del **idioma por defecto del centro** (`default_locale`); toda clave enviada pertenece a los **idiomas activos** del centro; cada valor se recorta y no puede quedar vacío; longitud máxima por valor la fija la especificación del módulo.
2. **Edición (`PATCH`)**: el mapa es un objeto anidado de claves cerradas y sigue `ADR-038 §9.2` regla 4 (**fusión**), no la regla 6 (reemplazo de JSON de forma libre): `{"name_translations": {"fr": "…"}}` cambia solo `fr`; `{"fr": null}` lo quita (regla 2); `""` es `422` (regla 3). Una clave no nula del cuerpo debe pertenecer a los idiomas activos. **Quitar o dejar sin la clave del idioma por defecto actual es `422`.** Las claves almacenadas que el cuerpo no menciona no se tocan, aunque su idioma ya no esté activo.
3. Retirar un idioma de `active_locales` **no borra** traducciones existentes; cambiar `default_locale` **no invalida** filas existentes que no tengan el nuevo idioma: la resolución de `§5.3` las cubre, y la siguiente edición del campo exige añadirlo.
4. Códigos de error comunes, en el catálogo compartido (no por módulo): `translations.default_locale_required`, `translations.locale_not_active`, `translations.empty_value` (prefijo final según el issue #60). Las especificaciones de módulo los citan, no los redefinen.

### 5.3 Resolución

Un único resolutor compartido (función pura en `App\Support\I18n`, sin base de datos) devuelve el texto y el idioma del que sale:

> **idioma pedido → `default_locale` del centro → `es-ES` → primera clave presente en el orden `es-ES`, `en`, `de`, `fr`**.

- En la API, «idioma pedido» es el resuelto por `ResolveApiLocale` (`ADR-038 §11`).
- En un **documento generado** (boletín, factura, notificación), es el idioma **del destinatario**, no del solicitante ni del proceso que lo genera (`ADR-021`). El trabajo en cola recibe el idioma del destinatario como dato, no lo deduce de `App::getLocale()`.
- Como la validación garantiza el idioma por defecto vigente al escribir, los dos últimos escalones solo actúan tras un cambio de `default_locale`. El resolutor nunca devuelve vacío para una fila válida.
- Ningún módulo resuelve por su cuenta (`->{'en'} ?? …` en un controlador es un fallo de revisión).

### 5.4 API

1. **Lectura**: toda representación que incluya un campo traducible devuelve **los dos**: `<campo>` (cadena resuelta en servidor, `§5.3`) y `<campo>_translations` (el objeto almacenado, con todas sus claves). Ninguno es opcional.
   - Motivo de devolver siempre el objeto: WCAG 2.2 AA **3.1.2 (idioma de las partes)**. Si el texto resuelto procede de la caída a otro idioma, la SPA debe marcarlo con `lang`. Con el objeto y `Content-Language` puede deducir de qué idioma salió sin un tercer campo; y el formulario de edición lo necesita igualmente.
2. **Escritura**: solo `<campo>_translations`. `<campo>` es de solo lectura: OpenAPI lo declara `readOnly` y el servidor no lo lee del cuerpo (se ignora; no es `422`, para que un cliente que reenvía la representación leída no falle).
3. **Filtro y búsqueda**: `q` sobre un campo traducible busca en el **valor resuelto al idioma de la petición** salvo que la especificación del módulo declare otra cosa en OpenAPI (`ADR-038 §5.2`: OpenAPI declara sobre qué campos busca). Si la tabla lo justifica por volumen, la especificación declara el índice (de expresión sobre el idioma por defecto o `tsvector`) con su consulta (`postgres-rendimiento`). Ningún listado de `1.11` tiene `q`.
4. **Orden**: `sort=<campo>` ordena por la expresión resuelta en SQL, con el idioma como **parámetro enlazado**, nunca interpolado: `COALESCE(col ->> :pedido, col ->> :por_defecto, col ->> 'es-ES')`. Se añade a la lista blanca de `sort` solo si la especificación lo declara (`ADR-038 §5.3`).
5. **CSV de datos** (`ADR-054`, `ADR-055`): un CSV de datos «no depende de quién lo solicita» (`ADR-055 §2` punto 3), así que **no** exporta el valor resuelto. Exporta **una columna por idioma del vocabulario cerrado**, siempre las cuatro y en orden fijo, vacía si falta: `<campo>_es_es`, `<campo>_en`, `<campo>_de`, `<campo>_fr`. Esquema fijo por recurso, independiente de los idiomas activos del centro (`ADR-054 §8.1`). Ningún recurso de `1.11` exporta.
6. Los enumerados siguen sin traducir (`ADR-038 §3.2`). Este ADR trata contenido escrito por el centro, no etiquetas de códigos del producto, que siguen en los catálogos.

### 5.5 Auditoría (`ADR-035`): desglose por idioma

El objeto completo de cuatro idiomas supera con facilidad el tope de 256 caracteres de `ADR-035 §5` y se redactaría `oversized` justo en el caso más corriente (renombrar una asignatura). El constructor de `changes` (`ADR-035 §4`, único camino) **desglosa** las columnas cuyo nombre termina en `_translations`:

```json
{
  "name_translations.es-ES": { "from": "Lengua", "to": "Lengua Castellana y Literatura" },
  "name_translations.fr":    { "from": null,     "to": "Langue castillane et littérature" }
}
```

- Una entrada por idioma **que cambió**; los que no cambian no aparecen.
- Los cinco pasos de `ADR-035 §4` se aplican **a cada entrada**, y el tope de `§5` también: un valor largo en un idioma se redacta solo en ese idioma.
- La política del modelo manda igual que con cualquier atributo: con `Selective`, se registra solo si `<campo>_translations` está en `$auditRecordedAttributes`; con `Redacted`, cada entrada se redacta como `special`. Contenido del centro no es dato personal *per se*, pero un campo traducible de texto libre puede contenerlo: el módulo lo clasifica como cualquier otro campo.
- Es una **ampliación** de `ADR-035 §4`, no un cambio: el formato de cada entrada es el mismo (`{from,to}` o `{redacted,…}`); cambia el nombre de la clave. Se adopta ahora porque `audit_logs` es inmutable: decidirlo después dejaría filas con dos formatos para siempre.

### 5.6 Ordenación: colación

`§5.4` punto 4 ordena con la colación por defecto de la base de datos. Una ordenación lingüísticamente correcta en cada idioma (`COLLATE` ICU por idioma) **no se adopta ahora**: ninguna especificación la pide, los listados de estructura académica se ordenan por `position`, y añadirla después es cambiar una expresión de `ORDER BY`, sin migración. Queda escrito como límite conocido.

### 5.7 SPA

- Un único componente de edición de campo traducible en el sistema de diseño (sobre shadcn-vue, `ADR-023`; ningún componente de terceros): una entrada por idioma activo del centro, el idioma por defecto primero y obligatorio, cada entrada con su `lang`.
- La presentación usa `<campo>` y marca `lang` cuando el idioma de procedencia no coincide con el de la página (`§5.4` punto 1).
- `lint:i18n` no cambia: el contenido del centro no es un literal del código.

### 5.8 Regla de arquitectura `AR-16`

Amplía `ADR-056 §3.3`. Sobre el esquema real: **toda columna cuyo nombre termina en `_translations` es `jsonb NOT NULL` y tiene el `CHECK` de `§5.1`** (comprobado por nombre de restricción o por definición; forma final en implementación). Lista de excepciones vacía.

**Límite, dicho para no prometer más de lo que comprueba** (`ADR-056 §3.4`): la regla no detecta un campo que debió ser traducible y se declaró `text`. Eso sigue siendo criterio de la especificación y de `doc-reviewer`.

---

## 6 · Motivo

1. **El texto vive con su fila**: RLS, borrado lógico, disparador de curso cerrado, auditoría de la entidad y copia por tenant lo cubren sin una pieza más. B tendría que reconstruir cada una.
2. **Una sola resolución en servidor** para la API y los documentos: el boletín y la pantalla no pueden discrepar sobre qué nombre tiene una asignatura.
3. **Lo que varía por centro se valida en servidor; lo que no varía, en el motor**: el `CHECK` no puede conocer `active_locales`, y no se intenta.
4. **Fusión en `PATCH`** porque las claves son un vocabulario cerrado, no un JSON opaco (`ADR-038 §9.2` regla 6 no aplica): así no se pierden en silencio traducciones de un idioma retirado cuando el cliente edita otro.
5. **Desglose en auditoría ahora** porque `audit_logs` es inmutable y el caso que el tope rompería es el más frecuente.

---

## 7 · Consecuencias

**Buenas**

- `1.11` puede crear sus tablas sin esperar a `1.17`, y `1.17` encuentra los nombres ya en cuatro idiomas.
- Cualquier módulo nuevo con contenido del centro tiene una receta de una línea en la migración y una regla de validación compartida.
- El nombre de una asignatura de un curso cerrado queda congelado por `ADR-057` sin código adicional.

**Malas, asumidas**

- **Piezas compartidas nuevas que nacen en `1.11`**, fuera del módulo `acad`: ayudante de `TenantMigration`, regla de validación y resolutor en `App\Support\I18n`, desglose en el constructor de `changes`, `AR-16`, componente de la SPA. Son infraestructura del núcleo; su primer consumidor es `acad` y se construyen en el mismo paso (precedente `ADR-057`/`AR-13` en `1.10`).
- **`jsonb` no tiene FK ni unicidad por idioma.** Si un módulo necesita «nombre único en el idioma por defecto», es índice de expresión declarado por su especificación; `acad` no lo necesita (la unicidad es por `code`).
- **Cambiar `default_locale`** deja filas sin el nuevo idioma hasta que se editen. Se muestran por caída (`§5.3`), no fallan. No se construye una migración de contenido al cambiar el idioma por defecto: el centro no tiene traductor automático y no se inventa uno.
- **El tamaño de la respuesta crece** (objeto de traducciones en cada fila). Irrelevante en tablas de estructura; una especificación de listado grande puede pedir excluirlo de la vista de lista si lo justifica con datos, en OpenAPI.

---

## 8 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| **B** · Tabla de traducciones polimórfica | El disparador de curso cerrado no la protege; un `JOIN` por campo; huérfanas sin integridad del motor; la auditoría del cambio queda fuera de la entidad (`§4`) |
| **C** · Solo idioma por defecto | Contradice `ADR-021` y obliga a migrar todas las tablas con nombres al llegar `1.17` |
| Una columna por idioma | Cuatro columnas por campo y cambio de esquema en 53 módulos por cada idioma añadido |
| Claves `es`/`en`/`de`/`fr` (Anexo A de la especificación) | El producto usa `es-ES` en `tenant_settings`, en la validación y en `Content-Language`. Dos grafías del mismo idioma exigirían una tabla de equivalencias en cada frontera; se toma la existente |
| **`es-ES` obligatorio en toda fila**, en lugar del idioma por defecto del centro | Choca con `RN-CORE-13`: un centro con `active_locales = ['en']` no podría guardar nada, o habría que aceptar `es-ES` aunque no esté activo. `es-ES` queda como **caída del producto** en la resolución (`§5.3`), que es lo que garantiza su papel de idioma por defecto sin obligar al centro a escribir en un idioma que no usa. Ver `§10` |
| `PATCH` con reemplazo entero del objeto | Un cliente que solo muestra los idiomas activos borraría en silencio las traducciones de un idioma retirado al guardar |
| Columna `<campo>` desnormalizada con el idioma por defecto junto a `<campo>_translations` | Dos fuentes del mismo dato; el día que diverjan, el boletín y la pantalla dirán cosas distintas |
| Función SQL compartida para el `CHECK` | Dependencia de 53 módulos sobre un objeto de esquema cuyo cambio exige reescribir todas las restricciones |
| Guardar el objeto entero en `changes` | Se redacta `oversized` en el caso más frecuente (`§5.5`) |

---

## 9 · Ajustes que la especificación de `REQ-ACAD` debe absorber (no los edita `architect`)

1. `funcional.md` Anexo A punto 1 y `datos.md §1.1` (`CHECK`): claves **`es-ES`**, `en`, `de`, `fr`, no `es`.
2. `funcional.md` Anexo A punto 3 y `api.md`: `<campo>` de solo lectura; `<campo>_translations` siempre presente en la lectura; `PATCH` por fusión (`§5.2`).
3. `funcional.md` Anexo A punto 5 y `datos.md §4`: la auditoría desglosa por idioma (`§5.5`), no «la columna entra entera».
4. `RN-ACAD-03` y `CA-ACAD-009`: códigos de error comunes de `§5.2` punto 4 en vez de `acad.validation.name_default_locale_required` / `locale_not_active`; añadir los casos «`PATCH` que quita el idioma por defecto ⇒ `422`» y «`PATCH` que cambia un idioma no toca los demás».
5. `OPEN-ACAD-05`: resuelta por este ADR.
6. Criterios nuevos a añadir (en la especificación de `1.11`, con su ID): `AR-16` en verde con tablas reales; resolución con caída (pedido ausente ⇒ idioma por defecto; tras cambiar `default_locale` ⇒ `es-ES` o primera clave); entrada de auditoría desglosada por idioma; `CHECK` del motor rechaza clave fuera del vocabulario y objeto vacío (`23514`).

---

## 10 · Preguntas abiertas (las decide el usuario)

| ID | Pregunta | Recomendación |
|---|---|---|
| `OPEN-062-01` | La recomendación aprobada decía «con es-ES obligatorio». Este ADR exige **el idioma por defecto del centro** al escribir y usa `es-ES` como caída del producto al leer (`§8`, fila correspondiente). ¿Se confirma? | **Sí.** Hoy son lo mismo para todo centro (`default_locale` por omisión `es-ES`); la diferencia solo aparece en un centro que elija otro idioma por defecto, y ahí exigir `es-ES` contradice `RN-CORE-13` |
| `OPEN-062-02` | ¿Se acepta el desglose por idioma en `changes` (`§5.5`) como ampliación de `ADR-035 §4`? | **Sí**, antes de la primera fila: `audit_logs` es inmutable |
