export default [
  {
    path: "/admin/settings",
    name: "settings",
    component: () => import("@/views/settings/index.vue"),
    meta: {
      authRequired: true,
      header: true,
      aside: true,
      accesses: ["admin", "shop"],
      superAdminOnly: true,
    },
  },
  {
    path: "/admin/settings/integrations",
    name: "integrations",
    component: () => import("@/views/integrations/index.vue"),
    meta: {
      authRequired: true,
      header: true,
      aside: true,
      accesses: ["admin", "shop"],
      superAdminOnly: true,
    },
  },
  {
    path: "/admin/settings/integrations/1c",
    name: "integrations-1c",
    component: () => import("@/views/integrations/OneCShopList.vue"),
    meta: {
      authRequired: true,
      header: true,
      aside: true,
      accesses: ["admin", "shop"],
      superAdminOnly: true,
    },
  },
  {
    path: "/admin/settings/app",
    name: "app-settings",
    component: () => import("@/views/app-settings/index.vue"),
    meta: {
      authRequired: true,
      header: true,
      aside: true,
      accesses: ["admin", "shop"],
      superAdminOnly: true,
    },
  },
];
