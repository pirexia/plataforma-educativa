# REQ-CALIF · Operación

> **Estado**: PROPUESTO (2026-11-12) junto con `funcional.md`. Nada de esto existe todavía. Lo relativo al servicio `pdf`, a su red y a su unidad Quadlet es de `ADR-078` (propuesta) y de su propio paso (`OPEN-CALIF-20`): aquí solo consta lo que este módulo añade.

## 1. Variables de entorno

Ninguna nueva en `1.16`. En `1.17-a`, ninguna propia del módulo: la dirección del servicio `pdf` y sus tiempos de espera son configuración de `App\Support\Pdf` (`ADR-078`). Parámetros del módulo en `config/calif.php`, con valores por defecto y sin secretos:

| Clave | Propuesta | Uso |
|-------|-----------|-----|
| `calif.grades.max_items` | 100 | Tope de `RN-CALIF-33` |
| `calif.grades.max_cells` | 2.000 | Tope de la rejilla (`api.md §2`) |
| `calif.report_cards.queue` | `calif-pdf` | Cola de generación |
| `calif.report_cards.tries` / `backoff` | A fijar con la medición de `ADR-078 §5.4` | Reintentos ante `PdfRendererUnavailable` |
| `calif.report_cards.url_ttl` | La caducidad corta ya usada por `App\Support\Files` | URL firmada de descarga |

## 2. Servicios externos de los que depende

| Servicio | Entrega | Si no responde |
|----------|---------|----------------|
| PostgreSQL, Redis | Todas | Como el resto de la aplicación |
| Servicio `pdf` (interno, `ADR-078`) | `1.17-a` | Los trabajos reintentan con espera; la emisión sigue `en_curso`. La API **no** se degrada: pedir una emisión sigue respondiendo `202`. `Wants=` + `After=` desde `worker`, nunca `Requires=` (`ADR-028`) |
| Almacén de objetos S3 (`ADR-076`) | `1.17-a` | La escritura del objeto falla ⇒ el trabajo reintenta; la descarga responde el error del almacén sin URL |
| Motor de notificaciones (`ADR-079`) y su proveedor de correo | `1.17-b` | El aviso se reintenta según `ADR-079`; **la publicación no depende del aviso**: publicar confirma aunque el aviso falle |
| Prestador de firma | No aplica | N1 se registra en la propia plataforma (`ADR-082`) |

Ningún dato del módulo sale a un tercero salvo el aviso de `1.17-b`, que no lleva calificaciones.

## 3. Despliegue: registro de módulos y permisos (`ADR-034 §5`)

- `platform:sync-registry` materializa el descriptor `calif` (`depends_on` de `OPEN-CALIF-01`), su catálogo de permisos (`permisos.md §2`) y ningún *feature flag*. Aborta si `depends_on` cita un código inexistente o forma ciclo: `calif` depende de `curso`, `acad` y `alum`, y ninguno de ellos depende de `calif` (los vetos y validaciones se registran por interfaces de **sus** `Domain`, en el sentido permitido).
- Tras el despliegue de cada entrega con permisos nuevos: `php artisan calif:grant-permissions` para los centros existentes (idempotente). Sin él, los roles predefinidos de esos centros no ven el módulo.
- Contratar `calif` en un centro exige `curso`, `acad` y `alum` contratados (`RMOD-006`); el backoffice lo resuelve en la vista previa de impacto.
- **Migraciones expand/contract** (`CLAUDE.md §9`): todas las de este módulo son tablas nuevas; ninguna modifica tablas existentes. Las ampliaciones de `acad` y `alum` (`funcional.md §7.3`) son de código, sin migración. Reversión de cada entrega: revertir el despliegue y, si procede, `migrate:rollback` de sus tablas, que solo es seguro **antes** de que un centro haya escrito calificaciones; después, la reversión es de código y las tablas se quedan (se documenta en el procedimiento de la entrega).
- Si `sync-registry` aborta: no se abre tráfico; se corrige el descriptor y se repite.

## 4. Tareas programadas y jobs

| Trabajo | Cola y proceso | Disparo | Notas |
|---------|----------------|---------|-------|
| Generación de un boletín (`1.17-a`) | `calif-pdf`, en el proceso `worker` existente, **al final** de su lista de prioridad (`ADR-078 §5.3`, opción Q1) | `POST /report-card-runs`, agrupados con `Bus::batch` | Uno por boletín; carga = identificadores; fija centro e idioma; idempotente; cabe en `--timeout=120`. La cola se añade a la orden del `worker` en `compose.yaml` y en `infra/quadlet/worker.container`, y a `core:queue-health` (`QueueWorkerTest` lo exige). Pasar a un proceso dedicado solo con la medición en la mano (`OPEN-PDF-09`) |
| Cierre de la emisión | Retrollamada del lote | Fin del lote | Fija `completada`, `completada_con_errores` o `fallida` y los recuentos |
| Aviso a tutores legales (`1.17-b`) | La que defina `ADR-079` | Evento `GroupEvaluationPublished` | Un aviso por destinatario y alumno |
| Tareas programadas | — | — | **Ninguna.** No hay cierre ni publicación automáticos por fecha [DERIVADA: ningún requisito lo pide] |

