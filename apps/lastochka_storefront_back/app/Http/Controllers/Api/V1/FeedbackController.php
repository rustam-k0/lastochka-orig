<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DTOs\Feedback\FeedbackData;
use App\Enums\ConsentContext;
use App\Enums\ConsentDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Feedback\SendFeedbackRequest;
use App\Mail\FeedbackMail;
use App\Services\Consent\ConsentService;
use App\Support\ClientIp;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Mail;

#[Group('Feedback', weight: 16)]
final class FeedbackController extends Controller
{
    /**
     * Отправить обращение
     *
     * Ставит письмо с обращением в очередь на единый адрес заказчика
     * (env FEEDBACK_EMAIL). В базе и админке обращение не сохраняется.
     * Ручка публичная, доступна гостям; согласие на обработку персональных
     * данных обязательно и фиксируется в user_consents.
     *
     * @response array{message: string}
     */
    #[ScrambleResponse(422, type: 'array{message: string, errors: array<string, string[]>}', description: 'Ошибка валидации полей формы либо не дано согласие на обработку персональных данных')]
    public function store(SendFeedbackRequest $request): JsonResponse
    {
        $data = FeedbackData::fromValidated($request->validated());

        Mail::to((string) config('feedback.email'))->send(new FeedbackMail($data));

        // MENDEV-7: обращение = согласие на обработку перс. данных.
        // Роут гостевой — без юзера согласие пишется с контактами из формы
        app(ConsentService::class)->record(
            $request->user('sanctum'),
            [ConsentDocument::PersonalData],
            ConsentContext::Feedback,
            ip: ClientIp::fromRequest($request),
            meta: array_filter([
                'name' => $data->name,
                'phone' => $data->phone,
            ], static fn (?string $value): bool => $value !== null),
        );

        return response()->json([
            'message' => 'Обращение успешно отправлено',
        ]);
    }
}
