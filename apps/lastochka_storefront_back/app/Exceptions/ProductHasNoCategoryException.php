<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ProductHasNoCategoryException extends RuntimeException
{
    public function __construct(string $message = 'Продукт не имеет категории, добавление в сравнение невозможно')
    {
        parent::__construct($message);
    }
}
