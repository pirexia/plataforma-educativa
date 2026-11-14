/**
 * `docs/modulos/REQ-CORE/funcional.md §14.6.4`, `RN-CORE-90`, `OPEN-CORE-53`
 * = A. Catálogo cerrado de tipos de documento de identidad: constante del
 * cliente, **comprobada contra el enumerado PHP** (`documentTypes.spec.ts`,
 * `App\Modules\Core\Domain\DocumentType`, fuente única del servidor). Un
 * catálogo de tres valores que solo cambia con un despliegue no justifica
 * una petición más. Mismo orden que el enumerado y que el `enum` de OpenAPI.
 *
 * El nombre visible de cada código es `core.person.documentType.<código>`;
 * un código que el cliente no conoce se pinta crudo (`ADR-038 §7.3`).
 */
export const DOCUMENT_TYPES = ['dni', 'nie', 'pasaporte'] as const

export type DocumentTypeCode = (typeof DOCUMENT_TYPES)[number]
