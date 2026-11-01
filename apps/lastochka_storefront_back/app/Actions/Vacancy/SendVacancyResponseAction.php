<?php

declare(strict_types=1);

namespace App\Actions\Vacancy;

use App\DTOs\Vacancy\VacancyResponseData;
use App\Mail\VacancyResponseMail;
use App\Models\Vacancy;
use Illuminate\Support\Facades\Mail;

final class SendVacancyResponseAction
{
    /**
     * Отправляет отклик письмом на единый адрес заказчика.
     *
     * Отправка синхронная: пользователю нельзя показать «отклик отправлен»,
     * пока письмо реально не ушло. Исключение пробрасывается наверх —
     * контроллер превращает его в ошибку отправки.
     */
    public function execute(Vacancy $vacancy, VacancyResponseData $data): void
    {
        $recipient = (string) config('vacancies.responses_email');

        Mail::to($recipient)->send(new VacancyResponseMail($vacancy, $data));
    }
}
