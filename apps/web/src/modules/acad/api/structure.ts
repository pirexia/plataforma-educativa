/**
 * REQ-ACAD/api.md §2 (paso 1.11-b): los 27 endpoints de la estructura académica.
 *
 * Todo identificador que entra en una ruta se codifica con `encodeURIComponent`
 * (#385). Sin `exportar` ni `importar` (`permisos.md §2.1`). `name` de etapa,
 * curso de etapa y asignatura es de solo lectura (`RN-ACAD-13`): nunca se envía;
 * se escribe `name_translations`.
 */
import { apiFetch } from '@/api/client'
import type {
  Course,
  EducationLevel,
  Group,
  Paginated,
  PublicId,
  Regime,
  StageType,
  Subgroup,
  Subject,
  Translations,
} from '../types'

/** `ADR-038 §5.3`: lista blanca `position`, `-position`, `code`, `-code`. */
export interface ListParams {
  /** `public_id`; omitido ⇒ curso activo (`RN-ACAD-40`). */
  academic_year?: PublicId
  sort?: string
  page?: number
  per_page?: number
}

export interface ListEducationLevelsParams extends ListParams {
  stage?: string[]
  regime?: string[]
}

export interface ListCoursesParams extends ListParams {
  education_level?: PublicId[]
}

export interface ListGroupsParams extends ListParams {
  course?: PublicId[]
  education_level?: PublicId[]
}

export interface ListSubjectsParams extends ListParams {
  course?: PublicId[]
  education_level?: PublicId[]
}

function query(params: object): string {
  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (Array.isArray(value)) {
      if (value.length > 0) {
        search.set(key, value.join(','))
      }
    } else if (value !== undefined && value !== '') {
      search.set(key, String(value))
    }
  }

  const text = search.toString()

  return text ? `?${text}` : ''
}

function path(resource: string, publicId?: PublicId, suffix = ''): string {
  return publicId === undefined
    ? `/${resource}`
    : `/${resource}/${encodeURIComponent(publicId)}${suffix}`
}

function send<T>(url: string, method: 'POST' | 'PATCH' | 'PUT', body: unknown): Promise<T> {
  return apiFetch<T>(url, { method, body: JSON.stringify(body) })
}

function remove(url: string): Promise<void> {
  return apiFetch<void>(url, { method: 'DELETE' })
}

// --- Etapas -----------------------------------------------------------------

export function listEducationLevels(
  params: ListEducationLevelsParams = {},
): Promise<Paginated<EducationLevel>> {
  return apiFetch<Paginated<EducationLevel>>(`${path('education-levels')}${query(params)}`)
}

export function getEducationLevel(publicId: PublicId): Promise<EducationLevel> {
  return apiFetch<EducationLevel>(path('education-levels', publicId))
}

export interface EducationLevelCreate {
  /** `RN-CURSO-22`: la etapa es la raíz y lleva el curso académico explícito. */
  academic_year: PublicId
  code: string
  name_translations: Translations
  stage: StageType
  regime: Regime
  position?: number
}

export function createEducationLevel(payload: EducationLevelCreate): Promise<EducationLevel> {
  return send<EducationLevel>(path('education-levels'), 'POST', payload)
}

/** `ADR-038 §9.2`: solo las claves modificadas; `name_translations` se fusiona por idioma (`ADR-062 §5.2`). */
export interface EducationLevelUpdate {
  code?: string
  name_translations?: Record<string, string | null>
  stage?: StageType
  regime?: Regime
  position?: number
}

export function updateEducationLevel(
  publicId: PublicId,
  payload: EducationLevelUpdate,
): Promise<EducationLevel> {
  return send<EducationLevel>(path('education-levels', publicId), 'PATCH', payload)
}

export function deleteEducationLevel(publicId: PublicId): Promise<void> {
  return remove(path('education-levels', publicId))
}

// --- Cursos de etapa ----------------------------------------------------------

export function listCourses(params: ListCoursesParams = {}): Promise<Paginated<Course>> {
  return apiFetch<Paginated<Course>>(`${path('courses')}${query(params)}`)
}

export function getCourse(publicId: PublicId): Promise<Course> {
  return apiFetch<Course>(path('courses', publicId))
}

export interface CourseCreate {
  education_level: PublicId
  code: string
  name_translations: Translations
  position?: number
}

