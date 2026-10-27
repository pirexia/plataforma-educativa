# REQ-FAM-UNIT · Permisos

> **Estado**: **APROBADA por el usuario** (2026-10-26, todas las recomendaciones de `OPEN-FAM-UNIT-01..21` aceptadas tal cual; ver `funcional.md §13`). **`1.14-a` implementada (2026-10-26)**: `FamUnitServiceProvider::declaredPermissions()` declara los **once** permisos de `familia`, `vinculo_tutor` y `revocacion_tutor`; los de `consentimiento_imagen`, `condiciones_uso` y `aceptacion_condiciones` se declaran con `1.14-b`/`-c`. **Enmendada por `ADR-066` (ACEPTADA, 2026-10-27; `funcional.md §16`)**: el recurso `condiciones_uso` pasa a llamarse **`texto_legal`** (`OPEN-066-03`) y se declara en **`1.14-b`** junto con `consentimiento_imagen`; `aceptacion_condiciones` sigue en `1.14-c`. Ningún permiso de estos estaba declarado ni sembrado, así que el cambio no migra concesiones. **`1.14-b` LISTA PARA IMPLEMENTAR (2026-10-27), aprobada por el usuario** (`funcional.md §17`), con las condiciones previas a datos reales de `funcional.md §13`: `secretaria` recibe `texto_legal.leer` (`OPEN-FAM-UNIT-22`) y `consentimiento_imagen.leer` cubre además `GET /image-consent-texts` (`OPEN-FAM-UNIT-23`). Siembra: `ProvisionTenantDefaults::FAMILY_PERMISSION_GRANTS` y `FAMILY_PERMISSION_UNIDAD_FAMILIAR_GRANTS`, y el comando `fam_unit:grant-permissions` para los centros existentes. La fuente de verdad será `FamUnitServiceProvider::declaredPermissions()` (`INV-007`, `ADR-034 §2`); esta tabla será su reflejo. La siembra de §4 es la recomendación de `OPEN-FAM-UNIT-10`. El recurso `restriccion_custodia` está **condicionado** a `OPEN-FAM-UNIT-05`.

---

## 1. Recursos que aporta el módulo

| Recurso | Qué representa | Por qué así |
|---------|----------------|-------------|
| `familia` | Unidades familiares, tutores (faceta) y alumnado de la unidad | Un solo recurso para la composición de la familia: mismo perfil de riesgo y nadie ha pedido separarlos. No se llama `unidad_familiar` para no confundirlo con el **ámbito** (`funcional.md §0.1`) |
| `vinculo_tutor` | Vínculos tutor ↔ alumno con sus atributos (relación, representación legal, acceso, categorías, emergencia) | Configurar quién accede a qué de un menor es distinto de dar de alta una familia |
| `revocacion_tutor` | Revocar y levantar el acceso de un tutor a un alumno, y ver vínculos revocados | Tiene consecuencias legales y revela la existencia de una resolución judicial. Separarlo de `vinculo_tutor.actualizar` evita que quien corrige un teléfono de emergencia pueda revocar una custodia. Mismo patrón que `estado_curso_academico` y `rol_datos_especiales`: recurso nuevo, ninguna acción inventada |
| `restriccion_custodia` (condicionado) | Detalle documental de la resolución | Categoría especial (`funcional.md §3.7`, C5) |
| `consentimiento_imagen` | Estado vigente (calculado), decisiones e informe | `REQ-FAM-UNIT-004`, `ADR-066` |
| `texto_legal` | Versiones de los textos legales del centro: condiciones de uso y texto del consentimiento de imagen por nivel (`terms_versions` con `kind`) | `REQ-FAM-UNIT-003`/`-004`. Nombre neutro porque el mismo mecanismo publica las condiciones y los textos de consentimiento (`OPEN-066-03`); antes `condiciones_uso` |
| `aceptacion_condiciones` | Consulta administrativa de las aceptaciones | Saber quién aceptó qué es un listado del personal sobre todos los tutores; quien redacta las condiciones no tiene por qué verlo |

Acciones solo de `RPERM-003`; ninguna inventada.

> **Entregas (`funcional.md §15`, D-6, enmendado por `ADR-066`)**: en `1.14-a` solo se declaran `familia`, `vinculo_tutor` y `revocacion_tutor`. `consentimiento_imagen` y `texto_legal` entran con **`1.14-b`**; `aceptacion_condiciones`, con **`1.14-c`**; `restriccion_custodia`, tras su ADR.

