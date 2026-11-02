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
| 1.14b-b | implementer | agente único (en curso) | | | | | sin tramos; referencia de agente largo |

## Resumen (se recalcula al cerrar cada paso)

Provisional, n = 1 paso (`1.14b-a`):

- `implementer`: 849 k tokens y 320 llamadas en total. La primera pasada fue el 86 % de los tokens y el 65 % de las llamadas.
- Revisores: ronda 1 = 402 k tokens; ronda 2 = 272 k (68 % de la ronda 1). La ronda 2 solo se justificó por un Medio de seguridad y los Medios de documentación.
- `verificador` y `traductor`: 30-65 k tokens cada uno; el `verificador` es lento en reloj (12-23 min).