export function createCourse(payload: CourseCreate): Promise<Course> {
  return send<Course>(path('courses'), 'POST', payload)
}

export interface CourseUpdate {
  code?: string
  name_translations?: Record<string, string | null>
  position?: number
}

export function updateCourse(publicId: PublicId, payload: CourseUpdate): Promise<Course> {
  return send<Course>(path('courses', publicId), 'PATCH', payload)
}

export function deleteCourse(publicId: PublicId): Promise<void> {
  return remove(path('courses', publicId))
}

// --- Grupos --------------------------------------------------------------------

export function listGroups(params: ListGroupsParams = {}): Promise<Paginated<Group>> {
  return apiFetch<Paginated<Group>>(`${path('groups')}${query(params)}`)
}

export function getGroup(publicId: PublicId): Promise<Group> {
  return apiFetch<Group>(path('groups', publicId))
}

export interface GroupCreate {
  course: PublicId
  code: string
  name: string
  position?: number
}

export function createGroup(payload: GroupCreate): Promise<Group> {
  return send<Group>(path('groups'), 'POST', payload)
}

export interface SimpleNameUpdate {
  code?: string
  name?: string
  position?: number
}

export function updateGroup(publicId: PublicId, payload: SimpleNameUpdate): Promise<Group> {
  return send<Group>(path('groups', publicId), 'PATCH', payload)
}

export function deleteGroup(publicId: PublicId): Promise<void> {
  return remove(path('groups', publicId))
}

// --- Subgrupos -------------------------------------------------------------------

/** Listado anidado: sin paginar y sin `academic_year` (el curso académico es el del grupo). */
export function listSubgroups(groupPublicId: PublicId): Promise<{ data: Subgroup[] }> {
  return apiFetch<{ data: Subgroup[] }>(path('groups', groupPublicId, '/subgroups'))
}

export function getSubgroup(publicId: PublicId): Promise<Subgroup> {
  return apiFetch<Subgroup>(path('subgroups', publicId))
}

export interface SubgroupCreate {
  code: string
  name: string
  position?: number
}

/** El grupo viaja en la ruta (`404` si no existe), nunca en el cuerpo (`CA-ACAD-007`). */
export function createSubgroup(
  groupPublicId: PublicId,
  payload: SubgroupCreate,
): Promise<Subgroup> {
  return send<Subgroup>(path('groups', groupPublicId, '/subgroups'), 'POST', payload)
}

export function updateSubgroup(publicId: PublicId, payload: SimpleNameUpdate): Promise<Subgroup> {
  return send<Subgroup>(path('subgroups', publicId), 'PATCH', payload)
}

export function deleteSubgroup(publicId: PublicId): Promise<void> {
  return remove(path('subgroups', publicId))
}

// --- Asignaturas -------------------------------------------------------------------

export function listSubjects(params: ListSubjectsParams = {}): Promise<Paginated<Subject>> {
  return apiFetch<Paginated<Subject>>(`${path('subjects')}${query(params)}`)
}

export function getSubject(publicId: PublicId): Promise<Subject> {
  return apiFetch<Subject>(path('subjects', publicId))
}

export interface SubjectCreate {
  course: PublicId
  code: string
  name_translations: Translations
  position?: number
}

export function createSubject(payload: SubjectCreate): Promise<Subject> {
  return send<Subject>(path('subjects'), 'POST', payload)
}

export function updateSubject(publicId: PublicId, payload: CourseUpdate): Promise<Subject> {
  return send<Subject>(path('subjects', publicId), 'PATCH', payload)
}

export function deleteSubject(publicId: PublicId): Promise<void> {
  return remove(path('subjects', publicId))
}

// --- Asignación de asignaturas a un grupo (`RN-ACAD-32`) ----------------------------

export function listGroupSubjects(groupPublicId: PublicId): Promise<{ data: Subject[] }> {
  return apiFetch<{ data: Subject[] }>(path('groups', groupPublicId, '/subjects'))
}

/** Sustituye el conjunto completo; lista vacía = quitar todas. Idempotente. */
export function replaceGroupSubjects(
  groupPublicId: PublicId,
  subjects: PublicId[],
): Promise<{ data: Subject[] }> {
  return send<{ data: Subject[] }>(path('groups', groupPublicId, '/subjects'), 'PUT', { subjects })
}
