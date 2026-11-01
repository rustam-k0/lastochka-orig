<?php

namespace App\Console\Commands\RetailCRM;

use App\Services\RetailCRM\RetailCRMService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreateCollectorDictionaryCommand extends Command
{
    protected $signature = 'retail:create-dictionary {code=collectors : Dictionary code} {name=Сборщики : Dictionary name}';

    protected $description = 'Create a custom dictionary in RetailCRM for collectors';

    protected $retailService;

    public function __construct(RetailCRMService $retailService)
    {
        parent::__construct();
        $this->retailService = $retailService;
    }

    public function handle()
    {
        $code = $this->argument('code');
        $name = $this->argument('name');

        $this->info("Checking dictionary '{$code}' in RetailCRM...");

        try {
            $response = $this->retailService->getCustomDictionary($code);
            
            if ($response && $response->customDictionary) {
                $this->info("Dictionary '{$code}' already exists.");
                return 0;
            }
        } catch (\Exception $e) {
            // Dictionary likely doesn't exist
        }
        
        $this->info("Creating dictionary '{$code}'...");

        $dictionary = [
            'code' => $code,
            'name' => $name,
            'elements' => [
                [
                    'name' => 'Иванов Иван Иванович',
                    'code' => 'ivanov_i_i',
                    'ordering' => 10,
                ]
            ]
        ];

        $result = $this->retailService->createCustomDictionary($dictionary);

        if ($result && $result->success) {
            $this->info("Dictionary '{$code}' successfully created.");
            return 0;
        } else {
            $this->error("Failed to create dictionary.");
            return 1;
        }
    }
}
