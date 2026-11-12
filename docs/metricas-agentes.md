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
| 1.14b-c1 | implementer | T2 escritura | 87130 | 32 | 4.2 | 2723 | reanudación; delta de 261396 acumulado; 21 tests nuevos |
| 1.14b-c1 | traductor | 120 claves × 3 idiomas | 82128 | 14 | 1.9 | 5866 | 7 [NT] dejadas; list.empty revertida a es-ES |
| 1.14b-c1 | implementer | T3 E2E real | 46821 | 24 | 6.6 | 1951 | reanudación; delta |
| 1.14b-c1 | verificador | suite web tras T3 | 27642 | 10 | 2.3 | 2764 | 1 rojo (falso positivo roleLiterals), corregido por la sesión |
| 1.14b-c1 | security-reviewer | ronda única, columna acotada | 130561 | 21 | 2.3 | 6217 | 0 C/A; 2 Bajos + 1 observación |
| 1.14b-c1 | implementer | correcciones de revisión (ronda 1) | 11000 | 7 | 2.1 | n/d | reanudación; delta aprox. |
| 1.14b-c1 | implementer | corrección de carrera (ronda 2) | 9200 | 7 | 2.3 | n/d | reanudación; delta aprox. |
| 1.14b-c2 | implementer | T1 módulo web y lectura | 196982 | 43 | 9.5 | n/d | 30 ficheros; 50 tests |
| 1.14b-c2 | implementer | T1b nombres en alertas (API) y filtro fecha | 122350 | 31 | 5.5 | n/d | decisión del usuario |
| 1.14b-c2 | implementer | T2 salida, entrada y alerta | 205803 | 50 | 12.3 | n/d | 31 tests nuevos |
| 1.14b-c2 | traductor | 93 claves × 3 idiomas | 77710 | 20 | 2.2 | n/d | 8 [NT] dejadas |
| 1.14b-c2 | implementer | T3 E2E real | 152085 | 38 | 10.3 | n/d | 3/3; S3 falso |
| 1.14b-c2 | implementer | #518 y suites completas | 87183 | 27 | 12.2 | n/d | Pest 2210 y Vitest 2000 |
| 1.14b-c2 | security-reviewer | lista completa (sin Codex) | 115441 | 22 | 2.5 | n/d | 0 C/A; 2 Bajos; 1 duda de auditoría |
| 1.14b-c2 | implementer | correcciones B-1 y B-2 | 68183 | 9 | 3.2 | n/d | |
| 1.14b-c2 | doc-precheck | ronda 1 | 111236 | 12 | 1.6 | n/d | |
| 1.14b-c2 | implementer | auditoría del listado de alertas y docs | 94927 | 23 | 4.6 | n/d | decisión del usuario |
| 1.14b-c2 | doc-reviewer | ronda 1 | 121728 | 32 | 3.1 | n/d | 0 C/A; 4 Medios; 4 Bajos |
| 1.14b-c2 | verificador | suite backend completa | 31284 | 13 | 9.8 | n/d | cifras intermedias; web no ejecutable (sin contenedor) |
| 1.14b-c2 | implementer | correcciones doc-reviewer y suite web | 79899 | 12 | 2.8 | n/d | |
| 1.14b-c2 | doc-precheck | ronda 2 | 110949 | 15 | 1.7 | n/d | 1 residuo, corregido por la sesión |
| 1.14b-d1 | spec-writer | especificación (Opus) | 196072 | 32 | 6.0 | n/d | propone partición `-d1`..`-d4`; 5 OPEN-PRL |
| 1.14b-d1 | implementer | T1 datos (migración) | 146377 | 48 | 23.9 | n/d | 576 Pest afectados |
| 1.14b-d1 | implementer | T2 bloqueo | 173499 | 54 | 13.1 | n/d | `Verificado` con cifra inflada (solapamiento) |
| 1.14b-d1 | db-reviewer | migración | 96199 | 10 | 1.6 | n/d | 0 C/A/M; 3 Bajos |
| 1.14b-d1 | security-reviewer | lista completa (sin Codex) | 107794 | 15 | 2.5 | n/d | 0 C/A; 1 Medio (tests de baja de vínculo/tutor); 4 Bajos |
| 1.14b-d1 | verificador | suite completa (1.ª) | 29655 | 6 | 12.4 | n/d | 1 fallo real de T2 (`grantFor()` compartida) |
| 1.14b-d1 | implementer | correcciones únicas | 112934 | 27 | 6.0 | n/d | 313 Pest afectados |
| 1.14b-d1 | verificador | suite completa (2.ª) | 33452 | 13 | 9.7 | n/d | 2161 + 65 Pest |
| 1.14b-d1 | doc-precheck | ronda 1 | 95212 | 10 | 1.1 | n/d | CHANGELOG y estados |
| 1.14b-d1 | verificador | E2E real (x2) | 81085 | 20 | 5.3 | n/d | fallo de entorno: `VITE_API_URL`; 3/3 al exportarla |
| 1.14b-d1 | doc-reviewer | ronda 1 | 131955 | 23 | 1.8 | n/d | 0 C/A; 5 Medios; 6 Bajos |
| 1.14b-d1 | implementer | correcciones doc-reviewer | 97122 | 15 | 1.9 | n/d | |
| 1.14b-d1 | doc-precheck | ronda 2 | 98539 | 13 | 1.2 | n/d | 3 residuos, corregidos por la sesión |
| proceso | implementer | aplicar ADR-069 (skill, docs) | 106801 | 16 | 2.4 | 6675 | |
| 1.15 (spec) | spec-writer (Opus) | especificación REQ-ALUM | 318164 | 58 | 13.3 | n/d | clasificación crítico; partición `-a`..`-d`; 30 OPEN-ALUM (8 bloqueantes resueltas) |
| 1.15 (spec) | doc-precheck | ronda 1 | 79851 | 8 | 0.9 | n/d | 0 hallazgos; CHANGELOG pendiente |
| 1.15 (spec) | doc-reviewer | ronda 1 | 152088 | 21 | 3.4 | n/d | 1 Alto, 7 Medios, 10 Bajos |
| 1.15 (spec) | spec-writer (Opus) | correcciones doc-reviewer | 73655 | 40 | 7.5 | n/d | reanudación; tokens = delta (391819 acumulado); reescribió funcional.md entero |
| 1.15 (spec) | doc-reviewer | ronda 2 | 130051 | 17 | 2.0 | n/d | 0 C/A; 2 Medios y 5 Bajos textuales, corregidos por la sesión |

