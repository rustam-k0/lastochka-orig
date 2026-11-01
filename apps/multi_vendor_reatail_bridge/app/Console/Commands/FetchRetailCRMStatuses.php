<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\RetailCRM\Http\RetailCrmClient;
use App\Exceptions\RetailCRM\RetailCrmException;

class FetchRetailCRMStatuses extends Command
{
    protected $signature = 'retailcrm:fetch-statuses';
    protected $description = 'Fetch all order statuses from RetailCRM';

    public function handle()
    {
        $client = app(RetailCrmClient::class);

        try {
            $response = $client->get('reference/statuses');
            
            $this->info('Statuses found:');
            $headers = ['Code', 'Name', 'Group', 'Active'];
            $rows = [];

            foreach ($response->statuses as $status) {
                $rows[] = [
                    $status['code'],
                    $status['name'],
                    $status['group'],
                    $status['active'] ? 'Yes' : 'No'
                ];
            }

            $this->table($headers, $rows);

        } catch (RetailCrmException $exception) {
            $this->error($exception->getMessage());
        }
    }
}
