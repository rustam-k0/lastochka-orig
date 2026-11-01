<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Img;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * img.source_url — URL, с которого файл скачан обменом 1С (MID-305).
 * Замена файла без нового source_url (ручная замена в админке) источник
 * сбрасывает: иначе следующий обмен с тем же URL счёл бы фото уже скачанным.
 */
class ImgSourceUrlTest extends TestCase
{
    use DatabaseTransactions;

    private function img(): Img
    {
        return Img::create([
            'path' => '/storage/products/onec/a.jpg',
            'origin_name' => 'a.jpg',
            'alt' => 'Фото',
            'size' => '1 КБ',
            'type' => 'jpg',
            'source_url' => 'https://img.test/a.jpg',
        ]);
    }

    public function test_path_change_without_source_url_resets_source(): void
    {
        $img = $this->img();

        $img->update(['path' => '/files/cropped/manual.jpg', 'origin_name' => 'manual.jpg']);

        $this->assertNull($img->fresh()->source_url);
    }

    public function test_path_change_with_new_source_url_keeps_it(): void
    {
        $img = $this->img();

        $img->update(['path' => '/storage/products/onec/b.jpg', 'source_url' => 'https://img.test/b.jpg']);

        $this->assertSame('https://img.test/b.jpg', $img->fresh()->source_url);
    }

    public function test_update_without_path_change_keeps_source(): void
    {
        $img = $this->img();

        $img->update(['alt' => 'Новое описание']);

        $this->assertSame('https://img.test/a.jpg', $img->fresh()->source_url);
    }
}
