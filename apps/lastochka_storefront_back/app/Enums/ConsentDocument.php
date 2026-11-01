<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Документы, согласие с которыми фиксируется в user_consents.
 */
enum ConsentDocument: string
{
    case PrivacyPolicy = 'privacy_policy';
    case PersonalData = 'personal_data';
    case PublicOffer = 'public_offer';
    case Adult = 'adult';
}
