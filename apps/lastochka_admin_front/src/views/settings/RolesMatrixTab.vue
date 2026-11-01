<template>
  <div class="settings-page__section">
    <h3 class="settings-page__section-title">Роли и доступы</h3>
    <p class="settings-page__section-desc">
      Управление ролями и видимостью разделов для каждой роли. Чек-бокс открывает раздел роли (в меню и в
      API). Системные роли отмечены замком; доступы администратора менять нельзя. Раздел «Роли и доступы»
      отдаёт роли эту же страницу — выдаётся только глобальным ролям.
    </p>

    <div v-if="loading" class="settings-page__saving">Загрузка...</div>

    <template v-else>
      <div class="roles-matrix__toolbar">
        <button class="btn btn--md" @click="openCreate">+ Новая роль</button>
      </div>

      <div class="roles-matrix__scroll">
        <table class="settings-page__menu-table roles-matrix__table">
          <thead>
            <tr>
              <th class="roles-matrix__role-col">Роль</th>
              <th v-for="s in sections" :key="s.name" class="roles-matrix__sec-col">{{ s.title }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="role in roles" :key="role.id">
              <td class="roles-matrix__role-col">
                <div class="roles-matrix__role-name">
                  <IconComponent v-if="role.is_system" name="lock-line" />
                  <span>{{ role.name_ru || role.name }}</span>
                </div>
                <div class="roles-matrix__role-scope">
                  {{ role.scope_type === "shop" ? "свой магазин" : "глобальная" }}
                </div>
                <div v-if="!role.is_system" class="roles-matrix__role-actions">
                  <button class="roles-matrix__link" @click="openEdit(role)">изм.</button>
                  <button class="roles-matrix__link roles-matrix__link--danger" @click="removeRole(role)">
                    удал.
                  </button>
                </div>
              </td>
              <td v-for="s in sections" :key="s.name" class="roles-matrix__cell">
                <label
                  class="settings-page__menu-toggle roles-matrix__check"
                  :title="sectionLockHint(role, s)"
                >
                  <input
                    type="checkbox"
                    :checked="role.section_keys.includes(s.name)"
                    :disabled="isSectionLocked(role, s) || saving"
                    @change="toggleSection(role, s.name, $event)"
                  />
                  <span class="settings-page__menu-toggle-slider"></span>
                </label>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <h3 class="settings-page__section-title roles-matrix__actions-title">Действия с заказами</h3>
      <p class="settings-page__section-desc">
        Какие действия с заказом доступны роли. Настраивается только для ролей с разделом «Заказы»; доступы
        администратора менять нельзя.
      </p>
      <div class="roles-matrix__scroll">
        <table class="settings-page__menu-table roles-matrix__table">
          <thead>
            <tr>
              <th class="roles-matrix__role-col">Роль</th>
              <th v-for="a in orderActions" :key="a.key" class="roles-matrix__sec-col">{{ a.title }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="role in roles" :key="'oa-' + role.id">
              <td class="roles-matrix__role-col">
                <div class="roles-matrix__role-name">
                  <IconComponent v-if="role.is_system" name="lock-line" />
                  <span>{{ role.name_ru || role.name }}</span>
                </div>
              </td>
              <td v-for="a in orderActions" :key="a.key" class="roles-matrix__cell">
                <label
                  class="settings-page__menu-toggle roles-matrix__check"
                  :title="!role.section_keys.includes('orders') ? 'У роли нет раздела «Заказы»' : ''"
                >
                  <input
                    type="checkbox"
                    :checked="role.order_action_keys.includes(a.key)"
                    :disabled="isAdmin(role) || saving || !role.section_keys.includes('orders')"
                    @change="toggleOrderAction(role, a.key, $event)"
                  />
                  <span class="settings-page__menu-toggle-slider"></span>
                </label>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Модалка создания/редактирования роли -->
      <div v-if="editing" class="roles-matrix__modal-backdrop" @click.self="editing = null">
        <div class="roles-matrix__modal">
          <h4>{{ editing.id ? "Редактирование роли" : "Новая роль" }}</h4>
          <label class="roles-matrix__field">
            <span>Название</span>
            <input type="text" v-model="editing.name_ru" placeholder="Напр. Менеджер" />
          </label>
          <label class="roles-matrix__field">
            <span>Область видимости</span>
            <select v-model="editing.scope_type">
              <option value="global">Глобальная (видит все данные)</option>
              <option value="shop">В рамках своего магазина</option>
            </select>
          </label>
          <div class="roles-matrix__modal-actions">
            <button class="btn btn--gray btn--md" @click="editing = null">Отмена</button>
            <button class="btn btn--md" :disabled="saving || !editing.name_ru" @click="saveRole">
              Сохранить
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import { isAuthenticationError } from "@/utils.js";
import ROLES from "@/graphql/queries/roles.graphql";
import MENU_ITEMS from "@/graphql/queries/menuItems.graphql";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { useAuthStore } from "@/stores/auth.js";

// Пункты, привязанные к модулям: показываются только если модуль включён —
// синхронно с боковым меню и вкладкой «Разделы меню».
const MODULE_KEY_MAP = {
  "product-group-filters": "product_group_filters",
  preorders: "preorders",
  brands: "brands",
  "product-issue-reports": "product_issue_reports",
  "assembly-zones": "assembly_zones",
  "category-1c": "category_1c_integration",
  "platform-products": "aggregator_enabled",
  "aggregator-quarantine": "aggregator_enabled",
};
import CREATE_ROLE from "@/graphql/mutations/CreateRole.graphql";
import UPDATE_ROLE from "@/graphql/mutations/UpdateRole.graphql";
import DELETE_ROLE from "@/graphql/mutations/DeleteRole.graphql";
import SYNC_ROLE_SECTIONS from "@/graphql/mutations/SyncRoleSections.graphql";
import SYNC_ROLE_ORDER_ACTIONS from "@/graphql/mutations/SyncRoleOrderActions.graphql";

// Действия с заказами (action-level матрицы, MEN-IN-DEV-11284).
// Ключи = Spatie-permissions orders.* (см. admin_back App\Support\OrderAction)
const ORDER_ACTIONS = [
  { key: "orders.cancel", title: "Отмена заказа" },
  { key: "orders.change_status", title: "Смена статуса" },
  { key: "orders.assign_collector", title: "Назначение сборщика" },
  { key: "orders.assign_courier", title: "Назначение курьера" },
  // { key: "orders.change_shop", title: "Смена магазина" }, // колонка скрыта по требованию заказчика
  { key: "orders.export", title: "Экспорт в xlsx" },
  { key: "orders.mark_paid", title: "Отметка оплаты наличными" },
];

export default {
  name: "RolesMatrixTab",
  inject: ["apollo"],
  components: { IconComponent },
  data() {
    return {
      loading: false,
      saving: false,
      roles: [],
      sections: [],
      orderActions: ORDER_ACTIONS,
      editing: null,
    };
  },
  mounted() {
    this.load();
  },
  methods: {
    isAdmin(role) {
      return role.name === "admin";
    },
    /**
     * Раздел «Роли и доступы» выдаётся только глобальным ролям: матрица правит
     * роли всех магазинов сразу, мерчанту это не положено (зеркало
     * AdminPolicy::manageRoles). Доступы администратора не редактируются вовсе.
     */
    isSectionLocked(role, section) {
      if (this.isAdmin(role)) return true;
      if (section.name !== "roles") return false;
      return role.scope_type === "shop" || this.isOwnRolesSection(role);
    },
    sectionLockHint(role, section) {
      if (this.isAdmin(role)) return "Доступы администратора менять нельзя";
      if (section.name === "roles" && role.scope_type === "shop") {
        return "Раздел «Роли и доступы» доступен только глобальным ролям";
      }
      if (section.name === "roles" && this.isOwnRolesSection(role)) {
        return "Нельзя снять раздел «Роли и доступы» со своей роли";
      }
      return null;
    },
    /**
     * Своя роль с уже выданным разделом «Роли и доступы»: снять галочку значит
     * закрыть себе матрицу без возможности вернуться (зеркало серверной
     * проверки в SyncRoleSections). Админа и супер-админа не касается —
     * их доступ грантами не управляется.
     */
    isOwnRolesSection(role) {
      const authStore = useAuthStore();
      if (authStore.isSuperAdmin || authStore.isAdminRole) return false;
      const ownRoleIds = (authStore.user?.roles || []).map((r) => String(r.id));
      return ownRoleIds.includes(String(role.id)) && role.section_keys.includes("roles");
    },
    load() {
      this.loading = true;
      Promise.all([
        this.apollo().admin.query({ query: ROLES, fetchPolicy: "network-only" }),
        this.apollo().admin.query({ query: MENU_ITEMS, fetchPolicy: "network-only" }),
      ])
        .then(([rolesRes, menuRes]) => {
          const moduleStore = useModuleConfigStore();
          // Сборщик и курьер в админку не ходят — их section-тогглеры ни на что
          // не влияют и только путают (MID-209). Из общего roles-query их убирать
          // нельзя: селект «Должность» в NewStaffModal назначает именно их.
          this.roles = rolesRes.data.roles
            .filter((r) => r.name !== "collector" && r.name !== "courier")
            .map((r) => ({
              ...r,
              section_keys: [...r.section_keys],
              order_action_keys: [...r.order_action_keys],
            }));
          // Колонки матрицы синхронизированы с «Разделы меню»: только разделы,
          // которые реально есть в меню — видимые (is_visible) и включённые модулем.
          // Если раздела нет в меню — выдать на него доступ нельзя.
          // «Настройки» исключены: раздел супер-админский и через матрицу не выдаётся.
          // «Роли и доступы» — обычная колонка (MID-210): галочка открывает
          // созданной глобальной роли доступ к этой же матрице.
          this.sections = [...menuRes.data.menuItems]
            .filter((m) => m.name !== "settings")
            .filter((m) => m.is_visible)
            .filter((m) => {
              const moduleKey = MODULE_KEY_MAP[m.name];
              return !moduleKey || moduleStore.isModuleEnabled(moduleKey);
            })
            .sort((a, b) => a.position - b.position);
        })
        .catch((err) => console.error("Error loading roles matrix:", err))
        .finally(() => {
          this.loading = false;
        });
    },
    toggleSection(role, key, event) {
      const checked = event.target.checked;
      const next = checked
        ? [...new Set([...role.section_keys, key])]
        : role.section_keys.filter((k) => k !== key);
      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: SYNC_ROLE_SECTIONS,
          variables: { role_id: role.id, section_keys: next },
        })
        .then(({ data }) => {
          role.section_keys = [...data.syncRoleSections.section_keys];
        })
        .catch((err) => {
          console.error("Error syncing role sections:", err);
          event.target.checked = !checked;
        })
        .finally(() => {
          this.saving = false;
        });
    },
    toggleOrderAction(role, key, event) {
      const checked = event.target.checked;
      const next = checked
        ? [...new Set([...role.order_action_keys, key])]
        : role.order_action_keys.filter((k) => k !== key);
      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: SYNC_ROLE_ORDER_ACTIONS,
          variables: { role_id: role.id, action_keys: next },
        })
        .then(({ data }) => {
          role.order_action_keys = [...data.syncRoleOrderActions.order_action_keys];
        })
        .catch((err) => {
          console.error("Error syncing role order actions:", err);
          event.target.checked = !checked;
        })
        .finally(() => {
          this.saving = false;
        });
    },
    openCreate() {
      this.editing = { id: null, name_ru: "", scope_type: "global" };
    },
    openEdit(role) {
      this.editing = { id: role.id, name_ru: role.name_ru, scope_type: role.scope_type };
    },
    saveRole() {
      this.saving = true;
      const isNew = !this.editing.id;
      const mutation = isNew ? CREATE_ROLE : UPDATE_ROLE;
      const variables = isNew
        ? { name_ru: this.editing.name_ru, scope_type: this.editing.scope_type, section_keys: [] }
        : { id: this.editing.id, name_ru: this.editing.name_ru, scope_type: this.editing.scope_type };
      this.apollo()
        .admin.mutate({ mutation, variables })
        .then(() => {
          this.editing = null;
          this.load();
        })
        .catch((err) => {
          console.error("Error saving role:", err);
          if (!isAuthenticationError(err)) alert("Не удалось сохранить роль.");
        })
        .finally(() => {
          this.saving = false;
        });
    },
    removeRole(role) {
      if (!confirm(`Удалить роль «${role.name_ru || role.name}»?`)) return;
      this.saving = true;
      this.apollo()
        .admin.mutate({ mutation: DELETE_ROLE, variables: { id: role.id } })
        .then(() => this.load())
        .catch((err) => {
          console.error("Error deleting role:", err);
          alert("Не удалось удалить роль (системная или есть пользователи).");
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.roles-matrix
  &__toolbar
    display flex
    justify-content flex-end
    margin-bottom 16px
  &__actions-title
    margin-top 32px
  &__scroll
    overflow-x auto
  &__table
    min-width 720px
  &__role-col
    text-align left
    min-width 180px
  &__sec-col
    font-size 0.7rem
    white-space nowrap
  &__role-name
    display flex
    align-items center
    gap 6px
    font-weight 600
    .icon
      width 14px
      height 14px
  &__role-scope
    font-size 0.7rem
    color var(--dark-light)
  &__role-actions
    display flex
    gap 8px
    margin-top 4px
  &__link
    background none
    border none
    cursor pointer
    font-size 0.72rem
    color var(--green)
    padding 0
    &--danger
      color var(--red)
  &__cell
    text-align center
  &__modal-backdrop
    position fixed
    inset 0
    background rgba(0,0,0,0.4)
    display flex
    align-items center
    justify-content center
    z-index 50
  &__modal
    background var(--white)
    border-radius 12px
    padding 24px
    width 360px
    display flex
    flex-direction column
    gap 16px
  &__field
    display flex
    flex-direction column
    gap 4px
    font-size 0.875rem
    input, select
      padding 8px 12px
      border 1px solid var(--gray-dark)
      border-radius 8px
      outline none
  &__modal-actions
    display flex
    justify-content flex-end
    gap 8px

// Самодостаточные стили (используются и как таб Настроек, и на отдельной
// странице /admin/roles, где глобальные .settings-page__* могут быть не загружены)
.settings-page__section
  margin-bottom 32px
  &:last-child
    margin-bottom 0
.settings-page__section-title
  font-size 1.125rem
  font-weight 600
  color var(--dark)
  margin-bottom 8px
.settings-page__section-desc
  font-size 0.875rem
  color var(--dark-light)
  margin-bottom 20px
.settings-page__saving
  margin-top 12px
  font-size 0.875rem
  color var(--dark-light)
.settings-page__menu-table
  width 100%
  border-collapse collapse
  th
    text-align left
    font-size 0.75rem
    font-weight 500
    color var(--dark-light)
    padding 8px 12px
    border-bottom 1px solid var(--gray-dark)
  td
    padding 8px 12px
    border-bottom 1px solid var(--gray-3)
    vertical-align middle
.settings-page__menu-toggle
  position relative
  display inline-block
  width 40px
  height 22px
  cursor pointer
  input
    opacity 0
    width 0
    height 0
.settings-page__menu-toggle-slider
  position absolute
  top 0
  left 0
  right 0
  bottom 0
  background var(--gray-dark)
  border-radius 22px
  transition background 0.2s
  &:before
    content ''
    position absolute
    width 16px
    height 16px
    left 3px
    bottom 3px
    background var(--white)
    border-radius 50%
    transition transform 0.2s
  input:checked + &
    background var(--green)
  input:checked + &:before
    transform translateX(18px)
</style>
