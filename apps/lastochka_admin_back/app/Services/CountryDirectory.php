<?php

namespace App\Services;

/**
 * Справочник стран ISO 3166-1 alpha-2.
 *
 * Код страны — то, по чему клиенты (сайт, мобильное приложение) рисуют флаг.
 * Названия остаются для текста, но источником истины считается код.
 */
class CountryDirectory
{
    /**
     * Карта "нормализованное название => код" для списка и алиасов.
     *
     * @var array<string, string>|null
     */
    private static ?array $titleIndex = null;

    /**
     * Список стран: код => название.
     *
     * @return array<string, string>
     */
    public function map(): array
    {
        return config('countries.list', []);
    }

    /**
     * Полный список для интерфейса, отсортированный по названию.
     *
     * @return array<int, array{code: string, title: string}>
     */
    public function all(): array
    {
        $list = [];

        foreach ($this->map() as $code => $title) {
            $list[] = ['code' => $code, 'title' => $title];
        }

        usort($list, fn (array $a, array $b) => strcmp($a['title'], $b['title']));

        return $list;
    }

    /**
     * Название страны по коду.
     */
    public function titleByCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return $this->map()[mb_strtoupper(trim($code))] ?? null;
    }

    /**
     * Код страны по названию: канонические названия и распространённые алиасы.
     * Регистр, лишние пробелы, буква «ё» и типографские апострофы/тире не важны.
     */
    public function codeByTitle(?string $title): ?string
    {
        if ($title === null) {
            return null;
        }

        $key = $this->normalize($title);

        if ($key === '') {
            return null;
        }

        return $this->titleIndex()[$key] ?? null;
    }

    /**
     * Есть ли такой код в справочнике.
     */
    public function isValidCode(?string $code): bool
    {
        return $this->titleByCode($code) !== null;
    }

    /**
     * Индекс "нормализованное название => код".
     *
     * @return array<string, string>
     */
    private function titleIndex(): array
    {
        if (self::$titleIndex !== null) {
            return self::$titleIndex;
        }

        $index = [];

        foreach ($this->map() as $code => $title) {
            $index[$this->normalize($title)] = $code;
        }

        // Алиасы не перекрывают канонические названия.
        foreach (config('countries.aliases', []) as $alias => $code) {
            $key = $this->normalize($alias);

            if (! isset($index[$key])) {
                $index[$key] = $code;
            }
        }

        return self::$titleIndex = $index;
    }

    /**
     * Приведение названия к виду, пригодному для сравнения.
     */
    private function normalize(string $value): string
    {
        // Типографские апострофы и тире различаются в разных источниках данных.
        $value = str_replace(
            ['’', '‘', '`', '´', '–', '—', '‑', '−'],
            ["'", "'", "'", "'", '-', '-', '-', '-'],
            $value,
        );

        $value = mb_strtolower(trim($value));
        $value = str_replace('ё', 'е', $value);

        return (string) preg_replace('/\s+/u', ' ', $value);
    }

    /**
     * Сброс кеша индекса — нужен тестам и командам, меняющим конфиг на лету.
     */
    public static function flushIndex(): void
    {
        self::$titleIndex = null;
    }
}
