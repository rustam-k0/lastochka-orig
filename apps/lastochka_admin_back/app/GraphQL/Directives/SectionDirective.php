<?php

namespace App\GraphQL\Directives;

use App\Models\User;
use App\Support\SectionAccess;
use Closure;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SectionDirective extends BaseDirective implements FieldMiddleware
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Ограничивает доступ к полю разрешением section.<key>, если включён
фича-флаг role_access_matrix_enabled. При выключенном флаге пропускает (1:1 со старым).
"""
directive @section(
  "Ключ раздела (совпадает с menu_items.name)"
  key: String!
  "Альтернативные разделы: доступ даёт ЛЮБОЙ из key/anyOf (для полей двойного назначения, напр. ShopUpdate из «Магазины» и «Мой магазин»)"
  anyOf: [String!]
) on FIELD_DEFINITION
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue, Closure $next): FieldValue
    {
        $key = $this->directiveArgValue('key');
        $keys = array_values(array_unique(array_merge(
            [$key],
            $this->directiveArgValue('anyOf') ?? []
        )));
        $resolver = $fieldValue->getResolver();

        $fieldValue->setResolver(function ($root, array $args, GraphQLContext $context, ResolveInfo $info) use ($resolver, $key, $keys) {
            // Правило доступа (матрица, суперпользователи, гранты section.<key>) —
            // в SectionAccess, общем с REST-эндпоинтами /action/*
            if (SectionAccess::enabled()) {
                /** @var User|null $user */
                $user = $context->user();

                if ($user === null) {
                    throw new AuthorizationException('Не авторизован.');
                }

                if (!SectionAccess::allowedAny($user, $keys)) {
                    throw new AuthorizationException("Нет доступа к разделу: $key");
                }
            }

            return $resolver($root, $args, $context, $info);
        });

        return $next($fieldValue);
    }
}
