<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Cart\BatchAddToCartAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Estimate\AddEstimateItemRequest;
use App\Http\Requests\Api\V1\Estimate\BatchAddEstimateItemsRequest;
use App\Http\Requests\Api\V1\Estimate\CreateEstimateRequest;
use App\Http\Requests\Api\V1\Estimate\UpdateEstimateItemRequest;
use App\Http\Requests\Api\V1\Estimate\UpdateEstimateRequest;
use App\Http\Resources\Api\V1\EstimateItemResource;
use App\Http\Resources\Api\V1\EstimateResource;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\Catalog\StoreOfferContext;
use App\Services\Pricing\ProductPriceResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Estimates', weight: 6)]
final class EstimateController extends Controller
{
    /**
     * Список смет пользователя
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $estimates = Estimate::query()
            ->where('user_id', $request->user()->id)
            ->withCount('items')
            ->with('items.product')
            ->orderByDesc('updated_at')
            ->get();

        $this->attachStoreOffers($request, $estimates->flatMap->items);

        return EstimateResource::collection($estimates);
    }

    /**
     * Создать смету
     */
    public function store(CreateEstimateRequest $request): JsonResponse
    {
        $estimate = Estimate::create([
            'user_id' => $request->user()->id,
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
        ]);

        $estimate->loadCount('items');

        return (new EstimateResource($estimate))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Детали сметы с товарами
     */
    public function show(Request $request, Estimate $estimate): EstimateResource
    {
        $this->authorize('view', $estimate);

        $estimate->load(['items.product.images'])->loadCount('items');

        $this->attachStoreOffers($request, $estimate->items);

        return new EstimateResource($estimate);
    }

    /**
     * Обновить смету
     */
    public function update(UpdateEstimateRequest $request, Estimate $estimate): EstimateResource
    {
        $this->authorize('update', $estimate);

        $estimate->update($request->validated());
        $estimate->load(['items.product'])->loadCount('items');

        $this->attachStoreOffers($request, $estimate->items);

        return new EstimateResource($estimate);
    }

    /**
     * Удалить смету
     */
    #[\Dedoc\Scramble\Attributes\Response(204, description: 'Смета удалена')]
    public function destroy(Estimate $estimate): \Illuminate\Http\Response
    {
        $this->authorize('delete', $estimate);

        $estimate->delete();

        return response()->noContent();
    }

    /**
     * Добавить товар в смету
     *
     * Если товар уже есть — увеличивает количество.
     */
    public function addItem(AddEstimateItemRequest $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);

        $productId = $request->validated('productId');
        $quantity = $request->validated('quantity');

        $item = $estimate->items()->where('product_id', $productId)->first();

        if ($item) {
            $item->update(['quantity' => $item->quantity + $quantity]);
        } else {
            $item = $estimate->items()->create([
                'product_id' => $productId,
                'quantity' => $quantity,
            ]);
        }

        $estimate->touch();
        $item->load('product.images');

        $this->attachStoreOffers($request, [$item]);

        return (new EstimateItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Массовое добавление товаров в смету
     *
     * Используется для кнопки «Добавить в смету» на странице комплексного решения.
     *
     * @response array{added: int}
     */
    public function batchAddItems(BatchAddEstimateItemsRequest $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('update', $estimate);

        $added = 0;

        foreach ($request->validated('items') as $itemData) {
            $productId = $itemData['productId'];
            $quantity = $itemData['quantity'];

            $existing = $estimate->items()->where('product_id', $productId)->first();

            if ($existing) {
                $existing->update(['quantity' => $existing->quantity + $quantity]);
            } else {
                $estimate->items()->create([
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ]);
            }

            $added++;
        }

        $estimate->touch();

        return response()->json(['added' => $added]);
    }

    /**
     * Обновить количество товара в смете
     */
    public function updateItem(
        UpdateEstimateItemRequest $request,
        Estimate $estimate,
        EstimateItem $item,
    ): EstimateItemResource {
        $this->authorize('manageItem', [$estimate, $item]);

        $item->update(['quantity' => $request->validated('quantity')]);
        $estimate->touch();
        $item->load('product.images');

        $this->attachStoreOffers($request, [$item]);

        return new EstimateItemResource($item);
    }

    /**
     * Удалить товар из сметы
     */
    #[\Dedoc\Scramble\Attributes\Response(204, description: 'Товар удалён из сметы')]
    public function removeItem(Estimate $estimate, EstimateItem $item): \Illuminate\Http\Response
    {
        $this->authorize('manageItem', [$estimate, $item]);

        $item->delete();
        $estimate->touch();

        return response()->noContent();
    }

    /**
     * Добавить все товары из сметы в корзину
     *
     * Товары, которые не удалось добавить, возвращаются в skippedProducts.
     * В сетевом каталоге магазин покупки задаётся параметром storeId; без него
     * берётся первый доступный магазин активного адреса.
     *
     * @response array{added: int, skipped: int, skippedProducts: array}
     */
    public function toCart(Request $request, Estimate $estimate, BatchAddToCartAction $action): JsonResponse
    {
        $this->authorize('view', $estimate);

        $estimate->load('items');

        $items = $estimate->items->map(fn (EstimateItem $item) => [
            'productId' => $item->product_id,
            'quantity' => $item->quantity,
        ])->toArray();

        if (empty($items)) {
            return response()->json(['added' => 0, 'skipped' => 0, 'skippedProducts' => []]);
        }

        // storeId — магазин покупки в сетевом каталоге (в обычном режиме
        // игнорируется): смета глобальна, а цену и остаток даёт оффер магазина.
        $storeId = (int) $request->input('storeId', 0);

        $result = $action->execute($request->user(), $items, $storeId > 0 ? $storeId : null);

        return response()->json($result);
    }

    /**
     * Создать копию сметы
     */
    public function clone(Request $request, Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);

        $copy = Estimate::create([
            'user_id' => $request->user()->id,
            'name' => $estimate->name.' (копия)',
            'description' => $estimate->description,
        ]);

        $estimate->load('items');

        foreach ($estimate->items as $item) {
            $copy->items()->create([
                'product_id' => $item->product_id,
                'quantity' => $item->quantity,
            ]);
        }

        $copy->load(['items.product'])->loadCount('items');

        $this->attachStoreOffers($request, $copy->items);

        return (new EstimateResource($copy))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Получить или создать share-ссылку
     */
    public function share(Estimate $estimate): JsonResponse
    {
        $this->authorize('view', $estimate);

        if (! $estimate->share_token) {
            $estimate->update(['share_token' => Str::uuid()->toString()]);
        }

        return response()->json([
            'shareToken' => $estimate->share_token,
            'shareUrl' => route('estimates.shared', ['token' => $estimate->share_token]),
        ]);
    }

    /**
     * Публичный доступ к смете по share-токену (без авторизации)
     */
    public function showShared(Request $request, string $token): EstimateResource|JsonResponse
    {
        $estimate = Estimate::where('share_token', $token)
            ->with(['items.product.images'])
            ->withCount('items')
            ->first();

        if (! $estimate) {
            return response()->json(['message' => 'Смета не найдена'], 404);
        }

        $this->attachStoreOffers($request, $estimate->items);

        return new EstimateResource($estimate);
    }

    /**
     * Экспорт сметы в PDF или XLSX
     */
    #[QueryParameter('format', description: 'Формат экспорта: pdf или xlsx', type: 'string', default: 'xlsx', example: 'xlsx')]
    public function export(Request $request, Estimate $estimate): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $estimate);

        $format = $request->query('format', 'xlsx');

        if (! in_array($format, ['pdf', 'xlsx'])) {
            return response()->json(['message' => 'Формат должен быть pdf или xlsx'], 422);
        }

        $estimate->load('items.product');

        $this->attachStoreOffers($request, $estimate->items);

        if ($format === 'xlsx') {
            return $this->exportXlsx($estimate);
        }

        return $this->exportPdf($estimate);
    }

