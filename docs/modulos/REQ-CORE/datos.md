# REQ-CORE · Modelo de datos

> Cubre `audit_logs` (registro automático desde el paso 0.9, esquema fijado en `ADR-034` §3, política de valores en `ADR-035`). El resto de entidades núcleo (`Person`, `User`, `Role`, `Permission`, `AcademicYear`, `ModuleSubscription`) se documentaron en el cierre de 0.8 (`docs/historial/0.8-modelo-de-datos-nucleo.md`); este fichero no las repite.

## `audit_logs.changes`: formato exacto

Columna `jsonb`, nullable (`NULL` en los eventos `read`/`exported`). Cada clave es el nombre de un atributo que cambió; cada valor es **siempre un objeto**, nunca un escalar, para que ningún consumidor tenga que ramificar por tipo.

Dos formas posibles de entrada, según si el valor se registra o se redacta (`ADR-035` §3/§4):

```jsonc
{
  // Se registra: el atributo está en Full, o en Selective y en la lista
  // de inclusión del modelo.
  "locale": { "from": "es-ES", "to": "en" },

  // Se redacta: el motivo va en "redacted", nunca el valor.
  "document_number": { "redacted": "identifier", "from_empty": false, "to_empty": false },
  "password":        { "redacted": "secret" },
  "diagnosis":        { "redacted": "special", "from_empty": true, "to_empty": false },
  "observations":     { "redacted": "oversized", "from_empty": false, "to_empty": false }
}
```

Los cuatro motivos de redacción, en el orden exacto en que se evalúan (`AuditChangeBuilder`, `app/Support/Audit/AuditChangeBuilder.php`):

| Motivo | Cuándo | Banderas de vacío |
|--------|--------|--------------------|
| `secret` | El atributo está en `auditSecretAttributes()` del modelo, o su nombre encaja con `config('audit.secret_attribute_patterns')` (`*password*`, `*token*`, `*secret*`, `*_key`, `*totp*`, `*recovery_code*`) | No lleva `from_empty`/`to_empty` — ni siquiera se insinúa si había algo antes |
| `special` | El modelo declara política `Redacted` (categoría especial: salud, NEAE, convivencia) | Sí |
| `identifier` | El modelo declara política `Selective` y el atributo no está en su lista de inclusión (`auditRecordedAttributes()`) — fallo en cerrado | Sí |
| `oversized` | El valor codificado en JSON supera `config('audit.max_value_length')` (256 por defecto). Nunca se trunca | Sí |

`from_empty`/`to_empty` son `true` cuando el valor anterior/nuevo es `null` o cadena vacía. Permiten responder "¿se rellenó/borró este campo?" sin conservar el valor.

## Política de valor por modelo (`AuditValuePolicy`)

Todo modelo auditable implementa `App\Support\Audit\Auditable` y declara `auditValuePolicy()` sin valor por defecto:

| Política | Efecto | Modelos del núcleo (0.9) |
|----------|--------|---------------------------|
| `Full` | Se registra el valor de todos los atributos (salvo secretos, siempre absolutos) | `AcademicYear`, `Role`, `ModuleSubscription` |
| `Selective` | Solo se registra el valor de `auditRecordedAttributes()`; el resto se redacta como `identifier` | `Person` (`locale`, `deleted_at`, `created_by`, `updated_by`), `User` (`status`, `email_verified_at`, `deleted_at`, `created_by`, `updated_by`) |
| `Redacted` | Nunca se registra ningún valor | Ningún modelo del núcleo todavía — reservada a categoría especial (salud, NEAE, convivencia), que llega con sus propios módulos |

`Tenant` y `Permission`/`Module` **no son auditables en 0.9** (ver `docs/historial/0.9-auditoria-i18n.md`, "Problemas abiertos"): `Tenant` es una entidad de plataforma sin `tenant_id` propio, y `audit_logs` es una tabla de tenant — su auditoría corresponde a `admin_action_logs` (paso 1.6, `ADR-033` §7), no a este mecanismo. `Permission`/`Module` son catálogos de referencia gestionados por `platform:sync-registry`, fuera del ámbito de auditoría por tenant.

## Columnas estructurales excluidas de `changes`

`id`, `tenant_id` y `public_id` nunca aparecen dentro de `changes`, aunque estén "sucios" en el momento del evento: ya están representados en las columnas propias de la fila (`auditable_id`, el `tenant_id` de la propia fila de `audit_logs`, `auditable_public_id`). Incluirlos añadiría entradas `redacted: identifier` sin ninguna información nueva.

## Eventos y su relación con `changes`

| `event` | `changes` |
|---------|-----------|
| `created` | Todos los atributos asignados en el alta (política de redacción aplicada igual que en `updated`) |
| `updated` | Solo los atributos que cambiaron. El `updated` interno que dispara el borrado/restauración lógicos (`SoftDeletes`) **se suprime**: lo registran `deleted`/`restored` para no duplicar fila |
| `deleted` | `{"deleted_at": {"from": null, "to": "<timestamp>"}}` |
| `restored` | `{"deleted_at": {"from": "<timestamp>", "to": null}}` |
| `read`, `exported` | `NULL` — la auditoría de lectura de categoría especial registra que se leyó, nunca lo que decía |

## Retención y supresión

Ver `PRIVACY.md` §5 y `docs/adr/ADR-035-datos-personales-en-el-registro-de-auditoria.md`. Resumen: el derecho de supresión no se ejerce editando una fila de `audit_logs` (la tabla es inmutable sin excepciones); se ejerce evitando que el valor identificativo entre en la fila desde el origen, y la supresión efectiva llega por vencimiento del plazo de retención (`REQ-CORE-005`, mínimo dos años). La purga por retención es trabajo de `REQ-PRIV-006`, no implementado todavía.
