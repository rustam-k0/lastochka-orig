// Способ наполнения товарной группы: manual — товары выбираются вручную,
// остальные — автоматические подборки, товары считает витрина
export const FILL_TYPE_MANUAL = "manual";

export const FILL_TYPE_OPTIONS = [
  { value: "manual", label: "Вручную" },
  { value: "purchase_history", label: "Из истории заказов" },
  { value: "viewed_not_bought", label: "Смотрел, но не купил" },
  { value: "is_new", label: "Новинки" },
  { value: "is_hurry_up", label: "Успей купить" },
];

export function isAutoFillType(fillType) {
  return !!fillType && fillType !== FILL_TYPE_MANUAL;
}

export function fillTypeLabel(fillType) {
  return FILL_TYPE_OPTIONS.find((o) => o.value === fillType)?.label || "";
}