---

## 2. Catálogo

`module_code = 'fam_unit'`. `applicable_scopes` **explícito** en cada entrada (`ADR-044 §4.1`). `resource_label_key = fam_unit.permissions.resources.<recurso>` en los cuatro idiomas (`CA-PERM-134`).

| `code` | Acción | `is_special_category` | `applicable_scopes` | *Endpoints* (`api.md`) |
|--------|--------|-----------------------|---------------------|------------------------|
| `familia.leer` | `leer` | `false` | `['todos','unidad_familiar']` | `GET /family-units`, `GET /family-units/{id}`, `GET /family-units/{id}/students`, `GET /guardians`, `GET /guardians/{id}` |
| `familia.crear` | `crear` | `false` | `['todos']` | `POST /family-units`, `POST /guardians` |
| `familia.actualizar` | `actualizar` | `false` | `['todos']` | `PATCH /family-units/{id}`, `PATCH /guardians/{id}`, `POST`/`DELETE /family-units/{id}/students…` |
| `familia.eliminar` | `eliminar` | `false` | `['todos']` | `DELETE /family-units/{id}`, `POST /family-units/{id}/restore`, `DELETE /guardians/{id}`, `include_deleted` |
| `vinculo_tutor.leer` | `leer` | `false` | `['todos','unidad_familiar']` | `GET /guardian-links`, `GET /guardian-links/{id}` |
| `vinculo_tutor.crear` | `crear` | `false` | `['todos']` | `POST /guardian-links` |
| `vinculo_tutor.actualizar` | `actualizar` | `false` | `['todos']` | `PATCH /guardian-links/{id}` |
| `vinculo_tutor.eliminar` | `eliminar` | `false` | `['todos']` | `DELETE /guardian-links/{id}` |
| `revocacion_tutor.leer` | `leer` | `false` | `['todos']` | Filtro `status=revocado` y campo `revocation` |
| `revocacion_tutor.crear` | `crear` | `false` | `['todos']` | `POST /guardian-links/{id}/revocation` |
| `revocacion_tutor.eliminar` | `eliminar` | `false` | `['todos']` | `DELETE /guardian-links/{id}/revocation` |
| `restriccion_custodia.leer` (condicionado) | `leer` | **`true`** | `['todos']` | `GET …/custody-restriction`, `GET …/document` |
| `restriccion_custodia.actualizar` (condicionado) | `actualizar` | **`true`** | `['todos']` | `PUT …/custody-restriction`, `POST …/document` |
| `consentimiento_imagen.leer` | `leer` | `false` | `['todos','unidad_familiar']` | `GET /image-consents`, `GET /image-consent-decisions`, `GET /image-consent-texts` (texto publicado de cada nivel; el ámbito no filtra, §3.1, `RN-FAM-UNIT-75`) |
| `consentimiento_imagen.actualizar` | `actualizar` | `false` | `['todos','unidad_familiar']` | `POST /image-consent-decisions`. Registrar en nombre de un tutor **revocado** exige además `revocacion_tutor.leer` (`RN-FAM-UNIT-41`) |
| `consentimiento_imagen.exportar` | `exportar` | `false` | `['todos']` | `POST /image-consents/exports`, `GET /data-exports/{id}` de tipo `image_consents` |
| `texto_legal.leer` | `leer` | `false` | `['todos']` | `GET /terms-versions`, `GET /terms-versions/{id}` |
| `texto_legal.crear` | `crear` | `false` | `['todos']` | `POST /terms-versions` |
| `texto_legal.actualizar` | `actualizar` | `false` | `['todos']` | `PATCH /terms-versions/{id}` |
| `texto_legal.eliminar` | `eliminar` | `false` | `['todos']` | `DELETE /terms-versions/{id}` |
| `texto_legal.publicar` | `publicar` | `false` | `['todos']` | `POST /terms-versions/{id}/publish` |
| `aceptacion_condiciones.leer` | `leer` | `false` | `['todos']` | `GET /terms-acceptances` |

`consentimiento_imagen.actualizar` y no `crear`: la decisión cambia el **estado** del consentimiento; que internamente se guarde como fila nueva es implementación [DERIVADA].

**Sin permiso, por identidad** (amplían la lista cerrada de `AR-07a`, aprobación expresa, `OPEN-FAM-UNIT-12`): `GET /me/terms`, `POST /me/terms-acceptances`.

