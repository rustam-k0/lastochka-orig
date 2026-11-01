import Cookies from "js-cookie";
import { defineStore } from "pinia";

export const useAuthStore = defineStore({
  id: "auth",
  state: () => ({
    token: Cookies.get("admin_token"),
    refresh_token: Cookies.get("refresh_token"),
    user: null,
  }),
  getters: {
    roleMatrixEnabled: (state) => !!state.user?.role_matrix_enabled,
    userSections: (state) => state.user?.section_keys || [],
    userOrderActions: (state) => state.user?.order_action_keys || [],
    // Историческое соглашение UI: «админ» = первая роль пользователя admin
    isAdminRole: (state) => state.user?.roles?.[0]?.name === "admin",
    isGlobalScope: (state) =>
      !!state.user?.roles?.some((r) => r.scope_type === "global"),
    isSuperAdmin: (state) => !!state.user?.is_super_admin,
    // «Глобальный» режим UI (зеркало бэкендового User::isGlobalScope()):
    // при выключенной матрице — как раньше, строго роль admin; при включённой —
    // любая роль с глобальным scope (в т.ч. кастомные роли матрицы).
    // Использовать вместо буквальной проверки roles[0].name === "admin"
    // везде, где смысл проверки — «работает со всеми магазинами, а не мерчант».
    isGlobalUser() {
      return this.roleMatrixEnabled ? this.isGlobalScope : this.isAdminRole;
    },
    // Управлять ролями/матрицей могут admin и super-admin всегда, а созданная
    // роль — если ей выдан раздел «Роли и доступы» (section_keys содержит roles)
    // и её scope глобальный. Зеркало AdminPolicy::manageRoles: мерчанту
    // (shop-scope) раздатчик доступов не положен даже с галочкой.
    canManageRoles() {
      if (this.isSuperAdmin || this.user?.roles?.some((r) => r.name === "admin")) {
        return true;
      }
      if (!this.roleMatrixEnabled) {
        return false;
      }
      return this.isGlobalScope && this.userSections.includes("roles");
    },
  },
  actions: {
    hasSection(key) {
      return (this.user?.section_keys || []).includes(key);
    },
    // Доступно ли пользователю действие с заказом (key — полный ключ,
    // напр. "orders.cancel"). При выключенной матрице — как раньше: только админ.
    canOrderAction(key) {
      if (!this.roleMatrixEnabled) {
        return this.isAdminRole;
      }
      return (this.user?.order_action_keys || []).includes(key);
    },
    setCookie(name, token) {
      Cookies.set(name, token, {
        SameSite: "Strict",
      });
    },
    deleteCookie(name) {
      Cookies.remove(name);
    },
    save(key, value) {
      this[key] = value;
    },
  },
});
