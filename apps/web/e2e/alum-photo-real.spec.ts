/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import { test, expect, request as playwrightRequest } from '@playwright/test'
import { deflateSync } from 'node:zlib'
import { RealSession } from './fixtures/realSession'
import { createStudent, tag } from './fixtures/alumReal'

/**
 * `docs/modulos/REQ-ALUM/funcional.md` (`CA-ALUM-056`, `CA-ALUM-057`, `CA-ALUM-058`, `RN-ALUM-50` a `-52`) y `ADR-076 §2.3`:
 * la fotografía del alumno **contra la API real y el almacén S3 real (Garage)**. Sube una imagen PNG **sintética** (generada
 * aquí, con un fragmento de texto añadido que simula metadatos; nunca una fotografía de una persona, `REQ-SEED-005`), pide la
 * URL firmada, la descarga **del almacén desde el host** y comprueba que lo guardado es la imagen reprocesada (misma
 * geometría, sin el fragmento de texto), que sin firma o con la firma alterada el almacén no la sirve (`403`), que la URL
 * no expone el host interno ni la clave de objeto en la respuesta de la API, y que sustituir y borrar la foto deja el
 * almacén y la API de acuerdo. El flujo de `alum` no exige consentimiento de imagen en la subida (`RN-ALUM-50`).
 *
 * Solo con `E2E_REAL_API=1`: `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh) levanta Garage (ADR-076) y prepara
 * los centros sintéticos. La caducidad de la URL firmada no se espera aquí (5 min).
 */
const WIDTH = 64
const HEIGHT = 48
const MARKER = 'FAKEMETA-E2E-SINTETICO'

function crc32(buffer: Buffer): number {
  let crc = 0xffffffff

  for (const byte of buffer) {
    crc ^= byte

    for (let k = 0; k < 8; k++) {
      crc = crc & 1 ? (crc >>> 1) ^ 0xedb88320 : crc >>> 1
    }
  }

  return (crc ^ 0xffffffff) >>> 0
}

function chunk(type: string, data: Buffer): Buffer {
  const head = Buffer.alloc(4)
  head.writeUInt32BE(data.length)
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data])
  const tail = Buffer.alloc(4)
  tail.writeUInt32BE(crc32(body))

  return Buffer.concat([head, body, tail])
}