### 2.1 Permisos que **no** se declaran

| No se declara | Motivo |
|---------------|--------|
| `familia.exportar`, `vinculo_tutor.exportar` | Ningún requisito lo pide; sin *endpoint*, un permiso invita a concederlo creyendo que hace algo (precedente `REQ-ACAD/permisos.md §2.1`) |
| `*.importar` | Es de `REQ-ONB` (`1.24`) |
| Ámbito `unidad_familiar` en escrituras de `familia` y `vinculo_tutor` | El autoservicio del tutor principal está pendiente de `OPEN-FAM-UNIT-04`. Añadir el ámbito después es ampliar `applicable_scopes`, compatible |
| `unidad_familiar` en `consentimiento_imagen.exportar` | El informe es del centro; el tutor ve lo suyo con `leer` |

---

## 3. Matriz recurso × acción × ámbito

Cada celda es lo que el permiso **admite** (`applicable_scopes`), no lo que se concede a quién (§4).

| Recurso | crear | leer | actualizar | eliminar | exportar | importar | aprobar | firmar | publicar |
|---------|-------|------|------------|----------|----------|----------|---------|--------|----------|
| `familia` | `todos` | `todos`, `unidad_familiar` | `todos` | `todos` | — | — | — | — | — |
| `vinculo_tutor` | `todos` | `todos`, `unidad_familiar` | `todos` | `todos` | — | — | — | — | — |
| `revocacion_tutor` | `todos` | `todos` | — | `todos` | — | — | — | — | — |
| `restriccion_custodia` (condicionado, especial) | — | `todos` | `todos` | — | — | — | — | — | — |
| `consentimiento_imagen` | — | `todos`, `unidad_familiar` | `todos`, `unidad_familiar` | — | `todos` | — | — | — | — |
| `texto_legal` | `todos` | `todos` | `todos` | `todos` | — | — | — | — | `todos` |
| `aceptacion_condiciones` | — | `todos` | — | — | — | — | — | — | — |

### 3.1 Ámbito restringido: resolutores de `unidad_familiar` (`ADR-044 §4.2`, `ADR-063 §5.2`)

Este módulo es el **dueño de la pertenencia** familiar, igual que `acad` lo es de la asignación docente. Registra el resolutor de sus **propios** recursos y ofrece `FamilyMembership` (`funcional.md §7.1`) para que **cada módulo dueño de un recurso con datos de alumnado** registre el suyo. El núcleo no sabe qué es una familia (`REQ-PERM/funcional.md §3.4`, punto 3).

Sujeto: la persona del usuario autenticado y, si la tiene, su faceta de tutor viva. Sin faceta ⇒ conjunto vacío (deniega).

| Par (ámbito, recurso) | Clase que lo implementa | Restricción de consulta |
|-----------------------|-------------------------|-------------------------|
| (`unidad_familiar`, `familia`) | Resolutor del módulo, en `Infrastructure` | Unidades con al menos un alumno con vínculo **activo** con el tutor del sujeto; tutores con vínculo activo con alguno de esos alumnos; pertenencias de esos alumnos |
| (`unidad_familiar`, `vinculo_tutor`) | Ídem | Vínculos **activos** cuyo alumno tiene vínculo activo con el tutor del sujeto (`RN-FAM-UNIT-36`: nunca revocados) |
| (`unidad_familiar`, `consentimiento_imagen`) — **`1.14-b`** | Ídem | Estados y decisiones de alumnos con vínculo activo con el tutor del sujeto; en el histórico, **sin** las decisiones de tutores cuyo vínculo con ese alumno está revocado (`RN-FAM-UNIT-71`) |

Reglas que **no** son permiso y se comprueban además (`permisos-y-roles`: visibilidad y reglas de negocio aparte):

