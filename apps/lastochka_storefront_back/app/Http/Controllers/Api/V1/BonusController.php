<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BonusTransactionResource;
use App\Models\BonusTransaction;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Bonuses', weight: 7)]
final class BonusController extends Controller
{
    /**
     * История бонусных транзакций
     *
     * Возвращает историю начислений и списаний бонусов текущего пользователя.
     *
     * @response array{balance: int, hasMore: bool, data: BonusTransactionResource[], total: int}
     */
    #[QueryParameter('page', description: 'Номер страницы', type: 'int', default: 1, example: 1)]
    #[QueryParameter('perPage', description: 'Количество на странице (max: 100)', type: 'int', default: 20, example: 20)]
    public function index(Request $request): JsonResponse
    {
        $perPage = min($request->integer('perPage', 20), 100);

        $transactions = BonusTransaction::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return response()->json([
            'balance' => (int) ($request->user()->available_bonus_balance ?? 0),
            'hasMore' => $transactions->hasMorePages(),
            'data' => BonusTransactionResource::collection($transactions),
            'total' => $transactions->total(),
        ]);
    }
}
