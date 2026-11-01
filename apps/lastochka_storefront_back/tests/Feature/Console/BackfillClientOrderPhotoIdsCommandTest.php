<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Address;
use App\Models\AddressPhoto;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class BackfillClientOrderPhotoIdsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_change_data(): void
    {
        [$orderAddress, $photo] = $this->seedOrderAddressWithUnattachedPhoto();

        $this->artisan('client-orders:backfill-photo-ids')
            ->assertExitCode(0);

        $orderAddress->refresh();
        // dry-run не должен дописывать id в JSON
        $this->assertArrayNotHasKey('id', $orderAddress->object_data['photos'][0]);
    }

    public function test_apply_fills_photo_id_when_match_found(): void
    {
        [$orderAddress, $photo] = $this->seedOrderAddressWithUnattachedPhoto();

        $this->artisan('client-orders:backfill-photo-ids', ['--apply' => true])
            ->assertExitCode(0);

        $orderAddress->refresh();
        $this->assertSame($photo->id, $orderAddress->object_data['photos'][0]['id']);
    }

    public function test_apply_leaves_record_untouched_when_no_match(): void
    {
        $orderAddress = $this->seedOrderAddressWithDanglingPhoto();
        $before = $orderAddress->object_data;

        $this->artisan('client-orders:backfill-photo-ids', ['--apply' => true])
            ->assertExitCode(0);

        $orderAddress->refresh();
        // Без матча команда не дописывает id и не меняет JSON
        $this->assertArrayNotHasKey('id', $orderAddress->object_data['photos'][0]);
        $this->assertSame($before, $orderAddress->object_data);
    }

    public function test_apply_is_idempotent(): void
    {
        [$orderAddress, $photo] = $this->seedOrderAddressWithUnattachedPhoto();

        $this->artisan('client-orders:backfill-photo-ids', ['--apply' => true])
            ->assertExitCode(0);
        $this->artisan('client-orders:backfill-photo-ids', ['--apply' => true])
            ->assertExitCode(0);

        $orderAddress->refresh();
        $this->assertSame($photo->id, $orderAddress->object_data['photos'][0]['id']);
    }

    public function test_apply_skips_photos_already_having_id(): void
    {
        [$orderAddress, $photo] = $this->seedOrderAddressWithUnattachedPhoto();
        $orderAddress->update([
            'object_data' => array_merge($orderAddress->object_data, [
                'photos' => [[
                    'id' => 99999,
                    'url' => $orderAddress->object_data['photos'][0]['url'],
                    'mime' => 'image/jpeg',
                ]],
            ]),
        ]);

        $this->artisan('client-orders:backfill-photo-ids', ['--apply' => true])
            ->assertExitCode(0);

        $orderAddress->refresh();
        $this->assertSame(99999, $orderAddress->object_data['photos'][0]['id']);
    }

    /**
     * @return array{0: OrderAddress, 1: AddressPhoto}
     */
    private function seedOrderAddressWithUnattachedPhoto(): array
    {
        $user = User::factory()->create();
        $address = Address::factory()->object()->for($user)->create();
        $photo = AddressPhoto::factory()->for($address)->create([
            'disk' => 'public',
            'path' => 'address-photos/'.$address->id.'/sample.jpg',
            'mime' => 'image/jpeg',
            'sort_order' => 0,
        ]);

        $url = Storage::disk('public')->url($photo->path);

        $order = Order::factory()->for($user)->create();
        $orderAddress = OrderAddress::create([
            'order_id' => $order->id,
            'original_id' => $address->id,
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Тестовая',
            'house_number' => '1',
            'full_address' => 'Россия, Москва, Тестовая, д. 1',
            'object_data' => [
                'name' => 'Test Object',
                'photos' => [[
                    'url' => $url,
                    'mime' => 'image/jpeg',
                    // id отсутствует — это и есть pre-fix snapshot
                ]],
            ],
        ]);

        return [$orderAddress, $photo];
    }

    private function seedOrderAddressWithDanglingPhoto(): OrderAddress
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create();

        return OrderAddress::create([
            'order_id' => $order->id,
            'original_id' => 99999, // не существует
            'country' => 'Россия',
            'city' => 'Москва',
            'street' => 'Тестовая',
            'house_number' => '1',
            'full_address' => 'Россия, Москва, Тестовая, д. 1',
            'object_data' => [
                'name' => 'Test Object',
                'photos' => [[
                    'url' => 'https://example.com/storage/address-photos/99999/missing.jpg',
                    'mime' => 'image/jpeg',
                ]],
            ],
        ]);
    }
}
