<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Pelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Batch3OrderAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_owner_uploads_private_attachment_and_can_download_it(): void
    {
        Storage::fake('local');
        /** @var User $owner */
        $owner = User::factory()->create(['role' => 'staf_order']);
        $order = $this->createOrder($owner);

        $response = $this->actingAs($owner, 'sanctum')->post("/api/v1/order/{$order->id}/lampiran", [
            'file' => UploadedFile::fake()->create('desain.pdf', 12, 'application/pdf'),
            'keterangan' => 'Desain produksi',
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $path = $response->json('data.path');
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->actingAs($owner, 'sanctum')
            ->get("/api/v1/order/{$order->id}/lampiran/{$response->json('data.id')}/unduh")
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_forbids_supervisor_from_listing_order_attachments(): void
    {
        /** @var User $owner */
        $owner = User::factory()->create(['role' => 'staf_order']);
        /** @var User $supervisor */
        $supervisor = User::factory()->create(['role' => 'supervisor']);
        $order = $this->createOrder($owner);

        $this->actingAs($supervisor, 'sanctum')
            ->getJson("/api/v1/order/{$order->id}/lampiran")
            ->assertForbidden();
    }

    private function createOrder(User $owner): Order
    {
        $customer = Pelanggan::create([
            'kode' => 'PLG-'.$owner->id,
            'nama' => 'Pelanggan '.$owner->id,
            'alamat' => '-',
        ]);

        return Order::create([
            'no_order' => 'ORD-'.$owner->id,
            'pelanggan_id' => $customer->id,
            'tgl_order' => now(),
            'deadline' => now()->addDays(7),
            'status' => 'draft',
            'dibuat_oleh' => $owner->id,
        ]);
    }
}
