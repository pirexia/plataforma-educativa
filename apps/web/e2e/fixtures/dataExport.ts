import { expect, request as playwrightRequest, type APIResponse } from '@playwright/test'

/**
 * Descarga real de una exportación (`GET /data-exports/{id}`, `CA-ALUM-041/042`, `ADR-076 §2.3`): el trabajo corre en el worker, escribe el
 * CSV en el almacén S3 (Garage) y la API devuelve una URL firmada que se descarga **desde el host** del test, sin reescribir. La exportación
 * tiene que terminar: un estado distinto de `completada` o una URL no alcanzable hacen fallar el test (no hay rama «no probada»).
 */
export interface ExportClient {
  send(method: string, path: string): Promise<APIResponse>
}

export async function downloadExportCsv(
  client: ExportClient,
  exportId: string,
  timeoutMs = 60_000,
): Promise<string> {
  const deadline = Date.now() + timeoutMs
  let status = 'pendiente'
  let downloadUrl = ''

  for (;;) {
    const response = await client.send('GET', `data-exports/${exportId}`)

    if (response.status() === 200) {
      const body = await response.json()
      status = body.status
      downloadUrl = body.download_url ?? ''

      break
    }

    // 409: la exportación aún no está lista.
    expect(response.status(), `data-exports/${exportId}: ${await response.text()}`).toBe(409)
    expect(Date.now(), 'la exportación no terminó a tiempo: ¿worker parado?').toBeLessThan(deadline)
    await new Promise((resolve) => setTimeout(resolve, 2000))
  }

  expect(status).toBe('completada')
  expect(downloadUrl).toMatch(/^http:\/\/127\.0\.0\.1:3900\//)

  const store = await playwrightRequest.newContext()
  const file = await store.get(downloadUrl)

  expect(file.status(), 'la URL firmada de la exportación debe servir el CSV desde Garage').toBe(
    200,
  )

  const csv = (await file.text()).replace(/^﻿/, '')

  await store.dispose()

  return csv
}
