/**
 * `funcional.md §11`, `ADR-038 §3.2`/`§7.3`: los enumerados viajan como código técnico y la SPA traduce su etiqueta; un
 * código sin clave en el catálogo (enumerado ampliado por el servidor) se muestra en crudo.
 */
import { useT } from '@/i18n'

export function useLabels() {
  const t = useT()

  function label(group: string, code: string): string {
    const key = `famUnit.enums.${group}.${code}`
    const text = t(key)

    return text === key ? code : text
  }

  return {
    relationshipLabel: (code: string) => label('relationship', code),
    accessLevelLabel: (code: string) => label('accessLevel', code),
    linkStatusLabel: (code: string) => label('linkStatus', code),
    revocationReasonLabel: (code: string) => label('revocationReason', code),
    restrictedCategoryLabel: (code: string) => label('restrictedCategory', code),
    consentLevelLabel: (code: string) => label('consentLevel', code),
    consentStateLabel: (code: string) => label('consentState', code),
    termsKindLabel: (code: string) => label('termsKind', code),
    termsStatusLabel: (code: string) => label('termsStatus', code),
    channelLabel: (code: string) => label('channel', code),
    decisionLabel: (code: string) => label('decision', code),
  }
}
