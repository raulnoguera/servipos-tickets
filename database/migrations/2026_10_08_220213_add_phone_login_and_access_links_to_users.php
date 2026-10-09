<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acceso de los técnicos con su número de teléfono y enlace de acceso de un solo uso.
     * - El correo deja de ser obligatorio (los técnicos entran con el teléfono).
     * - phone_normalized: el teléfono sin formato, único, para buscarlo al iniciar sesión.
     * - access_token_*: enlace que el administrador envía para que el usuario cree su contraseña.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone_normalized', 20)->nullable()->unique()->after('phone');
            $table->string('access_token_hash', 64)->nullable()->index()->after('remember_token');
            $table->timestamp('access_token_expires_at')->nullable()->after('access_token_hash');
            $table->timestamp('last_login_at')->nullable()->after('access_token_expires_at');
        });

        // Los técnicos importados del Excel tenían un correo de relleno: ya no hace falta.
        DB::table('users')->where('email', 'like', '%@tecnicos.servipos.test')->update(['email' => null]);

        // Teléfonos ya cargados: si dos usuarios comparten número, solo el primero queda como usuario de acceso.
        $seen = [];
        foreach (DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone']) as $user) {
            $normalized = User::normalizePhone($user->phone);
            if ($normalized && ! isset($seen[$normalized])) {
                $seen[$normalized] = true;
                DB::table('users')->where('id', $user->id)->update(['phone_normalized' => $normalized]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_normalized']);
            $table->dropIndex(['access_token_hash']);
            $table->dropColumn(['phone_normalized', 'access_token_hash', 'access_token_expires_at', 'last_login_at']);
        });
    }
};
