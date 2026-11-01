<template>
  <aside class="aside" :class="{ 'aside--folded': folded }">
    <router-link :to="{ name: 'desktop' }" class="aside__header">
      <template v-if="customLogo">
        <img
          :src="customLogo"
          alt="Logo"
          class="aside__custom-logo"
          :class="{ 'aside__custom-logo--folded': folded }"
        />
      </template>
      <template v-else>
        <div class="aside__logo-placeholder" :class="{ 'aside__logo-placeholder--folded': folded }">
          <IconComponent name="image-line" />
        </div>
      </template>
      <button @click.prevent="setFolded" class="aside__action">
        <IconComponent category="remix" name="arrow-drop-left-line" />
      </button>
    </router-link>
    <div class="aside__body">
      <div class="aside__links">
        <!--        <router-link :to="{ name: 'orders' }" class="aside__link" :title="folded ? 'Заказы' : null">-->
        <!--          <IconComponent name="list-check" />-->
        <!--          <span>Заказы</span>-->
        <!--        </router-link>-->
        <template v-for="item in availableRoutes" :key="item.id ?? item.name">
          <router-link
            v-if="!item.is_folder"
            :to="{ name: item.name }"
            class="aside__link"
            :class="{ 'router-link-active': isRouteActive(item) }"
            :title="folded ? item.title : null"
          >
            <IconComponent :name="menuIcon(item)" fallback="file-list-line" />
            <span>{{ item.title }}</span>
            <span v-if="item.name === 'aggregator-quarantine' && quarantineCount > 0" class="aside__count">{{
              quarantineCount
            }}</span>
          </router-link>

          <div v-else class="aside__folder">
            <button
              type="button"
              class="aside__link aside__folder-toggle"
              :title="folded ? item.title : null"
              @click="toggleFolder(item.id)"
            >
              <IconComponent name="folder-line" />
              <span>{{ item.title }}</span>
              <IconComponent
                class="aside__folder-arrow"
                :name="isFolderExpanded(item.id) ? 'arrow-down-s-line' : 'arrow-right-s-line'"
              />
            </button>
            <div v-if="isFolderExpanded(item.id)" class="aside__folder-children">
              <router-link
                v-for="child in item.children"
                :key="child.name"
                :to="{ name: child.name }"
                class="aside__link aside__link--child"
                :class="{ 'router-link-active': isRouteActive(child) }"
                :title="folded ? child.title : null"
              >
                <IconComponent :name="menuIcon(child)" fallback="file-list-line" />
                <span>{{ child.title }}</span>
              </router-link>
            </div>
          </div>
        </template>
      </div>
    </div>
  </aside>
</template>

<script>
import { useAuthStore } from "@/stores/auth.js";
import { useSettingsStore } from "@/stores/settings.js";
import { useDefaultStore } from "@/stores/default.js";
import { useMenuStore } from "@/stores/menu.js";
import Popper from "vue3-popper";
import IconComponent from "@/components/IconComponent.vue";
import { haveAccess } from "@/utils.js";
import ADMIN_SETTINGS from "@/graphql/queries/adminSettings.graphql";
import MENU_ITEMS from "@/graphql/queries/menuItems.graphql";
import { readBootCache, writeBootCache } from "@/utils/bootCache.js";
import ENTITY_CHECKBOXES from "@/graphql/queries/entityCheckboxes.graphql";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import PROJECT_MODULES from "@/graphql/queries/projectModules.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import AGGREGATOR_QUARANTINE_COUNT from "@/graphql/pages/Aggregator/aggregatorQuarantineCount.graphql";
import ENTITY_FIELDS from "@/graphql/queries/entityFields.graphql";
import { useFieldVisibilityConfigStore } from "@/stores/fieldVisibilityConfig.js";

