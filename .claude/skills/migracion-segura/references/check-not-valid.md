# `CHECK` sobre tablas con datos: patrón `NOT VALID`

Ampliar o corregir un `CHECK` con `ADD CONSTRAINT ... CHECK (...)` sin `NOT VALID` sobre una tabla que ya puede tener filas en producción: ese `ADD CONSTRAINT` a secas obliga a PostgreSQL a validar **todas** las filas existentes bajo un lock `ACCESS EXCLUSIVE` mientras dura el escaneo — bloquea lecturas y escrituras de esa tabla por la duración completa del escaneo. Patrón correcto, en dos sentencias:

```sql
ALTER TABLE t DROP CONSTRAINT t_check;
ALTER TABLE t ADD CONSTRAINT t_check CHECK (...) NOT VALID;
ALTER TABLE t VALIDATE CONSTRAINT t_check; -- solo SHARE UPDATE EXCLUSIVE, no bloquea lecturas/escrituras
```

Sobre una tabla nueva o verificablemente vacía en todos los entornos (el `CHECK` va en la misma migración que crea la tabla), el `ADD CONSTRAINT` sin `NOT VALID` es inocuo y no hace falta el patrón de tres sentencias — la regla es para **ampliar o corregir** un `CHECK` ya desplegado sobre una tabla que ya puede tener filas. Motivado por issues [#186](https://github.com/pirexia/plataforma-educativa/issues/186)/[#201](https://github.com/pirexia/plataforma-educativa/issues/201): dos migraciones reales de `admin_action_logs`/`dual_authorizations` repitieron el mismo patrón bloqueante antes de que existiera esta norma — inocuo entonces porque las tablas estaban vacías, pero exactamente el riesgo que golpearía la próxima vez que se toque ese `CHECK` con la tabla ya en producción.