**Compatibilidad entre versiones** (`CLAUDE.md §9`): un trabajo encolado por la versión anterior se resuelve con la plantilla vigente porque su carga son identificadores. Un cambio de plantilla **no** altera boletines ya emitidos.

**Emisión huérfana** [DERIVADA, lección de `RN-CURSO-85`]: si el `worker` muere, una emisión puede quedar `en_curso` y bloquear nuevas emisiones del grupo (`RN-CALIF-50`). Se especifica en `1.17-a` un comando `calif:cancel-stale-report-card-runs` que marca `fallida` la que lleve más de un umbral sin progreso; el umbral se fija con la medición.

## 5. Métricas y alertas

Propuesta, a concretar cuando exista la pila de observabilidad:

- Trabajos de `calif-pdf` pendientes y antigüedad del más viejo (ya cubierto por `core:queue-health` al registrar la cola).
- Boletines `fallido` por emisión; duración por boletín (mediana y percentil 95, las mismas cifras de `ADR-078 §5.4`).
- Emisiones `en_curso` más allá del umbral.
- Los registros **no** contienen nombres, calificaciones, comentarios ni HTML: solo identificadores, recuentos, duración y código de error (`ADR-078 §6.2` control 7).

Picos previsibles (`RNF-PERF-008`): la publicación de notas concentra lecturas de familias; la consulta de `unidad_familiar` usa los índices de `datos.md §3`. `RNF-PERF-007` (boletines de un curso completo en cola sin afectar al tiempo de respuesta general) se comprueba con `CA-CALIF-050` y `-063`.

## 6. Problemas conocidos y diagnóstico

| Síntoma | Causa probable | Qué mirar |
|---------|----------------|-----------|
| El docente recibe `409 scale_not_assigned` | Falta la asignación de escala de la etapa | `GET /grading-scale-assignments` |
| El docente no ve su clase | Sin asignación docente viva en ese curso académico, o rol sin ámbito `clase` | Ficha del grupo en `acad`; permisos efectivos del usuario |
| La familia no ve notas | Evaluación sin publicar, o reabierta; vínculo no activo | `GET /group-evaluations/{id}/transitions`; vínculo en `fam_unit` |
| No se puede cerrar el curso | Evaluaciones abiertas o sin publicar (`RN-CALIF-28`) | El `409` de `curso` trae los recuentos |
| No se puede borrar una escala, un grupo o una asignatura | Vetos de `calif` (`RN-CALIF-14`, `-17`, `-29`) | `params.dependents` del `409` |
| Boletines en `pendiente` indefinidamente | `worker` sin la cola `calif-pdf`, o servicio `pdf` caído | `core:queue-health`; estado del contenedor `pdf` |
| Boletín `fallido` con `PdfRenderingFailed` | La plantilla o un dato rompe el maquetado | `failure_code`; reproducir con `FakePdfRenderer` desactivado en local |
| Códigos de error con prefijo `core.` | Issue #60 | Comprobar en implementación |

Límite conocido heredado (#597): con `calif` descontratado en un centro, sus vetos no se ejecutan (`funcional.md §9`).

## 7. Impacto en copias de seguridad y restauración

- Las tablas entran en el PITR y en la exportación lógica por centro (`ADR-022`) sin tratamiento especial.
- **Los PDF viven en el almacén de objetos, no en la base**: una restauración de la base a un punto anterior puede dejar filas `report_cards` sin objeto (objeto creado después del punto) o al revés. El objeto huérfano lo limpia la purga; la fila sin objeto se detecta porque la descarga falla, y se reemite. El procedimiento de `1.26` debe contemplarlo y las copias del almacén deben cubrir el prefijo de boletines (`ADR-076`).
- Restaurar una copia anterior a una **retirada de publicación** devuelve la visibilidad a las familias: el procedimiento de restauración debe revisar las evaluaciones reabiertas después del punto de la copia (`datos.md §5`, precedente `OPEN-FAM-UNIT-21`).
- La purga física de un centro (`ADR-077`, propuesta) incluye las tablas de este módulo y el prefijo de boletines.

## 8. E2E contra la API real (`CLAUDE.md §3`)

Cada entrega con *endpoints* lleva su E2E real (HTTP con cookie de sesión y CSRF) ejecutado por `verificador`: `1.16-a` (configurar, abrir, calificar como docente, denegación de otra clase), `1.16-b` (cerrar, publicar, lectura como tutor legal antes y después), `1.17-a` (emisión y descarga desde el almacén, `CA-CALIF-064`), `1.16-d` y `1.17-b` de navegador. El S3 real del proveedor no se prueba en desarrollo (`ADR-076 §2.3`): se dice en el informe del paso.
