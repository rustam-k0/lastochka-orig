<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\ClientDeliveryInfo;
use App\Models\Shop;
use App\Models\Type;
use Illuminate\Database\Seeder;

class ClientDeliveryInfoSeeder extends Seeder
{
  /**
   * Run the database seeds.
   *
   * @return void
   */
  public function run()
  {
    $client = Client::firstWhere('email', 'client@mail.com');
    if ($client) {

      ClientDeliveryInfo::create([
        'shop_id' => Shop::first()->id,
        'client_id' => $client->id,
        'type_id' => Type::RECEIVE_PICKUP,
      ]);

    }

  }
}