## Resumen (se recalcula al cerrar cada paso)

Provisional, n = 1 paso (`1.14b-a`):

- `implementer`: 849 k tokens y 320 llamadas en total. La primera pasada fue el 86 % de los tokens y el 65 % de las llamadas.
- Revisores: ronda 1 = 402 k tokens; ronda 2 = 272 k (68 % de la ronda 1). La ronda 2 solo se justificó por un Medio de seguridad y los Medios de documentación.
- `verificador` y `traductor`: 30-65 k tokens cada uno; el `verificador` es lento en reloj (12-23 min).
| Bajos 2026-11-05 | explorer | inventario de 21 Bajos | 56711 | 4 | 1.1 | 14178 | |
| Bajos 2026-11-05 | implementer | grupo A tramo 1 (7 issues) | 141516 | 51 | 11.6 | 2775 | |
| Bajos 2026-11-05 | implementer | grupo A tramo 2 (#527) | 100629 | 20 | 10.5 | 5031 | |
| Bajos 2026-11-05 | verificador | suite completa grupo A | 36658 | 12 | 7.9 | 3055 | |
| Bajos 2026-11-05 | security-reviewer | grupo A | 66432 | 6 | 0.5 | 11072 | |
| Bajos 2026-11-05 | implementer | evidencia consentimiento P1 (#482-#484, #480) | 184549 | 84 | 21.5 | 2197 | |
| Bajos 2026-11-05 | db-reviewer | evidencia consentimiento | 78317 | 9 | 1.2 | 8702 | |
| Bajos 2026-11-05 | security-reviewer | evidencia consentimiento | 74554 | 7 | 0.9 | 10651 | |
| Bajos 2026-11-05 | implementer | evidencia consentimiento P2 correcciones (delta de la reanudación) | 64053 | 68 | 15.3 | 942 | |
| Bajos 2026-11-05 | verificador | suite evidencia (rojo: CA-067-08, consulta de más) | 43327 | 9 | 6.5 | 4814 | |
| Bajos 2026-11-05 | verificador | suite evidencia (verde) | 25039 | 5 | 8.7 | 5008 | |
| Bajos 2026-11-05 | doc-precheck | evidencia consentimiento | 80282 | 8 | 1.0 | 10035 | |
| Bajos 2026-11-05 | doc-reviewer | evidencia consentimiento | 84296 | 11 | 1.3 | 7663 | |
| Bajos 2026-11-05 | architect | ADR-070 (#481) | 87925 | 16 | 3.2 | 5495 | |
| Bajos 2026-11-05 | implementer | ADR-070 implementación | 122646 | 54 | 25.2 | 2271 | |
| Bajos 2026-11-05 | verificador | suite ADR-070 (verde) | 27972 | 9 | 15.1 | 3108 | |
| Bajos 2026-11-05 | security-reviewer | ADR-070 | 76080 | 12 | 1.1 | 6340 | |
| Bajos 2026-11-05 | doc-precheck | ADR-070 | 60063 | 6 | 0.5 | 10010 | |
| Bajos 2026-11-05 | doc-reviewer | ADR-070 | 81580 | 9 | 0.9 | 9064 | |
| Bajos 2026-11-05 | implementer | #537 orden de bloqueo | 117752 | 39 | 20.8 | 3019 | |
| Bajos 2026-11-05 | verificador | suite #537 (rojo AR-10, FamilyUnit) | 28337 | 7 | 12.4 | 4048 | |
| Bajos 2026-11-05 | verificador | suite #537 (rojo AR-10, Guardian) | 25844 | 6 | 13.0 | 4307 | |
| Bajos 2026-11-05 | verificador | suite #537 (verde) | 25298 | 6 | 15.4 | 4216 | |
| Bajos 2026-11-05 | security-reviewer | #537 | 65657 | 6 | 0.7 | 10943 | |
| Bajos 2026-11-05 | doc-reviewer | #537 | 64340 | 5 | 0.6 | 12868 | |
| Bajos 2026-11-05 | implementer | documentales (#529, #511, #479, #531) | 127226 | 36 | 4.7 | 3534 | |
| Bajos 2026-11-05 | doc-precheck | documentales | 58272 | 5 | 0.4 | 11654 | |
| Bajos 2026-11-05 | doc-reviewer | documentales | 98667 | 22 | 2.6 | 4485 | |
| Bajos 2026-11-05 | implementer | #509 y #512 | 154962 | 75 | 19.8 | 2066 | |
| Bajos 2026-11-05 | verificador | suite #509/#512 (verde) | 25610 | 6 | n/d | 4268 | |
| Bajos 2026-11-05 | security-reviewer | #509/#512 (cifra aproximada) | 65000 | 5 | 0.7 | 13000 | |
| Bajos 2026-11-05 | doc-reviewer | #509/#512 | 76786 | 7 | 0.9 | 10969 | |
| proceso | doc-precheck | #545 asignación de modelos y E2E a Haiku | 79861 | 7 | 0.8 | 11409 | 0 fallos; 1 referencia obsoleta y falta de CHANGELOG, corregidos por la sesión |

**Sesión 2026-11-05 (recapitulación de Bajos, 32 pasadas):** no es un paso del plan, así que no se recalcularon medias por tipo. Cifras tomadas de los avisos de fin; las de verificador y las de reanudaciones son las acumuladas del último aviso (la del implementer de la evidencia, P2, es el delta). Las filas «n/d» o «aproximada» no se anotaron en el momento. Codex sigue sin cuota hasta 2026-12-02 (régimen `ADR-050`).

## Codex (`ADR-069`)

Solo cuota de OpenAI; no se mide en tokens de Claude. Registrar por revisión: paso, duración, hallazgos por severidad, válidos frente a falsos positivos y hallazgos que Claude encuentra después y Codex no.

| Paso | Subcomando | Hallazgos | Válidos | Falsos positivos | Notas |
|------|------------|-----------|--------:|-----------------:|-------|
| 1.14b-b | adversarial-review --base develop | 1 Medio | 1 | 0 | carrera `PickupAlertService::raise()` (alumno borrado entre validación e `INSERT`); misma clase que `OPEN-FAM-UNIT-51`; llegó tras la revisión de Claude, que no lo vio; revisión de calibración sin ahorro |
| 1.14b-c1 | adversarial-review --base develop (ronda 1) | 1 Alto, 2 Medios | 3 | 0 | seguridad no vio nada de su columna que Codex no señalase; Codex no ejecutó Vitest (solo lectura) |
| 1.14b-c1 | adversarial-review --base 0902515 (ronda 2) | 1 Alto | 1 | 0 | carrera introducida por la propia corrección de la ronda 1; Claude no la habría visto |
| 1.14b-c1 | adversarial-review --base 76fe7c3 (ronda 3) | 0 | 0 | 0 | approve |
| 1.14b-c2 | adversarial-review --base develop | no disponible | 0 | 0 | cuota de OpenAI agotada (hasta 2026-12-02): el turno falló y su «approve» no cuenta; régimen de respaldo con security-reviewer completo |
| 1.14b-d1 | adversarial-review | no disponible | 0 | 0 | cuota de OpenAI agotada (hasta 2026-12-02): régimen de respaldo `ADR-050` con `security-reviewer` completo |
| 1.15-a T1 | implementer (reanudado tras corte) | datos y permisos | 122183 | 33 | 6.7 | 3702 | sin briefing; auditó trabajo previo sin commitear |
| 1.15-a T2 | explorer · briefing | briefing T2 | 165580 | 52 | 4.8 | 3184 | experimental; destapó 10 huecos spec/código |
| 1.15-a T2 | implementer | servicio, endpoints, resolutor, OpenAPI | 274862 | 65 | 15.1 | 4228 | con briefing; 0 cortes |
| 1.15-a cierre | security-reviewer | revisión completa (ADR-050, sin Codex) | 122463 | 24 | 3.6 | 5103 | 0 Crít./Altos; 2 Medios, 3 Bajos |
| 1.15-a cierre | db-reviewer | migración students | 73201 | 8 | 0.7 | 9150 | sin hallazgos |
| 1.15-a cierre | doc-precheck (ronda 1) | mecánica | 103430 | 14 | 1.6 | 7388 | |
| 1.15-a cierre | doc-reviewer | ronda 1 | 139482 | 20 | 2.6 | 6974 | 6 Medios, 5 Bajos (todos documentales) |
| 1.15-a cierre | verificador | suite completa (1.ª) | 35463 | 12 | 9.1 | 2955 | 4 rojos por expectativas de Core |
| 1.15-a cierre | implementer | reanudación única de correcciones | 137183 | 36 | 6.4 | 3811 | |
| 1.15-a cierre | doc-precheck (ronda 2) | lista cerrada | 70686 | 7 | 0.7 | 10098 | |
| 1.15-a cierre | verificador | suite completa (2.ª) | 30364 | 12 | 8.4 | 2530 | 2250+67 en verde |
| 1.15-a cierre | implementer | spec E2E real alum | 106808 | 25 | 3.6 | 4272 | |
| 1.15-a cierre | verificador | E2E real alum | n/d | n/d | n/d | n/d | 3/3 verde; cifras no recibidas |
| 1.15-b T1a | explorer | briefing | 137516 | 42 | 3.0 | 3189 | experimental (punto 2 de §2) |
| 1.15-b T1a | implementer | acad vetos, GroupDirectory, groupIdsFor | 131752 | 37 | 16.3 | 3561 | 743/743 ficheros afectados |
| 1.15-b T1b | explorer | briefing | 174599 | 42 | 3.8 | 4157 | |
| 1.15-b T1b | implementer | enrollments, vetos alum, permisos, medición #382 | 243629 | 131 | 63.7 | 1860 | 2 rojos a propósito hasta T2 |
| 1.15-b T2 | explorer | briefing | 165181 | 49 | 4.4 | | |
| 1.15-b T2a | implementer | CourseDirectory, EnrollmentService, 3 resolutores | 239281 | 52 | 73.9 | | |
| 1.15-b T2b | explorer | briefing | 120649 | 32 | 2.1 | | |
| 1.15-b T2b | implementer | endpoints matrícula, filtros GET /students, enrollments[], concurrencia | 217558 | 42 | 13.8 | | 98/98 informado; la sesión reejecutó 95/95 |
| 1.15-b T2b | implementer (reanudación) | ámbito matricula.leer en enrollments[] y enrollment en StudentSummary (delta) | 14482 | 11 | 8.7 | | 97/97 reejecutado por la sesión |

| 1.15-b T2c | implementer | exportación, OpenAPI, docs, CHANGELOG | 194256 | 53 | 9.3 | | 350/350 informado |
| 1.15-b cierre | implementer (reanudaciones) | correcciones A-F (M1, M2, índice, E2E spec), import, test CA-040, #598/#599/#601 (delta acumulado) | 128385 | 76 | | | duración acumulada con esperas, no fiable |
| 1.15-b cierre | db-reviewer | revisión | 84472 | 11 | 1.4 | | |
| 1.15-b cierre | security-reviewer | revisión (paso crítico) | 149271 | 23 | 3.2 | | |
| 1.15-b cierre | doc-precheck | pasada mecánica | 103288 | 11 | 1.4 | | |
| 1.15-b cierre | doc-reviewer | revisión | 135882 | 40 | 2.9 | | |
| 1.15-b cierre | janitor | 7 issues + correcciones documentales | 225083 | 37 | 7.4 | | |
| S-1a T1 | explorer | briefing | 127956 | 27 | 1.9 | | |
| S-1a T1 | implementer | experimento Garage, servicio, `garage.toml` e `init.sh` | 92845 | 43 | 5.3 | | |
| S-1a T2 | implementer | config `s3`, endpoint de firma, tope de TTL y tests (sin explorer propio: se reutilizó el briefing del T1) | 73604 | 7 | 1.0 | | Telemetría baja pero el trabajo es real: contrastado con el diff y Pest/Pint/Larastan por `verificador` |
| S-1a T3 | implementer | test de conformidad, job de CI y documentación (briefing del T1 reutilizado) | 137057 | 35 | 4.4 | | |
| S-1a cierre | verificador | T2: Pest afectados, Pint, Larastan | 27024 | 6 | 0.4 | | |
| S-1a cierre | verificador | suite completa en `e2b856c` | 38462 | 18 | 10.9 | | |
| S-1a cierre | verificador | correcciones y conformidad real contra Garage | 30981 | 9 | 0.9 | | |
| S-1a cierre | security-reviewer | revisión (paso crítico) | 83538 | 12 | 1.2 | | |
| S-1a cierre | doc-precheck | pasada mecánica | 109729 | 29 | 1.7 | | |
| S-1a cierre | doc-reviewer | revisión | 105753 | 13 | 1.4 | | |
| S-1b T1 | explorer | briefing | 126659 | 25 | 2.6 | | |
| S-1b T1 | implementer | #625: firmas por `PrivateObjectStore` | 92100 | 31 | 9.3 | | |
| S-1b T1 | verificador | suite completa | 29266 | 7 | 10.5 | | |
| S-1b T2 | implementer | pila con Garage, retirada de MinIO y scripts/specs de E2E | 132873 | 31 | 15.9 | | Dejó una regresión (orden de `E2E_ONLY`) por no ejecutar `npm test` |
| S-1b T3 | implementer | E2E de foto y CSV, documentación viva | 173610 | 52 | 17.7 | | |
| S-1b cierre | verificador | suite completa, Vitest y E2E reales | 50062 | 28 | 20.0 | | Detectó el Vitest en rojo |
| S-1b cierre | verificador | arreglos de scripts, Vitest y E2E | 34448 | 11 | 5.9 | | |
| S-1b cierre | security-reviewer | revisión (paso crítico) | 102657 | 12 | 1.2 | | |
| S-1b cierre | doc-precheck | 1.ª pasada | 134747 | 20 | 2.7 | | |
| S-1b cierre | doc-reviewer | revisión | 171459 | 41 | 4.0 | | |
| S-1b cierre | janitor | correcciones documentales (lista cerrada) | 127688 | 34 | 3.0 | | |
| S-1b cierre | doc-precheck | 2.ª ronda | 117956 | 15 | 2.5 | | |

Medias por tipo de `1.15-a` sin recalcular: el paso `1.15` no está cerrado (faltan `-b`..`-d`); se recalculan al cerrar `1.15`.
