<?php

namespace App\GraphQL\Directives;

use App\Models\AdminSetting;
use App\Models\User;
use App\Support\OrderActionAccess;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class OrderActionDirective extends BaseDirective implements FieldMiddleware
{
  public static function definition(): string
  {
    return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Ограничивает действие с заказом разрешением orders.<key> при включённом
фича-флаге role_access_matrix_enabled. Применяется только к сотрудникам
(guard api); клиентскую ветку (guard client) не трогает. При выключенном
флаге пропускает (1:1 со старым поведением).
"""
directive @orderAction(
  "Короткий ключ действия: cancel | change_status | change_shop | ..."
  key: String!
) on FIELD_DEFINITION
GRAPHQL;
  }

  public function handleField(FieldValue $fieldValue, Closure $next): FieldValue
  {
    $key = $this->directiveArgValue('key');
    $resolver = $fieldValue->getResolver();

    $fieldValue->setResolver(function ($root, array $args, GraphQLContext $context, ResolveInfo $info) use ($resolver, $key) {
      if (AdminSetting::getValue('role_access_matrix_enabled') === '1') {
        // Резолвим строго guard api (staff): не зависит от порядка директив
        // и от дефолтного guard'а Lighthouse (в purchase это client)
        $staff = auth()->guard('api')->user();

        if ($staff instanceof User && !OrderActionAccess::allowedUnderMatrix($staff, "orders.$key")) {
          throw new AuthorizationException("Нет доступа к действию: $key");
        }
      }

      return $resolver($root, $args, $context, $info);
    });

    return $next($fieldValue);
  }
}
