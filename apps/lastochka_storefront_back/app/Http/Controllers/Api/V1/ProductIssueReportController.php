<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIssueReport\CreateProductIssueReportRequest;
use App\Http\Resources\Api\V1\ProductIssueReportResource;
use App\Models\Product;
use App\Models\ProductIssueReport;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;

#[Group('Product Issue Reports', weight: 12)]
final class ProductIssueReportController extends Controller
{
    public function __construct(
        private ModuleConfigService $moduleConfig,
    ) {}

    /**
     * Сообщить о неточности в товаре
     *
     * Создаёт заявку о неточности в описании или характеристиках товара.
     * Доступно только при включённом модуле product_issue_reports.
     */
    #[ScrambleResponse(201, type: 'array{data: ProductIssueReportResource}', description: 'Заявка о неточности создана')]
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Модуль "Сообщить о неточности" отключён')]
    public function store(CreateProductIssueReportRequest $request, Product $product): JsonResponse
    {
        if (! $this->moduleConfig->isIssueReportsEnabled()) {
            abort(403, 'Модуль "Сообщить о неточности" отключён');
        }

        $report = ProductIssueReport::create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'store_id' => $product->store_id,
            'email' => $request->validated('email'),
            'type' => $request->validated('type'),
            'message' => $request->validated('message'),
        ]);

        return (new ProductIssueReportResource($report))
            ->response()
            ->setStatusCode(201);
    }
}
