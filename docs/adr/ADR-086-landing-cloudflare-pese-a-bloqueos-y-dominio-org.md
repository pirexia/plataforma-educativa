# ADR-086 · Landing: se mantiene Cloudflare pese a los bloqueos de LaLiga, cuarto dominio `.org` y estado del DNS

**Estado**: **ACEPTADA** (2026-11-14; decisión del usuario, opción A de «seguir con Cloudflare», con la recomendación de la sesión).
**Fecha**: 2026-11-14
**Origen**: decisiones del usuario del 2026-11-14 durante el alta del DNS y de los pasos previos al despliegue de la landing (issue #579).
**Afecta a**: `adagiox.com` y los dominios secundarios (DNS), `apps/landing` (`wrangler.jsonc`, `README.md`), `SYSADMIN.md §4b`. No afecta a `apps/api` ni a `apps/web`.
**Sustituye en parte a**:
- `ADR-072 §4.7` y `OPEN-072-02`: la condición «si siguen los bloqueos de LaLiga, se usa Netlify» **se da, y se acepta el riesgo por decisión expresa** (`§2.1`).
- `ADR-072 §1.1` punto 2 y `§4.3`: el cuarto dominio es `adagiox.org`, no `adagiox.net` (`§4.2`).
**Se apoya en**: `ADR-072`, `ADR-073`, `RNF-MANT-007` (la salida a Netlify sigue siendo un cambio de `deploy.sh`, un secreto y el DNS).

---

## 1 · Contexto

1. `ADR-072 §4.7` recomendó Cloudflare **condicionado** a comprobar, antes del alta, si seguían los bloqueos de IP de Cloudflare por operadores españoles durante partidos de LaLiga; si seguían, Netlify.
2. Comprobación del 2026-11-14 con los datos públicos de `hayahora.futbol` (`/estado/data.json`, actualizados ese día a las 10:38 UTC): de 16.618 IP de Cloudflare vigiladas, **0 bloqueadas en ese momento**, pero con bloqueos de miles de IP cada fin de semana de partido (17-18 y 24-25 de octubre, 31 de octubre y 1 de noviembre, 7 y 8 de noviembre de 2026), en DIGI, Masmovil, Movistar, Orange y Vodafone. **La condición de `ADR-072 §4.7` se da.** Es una fotografía puntual de una fuente de terceros, sin verificar por otra vía.
3. El usuario es titular de `adagiox.com`, `.es`, `.eu` y `.org`. `ADR-072` nombra `.net` como cuarto dominio.

## 2 · Decisión

### 2.1 · Proveedor: Cloudflare

Se **mantiene Cloudflare** (Workers *static assets*) y no se pasa a Netlify. Motivo: la landing es informativa, el público de la solicitud al programa de startups no está concentrado en España, y los bloqueos son de tardes y noches de fin de semana. **Riesgo aceptado y declarado**: durante un bloqueo, parte del público español no podrá abrir `adagiox.com`, incluido un revisor situado en España. **Salida documentada**: Netlify con el mismo artefacto (`_headers`/`_redirects`), tocando `apps/landing/scripts/deploy.sh`, el secreto y el DNS (`ADR-072 §4.6`). **Condición de revisión**: si un bloqueo afecta a una gestión real (por ejemplo, la solicitud al programa de startups), se reabre y se pasa a Netlify.

### 2.2 · Cuarto dominio: `adagiox.org`

Los dominios secundarios son `adagiox.es`, `adagiox.eu` y **`adagiox.org`**. `adagiox.net` no se usa. Donde `ADR-072` dice `.net`, léase `.org`.

### 2.3 · Estado del DNS (2026-11-14)

- Las cuatro zonas están dadas de alta en Cloudflare con los servidores de nombres `johnny.ns.cloudflare.com` y `keyla.ns.cloudflare.com`. `adagiox.com` y `adagiox.eu` están `active`; `adagiox.es` y `adagiox.org` esperaban a que Arsys propagara el cambio.
- `adagiox.com` conserva los registros de correo importados de Arsys (anotados en `apps/landing/README.md`, «Reversión»). Las zonas secundarias no tienen correo.
- Redirecciones 301 a `https://adagiox.com` conservando ruta y consulta: regla de redirección de zona en `www.adagiox.com` y en el ápice y `www` de cada dominio secundario, con registros `AAAA 100::` con proxy como marcador. Comprobado con `curl` en `www.adagiox.com` y `adagiox.eu`.
- **DNSSEC**: activo en Cloudflare para `adagiox.com`, en estado `pending`. **Falta el registro DS en el registrador** (Arsys lo hace por soporte). No bloquea el despliegue. Sin DS no hay cadena de confianza.
- **Pendiente** (`ADR-072 §4.3` se mantiene en estas exigencias): el MX nulo, el SPF `-all` y el DMARC `p=reject` en las zonas secundarias y el DMARC de `adagiox.com`; el **DNSSEC y su DS en `.es`, `.eu` y `.org`**; y el **CAA en las cuatro zonas**. Ninguna bloquea el despliegue de la landing.

### 2.4 · Token de despliegue

Token de cuenta con rol **Admin** de Workers para el primer despliegue, y permiso **Workers Routes → Write** sobre la zona `adagiox.com` para asociar el dominio. Después, rol **Editor** a nivel de producto Workers: los dominios personalizados **no admiten roles por Worker** (documentación de Cloudflare de septiembre de 2026), lo que corrige `SYSADMIN.md §4b` («Editor solo sobre el Worker»). Caducidad fijada y rotación anotada.

### 2.5 · Cierres

`OPEN-072-07` (marca) **cerrada**: el usuario no encontró «AdagioX» en TMview ni en la OEPM (sí «Adagio», como preveía `ADR-072 §10`). El copy en castellano e inglés está validado por el usuario. El buzón `founders@adagiox.com` funciona.

## 3 · Consecuencias

- **Buenas**: se puede publicar sin cambiar de proveedor, y el DNS, el DNSSEC, las redirecciones y las cabeceras quedan en un solo sitio.
- **A vigilar**: los bloqueos de LaLiga (`§2.1`); la dependencia de que Arsys ponga el DS; la reversión real contra Cloudflare **no está probada** (`apps/landing/README.md`).
- **No se ha desplegado nada**: el interruptor `LANDING_DEPLOY_ENABLED` sigue apagado. Desplegar exige la release de `develop` a `main` (`ADR-072`, `OPEN-072-06`).
