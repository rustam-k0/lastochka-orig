<?php

namespace App\Exports;

use App\Models\Category;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\Exportable;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CategoriesExport implements FromCollection, WithHeadings, WithStyles, WithColumnWidths
{
    use Exportable;

    private bool $activeOnly;
    private array $rowLevels = [];

    public function __construct(bool $activeOnly = false)
    {
        $this->activeOnly = $activeOnly;
    }

    public function collection(): Collection
    {
        $query = Category::query()->with('parent')->orderBy('sort');

        if ($this->activeOnly) {
            $query->active();
        }

        $categories = $query->get();
        $result = collect();

        // Строим дерево начиная с корневых категорий
        $rootCategories = $categories->whereNull('parent_id');

        foreach ($rootCategories as $category) {
            $this->addCategoryWithChildren($result, $category, $categories, 0);
        }

        return $result;
    }

    /**
     * Рекурсивно добавляем категорию и её детей
     */
    private function addCategoryWithChildren(
        Collection $result,
        Category $category,
        Collection $allCategories,
        int $level
    ): void {
        // Визуальное дерево с символами
        $prefix = $this->buildTreePrefix($level);
        
        $this->rowLevels[] = $level;

        $result->push([
            'tree' => $prefix . $category->name,
            'id' => $category->id,
            'external_id' => $category->external_id,
            'is_active' => $category->is_active ? '✓' : '✗',
            'sort' => $category->sort,
        ]);

        // Находим дочерние категории
        $children = $allCategories->where('parent_id', $category->id)->sortBy('sort');

        foreach ($children as $child) {
            $this->addCategoryWithChildren($result, $child, $allCategories, $level + 1);
        }
    }

    /**
     * Построить визуальный префикс дерева
     */
    private function buildTreePrefix(int $level): string
    {
        if ($level === 0) {
            return '';
        }

        // Используем пробелы и символы для визуального дерева
        $indent = str_repeat('    ', $level - 1);
        return $indent . '└── ';
    }

    public function headings(): array
    {
        return [
            'Дерево категорий',
            'ID',
            'External ID (1С)',
            'Активна',
            'Сорт.',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 60,  // Дерево - широкая колонка
            'B' => 8,
            'C' => 38,
            'D' => 10,
            'E' => 8,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [
            1 => [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ];

        // Подсветка корневых категорий (уровень 0)
        foreach ($this->rowLevels as $index => $level) {
            $row = $index + 2; // +2 потому что 1 - заголовок, и индекс с 0
            if ($level === 0) {
                $styles[$row] = [
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'FEF3C7'], // желтый для корневых
                    ],
                ];
            }
        }

        return $styles;
    }
}
