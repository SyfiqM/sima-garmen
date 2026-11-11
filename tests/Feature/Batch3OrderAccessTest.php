<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Pelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Batch3OrderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_forbids_supervisor_from_reading_order_details(): void
    {
        /** @var User $supervisor */
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $order = $this->createOrder(User::factory()->create());

        $this->actingAs($supervisor, 'sanctum')
            ->getJson("/api/v1/order/{$order->id}")
            ->assertForbidden();
    }

    public function test_forbids_staf_order_from_deleting_another_users_draft(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create(['role' => 'staf_order']);
        /** @var User $otherStaf */
        $otherStaf = User::factory()->create(['role' => 'staf_order']);
        $order = $this->createOrder($owner);

        $this->actingAs($otherStaf, 'sanctum')
            ->deleteJson("/api/v1/order/{$order->id}")
            ->assertForbidden();

        $this->assertModelExists($order);
    }

    public function test_forbids_staf_order_from_submitting_another_users_order(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create(['role' => 'staf_order']);
        /** @var User $otherStaf */
        $otherStaf = User::factory()->create(['role' => 'staf_order']);
        $order = $this->createOrder($owner);

        $this->actingAs($otherStaf, 'sanctum')
            ->postJson("/api/v1/order/{$order->id}/ajukan")
            ->assertForbidden();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'draft']);
    }

    public function test_rejected_order_can_be_returned_to_draft_by_its_owner(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create(['role' => 'staf_order']);
        $order = $this->createOrder($owner);
        $order->update(['status' => 'ditolak', 'alasan_penolakan' => 'Perlu revisi desain']);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/order/{$order->id}/perbaiki")
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'draft']);
    }

    private function createOrder(User $owner): Order
    {
        $pelanggan = Pelanggan::create([
            'kode' => 'PLG-'.$owner->id,
            'nama' => 'Pelanggan '.$owner->id,
            'alamat' => '-',
        ]);

        return Order::create([
            'no_order' => 'ORD-'.$owner->id,
            'pelanggan_id' => $pelanggan->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'draft',
            'dibuat_oleh' => $owner->id,
        ]);
    }
}
