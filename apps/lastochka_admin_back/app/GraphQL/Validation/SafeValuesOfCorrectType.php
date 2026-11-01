<?php

namespace App\GraphQL\Validation;

use GraphQL\Language\AST\ListValueNode;
use GraphQL\Language\AST\NodeKind;
use GraphQL\Language\Visitor;
use GraphQL\Validator\Rules\ValuesOfCorrectType;
use GraphQL\Validator\ValidationContext;

/**
 * Обходит TypeError в graphql-php v14 на списочном значении неизвестного аргумента.
 *
 * Штатный визитор для NodeKind::LST вызывает Type::getNullableType($context->getParentInputType()),
 * но у неизвестного аргумента родительского input-типа нет, а сигнатура getNullableType(Type $type)
 * в 14-й ветке не допускает null. Из-за этого запрос вида `{ field(unknownArg: [0]) }` отдаёт 500
 * вместо ошибки валидации. В 15-й ветке сигнатура допускает null, но она требует Lighthouse 6.
 *
 * Пропускаем узел: сам аргумент всё равно отбракует правило KnownArgumentNames,
 * поэтому клиент получает корректное сообщение «Unknown argument».
 */
class SafeValuesOfCorrectType extends ValuesOfCorrectType
{
  public function getVisitor(ValidationContext $context)
  {
    $visitor = parent::getVisitor($context);

    if (! isset($visitor[NodeKind::LST])) {
      return $visitor;
    }

    $original = $visitor[NodeKind::LST];

    $visitor[NodeKind::LST] = static function (ListValueNode $node) use ($context, $original) {
      if ($context->getParentInputType() === null) {
        return Visitor::skipNode();
      }

      return $original($node);
    };

    return $visitor;
  }
}
