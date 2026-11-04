<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Acceso de los técnicos: usuario = teléfono, enlace de un solo uso y cambio de contraseña. */
class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    /** Las contraseñas del test se generan al azar: no queda ninguna escrita en el código. */
    private string $clave;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clave = self::otraClave();
        // Las peticiones del portal llevan su origen; así Sanctum inicia la sesión.
        $this->withHeader('Referer', config('app.url'));
    }

    public function test_technician_signs_in_with_the_phone_in_any_format(): void
    {
        $technician = User::factory()->technician()->create(['phone' => '0414-123 45 67', 'password' => $this->clave]);
        $this->assertSame('4141234567', $technician->phone_normalized);

        foreach (['0414-1234567', '04141234567', '+58 414 1234567', '584141234567', '4141234567'] as $typed) {
            $this->postJson('/api/v1/auth/login', ['email' => $typed, 'password' => $this->clave])
                ->assertOk()->assertJsonPath('data.id', $technician->id)->assertJsonPath('data.login', '0414-123 45 67');
            $this->postJson('/api/v1/auth/logout')->assertOk();
        }
        $this->assertNotNull($technician->refresh()->last_login_at);

        // Otro número, o la contraseña equivocada: mismo mensaje genérico.
        $this->postJson('/api/v1/auth/login', ['email' => '0414-9999999', 'password' => $this->clave])
            ->assertJsonPath('errors.email.0', 'Usuario o contraseña incorrectos.');
        $this->postJson('/api/v1/auth/login', ['email' => '0414-1234567', 'password' => self::otraClave()])
            ->assertJsonPath('errors.email.0', 'Usuario o contraseña incorrectos.');
    }

    public function test_staff_still_sign_in_with_their_email(): void
    {
        $operator = User::factory()->create(['email' => 'Ana.Perez@servipos.test', 'password' => $this->clave]);

        $this->postJson('/api/v1/auth/login', ['email' => ' ana.perez@SERVIPOS.test ', 'password' => $this->clave])
            ->assertOk()->assertJsonPath('data.id', $operator->id);
    }

    public function test_a_technician_needs_a_unique_phone_but_no_email_or_password(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->technician()->create(['phone' => '0414-1234567']);

        // Sin teléfono no se puede crear; el mismo número con otro formato tampoco.
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Luis Anton', 'role' => 'technician'])
            ->assertJsonValidationErrors('phone');
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Luis Anton', 'role' => 'technician', 'phone' => '+58 414 123 45 67'])
            ->assertJsonValidationErrors(['phone' => 'Ese teléfono ya lo usa otro usuario.']);
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Luis Anton', 'role' => 'technician', 'phone' => 'abc'])
            ->assertJsonValidationErrors('phone');

        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Luis Anton', 'role' => 'technician', 'phone' => '0424-7654321'])
            ->assertCreated()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.login', '0424-7654321')
            ->assertJsonPath('data.last_login_at', null);

        // Para los demás roles el correo sigue siendo obligatorio.
        $this->actingAs($admin)->postJson('/api/v1/users', ['name' => 'Operadora', 'role' => 'operator'])
            ->assertJsonValidationErrors('email');
    }

    public function test_access_link_lets_the_technician_create_a_password_once(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create(['name' => 'Jesus Ramos', 'phone' => '0414-1234567', 'password' => self::otraClave()]);
        $technician->pushSubscriptions()->create(['endpoint' => 'https://fcm.googleapis.com/fcm/send/x', 'public_key' => 'k', 'auth_token' => 'a']);

        $link = $this->actingAs($admin)->postJson("/api/v1/users/{$technician->id}/access-link")->assertOk()->json();
        $this->assertStringStartsWith(config('app.url').'/acceso#', $link['url']);
        $this->assertStringStartsWith('https://wa.me/584141234567?text=', $link['whatsapp_url']);
        $this->assertStringContainsString('Hola Jesus', $link['message']);
        $token = explode('#', $link['url'])[1];
        // En la base de datos no queda el código, solo su huella.
        $this->assertNotSame($token, $technician->refresh()->access_token_hash);
        $this->assertSame(hash('sha256', $token), $technician->access_token_hash);
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/access/check', ['token' => $token])->assertOk()->assertExactJson(['name' => 'Jesus Ramos', 'login' => '0414-1234567']);
        // Contraseña débil o sin confirmar: se rechaza y el enlace sigue vigente.
        $this->postJson('/api/v1/access/complete', ['token' => $token, 'password' => 'corta', 'password_confirmation' => 'corta'])->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/access/complete', ['token' => $token, 'password' => $this->clave, 'password_confirmation' => $this->clave])
            ->assertOk()->assertJsonPath('data.id', $technician->id);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $technician->id);

        $technician->refresh();
        $this->assertTrue(Hash::check($this->clave, $technician->password));
        $this->assertNull($technician->access_token_hash);
        $this->assertSame(0, $technician->pushSubscriptions()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.access_link', 'user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.access_completed', 'user_id' => $technician->id]);
        $this->assertStringNotContainsString($token, json_encode(AuditLog::all()));

        // Un solo uso.
        $this->postJson('/api/v1/access/check', ['token' => $token])->assertJsonValidationErrors('token');
        $otra = self::otraClave();
        $this->postJson('/api/v1/access/complete', ['token' => $token, 'password' => $otra, 'password_confirmation' => $otra])->assertJsonValidationErrors('token');
    }

    public function test_access_links_expire_and_a_new_one_cancels_the_previous(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create();
        $token = fn () => explode('#', $this->actingAs($admin)->postJson("/api/v1/users/{$technician->id}/access-link")->json('url'))[1];

        $first = $token();
        $second = $token();
        $this->postJson('/api/v1/access/check', ['token' => $first])->assertJsonValidationErrors('token');
        $this->postJson('/api/v1/access/check', ['token' => $second])->assertOk();

        $this->travel(49)->hours();
        $this->postJson('/api/v1/access/check', ['token' => $second])->assertJsonValidationErrors('token');
    }

    public function test_only_admins_generate_access_links_and_only_for_users_who_can_sign_in(): void
    {
        $admin = User::factory()->admin()->create();
        $technician = User::factory()->technician()->create();

        $this->actingAs(User::factory()->create())->postJson("/api/v1/users/{$technician->id}/access-link")->assertForbidden();
        $this->actingAs($technician)->postJson("/api/v1/users/{$technician->id}/access-link")->assertForbidden();

        $inactive = User::factory()->technician()->create(['active' => false]);
        $this->actingAs($admin)->postJson("/api/v1/users/{$inactive->id}/access-link")->assertStatus(422);
        // Técnico importado del Excel: solo nombre, sin teléfono ni correo.
        $imported = User::factory()->technician()->create(['phone' => null, 'email' => null]);
        $this->actingAs($admin)->postJson("/api/v1/users/{$imported->id}/access-link")->assertStatus(422);
    }

    public function test_users_change_their_own_password_with_the_current_one(): void
    {
        $actual = self::otraClave();
        $nueva = $this->clave;
        $technician = User::factory()->technician()->create(['phone' => '0414-1234567', 'password' => $actual, 'remember_token' => 'anterior']);
        $this->postJson('/api/v1/auth/login', ['email' => '0414-1234567', 'password' => $actual])->assertOk();

        $this->putJson('/api/v1/me/password', ['current_password' => self::otraClave(), 'password' => $nueva, 'password_confirmation' => $nueva])
            ->assertJsonValidationErrors(['current_password' => 'La contraseña actual no es correcta.']);
        $this->putJson('/api/v1/me/password', ['current_password' => $actual, 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/me/password', ['current_password' => $actual, 'password' => $nueva, 'password_confirmation' => self::otraClave()])
            ->assertJsonValidationErrors('password');

        $this->putJson('/api/v1/me/password', ['current_password' => $actual, 'password' => $nueva, 'password_confirmation' => $nueva])->assertOk();

        $technician->refresh();
        $this->assertTrue(Hash::check($nueva, $technician->password));
        $this->assertNotSame('anterior', $technician->remember_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed', 'user_id' => $technician->id]);
        // Sigue con su sesión abierta.
        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_first_admin_is_created_from_the_console_with_an_access_link(): void
    {
        $this->artisan('servipos:crear-admin', ['correo' => 'Jefe@ServiPos2019.com', '--nombre' => 'Ana Jefe'])
            ->expectsOutputToContain('Administrador creado: Ana Jefe <jefe@servipos2019.com>')
            ->expectsOutputToContain(config('app.url').'/acceso#')
            ->assertSuccessful();

        $admin = User::where('email', 'jefe@servipos2019.com')->sole();
        $this->assertSame('admin', $admin->role->value);
        $this->assertNotNull($admin->access_token_hash);
        $this->assertTrue($admin->access_token_expires_at->isFuture());

        // Volver a ejecutarlo no crea otro usuario: solo emite un enlace nuevo.
        $this->artisan('servipos:crear-admin', ['correo' => 'jefe@servipos2019.com'])->expectsOutputToContain('ya existía')->assertSuccessful();
        $this->assertSame(1, User::where('email', 'jefe@servipos2019.com')->count());
        $this->artisan('servipos:crear-admin', ['correo' => 'no-es-correo'])->assertFailed();
    }
}
