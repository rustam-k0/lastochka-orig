<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Order;

use App\Models\Address;
use App\Models\AddressPhoto;
use App\Models\User;
use App\Services\Order\OrderObjectSnapshotter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class OrderObjectSnapshotterTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_null_for_non_object_address(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->for($user)->create(['is_object' => false]);

        $snap = app(OrderObjectSnapshotter::class)->snapshot($address);

        $this->assertNull($snap);
    }

    public function test_snapshot_returns_object_data_with_empty_photos(): void
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        $snap = app(OrderObjectSnapshotter::class)->snapshot($address);

        $this->assertIsArray($snap);
        $this->assertSame('Многоэтажка', $snap['name']);
        $this->assertSame([], $snap['photos']);
    }

    public function test_snapshot_includes_photos_urls(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();

        AddressPhoto::factory()->count(2)->for($address)->create([
            'disk' => 'public',
            'path' => 'address-photos/test.jpg',
            'mime' => 'image/jpeg',
        ]);

        $snap = app(OrderObjectSnapshotter::class)->snapshot($address->fresh('photos'));

        $this->assertCount(2, $snap['photos']);
        $this->assertArrayHasKey('url', $snap['photos'][0]);
        $this->assertSame('image/jpeg', $snap['photos'][0]['mime']);
    }
}
