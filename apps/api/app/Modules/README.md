# app/Modules/

Un directorio por bounded context (`ARCHITECTURE.md` §3). Cada módulo:

```
<Modulo>/
├── Domain/           # entidades, value objects, reglas de negocio puras
├── Application/       # casos de uso, DTOs
├── Infrastructure/    # Eloquent, repositorios concretos, <Modulo>ServiceProvider.php
└── Http/              # controladores, requests, resources
```

- **Ningún módulo importa código interno de otro** (`INV-007`). La comunicación es por interfaces públicas o eventos de dominio.
- El `ServiceProvider` de cada módulo se autodescubre por convención: `Infrastructure/<Modulo>ServiceProvider.php`, namespace `App\Modules\<Modulo>\Infrastructure`. No se registra a mano en `bootstrap/providers.php` — lo hace `App\Support\Modules\ModuleServiceProviderDiscovery`.
- Las rutas de negocio de un módulo se registran desde su propio `ServiceProvider` (o se incluyen desde `routes/api-v1.php`), nunca sueltas en `routes/`.

Vacío hasta el paso 1.1 (`REQ-CORE`), primer módulo real.
