/**
 * `docs/modulos/REQ-ACAD/funcional.md §10.2`. Superficie pública de
 * `src/academic-year`: estado, componente y *composable* del selector de curso
 * académico, reutilizable por toda pantalla con datos por curso.
 */
export { default as AcademicYearSelector } from './AcademicYearSelector.vue'
export {
  ACADEMIC_YEAR_QUERY,
  isWritableStatus,
  loadSelectableYears,
  resetSelectableYears,
  useAcademicYear,
  useSelectableYearsSync,
  usePinAcademicYear,
} from './useAcademicYear'
