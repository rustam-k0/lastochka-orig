<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Preorder\CreatePreorderRequest;
use App\Http\Resources\Api\V1\PreorderResource;
use App\Models\Preorder;
use App\Models\Product;
use App\Services\Settings\ModuleConfigService;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Preorders', weight: 11)]
final class PreorderController extends Controller
{
    public function __construct(
        private ModuleConfigService $moduleConfig,
    ) {}

    /**
     * Создать заявку "Под заказ"
     *
     * Создаёт заявку на товар без остатка.
     * Доступно только при включённом модуле preorders.
     */
    #[ScrambleResponse(201, type: 'array{data: PreorderResource}', description: 'Заявка создана')]
    #[ScrambleResponse(403, type: 'array{message: string}', description: 'Модуль "Под заказ" отключён')]
    public function store(CreatePreorderRequest $request, Product $product): JsonResponse
    {
        if (! $this->moduleConfig->isPreordersEnabled()) {
            abort(403, 'Модуль "Под заказ" отключён');
        }

        $preorder = Preorder::create([
            'user_id' => $request->user()->id,
            'product_id' => $product->id,
            'store_id' => $product->store_id,
            'full_name' => $request->validated('fullName'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'quantity' => $request->validated('quantity'),
            'comment' => $request->validated('comment'),
            'status' => 'new',
        ]);

        return (new PreorderResource($preorder))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Мои заявки "Под заказ"
     *
     * @response array{hasMore: bool, data: PreorderResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1)]
    #[QueryParameter('perPage', description: 'Элементов на странице (max: 50)', type: 'int', default: 20)]
    #[QueryParameter('status', description: 'Фильтр по статусу: new, completed, cancelled', type: 'string', required: false)]
    public function index(Request $request): JsonResponse
    {
        if (! $this->moduleConfig->isPreordersEnabled()) {
            abort(403, 'Модуль "Под заказ" отключён');
        }

        $perPage = min($request->integer('perPage', 20), 50);

        $query = Preorder::query()
            ->where('user_id', $request->user()->id)
            ->with(['product.images'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $preorders = $query->paginate($perPage);

        return response()->json([
            'hasMore' => $preorders->hasMorePages(),
            'data' => PreorderResource::collection($preorders),
            'total' => $preorders->total(),
        ]);
    }
}
