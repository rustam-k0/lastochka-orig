@php
  $routes = '';
  foreach ($data['routes'] as $route) {
    $roles = json_encode($route['allowedRoles'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $routes .= "{
      path: '{$route['path']}',
      name: '{$route['name']}',
      component: () => import('./views/generated/{$route['name']}/index.vue'),
      meta: {
        allowedRoles: $roles,
      },
    },";
  }
@endphp
/**
* НЕ РЕДАКТИРУЙТЕ СОДЕРЖИМОЕ ЭТОГО ФАЙЛА
* Если задача вышла за рамки генератора,
* то отредактируйте шаблон в папке generator/templates/frontend/router.blade.php
*/
import Vue from "vue";
import Router from "vue-router";

Vue.use(Router);

export default function createRouter() {
  const routes = [
    {
      path: "/admin/login",
      name: "login",
      component: () => import("./views/auth/login.vue"),
      meta: {
        hideComponents: true,
      },
    },
    {
      path: "/admin/password/reset",
      name: "passwordreset",
      component: () => import("./views/auth/reset.vue"),
      meta: {
        hideComponents: true,
      },
    },
    {
      path: "/admin/password/change",
      name: "password",
      component: () => import("./views/auth/password.vue"),
      meta: {
        allowedRoles: ["*"],
      },
    },
    {
      path: "/admin/profile",
      name: "profile",
      component: () => import("./views/auth/profile.vue"),
      meta: {
        allowedRoles: ["*"],
      },
    },
    {
      path: "*",
      name: "not-found",
      component: () => import("./views/PageNotFound.vue"),
      meta: {
        allowedRoles: ["*"],
      },
    },
    {!! $routes !!}
  ];
  const router = new Router({
    mode: "history",
    scrollBehavior(to, from, savedPosition) {
      if (savedPosition) {
        return savedPosition;
      }
      if (to.hash) {
        return {
          selector: to.hash,
          behavior: "smooth",
        };
      }
      return { x: 0, y: 0 };
    },
    fallback: true,
    routes,
  });
  // Проверка на доступность страницы
  router.beforeEach((to, from, next) => {
    if (window.user && window.user.roles) {
      let roles = window.user.roles.map((r) => r.name);
      if (!(to.meta.allowedRoles && to.meta.allowedRoles.some((r) => r === "*" || roles.includes(r)))) {
        next(false);
        return;
      }
    }
    next();
  });

  return router;
}
