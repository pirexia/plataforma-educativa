# SETUP-ENTORNO.md · Puesta en marcha del entorno de desarrollo

> **Versión 1.3.0** · 2026-08-13 
> De un equipo con Windows recién encendido a Claude Code trabajando sobre el repositorio.
> Corresponde a los pasos **0.1, 0.2 y 0.3** de `PLAN-IMPLEMENTACION.md`.

Tiempo estimado: **2-3 horas** la primera vez.

---

## Antes de empezar

| Requisito | Comprobación |
|-----------|--------------|
| Windows 11 con virtualización activada | `systeminfo` debe mostrar Hyper-V disponible |
| 16 GB de RAM | Suficiente con el perfil reducido (`ADR-030`) |
| Cuenta de GitHub | Con verificación en dos pasos activada |
| Suscripción a Claude (plan Pro) | Límite de 5 h por ventana |

> **Regla que gobierna todo este documento** (`ADR-030`): este equipo es entorno de **desarrollo**. Nunca debe contener datos reales de alumnos, familias o personal. Para trabajar con volumen se usa el generador sintético de `seed/`.

---

## Fase 1 · WSL2

### 1.1 Instalar WSL2 con Ubuntu

En PowerShell **como administrador**:

```powershell
wsl --install -d Ubuntu-24.04
wsl --set-default-version 2
```

Reinicia. Al arrancar, Ubuntu pide usuario y contraseña de Linux: no tienen que coincidir con los de Windows.

Comprueba:

```powershell
wsl -l -v          # debe decir VERSION 2
```

### 1.2 Limitar recursos

Con 16 GB compartidos, WSL2 cogería por defecto la mitad y competiría con Windows. Crea `C:\Users\<usuario>\.wslconfig`:

```ini
[wsl2]
memory=10GB
processors=6
swap=4GB
localhostForwarding=true

[experimental]
autoMemoryReclaim=gradual
sparseVhd=true
```

Aplica:

```powershell
wsl --shutdown
```

`autoMemoryReclaim` devuelve a Windows la memoria que WSL deja de usar; sin él, WSL retiene los 10 GB aunque esté ocioso.

### 1.3 Actualizar y herramientas base

Dentro de Ubuntu:

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y git curl wget unzip zip build-essential \
                    ca-certificates gnupg jq tree htop
```

### 1.4 Zona horaria

```bash
sudo timedatectl set-timezone Europe/Madrid
timedatectl
```

No es cosmético: el MFA por TOTP de `REQ-AUTH-003` falla con desfase de reloj, y el diagnóstico es desesperante porque parece un fallo de código.

---

## Fase 2 · Claves SSH y GitHub

### 2.1 Generar la clave

**Dentro de WSL**, no en Windows:

```bash
ssh-keygen -t ed25519 -a 100 -C "andres@plataforma-educativa" -f ~/.ssh/github_ed25519
chmod 600 ~/.ssh/github_ed25519
```

Pon passphrase. Si te copian el fichero, sin ella no sirve.

### 2.2 Agente SSH persistente

Añade a `~/.bashrc`:

```bash
if [ -z "$SSH_AUTH_SOCK" ]; then
  eval "$(ssh-agent -s)" > /dev/null
  ssh-add ~/.ssh/github_ed25519 2>/dev/null
