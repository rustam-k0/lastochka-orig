import { useAuthStore } from "@/stores/auth.js";
import apollo from "@/apollo.js";
import ME from "@/graphql/queries/me.graphql";
import PROJECT_MODULES from "@/graphql/queries/projectModules.graphql";
import ENTITY_CHECKBOXES from "@/graphql/queries/entityCheckboxes.graphql";
import ENTITY_FIELDS from "@/graphql/queries/entityFields.graphql";
import TABLE_COLUMNS from "@/graphql/queries/tableColumns.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";
import { useTableColumnsConfigStore } from "@/stores/tableColumnsConfig.js";
import { haveAccess } from "@/utils.js";
import { readBootCache, writeBootCache } from "@/utils/bootCache.js";
let $router = null;

/**
 * Куда уводить пользователя, которому запрещён целевой маршрут.
 * При включённой матрице доступов жёсткий fallback на orders опасен: если роли
 * не выдан раздел «Заказы» (или не выдан ни один раздел вовсе), редирект
 * orders → orders зацикливается и вешает вкладку намертво. Поэтому ищем первый
 * реально доступный раздел из section_keys, а при пустом списке — no-access.
 */
function deniedFallback(to) {
  const store = useAuthStore();
  if (!store.roleMatrixEnabled) {
    return { name: "orders" };
  }
  const moduleStore = useModuleConfigStore();
  const target = store.userSections.find((name) => {
    if (name === to.name) return false;
    if (!$router || !$router.hasRoute(name)) return false;
    try {
      const meta = $router.resolve({ name }).meta || {};
      if (meta.moduleKey && !moduleStore.isModuleEnabled(meta.moduleKey)) return false;
      if (meta.adminOnly && !store.canManageRoles) return false;
      if (meta.superAdminOnly && !store.isSuperAdmin) return false;
      return true;
    } catch (e) {
      return false;
    }
  });
  return target ? { name: target } : { name: "no-access" };
}

function check(to, from, next) {
  const store = useAuthStore();
  // Если есть пользователь, но страница относится к страницам авторизации
  if (store.user) {
    let userRole = store.user.roles?.[0]?.name;
    let accesses = to.meta.accesses;
    if (to.meta.authPage) {
      next({
        name: "desktop",
      });
      // next(firstRouteWithAccess($router));
      return;
    }
    // Гейт по фиче-флагу модуля: маршруты с meta.moduleKey доступны только если
    // соответствующий модуль включён (напр. aggregator_enabled). Защищает прямые
    // переходы по deep-link при выключенном режиме (fork-safety / §13.4).
    if (to.meta.moduleKey && !useModuleConfigStore().isModuleEnabled(to.meta.moduleKey)) {
      next(deniedFallback(to));
      return;
    }
    if (store.roleMatrixEnabled) {
      if (to.meta.adminOnly) {
        if (store.canManageRoles) {
          next();
          return;
        }
        next(deniedFallback(to));
        return;
      }
      if (to.meta.superAdminOnly) {
        if (store.isSuperAdmin) {
          next();
          return;
        }
        next(deniedFallback(to));
        return;
      }
      const section = to.meta.section;
      if (!section) {
        next();
        return;
      }
      if (store.userSections.includes(section)) {
        next();
        return;
      }
      next(deniedFallback(to));
      return;
    }
    if (!accesses || !accesses.length) {
      next();
      return;
    } else if (accesses.includes(userRole)) {
      next();
      return;
    } else {
      next({ name: "orders" });
      return;
    }
    // let accesses = to.meta?.entities?.map((e) => haveAccess(e, "read")).filter((a) => a);
    // if (
    //   (accesses && accesses.length) ||
    //   !to.meta?.entities?.length ||
    //   (to.meta?.entities?.includes("admin_only") && store.user.role.name === "admin") ||
    //   (to.meta?.entities?.includes("owner_only") && store.user.role.name === "owner")
    // ) {
    //   if (to.meta.authPage) {
    //     next({
    //       name: "desktop",
    //     });
    //     // next(firstRouteWithAccess($router));
    //     return;
    //   } else {
    //     next();
    //     return;
    //   }
    // }
  } else {
    // Если при переходе, странице требуется авторизация и
    // нет токена для авторизации по пин-коду или пользователя в store
    if (to.meta.authRequired) {
      if (!store.refresh_token) {
        // перекидываю на страницу авторизации
        next({ name: "login" });
        return;
      }
      if (!store.token) {
        // перекидываю на страницу ввода пин-кода
        next({ name: "login" });
        return;
      }
    }
  }
  if (!to.meta.authPage && to.name !== "no-access") {
    next({ name: "no-access" });
  } else {
    next();
  }
}

