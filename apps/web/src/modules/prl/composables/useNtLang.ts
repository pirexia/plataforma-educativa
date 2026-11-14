/**
 * `REQ-FAM-UNIT/funcional.md §23.7` (WCAG 3.1.2), `OPEN-FAM-UNIT-59`/`-40`: el protocolo de la alerta (`alert.protocol`,
 * texto de la API) está en es-ES hasta que exista su revisión humana. Cuando la interfaz no está en español, el elemento
 * que lo contiene lleva `lang="es-ES"`. Los demás textos [NT] ya están traducidos y no llevan marca.
 */
import { computed } from 'vue'
import { i18n } from '@/i18n'

export function useNtLang() {
  return computed<string | undefined>(() =>
    i18n.global.locale.value === 'es' ? undefined : 'es-ES',
  )
}
