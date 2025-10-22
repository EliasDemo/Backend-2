<?php
// tests/Feature/Academico/FacultadesCrudTest.php
declare(strict_types=1);

namespace Tests\Feature\Academico;

use Tests\Feature\ApiTestCase;
use Tests\Support\AuthHelpers;
use App\Models\Universidad;
use Illuminate\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Attributes\Test;

final class FacultadesCrudTest extends ApiTestCase
{
    use AuthHelpers;

    #[Test]
    public function admin_puede_listar_crear_actualizar_eliminar_facultad(): void
    {
        $headers = $this->loginAs('upeu.admin'); // ya seed

        $uni = Universidad::where('codigo','UPeU')->firstOrFail();

        // CREATE
        $create = $this->postJson('/api/administrador/academico/facultades', [
            'universidad_id' => $uni->id,
            'codigo' => 'FTEST',
            'nombre' => 'Fac. Test',
        ], $headers)->assertCreated()->json('data');

        $id = (int)($create['id']);

        // INDEX
        $this->getJson("/api/administrador/academico/facultades?universidad_id={$uni->id}", $headers)
            ->assertOk()
            ->assertJson(fn(AssertableJson $j) => $j->where('ok', true)->has('data')->etc());

        // UPDATE
        $this->putJson("/api/administrador/academico/facultades/{$id}", [
            'nombre' => 'Fac. Test Editada',
        ], $headers)->assertOk()->assertJsonPath('data.nombre', 'Fac. Test Editada');

        // DELETE
        $this->deleteJson("/api/administrador/academico/facultades/{$id}", [], $headers)
            ->assertNoContent();
    }
}