export default {
  name: "AsideComponent",
  inject: ["apollo"],

  data: () => ({
    folded: false,
    showPhone: false,
    customLogoPath: null,
    quarantineCount: 0,

    defaultRoutes: [
      {
        name: "orders",
        title: "Заказы",
        icon: "list-check",
        accesses: ["admin", "shop"],
      },
      {
        name: "products",
        title: "Товары",
        icon: "archive-line",
        accesses: ["admin", "shop"],
      },
      {
        name: "categories",
        title: "Категории",
        icon: "grid-fill",
        accesses: ["admin"],
      },
      {
        name: "attributes",
        title: "Атрибуты",
        icon: "list-settings-line",
        accesses: ["admin"],
      },
      {
        name: "assembly-zones",
        title: "Зоны сборки",
        icon: "map-pin-line",
        accesses: ["admin"],
      },
      {
        name: "clients",
        title: "Клиенты",
        icon: "team-line",
        accesses: ["admin"],
      },
      {
        name: "staffs",
        title: "Сотрудники",
        icon: "customer-service-2-line",
        accesses: ["admin", "shop"],
      },
      //{
      // name: "content",
      //  title: "Контент",
      //  icon: "draft-line",
      //  accesses: ["admin"],
      //},
      {
        name: "stores",
        title: "Магазины",
        icon: "store-2-line",
        accesses: ["admin"],
      },
      {
        name: "my_store",
        title: "Магазин",
        icon: "list-check",
        accesses: ["shop"],
      },
      {
        name: "brands",
        title: "Бренды",
        icon: "award-line",
        accesses: ["admin"],
      },
      {
        name: "cancel-reasons",
        title: "Причины отмены",
        icon: "close-circle-line",
        accesses: ["admin"],
      },
      {
        name: "order-cancel-reasons",
        title: "Причины отмены заказа",
        icon: "close-circle-line",
        accesses: ["admin"],
      },
      {
        name: "explain-reasons",
        title: "Причины уточнения",
        icon: "question-line",
        accesses: ["admin"],
      },
      {
        name: "stories",
        title: "Истории",
        icon: "play-circle-line",
        accesses: ["admin"],
      },
      {
        name: "merchant-sales",
        title: "Акции магазинов",
        icon: "price-tag-3-line",
        accesses: ["admin"],
      },
      {
        name: "merchant-product-groups",
        title: "Товарные группы",
        icon: "grid-fill",
        accesses: ["admin", "shop"],
      },
      {
        name: "merchant-banners",
        title: "Баннеры",
        icon: "image-line",
        accesses: ["admin", "shop"],
      },
      {
        name: "merchant-promocodes",
        title: "Промокоды",
        icon: "coupon-3-line",
        accesses: ["admin", "shop"],
      },
      {
        name: "announcements",
        title: "Уведомления об акциях",
        icon: "notification-3-line",
        accesses: ["admin"],
      },
      {
        name: "merchant-reviews",
        title: "Отзывы на магазины",
        icon: "star-line",
        accesses: ["admin", "shop"],
      },
      {
        name: "product-reviews",
        title: "Отзывы на товары",
        icon: "star-half-line",
        accesses: ["admin", "shop"],
      },
      {
        name: "order-reviews",
        title: "Отзывы на доставку",
        icon: "truck-line",
        accesses: ["admin", "shop"],
      },
      {
        name: "preorders",
        title: "Заявки",
        icon: "clipboard-line",
        accesses: ["admin"],
        moduleKey: "preorders",
      },
    ],
    routes: [],
    expandedFolders: [],
  }),
  computed: {
    settings() {
      return useSettingsStore().settings;
    },
    user() {
      return useAuthStore().user;
    },
    userRole() {
      return this.user?.roles[0].name;
    },
    availableRoutes() {
      const moduleStore = useModuleConfigStore();
      const authStore = useAuthStore();
      const matrixOn = authStore.roleMatrixEnabled;
      const passesFilter = (item) => {
        // Пункт меню может опередить деплой фронта (сидер admin_back уже
        // добавил запись, роута ещё нет) — без guard'а RouterLink.resolve
        // кидает Error без message на каждом рендере сайдбара
        if (!item.is_folder && !this.$router.hasRoute(item.name)) return false;
        // Разделы «Настройки» и «Настройки приложения» — только супер-админ
        // (при включённой матрице).
        // Видимость (is_visible из «Разделы меню») уже учтена в loadMenuItems,
        // поэтому если пункт выключен — он сюда не попадёт и в меню не появится.
        if (item.name === "settings" || item.name === "app-settings") {
          return matrixOn ? authStore.isSuperAdmin : item.accesses.includes(this.userRole);
        }
        // «Роли и доступы» — только при матрице и только admin/super-admin
        // (кастомные роли, даже глобальные, ролями не управляют).
        // is_visible/название/порядок управляются через «Разделы меню».
        if (item.name === "roles") {
          return matrixOn && authStore.canManageRoles;
        }
        if (matrixOn) {
          if (!authStore.userSections.includes(item.name)) return false;
        } else {
          if (!item.accesses.includes(this.userRole)) return false;
        }
        if (item.moduleKey && !moduleStore.isModuleEnabled(item.moduleKey)) return false;
        return true;
      };

      const list = [];
      this.routes.forEach((item) => {
        if (item.is_folder) {
          const children = (item.children || []).filter(passesFilter);
          if (children.length > 0) {
            list.push({ ...item, children });
          }
          return;
        }
        if (passesFilter(item)) {
          list.push(item);
        }
      });
      return list;
    },
    canUseVOIP() {
      return haveAccess("calls", "create") && this.user.call_department && this.user.call_department.number;
    },
    customLogo() {
      if (!this.customLogoPath) return null;
      return useDefaultStore().MEDIA_ENDPOINT + this.customLogoPath;
    },
  },
  created() {
    this.folded = this.settings.asideFolded;
    this.loadBranding();
    this.loadMenuItems();
    this.loadEntityCheckboxes();
    this.loadProjectModules();
    this.loadEntityFields();
    this.loadQuarantineCount();
  },
  methods: {
    haveAccess,
    menuIcon(item) {
      return item.icon || "file-list-line";
    },
    /**
     * Маппинг пунктов меню → moduleKey для модульной фильтрации.
     * Пункты с moduleKey отображаются только если модуль включён.
     */
    getModuleConfig(name) {
      const moduleMap = {
        "product-group-filters": { moduleKey: "product_group_filters" },
        preorders: { moduleKey: "preorders" },
        brands: { moduleKey: "brands" },
        "product-issue-reports": { moduleKey: "product_issue_reports" },
        "assembly-zones": { moduleKey: "assembly_zones" },
        "category-1c": { moduleKey: "category_1c_integration" },
        "platform-products": { moduleKey: "aggregator_enabled" },
        "aggregator-quarantine": { moduleKey: "aggregator_enabled" },
      };
      return moduleMap[name] || {};
    },
    loadQuarantineCount() {
      const moduleStore = useModuleConfigStore();
      if (moduleStore.loaded && !moduleStore.isModuleEnabled("aggregator_enabled")) {
        return;
      }
      this.apollo()
        .admin.query({
          query: AGGREGATOR_QUARANTINE_COUNT,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.quarantineCount = data?.aggregatorQuarantineCount ?? 0;
        })
        .catch(() => {});
    },
    loadMenuItems() {
      const cached = readBootCache("menuItems");
      if (cached !== null) {
        this.applyMenuItems(cached);
      }
      this.apollo()
        .admin.query({
          query: MENU_ITEMS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          writeBootCache("menuItems", data.menuItems);
          this.applyMenuItems(data.menuItems);
        })
        .catch(() => {
          if (cached !== null) return;
          // setItems([]) переводит menu store в loaded — гейтящиеся по
          // isVisible элементы (вкладка «Зоны сборки») получают fallback
          // «видимо», консистентно с defaultRoutes в сайдбаре
          useMenuStore().setItems([]);
          this.routes = this.defaultRoutes;
        });
    },
    applyMenuItems(menuItems) {
      useMenuStore().setItems(menuItems);
      if (menuItems && menuItems.length > 0) {
        const items = menuItems
          .filter((item) => item.is_visible)
          .map((item) => ({
            ...item,
            ...this.getModuleConfig(item.name),
          }));
        this.routes = this.buildMenuTree(items);
        this.autoExpandActiveFolder();
      } else {
        this.routes = this.defaultRoutes;
      }
    },
    buildMenuTree(items) {
      const roots = items.filter((item) => !item.parent_id).sort((a, b) => a.position - b.position);
      return roots.map((root) => {
        if (!root.is_folder) return root;
        const children = items
          .filter((item) => item.parent_id === root.id)
          .sort((a, b) => a.position - b.position);
        return { ...root, children };
      });
    },
    autoExpandActiveFolder() {
      this.routes.forEach((item) => {
        if (
          item.is_folder &&
          (item.children || []).some((child) => child.name === this.$route.name) &&
          !this.expandedFolders.includes(item.id)
        ) {
          this.expandedFolders.push(item.id);
        }
      });
    },
    toggleFolder(folderId) {
      const index = this.expandedFolders.indexOf(folderId);
      if (index === -1) {
        this.expandedFolders.push(folderId);
      } else {
        this.expandedFolders.splice(index, 1);
      }
    },
    isFolderExpanded(folderId) {
      return this.expandedFolders.includes(folderId);
    },
    setFolded() {
      this.folded = !this.folded;
      useSettingsStore().set("asideFolded", this.folded);
    },
    // Брендинг (логотип, тайтл, основной цвет) раньше уходил тремя отдельными
    // adminSetting-запросами на каждую загрузку — теперь один adminSettings
    // со stale-while-revalidate кэшем.
    loadBranding() {
      const cached = readBootCache("adminBranding");
      if (cached !== null) {
        this.applyBranding(cached);
      }
      this.apollo()
        .admin.query({
          query: ADMIN_SETTINGS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const branding = {};
          (data.adminSettings || []).forEach((setting) => {
            if (["admin_logo", "admin_title", "admin_primary_color"].includes(setting.key)) {
              branding[setting.key] = setting.value;
            }
          });
          writeBootCache("adminBranding", branding);
          this.applyBranding(branding);
        })
        .catch(() => {});
    },
    applyBranding(branding) {
      if (branding.admin_logo) {
        this.customLogoPath = branding.admin_logo;
        this.updateFavicon(useDefaultStore().MEDIA_ENDPOINT + branding.admin_logo);
      }
      if (branding.admin_title) {
        document.title = branding.admin_title;
      }
      if (branding.admin_primary_color) {
        const color = branding.admin_primary_color;
        const root = document.documentElement;
        root.style.setProperty("--main", color);
        const r = parseInt(color.slice(1, 3), 16);
        const g = parseInt(color.slice(3, 5), 16);
        const b = parseInt(color.slice(5, 7), 16);
        root.style.setProperty("--main-o9", `rgba(${r}, ${g}, ${b}, 0.9)`);
        root.style.setProperty("--main-o15", `rgba(${r}, ${g}, ${b}, 0.15)`);
        root.style.setProperty("--main-o05", `rgba(${r}, ${g}, ${b}, 0.05)`);
      }
    },
    loadEntityCheckboxes() {
      const checkboxStore = useCheckboxConfigStore();
      if (checkboxStore.loaded) return;
      this.apollo()
        .admin.query({
          query: ENTITY_CHECKBOXES,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          checkboxStore.setConfig(data.entityCheckboxes);
        })
        .catch((err) => {
          console.error("Error loading entity checkboxes config:", err);
          checkboxStore.loaded = true;
        });
    },
    loadEntityFields() {
      const fieldsStore = useFieldVisibilityConfigStore();
      if (fieldsStore.loaded) return;
      this.apollo()
        .admin.query({
          query: ENTITY_FIELDS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          fieldsStore.setConfig(data.entityFields);
        })
        .catch((err) => {
          console.error("Error loading entity fields config:", err);
          fieldsStore.loaded = true;
        });
    },
    loadProjectModules() {
      const moduleStore = useModuleConfigStore();
      if (moduleStore.loaded) return;
      this.apollo()
        .admin.query({
          query: PROJECT_MODULES,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          moduleStore.setConfig(data.projectModules);
        })
        .catch((err) => {
          console.error("Error loading project modules config:", err);
          moduleStore.loaded = true;
        });
    },
    updateFavicon(url) {
      let link = document.querySelector("link[rel='icon']");
      if (link) {
        link.href = url;
      }
    },
    isRouteActive(item) {
      const currentPath = this.$route.path;
      if (item.name === "settings") {
        return currentPath.startsWith("/admin/settings");
      }
      return this.$route.name === item.name;
    },
  },
  components: { IconComponent, Popper },
};
</script>