async function refreshModuleConfig(client) {
  try {
    const { data } = await client.query({
      query: PROJECT_MODULES,
      fetchPolicy: "network-only",
    });
    if (data && data.projectModules) {
      useModuleConfigStore().setConfig(data.projectModules);
      writeBootCache("projectModules", data.projectModules);
    }
  } catch (e) {
    // Если конфиг не загрузился — isModuleEnabled фолбэкнется на MODULE_DEFAULTS
    // (aggregator_enabled=false по умолчанию), что безопасно (скроет раздел).
  }
}

async function ensureModuleConfig(client) {
  const moduleStore = useModuleConfigStore();
  if (moduleStore.loaded) {
    return;
  }
  const cached = readBootCache("projectModules");
  if (cached !== null) {
    moduleStore.setConfig(cached);
    refreshModuleConfig(client);
    return;
  }
  await refreshModuleConfig(client);
}

async function refreshCheckboxConfig(client) {
  const checkboxStore = useCheckboxConfigStore();
  try {
    const { data } = await client.query({
      query: ENTITY_CHECKBOXES,
      fetchPolicy: "network-only",
    });
    checkboxStore.setConfig(data && data.entityCheckboxes);
    writeBootCache("entityCheckboxes", data && data.entityCheckboxes);
  } catch (e) {
    // Запрос упал — помечаем загруженным с config=null, чтобы isEnabled
    // фолбэкнулся на «показать все чекбоксы» и не скрыл их навсегда.
    checkboxStore.loaded = true;
  }
}

async function ensureCheckboxConfig(client) {
  const checkboxStore = useCheckboxConfigStore();
  if (checkboxStore.loaded) {
    return;
  }
  const cached = readBootCache("entityCheckboxes");
  if (cached !== null) {
    checkboxStore.setConfig(cached);
    refreshCheckboxConfig(client);
    return;
  }
  await refreshCheckboxConfig(client);
}

async function refreshEntityFieldsConfig(client) {
  const fieldsStore = useFieldVisibilityConfigStore();
  try {
    const { data } = await client.query({
      query: ENTITY_FIELDS,
      fetchPolicy: "network-only",
    });
    fieldsStore.setConfig(data && data.entityFields);
    writeBootCache("entityFields", data && data.entityFields);
  } catch (e) {
    // См. комментарий в refreshCheckboxConfig — fail-open после провала запроса.
    fieldsStore.loaded = true;
  }
}

async function ensureEntityFieldsConfig(client) {
  const fieldsStore = useFieldVisibilityConfigStore();
  if (fieldsStore.loaded) {
    return;
  }
  const cached = readBootCache("entityFields");
  if (cached !== null) {
    fieldsStore.setConfig(cached);
    refreshEntityFieldsConfig(client);
    return;
  }
  await refreshEntityFieldsConfig(client);
}

async function refreshTableColumnsConfig(client) {
  const columnsStore = useTableColumnsConfigStore();
  try {
    const { data } = await client.query({
      query: TABLE_COLUMNS,
      fetchPolicy: "network-only",
    });
    columnsStore.setConfig(data && data.tableColumns);
    writeBootCache("tableColumns", data && data.tableColumns);
  } catch (e) {
    // См. комментарий в refreshCheckboxConfig — fail-open после провала запроса:
    // таблица рисуется тем составом колонок, что объявлен в компоненте.
    columnsStore.loaded = true;
  }
}

async function ensureTableColumnsConfig(client) {
  const columnsStore = useTableColumnsConfigStore();
  if (columnsStore.loaded) {
    return;
  }
  const cached = readBootCache("tableColumns");
  if (cached !== null) {
    columnsStore.setConfig(cached);
    refreshTableColumnsConfig(client);
    return;
  }
  await refreshTableColumnsConfig(client);
}

/**
 * Конфиги видимости UI (модули, чекбоксы сущностей, поля сущностей, колонки
 * таблиц) обязаны быть загружены ДО первого рендера страницы: иначе геттеры
 * отдают fail-open дефолт и отключённые в настройках чекбоксы/поля/колонки
 * успевают мигнуть и пропасть после ответа.
 * После первой загрузки каждый ensure* выходит мгновенно по флагу loaded.
 */
async function ensureUiConfigs() {
  const client = apollo().defaultClient;
  await Promise.all([
    ensureModuleConfig(client),
    ensureCheckboxConfig(client),
    ensureEntityFieldsConfig(client),
    ensureTableColumnsConfig(client),
  ]);
}

function guard(router) {
  $router = router;
  router.beforeEach(async (to, from, next) => {
    const store = useAuthStore();
    if (store.token && !store.user) {
      await apollo()
        .defaultClient.query({
          query: ME,
        })
        .then(async ({ data }) => {
          if (data.me) {
            store.save("user", data.me);
          }
          await ensureUiConfigs();
          check(to, from, next);
        });
    } else {
      // Гейт по moduleKey требует загруженного moduleConfig (для deep-link/refresh),
      // а конфиги чекбоксов/полей — чтобы страница не мигала скрытыми элементами.
      if (store.user) {
        await ensureUiConfigs();
      }
      check(to, from, next);
    }
  });
}

export default guard;
