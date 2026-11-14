<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * datos.md §D.2, funcional.md §D.2.1 (REQ-AUTH-003, 1.3b). Expand aditiva:
 * el alta de un factor de entrega (`email`) necesita persistir el hash del
 * código enviado y su caducidad propia, distinta de la del alta —
 * `secret_encrypted` está prohibido en los métodos de entrega por el
 * `CHECK` de 1.3 y `expires_at` es la caducidad del alta, no la del código.
 *
 * Las dos columnas son *nullable* y no tienen valor sensato para las filas
 * existentes (todas TOTP): la versión anterior de la aplicación no las
 * conoce, no las escribe y sigue funcionando sin ellas.
 *
 * `NOT VALID` + `VALIDATE CONSTRAINT` (`CLAUDE.md §9`, `migracion-segura`):
 * la misma lección que costó un hallazgo Media en la revisión de 1.3 sobre
 * `login_attempts` (issue #98) — un `ADD CONSTRAINT` sin `NOT VALID`
 * bloquearía en `ACCESS EXCLUSIVE` mientras recorre la tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql_owner')->table('user_mfa_factors', function (Blueprint $table): void {
            $table->text('code_hash')->nullable()->after('secret_encrypted');
            $table->timestampTz('code_expires_at')->nullable()->after('code_hash');
        });

        $owner = DB::connection('pgsql_owner');

        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_code_only_delivery_check
                CHECK (code_hash IS NULL OR method <> 'totp') NOT VALID
            SQL);
        $owner->statement(<<<'SQL'
            ALTER TABLE user_mfa_factors ADD CONSTRAINT user_mfa_factors_code_hash_expires_check
                CHECK ((code_hash IS NULL) = (code_expires_at IS NULL)) NOT VALID
            SQL);

        $owner->statement('ALTER TABLE user_mfa_factors VALIDATE CONSTRAINT user_mfa_factors_code_only_delivery_check');
        $owner->statement('ALTER TABLE user_mfa_factors VALIDATE CONSTRAINT user_mfa_factors_code_hash_expires_check');
    }

    public function down(): void
    {
        $owner = DB::connection('pgsql_owner');

        $owner->statement('ALTER TABLE user_mfa_factors DROP CONSTRAINT IF EXISTS user_mfa_factors_code_only_delivery_check');
        $owner->statement('ALTER TABLE user_mfa_factors DROP CONSTRAINT IF EXISTS user_mfa_factors_code_hash_expires_check');

        Schema::connection('pgsql_owner')->table('user_mfa_factors', function (Blueprint $table): void {
            $table->dropColumn(['code_hash', 'code_expires_at']);
        });
    }
};
