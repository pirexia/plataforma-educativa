/**
 * `docs/design-system.md` §7.5 (`RUX-BRAND-003`). Opera sobre el primer
 * `link[rel~="icon"]` del documento. En la primera llamada memoriza su
 * `href`/`type` originales (los que sirve `index.html`, `/favicon.svg`)
 * para poder restaurarlos si el centro no tiene *favicon* propio o si
 * `GET /tenant/branding` falla con `not-found`.
 */

let memorized = false
let originalHref: string | null = null
let originalType: string | null = null

function getFaviconLink(): HTMLLinkElement | null {
  return document.querySelector('link[rel~="icon"]')
}

function memorizeOriginal(link: HTMLLinkElement): void {
  if (memorized) {
    return
  }

  originalHref = link.getAttribute('href')
  originalType = link.getAttribute('type')
  memorized = true
}

function setOrRemove(link: HTMLLinkElement, attribute: string, value: string | null): void {
  if (value === null) {
    link.removeAttribute(attribute)
  } else {
    link.setAttribute(attribute, value)
  }
}

export function applyFavicon(url: string | null): void {
  const link = getFaviconLink()

  if (!link) {
    return
  }

  memorizeOriginal(link)

  if (url === null) {
    setOrRemove(link, 'href', originalHref)
    setOrRemove(link, 'type', originalType)
    return
  }

  // El favicon del centro puede ser PNG, ICO o SVG (`REQ-CORE/api.md
  // §2.2`): se retira `type` para que el navegador lo detecte por sí
  // mismo en vez de arrastrar el `image/svg+xml` del favicon por defecto.
  link.setAttribute('href', url)
  link.removeAttribute('type')
}
