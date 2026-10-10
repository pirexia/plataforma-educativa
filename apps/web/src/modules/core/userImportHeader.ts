/**
 * `docs/modulos/REQ-CORE/api.md §7`, `funcional.md §14.6.1`. Cabecera exacta
 * que espera `POST /user-imports` (esquema fijo, sin mapeo visual,
 * `funcional.md §1.10`): se muestra copiable en la pantalla de subida. Se
 * comprueba contra `UserImportCsvReader::EXPECTED_HEADER` del servidor
 * (`documentTypes.spec.ts`). Es una constante y no una plantilla descargable:
 * generar un fichero en el cliente chocaría con `CA-CORE-192`.
 */
export const USER_IMPORT_COLUMNS = [
  'email',
  'given_name',
  'family_name_1',
  'family_name_2',
  'document_type',
  'document_number',
  'birth_date',
  'contact_email',
  'contact_phone',
  'locale',
  'roles',
] as const

/** Separador `;` (el servidor autodetecta `;` o `,`). */
export const USER_IMPORT_HEADER = USER_IMPORT_COLUMNS.join(';')

/** `api.md §7`: el servidor responde `413` por encima de esto; el cliente solo avisa antes (comodidad). */
export const USER_IMPORT_MAX_BYTES = 10 * 1024 * 1024
