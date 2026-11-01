<?php

namespace App\GraphQL\Mutations\AdminSetting;

use App\Helpers\Upload;
use App\Models\AdminSetting;

class SaveAdminSetting
{
    public function __invoke($_, array $args): AdminSetting
    {
        $key = $args['key'];
        $type = $args['type'];
        $value = $args['value'] ?? null;

        // Если передан файл — валидировать и загрузить
        if (isset($args['file']) && $args['file']) {
            $file = $args['file'];
            $allowed = ['image/png', 'image/svg+xml', 'image/jpeg', 'image/gif', 'image/webp'];
            if (!in_array($file->getMimeType(), $allowed)) {
                throw new \InvalidArgumentException('Допустимые форматы: PNG, SVG, JPEG, GIF, WebP');
            }
            $path = Upload::uploadFile($file, 'admin-settings');
            $value = $path;
        }

        // Если value = null и type = file — удалить настройку (сброс лого/баннера).
        // Eloquent delete (не query builder): observer публикует admin_setting.deleted,
        // иначе storefront-проекция остаётся навсегда.
        if ($value === null && $type === 'file') {
            AdminSetting::where('key', $key)->first()?->delete();
            return new AdminSetting(['key' => $key, 'value' => null, 'type' => $type]);
        }

        return AdminSetting::setValue($key, $value, $type);
    }
}
