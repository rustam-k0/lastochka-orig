<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Payment\ReturnUrl;

/**
 * Приводит адрес возврата после оплаты к абсолютному виду сразу на входе:
 * дальше по цепочке (сервис → шлюз → лог платежа) едет готовое значение,
 * а не относительный путь, который каждый слой склеивал бы сам.
 *
 * Допустимость адреса уже проверена {@see \App\Rules\ReturnUrlRule},
 * поэтому null здесь означает «клиент ничего не передал» — шлюз подставит
 * дефолтный адрес из конфига.
 */
trait ResolvesReturnUrl
{
    public function returnUrl(): ?string
    {
        $value = $this->validated('returnUrl');

        return is_string($value) ? ReturnUrl::normalize($value) : null;
    }
}
