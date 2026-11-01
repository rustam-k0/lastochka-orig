<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Address;
use App\Services\Store\AvailableStoreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class RegeocodeAddressesCommand extends Command
{
    protected $signature = 'addresses:regeocode
        {--dry-run : Только показать что будет сделано, без записи}
        {--id=* : ID конкретных адресов (можно несколько)}
        {--limit=0 : Ограничить кол-во обрабатываемых записей (0 = без лимита)}
        {--sleep=200 : Пауза между запросами в миллисекундах}';

    protected $description = 'Догеокодить адреса с нулевыми/пустыми координатами через Yandex Geocoder API';

    public function handle(AvailableStoreService $availableStoreService): int
    {
        $apiKey = (string) config('services.yandex_geocoder.api_key');

        if ($apiKey === '') {
            $this->error('Не задан ключ Yandex Geocoder API (services.yandex_geocoder.api_key). Укажите YANDEX_GEOCODER_API_KEY в .env.');

            return self::FAILURE;
        }

        $query = Address::query()
            ->where(function ($q): void {
                $q->whereNull('latitude')
                    ->orWhereNull('longitude')
                    ->orWhere(function ($q): void {
                        $q->where('latitude', 0)->where('longitude', 0);
                    });
            });

        $ids = (array) $this->option('id');
        if (! empty($ids)) {
            $query->whereIn('id', $ids);
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $total = (clone $query)->count();
        $this->info("Найдено адресов для догеокодинга: {$total}".($dryRun ? ' (dry-run)' : ''));

        if ($total === 0) {
            return self::SUCCESS;
        }

        $updated = 0;
        $notFound = 0;
        $errors = 0;

        foreach ($query->cursor() as $address) {
            /** @var Address $address */
            $address->loadMissing([]);

            $geoQuery = $this->buildGeoQuery($address);

            if ($geoQuery === '') {
                $this->line("[skip] #{$address->id}: пустой адрес");
                $notFound++;

                continue;
            }

            try {
                [$lat, $lng] = $this->geocode($geoQuery, $apiKey);
            } catch (\Throwable $e) {
                $this->error("[err] #{$address->id}: {$e->getMessage()}");
                Log::warning('Address regeocode failed', [
                    'address_id' => $address->id,
                    'query' => $geoQuery,
                    'error' => $e->getMessage(),
                ]);
                $errors++;

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            if ($lat === null || $lng === null) {
                $this->warn("[404] #{$address->id}: geocoder не нашёл '{$geoQuery}'");
                $notFound++;

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }

                continue;
            }

            $this->line("[ok] #{$address->id}: {$geoQuery} -> {$lat}, {$lng}");

            if (! $dryRun) {
                $address->latitude = $lat;
                $address->longitude = $lng;
                $address->save();
                $availableStoreService->invalidateCache($address->id);
            }

            $updated++;

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info("Обновлено: {$updated}, не найдено: {$notFound}, ошибок: {$errors}".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }

    private function buildGeoQuery(Address $address): string
    {
        if (! empty($address->full_address)) {
            return (string) $address->full_address;
        }

        $parts = array_filter([
            $address->country,
            $address->city,
            $address->street,
            $address->house_number,
        ], static fn ($v) => $v !== null && $v !== '');

        return implode(', ', $parts);
    }

    /**
     * @return array{0: float|null, 1: float|null}
     */
    private function geocode(string $query, string $apiKey): array
    {
        $response = Http::timeout(10)
            ->retry(1, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException)
            ->get('https://geocode-maps.yandex.ru/1.x/', [
                'apikey' => $apiKey,
                'geocode' => $query,
                'format' => 'json',
                'results' => 1,
            ]);

        if ($response->failed()) {
            throw new \RuntimeException('Yandex Geocoder HTTP '.$response->status());
        }

        $pos = $response->json('response.GeoObjectCollection.featureMember.0.GeoObject.Point.pos');

        if (! is_string($pos) || $pos === '') {
            return [null, null];
        }

        [$lng, $lat] = array_map('trim', explode(' ', $pos, 2) + ['', '']);

        if ($lat === '' || $lng === '') {
            return [null, null];
        }

        return [(float) $lat, (float) $lng];
    }
}
