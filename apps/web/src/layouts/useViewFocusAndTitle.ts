/**
 * `docs/modulos/REQ-CORE/funcional.md §12.3.2` punto 4, `CA-CORE-086`.
 * Tras cada navegación: el foco pasa al encabezado principal de la vista
 * (su `h1`, o `main` si no lo encuentra) y `document.title` pasa a
 * `<título traducido de la vista> · <nombre del centro>`.
 */
import { nextTick, watch, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { useT } from '@/i18n'
import { useTenantBranding } from '@/tenant/useTenantBranding'

export function useViewFocusAndTitle(mainRef: Ref<HTMLElement | null>): void {
  const route = useRoute()
  const t = useT()
  const { branding } = useTenantBranding()

  async function apply(): Promise<void> {
    await nextTick()

    const titleKey = route.meta.titleKey
    const viewTitle = titleKey ? t(titleKey) : ''
    const tenantName = branding.value?.name ?? t('layout.title')

    document.title = viewTitle
      ? t('shell.documentTitleFormat', { view: viewTitle, tenant: tenantName })
      : tenantName

    const main = mainRef.value

    if (!main) {
      return
    }

    const heading = main.querySelector<HTMLElement>('h1')
    const target = heading ?? main

    if (!target.hasAttribute('tabindex')) {
      target.setAttribute('tabindex', '-1')
    }

    target.focus()
  }

  watch(() => route.fullPath, apply, { immediate: true })
}
