/* eslint-disable @typescript-eslint/no-explicit-any -- cuerpos JSON de la API real, sin tipar a propósito. */
import { expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test'

/**
 * Cliente HTTP con cookie de sesión y CSRF reales contra la API real (mismo patrón que `prl-door-api.spec.ts`), para preparar
 * datos de un E2E de navegador. El centro se alcanza por `127.0.0.1:8000` con la cabecera `Host` de su subdominio (ADR-014).
 */
const API = process.env.E2E_API_URL ?? 'http://127.0.0.1:8000/api/v1'
const BASE_DOMAIN = process.env.E2E_BASE_DOMAIN ?? 'plataforma.test'

export class RealSession {
  private constructor(readonly ctx: APIRequestContext) {}

  static async login(slug: string, email: string, password: string): Promise<RealSession> {
    const ctx = await playwrightRequest.newContext({
      baseURL: `${API}/`,
      extraHTTPHeaders: { Host: `${slug}.${BASE_DOMAIN}:8000`, Accept: 'application/json' },
    })
    const session = new RealSession(ctx)

    await ctx.get('auth/csrf-cookie')
    const response = await ctx.fetch('auth/session', {
      method: 'POST',
      headers: { 'X-XSRF-TOKEN': await session.xsrf() },
      data: { email, password },
    })
    expect(response.status(), `login de ${email}: ${await response.text()}`).toBeLessThan(300)

    return session
  }

  private async xsrf(): Promise<string> {
    const cookies = (await this.ctx.storageState()).cookies

    return decodeURIComponent(cookies.find((c) => c.name === 'XSRF-TOKEN')?.value ?? '')
  }

  async json<T = Record<string, any>>(
    method: string,
    path: string,
    expected: number,
    data?: unknown,
  ): Promise<T> {
    const response = await this.ctx.fetch(path, {
      method,
      headers: method === 'GET' ? {} : { 'X-XSRF-TOKEN': await this.xsrf() },
      data: data as never,
    })
    const text = await response.text()

    expect(response.status(), `${method} ${path} → ${response.status()}: ${text}`).toBe(expected)

    return (text === '' ? {} : JSON.parse(text)) as T
  }

  async dispose(): Promise<void> {
    await this.ctx.dispose()
  }
}
