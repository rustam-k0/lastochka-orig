<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MyReviewResource;
use App\Models\OrderReview;
use App\Models\ProductReview;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

#[Group('Reviews', weight: 12)]
final class MyReviewsController extends Controller
{
    /**
     * Все мои отзывы
     *
     * Возвращает список всех отзывов текущего пользователя (на товары и заказы)
     * с пагинацией и сортировкой по дате создания (новые сверху), при равенстве —
     * по id по убыванию. Отзывы на магазины в выдачу не входят. Удалённые
     * (soft deleted) отзывы исключаются, неопубликованные отзывы на товары
     * возвращаются.
     *
     * @response array{hasMore: bool, data: MyReviewResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы (значения меньше 1 приводятся к 1)', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество элементов на странице (приводится к диапазону 1…100)', type: 'int', default: 20, example: 20)]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $page = max(1, $request->integer('page', 1));
        $perPage = min(max(1, $request->integer('perPage', 20)), 100);
        $offset = ($page - 1) * $perPage;

        $productReviewsQuery = DB::table('product_reviews')
            ->select([
                'id',
                DB::raw("'product' as type"),
                'created_at',
            ])
            ->where('user_id', $user->id)
            ->whereNull('deleted_at');

        $orderReviewsQuery = DB::table('order_reviews')
            ->select([
                'id',
                DB::raw("'order' as type"),
                'created_at',
            ])
            ->where('user_id', $user->id)
            ->whereNull('deleted_at');

        $union = $productReviewsQuery->unionAll($orderReviewsQuery);

        $slice = DB::query()
            ->fromSub($union, 'u')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $productTotal = DB::table('product_reviews')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->count();

        $orderTotal = DB::table('order_reviews')
            ->where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->count();

        $total = $productTotal + $orderTotal;

        $productIds = $slice->where('type', 'product')->pluck('id')->all();
        $orderIds = $slice->where('type', 'order')->pluck('id')->all();

        $productReviews = ProductReview::with('product.images')
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        $orderReviews = OrderReview::query()
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $ordered = $slice
            ->map(fn ($row) => $row->type === 'product'
                ? $productReviews->get((int) $row->id)
                : $orderReviews->get((int) $row->id))
            ->filter()
            ->values();

        $hasMore = ($offset + $slice->count()) < $total;

        return response()->json([
            'hasMore' => $hasMore,
            'data' => MyReviewResource::collection($ordered),
            'total' => $total,
        ]);
    }
}
