import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    // Issue #71: la SPA debe servirse desde el mismo host que la API
    // (resolución de tenant por subdominio, ADR-014) para que
    // document.cookie pueda leer XSRF-TOKEN. `allowedHosts` de Vite
    // bloquea por defecto cualquier Host que no sea localhost/IP/`.local`
    // (protección contra DNS rebinding) — sin esto, un navegador que entra
    // por demo.plataforma.test:5173 recibe 403 del propio Vite antes de
    // llegar a la aplicación. TENANCY_BASE_DOMAIN es fijo en desarrollo
    // (plataforma.test); un comodín cubre cualquier tenant sin listar cada
    // slug a mano.
    allowedHosts: ['.plataforma.test'],
  },
})
