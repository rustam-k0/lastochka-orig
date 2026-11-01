<?php

namespace App\GraphQL\Queries\Country;

use App\Services\CountryDirectory;

final class CountryList
{
    /**
     * Справочник стран для выбора в админке.
     *
     * @param  null  $_
     * @param  array{}  $args
     * @return array<int, array{code: string, title: string}>
     */
    public function __invoke($_, array $args): array
    {
        return (new CountryDirectory())->all();
    }
}
