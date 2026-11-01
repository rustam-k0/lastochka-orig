<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\CartItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Ответ API на не найденную модель вместо стандартного текста Laravel
 * «No query results for model [App\Models\...] N» с именем класса.
 *
 * Laravel превращает ModelNotFoundException в NotFoundHttpException до вызова
 * render-колбэков, поэтому исходное исключение ищем в previous.
 */
final class ModelNotFoundRenderer
{
    /** @var array<class-string, array{0:string, 1:string}> */
    private const ERRORS = [
        CartItem::class => ['CART_ITEM_NOT_FOUND', 'Позиция уже удалена из корзины'],
    ];

    private const DEFAULT_ERROR = ['NOT_FOUND', 'Запрашиваемый объект не найден'];

    public static function render(NotFoundHttpException $e, Request $request): ?JsonResponse
    {
        $previous = $e->getPrevious();

        if (! $previous instanceof ModelNotFoundException || ! $request->is('api/*')) {
            return null;
        }

        [$code, $message] = self::ERRORS[$previous->getModel()] ?? self::DEFAULT_ERROR;

        return response()->json([
            'error' => $code,
            'message' => $message,
        ], 404);
    }
}
