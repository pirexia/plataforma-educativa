# Cierre de la prueba de Codex: `ADR-050`

**Cerrada y resuelta el 2026-09-21.** El umbral formal de `ADR-049 §8.2` no se cumplió (Cobertura 0/4 en el calibrado sobre PR #204, medición irrepetible; Precisión y Aportación diferencial sí se cumplieron, con holgura en `1.6c` — issue #224, Alta). Presentada la tabla completa, **el usuario decidió expresamente mantener la herramienta**: *"No lo desinstalamos, lo utilizamos como herramienta extra de verificación"*.

`ADR-050` formaliza esa decisión (redactado por `architect`): sustituye `ADR-049 §8` completo, conserva `§9` íntegro como procedimiento **ordinario** de retirada (ya no ligado a un umbral), no instituye revalidación periódica, deja el uso como **permanente y opcional** (no invocar Codex en un paso no es incumplimiento, no bloquea cierre, no entra en `CLAUDE.md §10`). Las tres condiciones de retirada inmediata (escritura en el árbol/`rescue`/`transfer`/puerta `Stop`; dato personal real; archivo/licencia/aviso de seguridad del plugin) siguen armadas sin cambio.

Textos actualizados en consecuencia: `CLAUDE.md §2` (v2.5.3), *skill* `revision-con-codex`, `docs/REQUISITOS-PLATAFORMA-EDUCATIVA.md` (v3.2.5, índice `§18` + historial `§20.2`), `CHANGELOG.md`.

## Punto abierto para el usuario, señalado por `architect` en `ADR-050 §11`

La capa gratuita de ChatGPT se aceptó en `ADR-049 §2.1` explícitamente *"para esta fase de prueba, antes de decidir si merece la pena estudiar la política de datos con más cuidado"* — esa fase ha terminado y el uso pasa a ser indefinido. Sin decidir: ¿capa gratuita tal cual (el contenido puede usarse para entrenar salvo desactivación expresa), desactivar el entrenamiento en la cuenta de OpenAI (recomendación de `architect`: gratis, no cambia el funcionamiento), o pasar a una capa de pago? No bloquea nada mientras tanto. Si se quiere seguir formalmente, hace falta abrir un issue o un `OPEN-NN` en la sección 18 del documento de requisitos — no se ha abierto todavía, a la espera de que el usuario decida si quiere trazarlo así.

## Deuda de documentación abierta por `ADR-050 §14` punto 5

`SECURITY.md`/`PRIVACY.md` no contemplan la salida permanente de código fuente hacia Codex (un tercero) durante el desarrollo — antes era "de una prueba de dos pasos", ahora es práctica permanente. Corresponde a `doc-reviewer` en el siguiente cierre de fase (`CLAUDE.md §6` regla 7). **Nota 2026-09-24: sigue sin resolver**, ningún cierre de fase posterior lo ha tocado todavía.
