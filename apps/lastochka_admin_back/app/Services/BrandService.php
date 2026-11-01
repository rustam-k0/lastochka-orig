<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Status;
use Illuminate\Validation\ValidationException;

class BrandService
{
    /**
     * Поля стран: название => код.
     */
    private const COUNTRY_FIELDS = [
        'country_origin' => 'country_origin_code',
        'country_production' => 'country_production_code',
    ];

    /**
     * Разбор кодов стран из аргументов мутации.
     *
     * Код — источник истины: если он передан, название берётся из справочника,
     * чтобы текст и флаг на клиенте не разъезжались. Явный null очищает пару
     * «код + название», отсутствие ключа не трогает сохранённое значение.
     *
     * @throws ValidationException если код отсутствует в справочнике
     */
    public function resolveCountryFields(array $args): array
    {
        $directory = new CountryDirectory();
        $data = [];

        foreach (self::COUNTRY_FIELDS as $titleField => $codeField) {
            if (!array_key_exists($codeField, $args)) {
                continue;
            }

            $code = $args[$codeField];

            if ($code === null || trim((string) $code) === '') {
                $data[$codeField] = null;
                $data[$titleField] = null;

                continue;
            }

            if (!$directory->isValidCode((string) $code)) {
                throw ValidationException::withMessages([
                    $codeField => ['Неизвестный код страны: ' . $code],
                ]);
            }

            $normalized = mb_strtoupper(trim((string) $code));

            $data[$codeField] = $normalized;
            $data[$titleField] = $directory->titleByCode($normalized);
        }

        return $data;
    }

    /**
     * Заполнение логотипа бренда
     */
    public function fillLogo(Brand $brand, array $args): void
    {
        if (!isset($args['logo_image'])) {
            return;
        }

        $existingJoin = $brand->joinLogoImage;

        if ($existingJoin) {
            if ($args['logo_image'] === null || $args['logo_image'] === []) {
                $brand->deleteImg($existingJoin);
            } else {
                $brand->updateImg($existingJoin, $args['logo_image']);
            }
        } else {
            if ($args['logo_image'] !== null && $args['logo_image'] !== []) {
                $brand->saveImg($args['logo_image'], Status::CODE_BRAND_LOGO);
            }
        }
    }
}
