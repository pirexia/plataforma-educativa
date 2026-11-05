# Se carga con `source` desde e2e-real-api.sh y e2e-real-prl.sh (issue #530). Fija `VITE_API_URL` al subdominio del centro
# de la suite (`http://<slug>.plataforma.test:8000/api/v1`) y la exporta, de modo que el servidor de desarrollo que
# arranca Playwright (`npm run dev`) la use y no la de `apps/web/.env` (`localhost:8000`, un host sin centro: la SPA
# recibe 404 en `/tenant/branding` y los tests de navegador fallan en `signIn`).
#
# Si ya hay una SPA en 127.0.0.1:5173 (Playwright la reutiliza), no se le puede cambiar la variable: se lee la que lleva
# y, si no coincide o no se puede leer, el script termina con código 2 y un mensaje que dice qué hacer.
#
# Uso: prepare_vite_api_url <slug>
prepare_vite_api_url() {
  local slug="$1" expected running
  expected="http://${slug}.plataforma.test:8000/api/v1"

  if curl -s -m 3 -o /dev/null http://127.0.0.1:5173/; then
    running="$(curl -s -m 5 http://127.0.0.1:5173/src/api/client.ts | sed -n 's/.*"VITE_API_URL": *"\([^"]*\)".*/\1/p' | head -n 1)"
    if [[ -z "$running" ]]; then
      echo "Hay una SPA en 127.0.0.1:5173 y no se pudo leer su VITE_API_URL. Detenla y vuelve a lanzar (el script arranca la suya con ${expected}), o arráncala tú con VITE_API_URL=${expected}." >&2
      return 2
    fi
    if [[ "$running" != "$expected" ]]; then
      echo "La SPA de 127.0.0.1:5173 usa VITE_API_URL=${running}, pero la suite necesita ${expected}. Detenla y vuelve a lanzar, o arráncala con esa variable." >&2
      return 2
    fi
  fi

  export VITE_API_URL="$expected"
}