1. Para **registrar** una decisión de imagen con ámbito `unidad_familiar`, el vínculo propio debe estar activo y tener representación legal (`RN-FAM-UNIT-41`). Con ámbito `todos` (en nombre de un tutor), el tutor debe estar entre quienes deciden (vínculo vivo, `activo` o `revocado`, con representación legal); si está revocado, el sujeto necesita además `revocacion_tutor.leer`, y sin él la respuesta es la de un tutor no vinculado (`RN-FAM-UNIT-40`, `ADR-066 §5.4`).
2. La categoría restringida no afecta a los recursos de este módulo (ninguno es de `salud`); la aplican los módulos consumidores con `FamilyMembership` pidiendo la categoría.
3. Nivel de acceso `lectura`/`lectura_escritura`: sin efecto sobre los recursos de este módulo en `1.14` (`OPEN-FAM-UNIT-04`).
4. `autorizo` exige texto publicado del nivel (`RN-FAM-UNIT-68`): regla de negocio, no permiso.
5. **`GET /image-consent-texts`** (`RN-FAM-UNIT-75`, `OPEN-FAM-UNIT-23` aprobada el 2026-10-27) exige `consentimiento_imagen.leer` con cualquiera de sus dos ámbitos, pero **no pasa por el resolutor** ni por `ScopedQuery` [DERIVADA; aceptada por el usuario el 2026-10-27: también la lee un tutor sin vínculos activos]: devuelve el texto publicado del centro, que no es dato de ningún alumno ni tutor, y solo versiones `publicada`. Por eso se lee con `consentimiento_imagen.leer` y no con `texto_legal.leer` (que solo admite `todos` y da acceso a borradores y versiones retiradas): un tutor nunca necesita `texto_legal.*`. Si `AR-10` lo detectara como consulta de un recurso con ámbito restringido fuera de `ScopedQuery`, `implementer` para y lo consulta: el mapa cerrado no se amplía sin aprobación.

**Mapa cerrado de `AR-10`** (ampliación que requiere aprobación expresa, como `OPEN-ACAD-42`): `familia` → `FamilyUnit`, `Guardian`, `FamilyUnitStudent`; `vinculo_tutor` → `GuardianStudentLink`; `consentimiento_imagen` → **`ImageConsentDecision` solo** (**`1.14-b`**, `ADR-066 §9.25`: `ImageConsent` ya no es modelo; el cálculo del estado lee vínculos y decisiones dentro del servicio sancionado del recurso, que fija `implementer` con `1.14-b`; `funcional.md §15` D-6: en `1.14-a` solo entran los dos primeros recursos). `texto_legal` solo admite `todos` y no entra en el mapa. `familia` con sus **tres** servicios sancionados (`FamilyUnitService`, `GuardianService`, `FamilyUnitStudentService`, `funcional.md §15` D-8) y `vinculo_tutor` con uno (`GuardianLinkService`). El test no ve accesos por relación ni `DB::table()`: lo cubren `CA-FAM-UNIT-040`/`-041` (listado **y** detalle).

### 3.2 Contrato para los resolutores de otros módulos

Un módulo con datos de alumnado (expediente, calificaciones, asistencia, salud…) que quiera admitir `unidad_familiar`:

1. Declara `unidad_familiar` en `applicable_scopes` de su permiso.
2. Registra su resolutor (`unidad_familiar`, `<su recurso>`), que acota su consulta a los alumnos devueltos por `FamilyMembership` para la persona del sujeto, **pidiendo la categoría** de información si su recurso pertenece a una (p. ej. `salud`).
3. No cachea el resultado entre peticiones (`RN-FAM-UNIT-34`): la revocación debe surtir efecto en la petición siguiente.
4. Escribe su propio criterio de acceso denegado en listado y detalle.

---

## 4. Asignación en roles predefinidos (`OPEN-FAM-UNIT-10`, recomendación)

