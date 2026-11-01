<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * MID-227: в валидационных сообщениях название поля выводилось на латинице
 * («Количество символов в поле meta description не может превышать 255»).
 * Тексты правил локализованы, а имена атрибутов (:attribute) должны браться
 * из resources/lang/ru/validation.php → attributes.
 */
class ValidationAttributeTranslationsTest extends TestCase
{
    /**
     * @dataProvider attributeProvider
     */
    public function test_attribute_is_translated(string $field, string $expected): void
    {
        $validator = Validator::make(
            [$field => str_repeat('а', 300)],
            [$field => 'max:255']
        );

        $message = $validator->errors()->first($field);

        $this->assertStringContainsString($expected, $message);
        $this->assertStringNotContainsString(str_replace('_', ' ', $field), $message);
    }

    public static function attributeProvider(): array
    {
        return [
            'meta_description' => ['meta_description', 'Мета описание'],
            'meta_title' => ['meta_title', 'Мета заголовок'],
            'articul' => ['articul', 'Артикул'],
            'additional_info' => ['additional_info', 'Дополнительная информация'],
            'short_description' => ['short_description', 'Краткое описание'],
        ];
    }

    public function test_nested_wildcard_attribute_is_translated(): void
    {
        $validator = Validator::make(
            ['supplements' => [['title' => str_repeat('а', 300)]]],
            ['supplements.*.title' => 'max:255']
        );

        $message = $validator->errors()->first('supplements.0.title');

        $this->assertStringContainsString('Название добавки', $message);
    }
}
