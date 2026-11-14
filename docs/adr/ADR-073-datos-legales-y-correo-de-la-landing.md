# ADR-073 · Datos legales y correo de la landing: titular sin NIF ni domicilio, `founders@adagiox.com` en Arsys y publicación sin asesor

**Estado**: **ACEPTADA** (2026-11-09; decisiones del usuario, con la discrepancia de la sesión registrada en `§5`).
**Fecha**: 2026-11-09
**Origen**: decisión del usuario del 2026-11-09 al rellenar los marcadores del aviso legal de la landing (#579).
**Afecta a**: `apps/landing` (textos de `/legal/`, `wrangler.jsonc`), DNS de `adagiox.com` (registros de correo).
**Sustituye en parte a `ADR-072`**: `OPEN-072-03` (correo del dominio), el paso `U4` (revisión del aviso legal por un asesor) y los datos de identificación del titular de `§11`/`U4` (NIF y domicilio). El resto de `ADR-072` sigue vigente.

---

## 1 · Contexto

La landing de `adagiox.com` (`ADR-072`) tenía siete tipos de marcador pendientes en `/legal/` (nueve apariciones en `es-ES.json`): `[TITULAR]`, `[NIF]`, `[DOMICILIO]`, `[CORREO]`, `[PROVEEDOR]`, `[GARANTIA]` y `[PLAZO]`. `check:publish` y `deploy.sh` bloquean el despliegue mientras exista alguno (#582). El objetivo inmediato del usuario es tener la landing publicada para solicitar el programa de Anthropic para startups, no para buscar financiación.

## 2 · Decisiones

1. **Titular**: persona física, **Andrés Matías López**. **No se publican ni el NIF ni el domicilio**, ni en la web ni en el repositorio.
2. **Regla permanente**: el NIF/DNI y la dirección física del usuario **nunca** se escriben en Git (ficheros, mensajes de commit, PR, *issues*, comentarios), ni en el historial (`CLAUDE.md §11`). Si algún día hicieran falta en la web, solo se inyectarían en el despliegue desde secretos, nunca commiteados.
3. **Correo** (sustituye a `OPEN-072-03`): `adagiox.com` tiene buzón **`founders@adagiox.com`**, prestado por **Arsys** (registrador de los dominios). El ápice de `adagiox.com` publica los **MX de Arsys**, un SPF que autoriza los servidores de envío de Arsys y DMARC; DKIM si Arsys lo ofrece. Los valores exactos los da el panel de Arsys en la fecha de alta (paso fuera del repositorio). `.es`, `.eu` y `.net` siguen sin correo: MX nulo + SPF `-all` + DMARC `p=reject`, como en `ADR-072`.
4. **Publicación sin revisión de asesor** (sustituye a `U4`): el texto legal se publica tal como queda en este cambio, sin revisión profesional previa.
5. **Datos del encargado y de la transferencia**, verificados el 2026-11-09:
   - Alojamiento: **Cloudflare, Inc.** (EE. UU.), parte contratante según su DPA v6.4 (3 de abril de 2026), incorporado a los términos de autoservicio.
   - Garantía de la transferencia: Decisión de Ejecución (UE) 2023/1795 (Marco de Privacidad de Datos UE-EE. UU.; Cloudflare certificada, art. 45 RGPD) y, en su defecto, cláusulas contractuales tipo de la Decisión 2021/914, módulos 2 y 3 (art. 46.2.c RGPD).
   - Plazo: los datos de análisis de tráfico HTTP se conservan **al menos 31 días** en el plan gratuito (documentación de Cloudflare, 2026-10-02). Los *Workers Logs* quedan **desactivados** (`observability.enabled: false`), así que no hay registros propios.
   - Correo: **Arsys Internet, S.L.U.** (España), encargado del tratamiento de los mensajes recibidos. La razón social se toma del registro del dominio; se comprobará contra el contrato.
6. La política de privacidad incluye el tratamiento de los correos que se reciban en `founders@adagiox.com` (datos, finalidad, base jurídica de interés legítimo y conservación).

## 3 · Consecuencias

- `check:publish` pasa: el despliegue depende ya solo de `LANDING_DEPLOY_ENABLED`, del *environment* `landing-production`, del token de Cloudflare y del DNS.
- El buzón debe funcionar **antes** de publicar: el art. 10 LSSI-CE exige un medio de contacto efectivo.
- `security.txt` (`ADR-072 §4.4`) sigue diferido aunque el contacto `founders@adagiox.com` ya exista: se añade cuando el buzón esté operativo, en un cambio aparte.
- Las pruebas que daban por hecho los marcadores en el proyecto real usan ahora una copia con un marcador reintroducido, para seguir cubriendo la puerta de publicación (#582).

## 4 · Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Publicar NIF y domicilio particular | Riesgo físico para la familia del usuario y exposición permanente en un repositorio público. Decisión del usuario. |
| Domicilio de actividad en una oficina virtual | Cumple sin exponer la vivienda, pero exige alta censal y coste mensual. No elegida ahora; queda como vía de regularización (`§5`). |
| Inyectar NIF y domicilio desde secretos | Evita el repositorio, pero los publica igualmente en la web. Descartada por `§2.1`. |
| Cloudflare Email Routing (solo reenvío) | El usuario ya tiene buzón en Arsys con el registro del dominio. |

## 5 · Discrepancia registrada (`CLAUDE.md §0`)

La sesión advirtió, antes de ejecutar, de lo siguiente:

1. **Art. 10 LSSI-CE.** Una web que presenta un producto comercial puede considerarse servicio de la sociedad de la información aunque sea gratuita y aunque su fin sea pedir créditos a un programa de startups. En ese caso el art. 10 exige nombre, domicilio (o establecimiento permanente), correo y **NIF**. Omitirlos es una infracción leve (multa de hasta 30.000 €). El riesgo de persecución es bajo para una página informativa sin contratación, pero existe.
2. **Publicación sin asesor.** La sesión no sustituye una revisión jurídica.

El usuario mantuvo su decisión, con un motivo legítimo: el derecho a la protección de sus datos y la seguridad física de su familia. **Vías de regularización**, si el riesgo cambia: un domicilio de actividad que no sea la vivienda (oficina virtual con alta censal), o constituir una sociedad (razón social, CIF y domicilio social; ver `ADR-072`, `U3`).
