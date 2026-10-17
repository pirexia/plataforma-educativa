# Archivo de `memory.md` · 2026-10-17

Detalle ya cerrado y mezclado que `memory.md` dejó de llevar al cerrar `1.11d-a`: entregas `1.11-a` y `1.11-b` de `REQ-ACAD`, y el bloque «Hecho en la sesión del 2026-10-08/10».

**`1.11-b` · CERRADA Y MEZCLADA** (PR #423, `af26b73`). Lo que sigue es el detalle de esa entrega:

**`1.11-b` · implementada y revisada (2026-10-12), a falta de CI y mezcla**: rama `feature/REQ-ACAD-1-11b-modulo-acad` (módulo `acad`: 6 tablas con disparador, 27 endpoints, 5 pantallas, selector de curso, campo traducible, `AR-16`, `acad:grant-structure-permissions`; E2E real `CA-ACAD-053` 3/3). Revisada por `db-reviewer`, `security-reviewer`, `traductor` y `doc-reviewer`; sus hallazgos corregidos en la rama. Decisiones del usuario: `RPERM-013` acotada (un rol existente se asigna o clona con sus permisos inertes ya presentes; añadir uno inerte nuevo exige poseerlo; hay `deny` que lo impide; escalada latente S-1); texto simple de `code`/`name` rechaza caracteres de control (`acad.validation.invalid_characters`); padre ilegible del cuerpo = `422 parent_not_found`. Defecto de producción arreglado: el alias `auth` daba 500 con cookie real (se usa `RequireAuthenticatedUser` en las rutas de `acad`). #382: disparador sobre `subjects` +85 % a +104 % masiva (menos que los +182 % de 1.10). Issues nuevos: #420 (arreglado en la rama), #421 (filtro `subject` en `GET /groups`), #422 (72 FK sin índice cubriente, 66 de autoría). Se cierran al mezclar: #407 (inventario hecho), #408, #409, #420, #387 (comprobar su lista), #104. Verificado: ver el último commit de la rama y el `CHANGELOG.md`.

**`1.11-a` · cerrada (2026-10-12)**: PR [#417](https://github.com/pirexia/plataforma-educativa/pull/417) mezclado con CI en verde (ver `CHANGELOG.md`). Verificado: suite completa 1257+9 Pest, Pint y Larastan en verde sobre `fe71ea8` más cambios solo de documentación y un comentario. Sin trabajo a medias. Resuelve #412 a #416 (cierre al mezclar). Decisiones del usuario: `OPEN-ACAD-01..23` con las recomendaciones; `OPEN-062-01` = `default_locale`; `OPEN-063-01` = fijar «clase»; dos entregas; endurecer `TEMP` ahora; `withIndex` en el ayudante (4 migraciones antiguas, excepción documentada). El db-reviewer erró 17/5: son 18 a crear y 4 cubiertas (el UNIQUE de `saml_identity_provider_settings` es parcial).

Antes: cierre de sesión del 2026-10-10 con `develop` limpio. Notas anteriores archivadas en `docs/historial/memory-archivo-2026-10-10.md`.

**Hecho en la sesión del 2026-10-08/10** (todo mezclado en `develop`):
- `ADR-059` (reapertura de curso cerrado, PR #394): `OPEN-CURSO-08` resuelta; la implementación es el paso `1.11c`.
- Issues: #374, #375 (`AR-02` con 0 excepciones; nota de estado posterior en `ADR-056`), #377 y #378 B1/B2 (PR #395-#398); #199 y #401 cerrados.
- `ADR-060` (PR #400): suite en paralelo, `composer test` ~105 s frente a 465 s; `CLAUDE.md` 2.7.0 §3 (suite parcial durante el trabajo, completa una vez antes del PR y en CI) y agentes actualizados.
- Dependencias: Laravel 13.30 por CVE (#272), Actions checkout/setup-node v7 y cache v6 (#402), lockfile web (#403), Pest 5 / PHPUnit 13 (#404).
- `ADR-061` PostgreSQL 18 (PR #405): volumen nuevo `postgres-cluster`; desarrollo migrado y verificado (inventario idéntico, 1105/1105, reversión probada). **Decisión del usuario pendiente**: borrar el volumen `plataforma-educativa_postgres-data` (17) y `~/plataforma-respaldos/pg17-a-pg18-20261010-1655/`, conservados al menos hasta el 2026-10-17.