<style lang="stylus">
.aside {
  display grid
  grid-template-rows 70px minmax(0, 1fr) auto
  width 264px
  flex-shrink 0
  background var(--white)
  transition .2s
  height 100vh
  border-right 1px solid var(--gray-dark)
  position sticky
  top 0
  z-index 6

  &--folded {
    width 80px

    .remix-arrow-drop-left-line-icon {
      transform rotate(180deg)
    }
  }

  &__header {
    position relative
    height 70px
    width 100%
    display flex
    align-items center
    justify-content flex-start
    padding 24px 0 24px 32px
    border-bottom 1px solid var(--gray-dark)
    transition .2s

    ^[0]--folded & {
      padding 20px 16px
      position relative
    }

    .default-logo-small-icon {
      display inline-flex
      justify-content flex-start
      max-height 36px
      width auto

      svg {
        max-height 56px
        width auto
      }

      ^[0]--folded & {
        max-height 10px

        svg {
          max-height 10px
        }
      }
    }
  }

  &__header-logo {
    max-width 48px
    width 100%
  }

  &__custom-logo {
    max-height 36px
    width auto
    object-fit contain

    &--folded {
      max-width 48px
      max-height 48px
    }
  }

  &__logo-placeholder {
    display flex
    align-items center
    justify-content center
    width 120px
    height 36px
    border-radius 6px
    background var(--gray-3)
    border 1px dashed var(--gray-dark)

    .icon {
      width 24px
      height 24px

      svg path {
        fill var(--gray-light)
      }
    }

    &--folded {
      width 48px
      height 48px
    }
  }

  &__action {
    position absolute
    display flex
    align-items center
    justify-content center
    z-index 10
    right -12px
    width 24px
    height 24px
    border-radius 999px
    border 1px solid #F2F2F2
    background #FFFFFF

    .icon {
      width 16px
      height 16px

      svg path {
        fill #5D6368
      }
    }
  }

  &__body {
    display grid
    width 100%
    height 100%
    min-height 0
    align-items start
    overflow-y auto
    overflow-x hidden

    // тонкий аккуратный скроллбар, появляется только при переполнении
    scrollbar-width thin
    scrollbar-color var(--gray-light) transparent

    &::-webkit-scrollbar {
      width 6px
    }

    &::-webkit-scrollbar-track {
      background transparent
    }

    &::-webkit-scrollbar-thumb {
      background var(--gray-light)
      border-radius 999px
    }
  }

  &__links {
    display grid
    align-items start
    padding 16px

    ^[0]--folded & {
      padding 16px 13px
    }
  }

  &__link {
    background none
    border none
    padding 16px
    display flex
    align-items center
    gap 8px
    height 52px
    font-weight: 400;
    font-size: 0.813rem
    line-height: 20px;
    color: var(--dark);
    transition color .2s, background .2s, border-radius 0s

    ^[0]--folded & {
      padding 0
      justify-content center
    }

    &:active .icon {
      //transform scale(0.97)
    }

    &:hover {
      color var(--green)

      .icon svg path {
        fill var(--green)
      }
    }

    .icon {
      background: var(--white);
      border-radius: 100px;
      width 20px
      height 20px
      transition .2s

      svg {
        width 20px
        height 20px

        path {
          transition .2s
          fill var(--dark)
        }
      }
    }

    &.router-link-active {
      color var(--pink)
      border 1px solid var(--gray-light)
      background var(--gray-3)
      border-radius 8px
      padding-left: 15px;

      ^[0]--folded & {
        padding 0
        justify-content center
      }

      .icon svg path {
        fill var(--pink)
      }
    }
    spanExceptCountWhenFolded = '^[0]--folded & span:not(^[0]__count)';

    {spanExceptCountWhenFolded} {
      display none
    }
  }

  &__folder {
    display grid
  }

  &__folder-toggle {
    width 100%
    border none
    background none
    cursor pointer
    justify-content space-between

    ^[0]--folded & {
      justify-content center
    }
  }

  &__folder-arrow {
    margin-left auto
    width 16px
    height 16px

    ^[0]--folded & {
      display none
    }
  }

  &__folder-children {
    display grid
    padding-left 20px

    ^[0]--folded & {
      padding-left 0
    }
  }

  &__link--child {
    height 44px
  }

  &__count {
    margin-left auto
    border-radius: 100px;
    background: var(--red);
    font-weight: 500;
    font-size: 0.625rem;
    line-height: 12px;
    color: var(--white);
    height 20px
    min-width 20px
    display inline-flex
    align-items center
    justify-content center

    ^[0]--folded & {
      position: absolute;
      right: 0;
      transform: translateX(50%);
    }
  }

  &__footer {
    display grid
    width 100%
  }

  &__calls {
    display flex
    padding 30px 15px
    border-bottom 1px solid var(--gray-dark)
    transition .2s

    &-container {
      display flex
      align-items center
      justify-content stretch
      gap 10px

      & > .btn {
        width 100%

        ^[0]--folded & {
          border-radius 0

          span {
            display none
          }
        }
      }
    }

    .phone-input {
      z-index 2
    }

    ^[0]--folded & {
      padding 30px 0
    }
  }
}
</style>
