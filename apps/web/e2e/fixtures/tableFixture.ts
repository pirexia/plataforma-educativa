/**
 * `docs/modulos/REQ-CORE/funcional.md §13.18`: «tabla de prueba» para los
 * criterios **[Playwright]** de `src/data-table` (`CA-CORE-177`, `-178`,
 * `-185`, `-203`). Solo existe para los tests de extremo a extremo: el
 * servidor de desarrollo de Vite sirve este fichero (y transforma sus
 * importaciones) cuando el test lo importa desde la página, así que la
 * tabla se pinta en un navegador real, con *layout*, *media queries* y
 * foco de verdad, sin añadir ninguna ruta de prueba a la aplicación.
 */
import { createApp, h } from 'vue'
import { i18n } from '../../src/i18n'
import DataTable from '../../src/data-table/components/DataTable.vue'
import { Button } from '../../src/components/ui/button'
import type {
  DataTableColumn,
  DataTableFetcher,
  DataTableFilter,
  DataTableQuery,
} from '../../src/data-table'

export interface FixtureOptions {
  mode?: 'page' | 'cursor'
  mobile?: 'cards' | 'scroll'
  /** Columnas de datos además del nombre y las acciones. */
  extraColumns?: number
  pageCount?: number
  canExport?: boolean
}

interface Row {
  public_id: string
  name: string
  [key: string]: string
}

const FIXTURE_MESSAGES: Record<string, string> = {
  name: 'Nombre',
  status: 'Estado',
  actions: 'Acciones',
}

export function mountFixture(options: FixtureOptions = {}): void {
  const {
    mode = 'page',
    mobile = 'cards',
    extraColumns = 3,
    pageCount = 3,
    canExport = false,
  } = options

  i18n.global.mergeLocaleMessage('es', {
    fixture: {
      ...FIXTURE_MESSAGES,
      ...Object.fromEntries(
        Array.from({ length: extraColumns }, (_, index) => [`col${index}`, `Columna ${index}`]),
      ),
    },
  })

  const columns: DataTableColumn<Row>[] = [
    {
      id: 'name',
      headerKey: 'fixture.name',
      rowHeader: true,
      sortable: true,
      hideable: false,
      card: 'title',
    },
    ...Array.from({ length: extraColumns }, (_, index) => ({
      id: `col${index}`,
      headerKey: `fixture.col${index}`,
      sortable: index === 0,
      card: 'field' as const,
    })),
    { id: 'actions', headerKey: 'fixture.actions', hideable: false, card: 'actions' },
  ]

  const filters: DataTableFilter[] = [
    {
      type: 'enum',
      id: 'status',
      labelKey: 'fixture.status',
      options: [{ value: 'activo' }, { value: 'inactivo' }],
    },
  ]

  function rows(tag: string): Row[] {
    return Array.from({ length: 5 }, (_, index) => ({
      public_id: `${tag}-${index}`,
      name: `Persona ${tag}-${index}`,
      ...Object.fromEntries(
        Array.from({ length: extraColumns }, (_, column) => [
          `col${column}`,
          `Valor largo de la columna ${column} fila ${index}`,
        ]),
      ),
    }))
  }

  let cursorLoads = 0

  const fetcher: DataTableFetcher<Row> = async (query: DataTableQuery) => {
    if (mode === 'cursor') {
      cursorLoads += 1

      return {
        data: rows(`c${cursorLoads}`),
        meta: { next_cursor: `c${cursorLoads}`, has_more: cursorLoads < 10 },
      }
    }

    const page = query.page ?? 1

    return {
      data: rows(`p${page}`),
      meta: { current_page: page, per_page: 25, total: pageCount * 25, last_page: pageCount },
    }
  }

  const host = document.createElement('div')
  host.id = 'fixture-root'
  host.style.padding = '16px'
  document.getElementById('app')?.setAttribute('hidden', '')
  document.body.appendChild(host)

  const app = createApp({
    render: () =>
      h(
        DataTable as never,
        {
          tableId: 'fixture.e2e',
          caption: 'Personas de prueba',
          columns,
          mode,
          mobile,
          fetcher,
          filters,
          searchable: true,
          emptyTitle: 'Sin personas',
          exportConfig: canExport
            ? {
                canExport: true,
                request: async () => ({ public_id: 'export-1' }),
                status: async () => ({
                  public_id: 'export-1',
                  status: 'pendiente',
                  download_url: null,
                  expires_at: null,
                }),
              }
            : undefined,
        },
        {
          'cell-actions': ({ row }: { row: Row }) =>
            h(
              Button,
              { type: 'button', variant: 'outline', size: 'sm', class: 'fixture-action' },
              () => `Acción de ${row.name}`,
            ),
        },
      ),
  })

  app.use(i18n)
  app.mount(host)
}