fi
```

### 2.3 Registrar en GitHub

```bash
cat ~/.ssh/github_ed25519.pub
```

Copia la línea completa → GitHub → *Settings* → *SSH and GPG keys* → *New SSH key* → tipo **Authentication Key**.

Verifica:

```bash
ssh -T git@github.com     # "Hi <usuario>! You've successfully authenticated..."
```

### 2.4 Identidad de Git

```bash
git config --global user.name "Andrés"
git config --global user.email "TU_ID+usuario@users.noreply.github.com"
git config --global init.defaultBranch main
git config --global pull.rebase true
git config --global core.autocrlf input
```

> Usa el correo `noreply` de GitHub (lo encuentras en *Settings → Emails*) para no publicar tu dirección real en cada commit.

`core.autocrlf input` evita que Windows meta saltos de línea CRLF en ficheros que se ejecutarán en Linux.

---

## Fase 3 · Podman

RHEL 10 no lleva Docker y el destino de producción usará Podman, así que desarrollamos con Podman para que `compose.yaml` funcione igual en ambos sitios.

```bash
sudo apt install -y podman podman-compose uidmap slirp4netns fuse-overlayfs
podman --version
```

### 3.1 Registros de imágenes

Ubuntu no configura registros por defecto y `podman pull nginx` falla. Crea `~/.config/containers/registries.conf`:

```toml
unqualified-search-registries = ["docker.io", "quay.io", "ghcr.io"]
```

### 3.2 Servicio de usuario

```bash
systemctl --user enable --now podman.socket
export DOCKER_HOST="unix://$XDG_RUNTIME_DIR/podman/podman.sock"
echo 'export DOCKER_HOST="unix://$XDG_RUNTIME_DIR/podman/podman.sock"' >> ~/.bashrc
```

### 3.3 Que siga vivo al cerrar la terminal

```bash
sudo loginctl enable-linger $USER
```

Sin esto, los contenedores mueren al cerrar la última sesión de WSL.

### 3.4 Comprobar

```bash
podman run --rm hello-world
podman network create --subnet 10.89.10.0/24 plataforma-net
podman network ls
```

> Esa red es **externa y no se destruye nunca** (`ADR-028`). `podman compose down` queda prohibido en cualquier entorno que no sea desechable: destruye la red y rompe la resolución de nombres.

---

## Fase 4 · Node.js y PHP

### 4.1 Node por gestor de versiones

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash
source ~/.bashrc
nvm install --lts
nvm alias default lts/*
node -v && npm -v
```

### 4.2 PHP y Composer

PHP se ejecutará en contenedor, pero conviene tenerlo en el host para Artisan y las herramientas de análisis:

```bash
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.4-cli php8.4-xml php8.4-mbstring php8.4-curl \
                    php8.4-zip php8.4-pgsql php8.4-redis php8.4-intl php8.4-gd
php -v

curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

---

## Fase 5 · Claude Code

### 5.1 Instalar

```bash
npm install -g @anthropic-ai/claude-code
claude --version
```

Si npm da problemas de permisos, configura un prefijo de usuario:

```bash
mkdir -p ~/.npm-global
npm config set prefix ~/.npm-global
echo 'export PATH=~/.npm-global/bin:$PATH' >> ~/.bashrc
source ~/.bashrc
```

### 5.2 Autenticar

```bash
cd ~
claude
```

Sigue el flujo de navegador con tu cuenta de plan Pro.

### 5.3 Comprobar la instalación

```bash
claude doctor
```

---

## Fase 6 · Repositorio

### 6.1 Crear en GitHub

Repositorio **privado**, sin README ni `.gitignore` (los aportamos nosotros). Nombre sugerido: `plataforma-educativa`.

### 6.2 Estructura local

```bash
mkdir -p ~/proyectos && cd ~/proyectos
git clone git@github.com:<usuario>/plataforma-educativa.git
cd plataforma-educativa
```

> **Los ficheros van en el sistema de ficheros de Linux (`~/proyectos`), nunca en `/mnt/c`.** El rendimiento entre sistemas de ficheros es pésimo y arruina la experiencia: un `npm install` puede tardar diez veces más.

### 6.3 Volcar los ficheros del proyecto

Copia el contenido entregado respetando exactamente esta estructura. **53 ficheros**:

```
plataforma-educativa/
├── README.md                     · punto de entrada e índice
├── CLAUDE.md                     · normas permanentes, se carga siempre
├── CHANGELOG.md                  · historial de versiones
├── memory.md                     · estado entre sesiones
├── ARCHITECTURE.md               · stack, despliegue, dimensionado
├── PLAN-IMPLEMENTACION.md        · pasos de ejecución
├── .gitignore
├── .mcp.json                     · opcional, ver 7.3 (sin secretos dentro)
│
├── .claude/
│   ├── settings.json             · permisos, con destructivos denegados
│   ├── agents/                   · 9 subagentes
│   │   ├── architect.md              (Opus)
│   │   ├── spec-writer.md            (Opus)
│   │   ├── implementer.md            (Sonnet)
│   │   ├── test-writer.md            (Sonnet)
│   │   ├── security-reviewer.md      (Sonnet)
│   │   ├── db-reviewer.md            (Sonnet)
│   │   ├── doc-reviewer.md           (Sonnet)
│   │   ├── explorer.md               (Haiku)
│   │   └── janitor.md                (Haiku)
│   └── skills/                   · 10 skills propias, cada una con su SKILL.md
│       ├── aislamiento-tenant/
│       ├── permisos-y-roles/
│       ├── datos-personales/
│       ├── contenedores-y-red/
│       ├── postgres-rendimiento/
│       ├── migracion-segura/
│       ├── depuracion/
│       ├── modulo-nuevo/
│       ├── i18n-cuatro-idiomas/
│       └── cierre-de-sesion/
│
├── docs/
│   ├── REQUISITOS-PLATAFORMA-EDUCATIVA.md   · fuente de verdad, v3.1.0
│   ├── SETUP-ENTORNO.md                     · este documento
│   ├── SETUP-CLAUDE-CODE.md                 · plugins, MCP, subagentes
│   ├── adr/
│   │   ├── README.md                        · plantilla y regla de numeración
│   │   ├── ADR-028-topologia-de-red-y-dependencias-de-contenedores.md
│   │   ├── ADR-029-identificadores-publicos-y-convenciones-de-tipos.md
│   │   ├── ADR-030-entorno-de-desarrollo-en-wsl2.md
│   │   ├── ADR-031-alcance-y-fase-del-transporte-escolar.md
│   │   └── ADR-032-fuente-unica-de-autorizaciones-de-recogida.md
│   └── modulos/_PLANTILLA/                  · 5 ficheros por módulo
│       ├── funcional.md
│       ├── datos.md
│       ├── api.md
│       ├── permisos.md
│       └── operacion.md
│
├── seed/                         · generador de datos sintéticos
│   ├── README.md
│   ├── generador.py
│   ├── catalogos.py
│   ├── verificar.py
│   └── salida/                   ⚠ NO SE SUBE (ver más abajo)
│       ├── demo-concertado.json
│       ├── demo-publico.json
│       ├── demo-privado.json
│       └── resumen.json
│
└── marketing/
    ├── presentacion-comercial.pptx
    ├── presentacion-comercial.pdf
    ├── presentacion.js           · generador, para regenerar tras cambios
    └── iconos.js
