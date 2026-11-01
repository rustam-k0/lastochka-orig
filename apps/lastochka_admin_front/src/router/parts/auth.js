export default [
  {
    path: "/admin/login",
    name: "login",
    component: () => import("@/views/auth/login/index.vue"),
    meta: {
      entities: [],
      authPage: true, // Чтобы после авторизации нельзя было по ним перейти
      header: false,
      aside: false,
    },
  },
  {
    path: "/admin/lock",
    name: "lock",
    component: () => import("@/views/auth/lock/index.vue"),
    meta: {
      entities: [],
      authPage: true, // Чтобы после авторизации нельзя было по ним перейти
      header: false,
      aside: false,
    },
  },
  {
    path: "/admin/reset-password",
    name: "reset-password",
    component: () => import("@/views/auth/reset/index.vue"),
    meta: {
      entities: [],
      authPage: true, // Чтобы после авторизации нельзя было по ним перейти
      header: false,
      aside: false,
    },
  },
  {
    path: "/admin/restore",
    name: "restore-password",
    component: () => import("@/views/auth/restore/index.vue"),
    meta: {
      entities: [],
      authPage: true, // Чтобы после авторизации нельзя было по ним перейти
      header: false,
      aside: false,
    },
  },
];
