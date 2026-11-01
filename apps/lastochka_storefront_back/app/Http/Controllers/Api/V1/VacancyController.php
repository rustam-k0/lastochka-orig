<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vacancy\SendVacancyResponseAction;
use App\DTOs\Vacancy\VacancyResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Vacancy\VacancyResponseRequest;
use App\Http\Resources\Api\V1\VacancyListResource;
use App\Http\Resources\Api\V1\VacancyResource;
use App\Models\Vacancy;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ScrambleResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Throwable;

#[Group('Vacancies')]
final class VacancyController extends Controller
{
    /**
     * Список вакансий
     *
     * Возвращает опубликованные вакансии раздела «Работа в компании»
     * в порядке отображения (position по возрастанию, вакансии без позиции — в конце).
     * Пагинации и фильтров нет.
     *
     * @response array{data: VacancyListResource[]}
     */
    public function index(): AnonymousResourceCollection
    {
        $vacancies = Vacancy::query()
            ->published()
            ->ordered()
            ->get();

        return VacancyListResource::collection($vacancies);
    }

    /**
     * Карточка вакансии
     *
     * Возвращает подробное описание вакансии. Неопубликованная
     * или снятая с публикации вакансия отдаёт 404.
     *
     * @response array{data: VacancyResource}
     */
    #[ScrambleResponse(404, description: 'Вакансия не найдена или снята с публикации', type: 'array{message: string}')]
    public function show(Vacancy $vacancy): VacancyResource
    {
        abort_unless($vacancy->is_published, 404);

        return new VacancyResource($vacancy);
    }

    /**
     * Отклик на вакансию
     *
     * Принимает анкету кандидата с резюме (multipart/form-data) и отправляет её
     * письмом на почту заказчика. Отклик, резюме и контакты кандидата в системе
     * не сохраняются и в админ-панели не отображаются.
     *
     * Отправка синхронная: успех возвращается только после того, как письмо ушло.
     */
    #[ScrambleResponse(200, description: 'Отклик отправлен', type: 'array{message: string}')]
    #[ScrambleResponse(404, description: 'Вакансия не найдена или снята с публикации', type: 'array{message: string}')]
    #[ScrambleResponse(422, description: 'Анкета заполнена некорректно', type: 'array{message: string, errors: array<string, string[]>}')]
    #[ScrambleResponse(502, description: 'Письмо с откликом отправить не удалось', type: 'array{message: string}')]
    public function respond(
        VacancyResponseRequest $request,
        Vacancy $vacancy,
        SendVacancyResponseAction $action,
    ): JsonResponse {
        // Отклик принимается только на опубликованную вакансию.
        abort_unless($vacancy->is_published, 404);

        $data = VacancyResponseData::fromRequest(
            $request->file('resume'),
            $request->validated(),
        );

        try {
            $action->execute($vacancy, $data);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'Не удалось отправить отклик. Попробуйте ещё раз позже.',
            ], 502);
        }

        // MENDEV-7: отклик = согласие на обработку перс. данных. Роут гостевой,
        // юзера может не быть — тогда согласие пишется с контактами из формы
        app(\App\Services\Consent\ConsentService::class)->record(
            $request->user('sanctum'),
            [\App\Enums\ConsentDocument::PersonalData],
            \App\Enums\ConsentContext::Vacancy,
            ip: \App\Support\ClientIp::fromRequest($request),
            meta: [
                'vacancy_id' => $vacancy->id,
                'name' => $request->validated('name'),
                'phone' => $request->validated('phone'),
            ],
        );

        return response()->json([
            'message' => 'Отклик успешно отправлен',
        ]);
    }
}
