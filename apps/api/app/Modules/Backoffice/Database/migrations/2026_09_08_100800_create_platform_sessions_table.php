<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-046 §5, datos.md §2.6. Almacén del driver `database` de Laravel
 * para el guard `platform`: misma forma que `sessions`, a propósito, para
 * que el driver estándar funcione sin adaptador propio.
 *
 * No usa `TenantMigration::platformTable()` (su PK es `text`, no
 * `bigserial`: no hay secuencia que revocar) ni lleva timestamps,
 * borrado lógico ni autoría — la forma la fija el driver, no esta
 * migración (datos.md §2.6.3).
 *
 * **Desviación deliberada del nombre de columna de `datos.md §2.6.3`,
 * verificada en ejecución y declarada para revisión**: esa tabla llama
 * `platform_admin_id` a la columna que aquí se llama `user_id`.
 * `Illuminate\Session\DatabaseSessionHandler` escribe el identificador
 * del autenticado con la clave `user_id` **sin que sea configurable**
 * (no hay ninguna entrada en `config/session.php` para renombrarla,
 * comprobado contra el código de Laravel y contra el error real que
 * produce en cuanto alguien hace login: `column "user_id" of relation
 * "platform_sessions" does not exist`). `datos.md §2.6.3` ya avisa de
 * que la forma de esta tabla "la impone el driver, y apartarse de ella
 * es sustituir una convención de esquema por un adaptador que hay que
 * mantener" para `id`/`last_activity`; el mismo argumento alcanza a esta
 * columna, que el propio documento describe como "equivalente de
 * sessions.user_id" — aquí se toma esa frase al pie de la letra en el
 * nombre, no solo en el propósito.
 *
 * `REVOKE ALL … FROM plataforma_app` es la mitad de la decisión: que el
 * runtime de un centro no pueda leer una sesión de plataforma deja de
 * ser disciplina y pasa a ser un GRANT, verificado por CA-BO-018.
 * `plataforma_platform` conserva SELECT/INSERT/UPDATE/DELETE completos:
 * es el runtime del backoffice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->create('platform_sessions', function (Blueprint $table): void {
            $table->text('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->ipAddress('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('payload');
            $table->integer('last_activity');
        });

        DB::connection('pgsql_owner')->statement('REVOKE ALL ON platform_sessions FROM plataforma_app');
    }

    public function down(): void
    {
        DB::connection('pgsql_owner')->statement('DROP TABLE IF EXISTS platform_sessions');
    }
};
