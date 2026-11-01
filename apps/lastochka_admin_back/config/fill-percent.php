<?php

/*
 * Веса полей для расчёта % заполненности карточки товара
 * (LASTOCHKI-JAMI-11653, принцип из ЗЯ / zelenoe_admin_back).
 * Веса атрибутов живут в таблице default_attributes.
 * Итоговый процент нормализуется к 100 от суммы всех весов.
 */
return [
  'fields' => [
    'base' => 1,        // сам факт существования товара
    'img' => 25,        // главное фото
    'articul' => 5,
    'price' => 5,       // цена PRICE_DEFAULT со value > 0
    'barcode' => 1,
    'description' => 1,
    'meta' => 1,        // meta_title + meta_description + meta_keywords, все три
    'category' => 1,    // привязана хотя бы одна категория
  ],
];