/** PNG RGB de `WIDTH`×`HEIGHT` con degradado, más un fragmento `tEXt` con `MARKER` (metadato ficticio). */
function syntheticPng(): Buffer {
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(WIDTH, 0)
  ihdr.writeUInt32BE(HEIGHT, 4)
  ihdr[8] = 8
  ihdr[9] = 2

  const rows: Buffer[] = []

  for (let y = 0; y < HEIGHT; y++) {
    const row = Buffer.alloc(1 + WIDTH * 3)

    for (let x = 0; x < WIDTH; x++) {
      row[1 + x * 3] = (x * 4) & 255
      row[2 + x * 3] = (y * 5) & 255
      row[3 + x * 3] = 128
    }

    rows.push(row)
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('tEXt', Buffer.from(`Comment\0${MARKER}`, 'latin1')),
    chunk('IDAT', deflateSync(Buffer.concat(rows))),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

test.describe('CA-ALUM-056 CA-ALUM-057 CA-ALUM-058 (REQ-ALUM-003): fotografía del alumno contra la API real y Garage', () => {
  test.skip(
    process.env.E2E_REAL_API !== '1',
    'Necesita la API real, Garage y un centro de pruebas: ejecútalo con `npm run test:e2e:real:alum` (apps/web/scripts/e2e-real-alum.sh).',
  )
  test.describe.configure({ mode: 'serial' })

  const env = (name: string) => process.env[`E2E_A_${name}`] ?? ''
  let admin: RealSession
  let otherCentre: RealSession
  let studentId = ''

  test.beforeAll(async () => {
    test.setTimeout(180_000)
    admin = await RealSession.login(env('SLUG'), env('ADMIN_EMAIL'), env('ADMIN_PASSWORD'))
    studentId = (await createStudent(admin, 'Alumno', `Foto${tag()}`)).id
  })

  test.afterAll(async () => {
    await admin?.dispose()
    await otherCentre?.dispose()
  })

  async function signedUrl(): Promise<{ url: string; expires_at: string }> {
    return admin.json('GET', `students/${studentId}/photo`, 200)
  }

  test('CA-ALUM-056 CA-ALUM-058: la foto subida se descarga de Garage por URL firmada, reprocesada y sin metadatos', async () => {
    await admin.json('GET', `students/${studentId}/photo`, 404)

    await admin.upload('PUT', `students/${studentId}/photo`, 200, {
      file: { name: 'foto-ficticia.png', mimeType: 'image/png', buffer: syntheticPng() },
    })

    const body = await signedUrl()

    // La respuesta de la API solo trae la URL y su caducidad: ni clave de objeto ni host interno.
    expect(Object.keys(body).sort()).toEqual(['expires_at', 'url'])
    expect(body.url).toMatch(/^http:\/\/127\.0\.0\.1:3900\//)
    expect(new URL(body.url).host).toBe('127.0.0.1:3900')
    expect(body.url).not.toContain('plataforma-garage')
    expect(body.url).not.toContain('object_key')
    expect(body.url).toContain('X-Amz-Signature=')

    const store = await playwrightRequest.newContext()
    const image = await store.get(body.url)

    expect(image.status()).toBe(200)
    expect(image.headers()['content-type']).toContain('image/png')

    const bytes = await image.body()

    expect(bytes.subarray(0, 8).toString('hex')).toBe('89504e470d0a1a0a')
    // IHDR: anchura y altura de la imagen original (menores que el lado máximo, no se reescala).
    expect(bytes.readUInt32BE(16)).toBe(WIDTH)
    expect(bytes.readUInt32BE(20)).toBe(HEIGHT)
    // Lo guardado es lo recodificado por el servidor: no es el original y no conserva el fragmento de texto.
    expect(bytes.equals(syntheticPng())).toBe(false)
    expect(bytes.includes(MARKER)).toBe(false)

    // Dos descargas con la misma URL dan los mismos bytes (el objeto es el que sirve el almacén).
    const again = await store.get(body.url)

    expect((await again.body()).equals(bytes)).toBe(true)
    await store.dispose()
  })

  test('CA-ALUM-058 ADR-076 §2.3: sin firma o con la firma alterada el almacén no sirve la imagen', async () => {
    const { url } = await signedUrl()
    const store = await playwrightRequest.newContext()

    // Sin ningún parámetro de firma.
    const unsigned = await store.get(url.split('?')[0])

    expect(unsigned.status()).toBeGreaterThanOrEqual(400)
    expect(unsigned.status()).toBeLessThan(500)

    // Firma alterada (último carácter hexadecimal cambiado).
    const signature = /X-Amz-Signature=([0-9a-f]+)/.exec(url)![1]
    const flipped = signature.slice(0, -1) + (signature.endsWith('0') ? '1' : '0')
    const tampered = await store.get(url.replace(signature, flipped))

    expect(tampered.status()).toBe(403)
    await store.dispose()
  })

  test('CA-ALUM-057: subir otra foto sustituye la anterior (la URL nueva sirve la nueva imagen)', async () => {
    const first = await signedUrl()

    await admin.upload('PUT', `students/${studentId}/photo`, 200, {
      file: { name: 'foto-ficticia-2.png', mimeType: 'image/png', buffer: syntheticPng() },
    })

    const second = await signedUrl()
    const store = await playwrightRequest.newContext()
    const oldKey = new URL(first.url).pathname
    const newKey = new URL(second.url).pathname

    expect(newKey).not.toBe(oldKey)
    expect((await store.get(second.url)).status()).toBe(200)
    // El objeto anterior se borra del almacén al confirmar la transacción.
    expect((await store.get(first.url)).status()).toBe(404)
    await store.dispose()
  })

  test('CA-ALUM-056: un fichero que no es una imagen es 422 y la foto viva no cambia', async () => {
    const before = new URL((await signedUrl()).url).pathname
    const rejected = await admin.upload('PUT', `students/${studentId}/photo`, 422, {
      file: {
        name: 'no-es-imagen.png',
        mimeType: 'image/png',
        buffer: Buffer.from('texto ficticio'),
      },
    })

    expect(JSON.stringify(rejected)).toContain('alum.photo.')
    expect(new URL((await signedUrl()).url).pathname).toBe(before)
  })

  test('CA-ALUM-058 INV-001: el otro centro no ve la foto (404) y borrarla la retira del almacén', async () => {
    otherCentre = await RealSession.login(
      process.env.E2E_B_SLUG ?? '',
      process.env.E2E_B_ADMIN_EMAIL ?? '',
      process.env.E2E_B_ADMIN_PASSWORD ?? '',
    )
    // El administrador de otro centro no la alcanza: 404, sin revelar existencia.
    const denied = await otherCentre.send('GET', `students/${studentId}/photo`)

    expect(denied.status()).toBe(404)

    const { url } = await signedUrl()

    await admin.json('DELETE', `students/${studentId}/photo`, 204)
    await admin.json('GET', `students/${studentId}/photo`, 404)

    const store = await playwrightRequest.newContext()

    expect((await store.get(url)).status()).toBe(404)
    await store.dispose()
  })
})