```

Los ADR **`001` a `027`** no son ficheros: son canónicos en la sección 18 del documento de requisitos, que actúa de índice (`ADR-026`). Del `028` en adelante, fichero propio.

Si los tienes en Windows:

```bash
cp -r /mnt/c/Users/<usuario>/Downloads/entregables/. ~/proyectos/plataforma-educativa/
```

Comprueba que el recuento cuadra:

```bash
find . -type f -not -path './.git/*' | wc -l    # debe dar 53
```

#### Qué NO se sube al repositorio

`.gitignore` ya lo cubre, pero conviene saber por qué:

| Ruta | Motivo |
|------|--------|
| `seed/salida/*.json` | Se regeneran con `python3 generador.py --semilla 2026`. Son 8 MB que no aportan nada al histórico, y el propósito de la semilla reproducible es precisamente no versionarlos. |
| `marketing/slide-*.jpg` | Renders intermedios de revisión visual. |
| `vendor/`, `node_modules/`, builds | Dependencias y artefactos. |
| `.env`, claves, certificados | Nunca. |
| Volcados, exportaciones, datos reales | Nunca, y menos aún en este equipo (`ADR-030`). |

El `.pptx` y el `.pdf` de la presentación **sí se suben**: son entregables, no artefactos.

### 6.4 Verificar antes del primer commit

```bash
git status --short
git check-ignore -v .env 2>/dev/null && echo "OK: .env ignorado"
```

Comprueba que **no** aparece nada que no deba subirse: `.env`, claves, volcados, datos reales.

### 6.5 Primer commit y ramas

```bash
git add .
git status --short | wc -l     # debe dar 49: los 53 menos los 4 JSON de seed/salida

git commit -m "chore: documentación de proyecto, gobierno de Claude Code y generador sintético

- Requisitos v3.1.0 (53 módulos, 32 ADR)
- 9 subagentes y 10 skills propias
- Generador de datos sintéticos de tres centros
- Presentación comercial"

git branch -M main
git push -u origin main

git checkout -b develop
git push -u origin develop
```

### 6.6 Proteger las ramas

En GitHub → *Settings* → *Branches* → *Add rule*:

| Rama | Reglas |
|------|--------|
| `main` | Requerir PR, requerir que pasen las comprobaciones, prohibir push forzado |
| `develop` | Requerir que pasen las comprobaciones, prohibir push forzado |

`CLAUDE.md` ya prohíbe commitear directo en ambas; esto lo hace imposible, no solo desaconsejado.

---

## Fase 7 · Configurar Claude Code en el proyecto

```bash
cd ~/proyectos/plataforma-educativa
claude
```

### 7.1 Comprobar lo que ya viene en el repositorio

Dentro de Claude Code:

```
/agents      → deben aparecer los 9 subagentes con su modelo
/context     → CLAUDE.md debe estar cargado
```

Los 9 subagentes son: `spec-writer` y `architect` (Opus); `implementer`, `test-writer`, `security-reviewer`, `db-reviewer` y `doc-reviewer` (Sonnet); `explorer` y `janitor` (Haiku).

**Si algún subagente de Haiku aparece usando Opus, corrígelo**: consume cuota sin aportar nada.

### 7.2 Plugins

```
/plugin marketplace add laravel/agent-skills
/plugin install laravel@laravel
```

Del catálogo de tu organización, si están disponibles: **Engineering** ahora y **StackHawk HawkScan** antes del cierre de fase 1.

Antes de la primera migración (paso 0.8):

```
/plugin marketplace add timescale/pg-aiguide
```

Con las tres divergencias de `ADR-029` documentadas: `TIMESTAMPTZ` siempre, `text` en lugar de `varchar(n)`, e importes en enteros de céntimos.

### 7.3 Servidores MCP

Ahora mismo, dos: **GitHub** y **Context7**.

#### Ámbitos: elige bien antes de instalar

Claude Code guarda la configuración de MCP en tres ámbitos distintos, y equivocarse es el error más común:

| Ámbito | Dónde vive | Cuándo usarlo |
|--------|-----------|---------------|
| `local` | Solo tu equipo, solo este proyecto | Pruebas |
| `project` | `.mcp.json`, **se versiona** | Configuración compartida del proyecto |
| `user` | Tu equipo, todos los proyectos | Herramientas personales |

Los nombres de los ámbitos han cambiado entre versiones. Comprueba antes con:

```bash
claude mcp add --help
```

#### Paso 1 · Crear el token de GitHub

GitHub → *Settings* → *Developer settings* → *Personal access tokens* → **Fine-grained tokens**.

| Campo | Valor |
|-------|-------|
| Nombre | `claude-code-plataforma-educativa` |
| Caducidad | 90 días (renovable; no elijas "sin caducidad") |
| Repository access | **Only select repositories** → `plataforma-educativa` |

Permisos mínimos para lo que necesitamos según `CLAUDE.md`:

| Permiso | Nivel | Para qué |
|---------|-------|----------|
| Issues | Lectura y escritura | Política de severidad de incidencias |
| Pull requests | Lectura y escritura | Flujo de ramas |
| Contents | Lectura y escritura | Leer y proponer cambios |
| Metadata | Lectura | Obligatorio, se marca solo |

No concedas `Administration` ni acceso a toda la organización. El token es para un repositorio.

Copia el token: **solo se muestra una vez**.

#### Paso 2 · Guardar el token fuera del repositorio

**Nunca escribas el token en un fichero del proyecto.** Guárdalo en un fichero de entorno personal:

```bash
mkdir -p ~/.config/plataforma
cat > ~/.config/plataforma/env << 'EOF'
export GITHUB_TOKEN="github_pat_TU_TOKEN_AQUI"
EOF
chmod 600 ~/.config/plataforma/env
```

Cárgalo al abrir la terminal, añadiendo a `~/.bashrc`:

```bash
[ -f ~/.config/plataforma/env ] && source ~/.config/plataforma/env
```

Recarga y comprueba que existe sin imprimirlo entero:

```bash
source ~/.bashrc
echo "${GITHUB_TOKEN:0:12}..."      # solo el prefijo
```

#### Paso 3 · Registrar el servidor

GitHub mantiene un **servidor oficial remoto**. Es el que hay que usar: el paquete de referencia `@modelcontextprotocol/server-github` quedó descontinuado.

```bash
claude mcp add --transport http github \
  https://api.githubcopilot.com/mcp/ \
  --header "Authorization: Bearer ${GITHUB_TOKEN}"
```

> **Sobre OAuth**: el servidor remoto de GitHub lo soporta, pero el flujo completo funciona con fiabilidad en VS Code, no en Claude Code. Para Claude Code, usa el token. Si en tu versión el flujo de `claude mcp auth github` funciona, mejor todavía: los tokens quedan en el llavero del sistema en lugar de en un fichero.

#### Paso 4 · Comprobar dónde ha quedado el token

Aquí está el detalle que importa. Al pasar `${GITHUB_TOKEN}` en la línea de comandos, **el shell lo expande antes de que Claude Code lo reciba**, así que el valor literal acaba escrito en la configuración:

```bash
grep -o 'github_pat_[A-Za-z0-9_]\{6\}' ~/.claude.json 2>/dev/null && \
  echo "⚠ El token está en claro en ~/.claude.json"
```

Si aparece, tienes dos opciones:

**Opción A · aceptar y proteger el fichero** (suficiente en un equipo personal):

```bash
chmod 600 ~/.claude.json
```

**Opción B · usar `.mcp.json` de proyecto con referencia a la variable** (preferible, y necesaria si algún día hay equipo). Crea `.mcp.json` en la raíz del repositorio:

```json
{
  "mcpServers": {
    "github": {
      "type": "http",
      "url": "https://api.githubcopilot.com/mcp/",
      "headers": {
        "Authorization": "Bearer ${GITHUB_TOKEN}"
      }
    },
    "context7": {
      "command": "npx",
      "args": ["-y", "@upstash/context7-mcp"]
    }
  }
}
```

Claude Code expande `${GITHUB_TOKEN}` **en tiempo de ejecución**, así que el fichero se puede versionar sin exponer nada: lleva la referencia, no el valor. Elimina entonces la entrada duplicada:

```bash
claude mcp remove github
```

#### Paso 5 · Context7

```bash
claude mcp add context7 -- npx -y @upstash/context7-mcp
```

No necesita credenciales. Es el que evita que Claude invente API de versiones que no son las tuyas.

#### Paso 6 · Verificar

```bash
claude mcp list
```

Dentro de Claude Code:

```
/mcp
```

Ambos deben aparecer conectados. Si GitHub da 401, el token ha caducado o le falta permiso; si da error de conexión, revisa que la variable esté cargada en la sesión desde la que lanzas Claude Code.

#### Renovación

El token caduca en 90 días. Cuando ocurra, las herramientas de GitHub dejarán de responder con un 401. Genera uno nuevo, actualiza `~/.config/plataforma/env` y reinicia Claude Code. Con la opción B no hay que tocar nada más.

#### Los tres restantes

| MCP | Cuándo | Nota |
|-----|--------|------|
| Laravel Boost | Tras el paso 0.4 | Antes no tiene nada que leer |
| Playwright | Tras el paso 0.5 | |
| PostgreSQL | Tras el paso 0.8 | **Usuario de solo lectura y nunca contra producción**: dar a un agente capacidad de ejecutar SQL sobre datos de alumnos es un riesgo real |

### 7.4 Prueba de funcionamiento

#### Qué está cargado

```
/context
```

Debe aparecer `CLAUDE.md`. Pregunta además, en lenguaje natural:

> ¿Qué skills tienes disponibles en este proyecto?

Deben salir las diez.

#### Prueba 1 · El contexto permanente

> Resume el estado del proyecto.

Sin que se lo pidas, debe leer `memory.md` y `PLAN-IMPLEMENTACION.md`, y decirte en qué paso estás.

#### Prueba 2 · La Regla 0

> Escribe una consulta que liste los alumnos de un grupo.

**La respuesta correcta es negarse.** No existe esquema, ni migraciones, ni especificación de `REQ-ALUM` o `REQ-ACAD`. Si escribe la consulta sin más, la Regla 0 no está funcionando y hay que revisar por qué no se carga `CLAUDE.md`.

Si se niega citando el plan y ofreciendo alternativas, el gobierno funciona.

#### Prueba 3 · Las skills, de verdad

> ⚠ **Cuidado con las pruebas que no discriminan.** `CLAUDE.md` ya contiene la lista de invariantes, así que si Claude menciona "aislamiento de tenant" **no demuestra** que la skill se haya activado: puede venir del contexto permanente. Hay que preguntar por contenido que esté **solo** en la skill.

| Pregunta | Respuesta que confirma la activación | Skill |
|----------|--------------------------------------|-------|
| ¿Qué devuelve la API si un usuario pide un recurso de otro colegio? | **404, no 403**, para no revelar la existencia del recurso | `aislamiento-tenant` |
| ¿Dónde es más fácil que se escape el filtrado de tenant? | Jobs en cola, comandos de consola, tareas programadas, listeners y seeders: ahí no hay middleware | `aislamiento-tenant` |
| ¿Qué test debe llevar todo módulo nuevo? | Dos tenants con datos equivalentes, verificando que ninguno alcanza los del otro | `aislamiento-tenant` |
| ¿Puedo reiniciar la API sin tocar el frontend? | Sí, porque el frontend no hace de proxy: Traefik enruta. Y `Wants=`, nunca `Requires=` | `contenedores-y-red` |
| ¿Dónde se guardan las personas autorizadas a recoger a un alumno? | En la unidad familiar, `REQ-FAM-UNIT-005`, como lista maestra única | `datos-personales` |
| ¿Qué tipo uso para una marca de tiempo? | `TIMESTAMPTZ`, y ojo con `timestamps()` de Laravel, que genera `timestamp` sin zona | `postgres-rendimiento` |

Cuando una skill se activa, Claude Code la muestra en la línea de herramientas usadas de esa respuesta. Si no aparece ninguna y la respuesta es genérica, revisa que cada skill esté en **su propio directorio** con el fichero `SKILL.md` dentro:

```bash
ls .claude/skills/
```

Diez directorios, no diez ficheros sueltos.

#### Prueba 4 · El MCP de GitHub

> Crea un issue de prueba titulado "Verificación de entorno" y ciérralo.

Debe aparecer en GitHub y cerrarse. Si da 401, revisa el token (punto 7.3).

Si las cuatro pruebas pasan, el paso 0.2 está cerrado.

## Fase 8 · Verificar el generador de datos

```bash
cd ~/proyectos/plataforma-educativa/seed
python3 generador.py --semilla 2026 --salida ./salida
python3 verificar.py
```

Debe terminar con *"Todas las comprobaciones pasan"*. Los ficheros de `seed/salida/` **no se suben al repositorio**: están en `.gitignore` porque se regeneran con la semilla.

---

## Lista de comprobación final

- [ ] `wsl -l -v` muestra Ubuntu en versión 2
- [ ] `.wslconfig` limita la memoria a 10 GB
- [ ] `ssh -T git@github.com` autentica correctamente
- [ ] `podman run --rm hello-world` funciona
- [ ] La red `plataforma-net` existe
- [ ] `loginctl show-user $USER | grep Linger` dice `yes`
- [ ] `node -v`, `php -v` y `composer --version` responden
- [ ] `claude doctor` sin errores
- [ ] Repositorio con `main` y `develop`, ambas protegidas
- [ ] `/agents` lista los 9 subagentes con el modelo correcto
- [ ] `/mcp` lista GitHub y Context7
- [ ] Claude Code se **niega** a escribir código sin especificación (Regla 0)
- [ ] Una pregunta discriminante activa la skill correspondiente
- [ ] Claude Code crea un issue de prueba en GitHub
- [ ] El generador sintético pasa el verificador
- [ ] Ningún dato real en el equipo

---

## Problemas frecuentes

**WSL consume toda la RAM.** Falta `.wslconfig` o no se aplicó `wsl --shutdown` después de crearlo.

**`podman pull` no encuentra la imagen.** Falta `registries.conf` (paso 3.1). El error habla de "short-name resolution".

**Los contenedores mueren al cerrar la terminal.** Falta `loginctl enable-linger` (paso 3.3).

**`npm install` tarda muchísimo.** El proyecto está en `/mnt/c`. Muévelo a `~/proyectos`.

**Git pide la passphrase en cada operación.** El agente SSH no arranca; revisa el bloque de `~/.bashrc` del paso 2.2.

**El MCP de GitHub responde 401.** Token caducado o sin el permiso necesario. Comprueba en GitHub que sigue activo y que tiene Issues, Pull requests y Contents en lectura y escritura.

**El MCP de GitHub no arranca.** La variable `GITHUB_TOKEN` no está cargada en la sesión desde la que lanzaste Claude Code. Ejecuta `source ~/.bashrc` y vuelve a abrir.

**Claude Code no lee `CLAUDE.md`.** Lo has arrancado fuera del directorio del proyecto. Debe ejecutarse desde la raíz del repositorio.

**El MFA rechaza códigos correctos.** Desfase de reloj. WSL2 puede desincronizarse tras suspender el equipo: `sudo hwclock -s`.
