# Registro de decisiones arquitectónicas

Los ADR **`001` a `027`** son canónicos en la sección 18 de `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md`, que actúa de índice histórico.

A partir del **`028`**, cada ADR se escribe como fichero propio en este directorio con el nombre `ADR-NNN-titulo-en-kebab-case.md` y se añade una línea de referencia en aquella sección.

## Anexos

Un ADR puede llevar un **anexo** (procedimientos, listas de comprobación, plan de ejecución) como fichero aparte con el nombre `ADR-NNN-anexo-titulo.md`. El anexo **no tiene número propio**: forma parte del ADR `NNN`, comparte su estado y su inmutabilidad, y se cita como «`ADR-NNN`, anexo». Un número identifica una sola decisión. Hoy el único caso es `ADR-090-anexo-plan-parcheo-y-mantenimiento.md`. Una decisión nueva nunca va en un anexo: lleva el siguiente número libre.

## Plantilla

```markdown
# ADR-NNN · Título

**Estado**: PROPUESTA / ACEPTADA / SUSTITUIDA POR ADR-MMM
**Fecha**:

## Contexto
El problema y por qué hay que decidir ahora.

## Opciones consideradas
Las reales, no las teóricas.

## Decisión

## Motivo
Argumentado, no por preferencia.

## Consecuencias
Lo bueno y lo malo. Qué requisitos se ven afectados.

## Alternativas descartadas y por qué
```

Un ADR es **inmutable**. No se edita: se sustituye por otro que lo referencia.
