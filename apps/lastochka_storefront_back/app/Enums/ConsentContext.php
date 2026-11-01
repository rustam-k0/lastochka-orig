<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Источник (точка сбора) согласия в user_consents.
 */
enum ConsentContext: string
{
    case Auth = 'auth';
    case AdultConfirm = 'adult_confirm';
    case Order = 'order';
    case Vacancy = 'vacancy';
    case Feedback = 'feedback';
}
