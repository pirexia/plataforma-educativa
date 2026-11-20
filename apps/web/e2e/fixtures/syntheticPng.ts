import { deflateSync } from 'node:zlib'

/**
 * PNG RGB **sintético** (degradado, nunca una fotografía de una persona, `REQ-SEED-005`) de `width`×`height` para subir como
 * fotografía en los E2E de navegador. La API la reprocesa conservando la geometría, de modo que la anchura permite distinguir una
 * imagen de otra (`pngWidth`).
 */
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

export function syntheticPng(width: number, height: number): Buffer {
  const ihdr = Buffer.alloc(13)
  ihdr.writeUInt32BE(width, 0)
  ihdr.writeUInt32BE(height, 4)
  ihdr[8] = 8
  ihdr[9] = 2

  const rows: Buffer[] = []

  for (let y = 0; y < height; y++) {
    const row = Buffer.alloc(1 + width * 3)

    for (let x = 0; x < width; x++) {
      row[1 + x * 3] = (x * 4) & 255
      row[2 + x * 3] = (y * 5) & 255
      row[3 + x * 3] = 128
    }

    rows.push(row)
  }

  return Buffer.concat([
    Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
    chunk('IHDR', ihdr),
    chunk('IDAT', deflateSync(Buffer.concat(rows))),
    chunk('IEND', Buffer.alloc(0)),
  ])
}

/** Anchura (píxeles) de un PNG: campo `width` del fragmento `IHDR`. */
export function pngWidth(bytes: Buffer): number {
  return bytes.readUInt32BE(16)
}
