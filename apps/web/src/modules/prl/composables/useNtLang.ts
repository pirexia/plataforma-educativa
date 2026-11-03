/**
 * `REQ-FAM-UNIT/funcional.md §23.7` (WCAG 3.1.2), `OPEN-FAM-UNIT-59` B: los textos **[NT]** (seguridad y privacidad)
 * están en es-ES en los cuatro ficheros hasta la revisión humana. Cuando la interfaz no está en español, el elemento que
 * los contiene lleva `lang="es-ES"`. La marca se calcula comparando el idioma activo con `es`, no por clave.
 */
import { computed } from 'vue'
import { i18n } from '@/i18n'

export function useNtLang() {
  return computed<string | undefined>(() =>
    i18n.global.locale.value === 'es' ? undefined : 'es-ES',
  )
}