Denegación por defecto (`RPERM-011`). Se siembra en `ProvisionTenantDefaults` para centros nuevos y con un comando en `Core` para los existentes (`operacion.md §3`), idempotente, solo roles predefinidos, sin tocar roles personalizados, y sin conceder nada si algún permiso falta o está retirado (issue #387). Ninguna regla de código compara estos códigos (`RN-PERM-46`).

| Rol (`code`) | Permisos | Ámbito | Motivo (`§11.1`) |
|--------------|----------|--------|------------------|
| `administrador_centro` | Todos los de §2 **salvo** `restriccion_custodia.*` (incluidos `texto_legal.*`, con **`1.14-b`**) | `todos` | «Gestión completa de su tenant». Las condiciones de uso las «personaliza el Administrador de Centro» (`REQ-FAM-UNIT-003`); el texto del consentimiento de imagen sigue el mismo criterio. Es el único rol predefinido que **redacta y publica** textos legales |
| `secretaria` | `familia.*`, `vinculo_tutor.*`, `revocacion_tutor.leer`, `aceptacion_condiciones.leer` (**`1.14-c`**) y, con **`1.14-b`**, `consentimiento_imagen.leer`, `.actualizar`, `.exportar` y **`texto_legal.leer`** (solo lectura; ni `crear`, ni `actualizar`, ni `eliminar`, ni `publicar`) | `todos` | «Matrícula, registro»: es quien da de alta familias y recoge las autorizaciones en papel. `texto_legal.leer` porque, al registrar una decisión `presencial`, puede tener que indicar la versión `retirada` que firmó la familia (`RN-FAM-UNIT-69`) y necesita listarlas (`OPEN-FAM-UNIT-22`, aprobada por el usuario el 2026-10-27; `CA-FAM-UNIT-106`) |
| `direccion` | `familia.leer`, `vinculo_tutor.leer`, `revocacion_tutor.leer` y, con **`1.14-b`**, `consentimiento_imagen.leer` | `todos` | Consulta |
| `administrativo` | `familia.leer`, `vinculo_tutor.leer` | `todos` | «Matrículas, documentación» |
| `tutor_legal` | `familia.leer`, `vinculo_tutor.leer`; y, con **`1.14-b`**, `consentimiento_imagen.leer`, `consentimiento_imagen.actualizar` | **`unidad_familiar`** | «Información de los estudiantes vinculados, autorizaciones» |
| Los 11 restantes | — | — | Sin base en `§11.1` en este paso. Docentes y tutores de grupo verán a los tutores de sus alumnos cuando exista el ámbito `grupo` sobre alumnado (`1.15`) |

**Revocar y levantar** (`revocacion_tutor.crear`/`.eliminar`) solo `administrador_centro` en la siembra: es la acción con consecuencias legales del módulo. El centro puede delegarla con un rol propio.

`restriccion_custodia.*` (condicionado): **ningún rol por defecto** (`RPERM-012`), ni siquiera `administrador_centro` (`special_data_access = false`, `REQ-PERM/funcional.md §5.5`). El centro lo concede a un rol con `special_data_access = true`.

`soporte_plataforma`: nada. Super Administrador: no es fila de `roles`.

**`RPERM-013`** (nadie concede lo que no tiene): sin cambios respecto a `REQ-PERM`.

---

## 5. Datos de categoría especial

- **`restriccion_custodia`** (condicionado a `OPEN-FAM-UNIT-05`): `is_special_category = true`, inerte desde un rol sin `special_data_access` (`REQ-PERM/funcional.md §5.1`), en ningún rol por defecto, tabla separada y cifrada, **auditoría de lectura** (`read`) en cada acceso. Declararlo dispara `AR-09`: la especificación del ADR debe sustituir el cable trampa por la regla real (`REQ-PERM/funcional.md §5.4`).
- **Revocación** (`revocacion_tutor`): **no** es categoría especial en esta propuesta. Es un código (`resolucion_judicial`), sin detalle, y es lo que necesitan conserjería, transporte y comedor para no entregar a un menor (`ADR-032`). Marcarla especial impediría que la viera ningún rol por defecto y rompería la exclusión automática de `1.14b`. Su lectura se limita con permiso propio y `RN-FAM-UNIT-36`.
- **Consentimiento de imagen**: dato personal de un menor, no de categoría especial (art. 9 RGPD). Permiso ordinario. `ImageConsentPolicy` no audita lecturas (`ADR-066 §5.3`).

## 6. MFA

Ningún cambio de `mfa_required` en roles. Recomendación que no se aplica aquí: si un centro crea un rol con `revocacion_tutor.*` o `restriccion_custodia.*`, debería llevar `mfa_obligatorio` (`REQ-AUTH-003`: «roles con acceso a datos de categoría especial»). `administrador_centro` ya lo lleva.

---

## 7. Visibilidad de los vínculos revocados (hallazgo `M1` de `security-reviewer`, 1.14-a)

`revocacion_tutor.leer` gobierna ver los vínculos `revocado` **y su campo `revocation`** (`RN-FAM-UNIT-40`): sin él, `GET /guardian-links` los omite y el detalle de uno revocado es `404`; el filtro `status=revocado` es `403`. El ámbito `unidad_familiar` nunca los ve (`RN-FAM-UNIT-36`). Con el permiso, `vinculo_tutor.leer` los devuelve junto a los activos.
