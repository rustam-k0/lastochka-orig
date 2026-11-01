// Справочники раздела «Работа в компании».
// Значения совпадают с enum VacancyWorkFormat / VacancyEmploymentType в GraphQL.

export const VACANCY_WORK_FORMATS = [
  { label: "Офис", value: "OFFICE" },
  { label: "Удалённая работа", value: "REMOTE" },
  { label: "Гибридный формат", value: "HYBRID" },
  { label: "Разъездной формат", value: "FIELD" },
];

export const VACANCY_EMPLOYMENT_TYPES = [
  { label: "Полная занятость", value: "FULL" },
  { label: "Частичная занятость", value: "PART" },
  { label: "Временная занятость", value: "TEMPORARY" },
  { label: "Иная занятость", value: "OTHER" },
];

export const VACANCY_WORK_FORMAT_LABELS = VACANCY_WORK_FORMATS.reduce(
  (acc, item) => ({ ...acc, [item.value]: item.label }),
  {}
);

export const VACANCY_EMPLOYMENT_TYPE_LABELS = VACANCY_EMPLOYMENT_TYPES.reduce(
  (acc, item) => ({ ...acc, [item.value]: item.label }),
  {}
);
