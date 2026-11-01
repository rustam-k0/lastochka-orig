<?php

return [
  // Роли, которые 1С может назначать сотрудникам (MID-306). Пользователей с ролями вне списка 1С не изменяет.
  // superadmin и api не назначаются никогда, даже если вписаны в список.
  'employee_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('ONEC_EMPLOYEE_ROLES', 'collector,courier,admin'))))),
];