    /**
     * Подставить товарам сметы офферы магазина контекста (сетевой каталог):
     * цена позиции и итог сметы считаются через резолвер цены
     * ({@see \App\Services\Pricing\ProductPriceResolver::quote()} /
     * priceForQuantity()), а он в сетевом режиме читает оффер, навешенный
     * здесь. Без контекста магазина берётся лучший оффер сети, при
     * выключенном модуле метод ничего не делает.
     *
     * @param  iterable<int, EstimateItem>  $items
     */
    private function attachStoreOffers(Request $request, iterable $items): void
    {
        $products = collect($items)
            ->map(fn (EstimateItem $item) => $item->product)
            ->filter();

        StoreOfferContext::attach(
            $products,
            app(StoreOfferContext::class)->resolveStoreId($request),
        );

        // Цены от количества (MID-308): лесенки всех товаров сметы одним запросом
        // (после навешивания офферов: в сетевом каталоге магазин лесенки = магазин оффера).
        app(ProductPriceResolver::class)->preloadProducts($products);
    }

    private function exportXlsx(Estimate $estimate): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Смета');

        $sheet->setCellValue('A1', 'Смета: '.$estimate->name);
        $sheet->mergeCells('A1:E1');

        $headers = ['A' => '№', 'B' => 'Артикул', 'C' => 'Название', 'D' => 'Кол-во', 'E' => 'Цена', 'F' => 'Сумма'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValue("{$col}3", $header);
        }

        $row = 4;
        $total = 0;
        $resolver = app(ProductPriceResolver::class);

        foreach ($estimate->items as $index => $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }

            $price = $resolver->priceForQuantity($product, (int) $item->quantity);
            $sum = $resolver->lineTotalForQuantity($product, (int) $item->quantity);
            $total += $sum;

            $sheet->setCellValue("A{$row}", $index + 1);
            $sheet->setCellValue("B{$row}", $product->sku ?? '');
            $sheet->setCellValue("C{$row}", $product->title);
            $sheet->setCellValue("D{$row}", $item->quantity);
            $sheet->setCellValue("E{$row}", $price);
            $sheet->setCellValue("F{$row}", $sum);
            $row++;
        }

        $sheet->setCellValue("E{$row}", 'Итого:');
        $sheet->setCellValue("F{$row}", $total);

        $filename = 'smeta_'.$estimate->id.'.xlsx';

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(Estimate $estimate): StreamedResponse
    {
        $resolver = app(ProductPriceResolver::class);

        $items = $estimate->items->map(function ($item, $index) use ($resolver) {
            $product = $item->product;
            $price = $product ? $resolver->priceForQuantity($product, (int) $item->quantity) : 0;

            return [
                'num' => $index + 1,
                'sku' => $product->sku ?? '',
                'title' => $product->title ?? '',
                'quantity' => $item->quantity,
                'price' => $price,
                'sum' => $product ? $resolver->lineTotalForQuantity($product, (int) $item->quantity) : 0,
            ];
        });

        $total = $items->sum('sum');

        $html = view('exports.estimate-pdf', [
            'estimate' => $estimate,
            'items' => $items,
            'total' => $total,
        ])->render();

        $pdf = Pdf::loadHTML($html)->setPaper('a4');
        $filename = 'smeta_'.$estimate->id.'.pdf';

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->output();
        }, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
