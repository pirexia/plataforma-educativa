import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  // Sin reintentos tampoco en CI: un reintento silencioso esconde los intermitentes
  // (un test que falla y pasa al reintentar se da por bueno). Si un E2E es
  // intermitente se abre un issue; no se tapa (#749, docs/proceso/e2e-real-ci.md D5).
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: 'http://127.0.0.1:5173',
    trace: 'retain-on-failure',
    // ADR-021: es-ES es el idioma por defecto de la interfaz. Sin esto el
    // locale del navegador lo decide el runner (en-US en CI) y el e2e
    // dejaría de validar el idioma por defecto real.
    locale: 'es-ES',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
  webServer: {
    command: 'npm run dev -- --host 127.0.0.1 --port 5173',
    url: 'http://127.0.0.1:5173',
    reuseExistingServer: !process.env.CI,
  },
})
