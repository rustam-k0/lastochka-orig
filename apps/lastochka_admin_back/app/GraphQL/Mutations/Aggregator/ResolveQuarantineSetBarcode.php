<?php

namespace App\GraphQL\Mutations\Aggregator;

use App\Aggregator\Events\ProductImportedFromEvent;
use App\Aggregator\Importers\AggregatorOfferImporter;
use App\Models\AggregatorQuarantine;
use App\Models\Status;

/**
 * Карантин no_ean: админ проставляет штрихкод товару (§8.4). Сразу пытаемся
 * сопоставить (повторный прогон импортёра), запись карантина закрываем.
 */
final class ResolveQuarantineSetBarcode
{
    public function __construct(private AggregatorOfferImporter $importer)
    {
    }

    public function __invoke($_, array $args): array
    {
        $record = AggregatorQuarantine::findOrFail($args['id']);
        $ean = preg_replace('/\D/', '', (string) $args['ean']);

        if ($ean === '') {
            throw new \GraphQL\Error\Error('Штрихкод должен содержать цифры');
        }

        $product = $record->localProduct;
        if ($product === null) {
            throw new \GraphQL\Error\Error('Товар карантинной записи не найден');
        }

        // Дописать штрихкод товару + вернуть из скрытого статуса (на сопоставление).
        $barcodes = collect($product->barcodes ?? [])->push($ean)->unique()->values()->all();
        $product->withoutEvents(function () use ($product, $barcodes, $ean) {
            $product->update([
                'barcodes' => $barcodes,
                'barcode' => $barcodes[0] ?? $ean,
                'status_id' => Status::CODE_ACTIVE,
            ]);
        });

        // Немедленно пересопоставить (привяжет к карточке или заведёт новую / повторно
        // отправит в карантин с другой причиной).
        $this->importer->handle(new ProductImportedFromEvent($product->fresh(), [
            'title' => $product->title,
            'barcodes' => $barcodes,
        ]));

        $record->update([
            'resolved_at' => now(),
            'resolved_by' => auth()->guard('api')->id(),
        ]);

        return ['code' => 200, 'message' => 'OK'];
    }
}
