<?php

namespace App\Jobs;

use App\Models\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Job для обработки изображений (ресайз, оптимизация)
 */
class ProcessImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        protected int $imageId
    ) {}

    /**
     * Выполнение задачи
     */
    public function handle(): void
    {
        $image = Image::find($this->imageId);

        if (!$image) {
            Log::channel('1c-exchange')->warning("ProcessImagesJob: изображение не найдено #{$this->imageId}");
            return;
        }

        try {
            $this->processImage($image);

            Log::channel('1c-exchange')->info("ProcessImagesJob: обработано #{$this->imageId}");
        } catch (\Throwable $e) {
            Log::channel('1c-exchange')->error("ProcessImagesJob: ошибка - {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Обработка изображения
     */
    protected function processImage(Image $image): void
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($image->path)) {
            Log::channel('1c-exchange')->warning("Файл не найден: {$image->path}");
            return;
        }

        // Здесь можно добавить обработку изображений:
        // - Ресайз до нужных размеров
        // - Создание миниатюр
        // - Оптимизация/сжатие
        // - Конвертация в WebP

        // Пример с Intervention Image (если установлен):
        // $img = \Intervention\Image\Facades\Image::make($disk->path($image->path));
        // $img->resize(800, 800, function ($constraint) {
        //     $constraint->aspectRatio();
        //     $constraint->upsize();
        // });
        // $img->save();
    }

    /**
     * Обработка неудачного выполнения
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel('1c-exchange')->error("ProcessImagesJob: FAILED - {$exception->getMessage()}", [
            'image_id' => $this->imageId,
        ]);
    }
}
