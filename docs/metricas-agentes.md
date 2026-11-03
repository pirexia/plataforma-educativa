# Métricas de subagentes

Registro del gasto de cada subagente, para calibrar el tamaño de los tramos de `implementer` y el uso de revisores (norma de ahorro de cuota, `CLAUDE.md` sección 2, punto 4).

**Cómo se mantiene.** Al terminar cada subagente se añade una fila con los datos de su aviso de fin (`subagent_tokens`, `tool_uses`, `duration_ms`). En una reanudación el aviso muestra cifras **acumuladas** del agente: se anota el **delta**. Al cerrar cada paso se recalculan media, mediana y tokens por llamada **por tipo de agente** (sección «Resumen») y se decide el número de tramos del paso siguiente. Con menos de 5 observaciones por tipo, las cifras son orientativas.

**Limitación.** `subagent_tokens` parece contar solo tokens nuevos (entrada no cacheada y salida), no las lecturas de caché: una reanudación muestra pocos tokens aunque haga decenas de llamadas. Sirve para comparar tramos entre sí, no como coste real. Si el cliente muestra el consumo de cuota de un paso, anotarlo en «Notas».

## Registro

| Paso | Agente | Tramo / pasada | Tokens | Llamadas | Min | Tok/llamada | Notas |
|------|--------|----------------|-------:|---------:|----:|------------:|-------|
| 1.14b-a | implementer | P1 implementación | 727282 | 209 | 97.7 | 3480 | 13 endpoints, 3 migraciones, Core |
| 1.14b-a | implementer | P2 correcciones (S3, límites, índices, docs) | 86340 | 65 | 58.2 | 1328 | reanudación |
| 1.14b-a | implementer | P3 correcciones (N1, tests, docs) | 35504 | 46 | 39.4 | 772 | reanudación |
| 1.14b-a | traductor | 26 claves × 3 idiomas | 65742 | 22 | 1.5 | 2988 | |
| 1.14b-a | traductor | 1 clave | 30285 | 12 | 0.5 | 2524 | |
| 1.14b-a | verificador | suite completa (rojo por `gd` ausente) | 35229 | 12 | 11.6 | 2936 | |
| 1.14b-a | verificador | suite completa (verde) | 36419 | 13 | 23.1 | 2802 | |
| 1.14b-a | db-reviewer | ronda 1 | 88525 | 14 | 2.0 | 6323 | |
| 1.14b-a | security-reviewer | ronda 1 | 185996 | 39 | 5.9 | 4769 | |
| 1.14b-a | doc-reviewer | ronda 1 | 127967 | 25 | 3.1 | 5119 | |
| 1.14b-a | db-reviewer | ronda 2 | 61726 | 4 | 0.6 | 15432 | |
| 1.14b-a | security-reviewer | ronda 2 | 101950 | 15 | 2.3 | 6797 | |
| 1.14b-a | doc-reviewer | ronda 2 | 108809 | 20 | 2.6 | 5440 | |
| proceso | architect (Opus) | ADR-069 Codex | 137258 | 27 | 5.1 | 5084 | lee ADR-049/050, skill y agentes |
| proceso | implementer | recorte CLAUDE.md y memory.md | 140117 | 22 | 4.8 | 6369 | 3 commits, solo documentación |
| 1.14b-b | implementer | P1 implementación (agente único, sin tramos) | 632476 | 197 | 70.7 | 3211 | módulo prl, E2E real; referencia de agente largo |
| 1.14b-b | implementer | P2 correcciones (M1, docs) | 51243 | 32 | 16.6 | 1601 | reanudación; tokens = delta; llamadas y min tal como los reporta el aviso |
| 1.14b-b | implementer | P3 ajuste S3 | 15863 | 9 | 6.2 | 1763 | reanudación |
| 1.14b-b | traductor | 25 claves × 3 idiomas | 54572 | 17 | 1.0 | 3210 | |
| 1.14b-b | traducción directa (sesión) | 1 clave × 3 idiomas | ~3000 | 4 | <1 | n/d | regla de traducciones pequeñas |
| 1.14b-b | doc-precheck | pasada previa | 83036 | 12 | 1.1 | 6920 | |
| 1.14b-b | db-reviewer | ronda única | 76023 | 5 | 1.3 | 15205 | 0 C/A/M |
| 1.14b-b | security-reviewer | ronda única | 145515 | 18 | 3.1 | 8084 | 2 Medios |
| 1.14b-b | doc-reviewer | ronda única | 103117 | 18 | 2.2 | 5729 | 3 Medios |
| 1.14b-b | verificador | suite completa + E2E real | 37742 | 17 | 11.7 | 2220 | |
| 1.14b-c (spec) | spec-writer (Opus) | P1 especificación UI §23 | 197804 | 37 | 7.9 | 5346 | clasificación crítico; partición c1/c2 |
| 1.14b-c (spec) | spec-writer (Opus) | P2 enmiendas tras doc-reviewer | 75177 | 31 | 4.2 | 2425 | reanudación; tokens = delta (273k acumulado) |
| 1.14b-c (proceso) | general-purpose (Sonnet) | 3 skills nuevas | 91759 | 14 | 1.5 | 6554 | frontend-spa, auditoria, colas-y-workers |
| 1.14b-c (spec) | doc-precheck | pasada 1 | 86340 | 11 | 0.9 | 7849 | 4 hallazgos |
| 1.14b-c (spec) | doc-reviewer | ronda única | 194526 | 41 | 4.0 | 4744 | 0 C/A, 7 Medios, 12 Bajos |
| 1.14b-c (spec) | doc-precheck | pasada 2 (sobre enmiendas) | 110265 | 15 | 1.6 | 7351 | 2 menores |
| 1.14b-c1 | implementer | T1 lectura | 174266 | 40 | 6.2 | 4357 | 16 ficheros, 1634 líneas; sin incidencias |
| proceso | implementer | aplicar ADR-069 (skill, docs) | 106801 | 16 | 2.4 | 6675 | |

## Resumen (se recalcula al cerrar cada paso)

Provisional, n = 1 paso (`1.14b-a`):

- `implementer`: 849 k tokens y 320 llamadas en total. La primera pasada fue el 86 % de los tokens y el 65 % de las llamadas.
- Revisores: ronda 1 = 402 k tokens; ronda 2 = 272 k (68 % de la ronda 1). La ronda 2 solo se justificó por un Medio de seguridad y los Medios de documentación.
- `verificador` y `traductor`: 30-65 k tokens cada uno; el `verificador` es lento en reloj (12-23 min).

## Codex (`ADR-069`)

Solo cuota de OpenAI; no se mide en tokens de Claude. Registrar por revisión: paso, duración, hallazgos por severidad, válidos frente a falsos positivos y hallazgos que Claude encuentra después y Codex no.

| Paso | Subcomando | Hallazgos | Válidos | Falsos positivos | Notas |
|------|------------|-----------|--------:|-----------------:|-------|
| 1.14b-b | adversarial-review --base develop | 1 Medio | 1 | 0 | carrera `PickupAlertService::raise()` (alumno borrado entre validación e `INSERT`); misma clase que `OPEN-FAM-UNIT-51`; llegó tras la revisión de Claude, que no lo vio; revisión de calibración sin ahorro |
