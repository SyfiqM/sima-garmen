<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

class Batch1BTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_sukses_mengembalikan_token()
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => bcrypt('password123'),
            'is_active' => 1
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'testuser',
            'password' => 'password123'
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_gagal_kredensial_salah()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'salah',
            'password' => 'salah'
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['login']);
    }

    public function test_user_nonaktif_ditolak_login()
    {
        User::factory()->create([
            'username' => 'nonaktif',
            'password' => bcrypt('password123'),
            'is_active' => 0
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'nonaktif',
            'password' => 'password123'
        ]);

        $response->assertStatus(401);
    }

    public function test_akses_endpoint_tanpa_token_ditolak()
    {
        $response = $this->getJson('/api/v1/me');
        $response->assertStatus(401);
    }

    public function test_me_mengembalikan_data_dan_menu()
    {
       /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
$user = User::factory()->create(['role' => 'supervisor', 'is_active' => 1]);

$response = $this->actingAs($user)->getJson('/api/v1/me');
        
        $response->assertStatus(200)
                 ->assertJsonPath('data.role', 'supervisor')
                 ->assertJsonStructure(['data' => ['menu']]);
    }
}