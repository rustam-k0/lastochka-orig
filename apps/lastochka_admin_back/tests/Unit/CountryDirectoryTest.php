<?php

namespace Tests\Unit;

use App\Services\CountryDirectory;
use Tests\TestCase;

class CountryDirectoryTest extends TestCase
{
    private CountryDirectory $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = new CountryDirectory();
    }

    /** @test */
    public function title_by_code_returns_russian_name(): void
    {
        $this->assertSame('Россия', $this->directory->titleByCode('RU'));
        $this->assertSame('Китай', $this->directory->titleByCode('CN'));
    }

    /** @test */
    public function title_by_code_is_case_insensitive(): void
    {
        $this->assertSame('Россия', $this->directory->titleByCode('ru'));
    }

    /** @test */
    public function title_by_code_returns_null_for_unknown_code(): void
    {
        $this->assertNull($this->directory->titleByCode('XX'));
        $this->assertNull($this->directory->titleByCode(null));
        $this->assertNull($this->directory->titleByCode(''));
    }

    /** @test */
    public function code_by_title_resolves_canonical_name(): void
    {
        $this->assertSame('RU', $this->directory->codeByTitle('Россия'));
        $this->assertSame('CN', $this->directory->codeByTitle('Китай'));
    }

    /** @test */
    public function code_by_title_ignores_case_and_spaces(): void
    {
        $this->assertSame('RU', $this->directory->codeByTitle('  россия '));
        $this->assertSame('DE', $this->directory->codeByTitle('ГЕРМАНИЯ'));
    }

    /** @test */
    public function code_by_title_resolves_common_aliases(): void
    {
        $this->assertSame('RU', $this->directory->codeByTitle('РФ'));
        $this->assertSame('RU', $this->directory->codeByTitle('Российская Федерация'));
        $this->assertSame('US', $this->directory->codeByTitle('США'));
        $this->assertSame('KR', $this->directory->codeByTitle('Республика Корея'));
        $this->assertSame('KR', $this->directory->codeByTitle('Южная Корея'));
        $this->assertSame('GB', $this->directory->codeByTitle('Великобритания'));
    }

    /** @test */
    public function code_by_title_ignores_yo_letter(): void
    {
        $this->assertSame('TC', $this->directory->codeByTitle('Острова Тёркс и Кайкос'));
        $this->assertSame('TC', $this->directory->codeByTitle('Острова Теркс и Кайкос'));
    }

    /** @test */
    public function code_by_title_normalizes_typographic_apostrophe_and_dash(): void
    {
        $this->assertSame('CI', $this->directory->codeByTitle('Кот-д'."'".'Ивуар'));
        $this->assertSame('CI', $this->directory->codeByTitle('Кот-д’Ивуар'));
        $this->assertSame('CI', $this->directory->codeByTitle('Кот–д’Ивуар'));
    }

    /** @test */
    public function code_by_title_returns_null_for_unknown_title(): void
    {
        $this->assertNull($this->directory->codeByTitle('Нарния'));
        $this->assertNull($this->directory->codeByTitle(null));
        $this->assertNull($this->directory->codeByTitle('   '));
    }

    /** @test */
    public function is_valid_code_checks_directory(): void
    {
        $this->assertTrue($this->directory->isValidCode('RU'));
        $this->assertTrue($this->directory->isValidCode('cn'));
        $this->assertFalse($this->directory->isValidCode('XX'));
        $this->assertFalse($this->directory->isValidCode(''));
    }

    /** @test */
    public function all_returns_full_iso_list_sorted_by_title(): void
    {
        $all = $this->directory->all();

        $this->assertGreaterThanOrEqual(249, count($all));

        $first = $all[0];
        $this->assertArrayHasKey('code', $first);
        $this->assertArrayHasKey('title', $first);

        $titles = array_column($all, 'title');
        $sorted = $titles;
        usort($sorted, fn ($a, $b) => strcmp($a, $b));
        $this->assertSame($sorted, $titles, 'Список стран должен быть отсортирован по названию');

        $codes = array_column($all, 'code');
        $this->assertSame($codes, array_unique($codes), 'Коды стран должны быть уникальны');
        $this->assertContains('RU', $codes);
        $this->assertContains('CN', $codes);
    }
}
