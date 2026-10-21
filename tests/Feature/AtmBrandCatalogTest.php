<?php

namespace Tests\Feature;

use App\Models\Atm;
use App\Models\AtmBrand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AtmBrandCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function atmPayload(array $with = []): array
    {
        return ['atm_number' => 'A1910500', 'agency_name' => 'Agencia de prueba', 'active' => true] + $with;
    }

    public function test_the_administrator_manages_brands_and_their_models(): void
    {
        $this->actingAs($this->admin);

        $ncr = $this->postJson('/api/v1/catalogs/brands', ['name' => ' ncr '])->assertCreated()->assertJsonPath('name', 'NCR')->json('id');
        $this->postJson('/api/v1/catalogs/models', ['name' => 'selfserv 22', 'atm_brand_id' => $ncr])->assertCreated()->assertJsonPath('name', 'SELFSERV 22');
        $this->postJson('/api/v1/catalogs/models', ['name' => 'SELFSERV 22', 'atm_brand_id' => $ncr])->assertStatus(422)->assertJsonValidationErrors('name');

        // El mismo nombre de modelo puede existir en otra marca; un modelo siempre pertenece a una marca.
        $diebold = AtmBrand::where('name', 'DIEBOLD')->value('id');
        $this->postJson('/api/v1/catalogs/models', ['name' => 'SELFSERV 22', 'atm_brand_id' => $diebold])->assertCreated();
        $this->postJson('/api/v1/catalogs/models', ['name' => 'OTRO'])->assertStatus(422)->assertJsonValidationErrors('atm_brand_id');

        $this->getJson('/api/v1/catalogs')->assertOk()
            ->assertJsonPath('brands.0.name', 'DIEBOLD')
            ->assertJsonPath('brands.1.name', 'NCR')
            ->assertJsonPath('brands.1.models.0.name', 'SELFSERV 22');

        $this->actingAs(User::factory()->create())->postJson('/api/v1/catalogs/brands', ['name' => 'X'])->assertForbidden();
    }

    public function test_an_atm_takes_its_brand_and_model_from_the_catalog(): void
    {
        $brand = AtmBrand::where('name', 'DIEBOLD')->firstOrFail();
        $brand->models()->create(['name' => 'OPTEVA 562']);
        $this->actingAs($this->admin);

        $this->postJson('/api/v1/atms', $this->atmPayload(['brand' => 'NO EXISTE']))->assertStatus(422)->assertJsonValidationErrors('brand');
        $this->postJson('/api/v1/atms', $this->atmPayload(['brand' => 'DIEBOLD', 'model' => 'SELFSERV 22']))->assertStatus(422)->assertJsonValidationErrors('model');
        $this->postJson('/api/v1/atms', $this->atmPayload(['brand' => 'diebold', 'model' => 'opteva 562']))->assertCreated()
            ->assertJsonPath('data.brand', 'DIEBOLD')->assertJsonPath('data.model', 'OPTEVA 562');
    }

    public function test_renaming_in_the_catalog_renames_it_on_the_atms(): void
    {
        $brand = AtmBrand::where('name', 'DIEBOLD')->firstOrFail();
        $model = $brand->models()->create(['name' => 'OPTEVA 562']);
        $atm = Atm::factory()->create(['brand' => 'DIEBOLD', 'model' => 'OPTEVA 562']);
        $other = Atm::factory()->create(['brand' => 'NCR', 'model' => 'OPTEVA 562']);
        $this->actingAs($this->admin);

        $this->putJson("/api/v1/catalogs/models/{$model->id}", ['name' => 'Opteva 562 Plus', 'active' => true])->assertOk();
        $this->putJson("/api/v1/catalogs/brands/{$brand->id}", ['name' => 'Diebold Nixdorf', 'active' => true])->assertOk();

        $this->assertSame(['DIEBOLD NIXDORF', 'OPTEVA 562 PLUS'], [$atm->refresh()->brand, $atm->model]);
        $this->assertSame(['NCR', 'OPTEVA 562'], [$other->refresh()->brand, $other->model]);
    }
}
