import { createRouter, createWebHistory } from "vue-router";
import guard from "@/router/parts/guard.js";
import authRoutes from "@/router/parts/auth.js";
// import patientsRoutes from "@/router/parts/patients.js";
import settingsRoutes from "@/router/parts/settings.js";

/**
 * Динамический name в роутере для того что бы получать
 * списки магазинов и товаров присущие магазинам или кафе
 * когда роль Агрегатор может быть пустым значением
 */

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: "/admin",
      name: "desktop",
      component: () => import("@/views/orders/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        entities: [],
        // accesses: ["admin", "shop"],
        section: "orders",
      },
    },
    {
      path: "/admin/roles",
      name: "roles",
      component: () => import("@/views/roles/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        adminOnly: true,
      },
    },
    {
      path: "/admin/products/:id?",
      name: "products",
      component: () => import("@/views/products/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "products",
      },
    },
    {
      path: "/admin/product/:shop_id?/:id?",
      name: "product",
      component: () => import("@/views/products/open.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "products",
      },
    },
    {
      path: "/admin/configurable-product/:shop_id?/:id?",
      name: "configurable-product",
      component: () => import("@/views/products/configurable.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "products",
      },
    },
    {
      path: "/admin/orders",
      name: "orders",
      component: () => import("@/views/orders/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "orders",
      },
    },
    {
      path: "/admin/order/:id?",
      name: "order",
      component: () => import("@/views/orders/open.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "orders",
      },
    },
    {
      path: "/admin/stores",
      name: "stores",
      component: () => import("@/views/stores/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "stores",
      },
    },
    {
      path: "/admin/my-store",
      name: "my_store",
      component: () => import("@/views/stores/open.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["shop"],
        section: "my_store",
      },
    },
    {
      path: "/admin/store/:id?",
      name: "store",
      component: () => import("@/views/stores/open.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "stores",
      },
    },

    {
      path: "/admin/attributes",
      name: "attributes",
      component: () => import("@/views/attributes/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/attributes/create",
      name: "attribute-create",
      component: () => import("@/views/attributes/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/attributes/:id/edit",
      name: "attribute-edit",
      component: () => import("@/views/attributes/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/attribute-families",
      name: "attribute-families",
      component: () => import("@/views/attribute-families/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/attribute-families/create",
      name: "attribute-family-create",
      component: () => import("@/views/attribute-families/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/attribute-families/:id/edit",
      name: "attribute-family-edit",
      component: () => import("@/views/attribute-families/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/categories/:id?",
      name: "categories",
      component: () => import("@/views/categories/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "categories",
      },
    },
    {
      path: "/admin/category/:id?",
      name: "category",
      component: () => import("@/views/categories/open.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "categories",
      },
    },
    {
      path: "/admin/assembly-zones",
      name: "assembly-zones",
      component: () => import("@/views/assembly-zones/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/categories-1c",
      name: "category-1c",
      component: () => import("@/views/category-1c-management/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        moduleKey: "category_1c_integration",
      },
    },
    {
      path: "/admin/platform-products",
      name: "platform-products",
      component: () => import("@/views/aggregator/platformProducts/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        moduleKey: "aggregator_enabled",
      },
    },
    {
      path: "/admin/platform-product/:id?",
      name: "platform-product",
      component: () => import("@/views/aggregator/platformProducts/open.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        moduleKey: "aggregator_enabled",
      },
    },
    {
      path: "/admin/aggregator-quarantine",
      name: "aggregator-quarantine",
      component: () => import("@/views/aggregator/quarantine/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        moduleKey: "aggregator_enabled",
      },
    },
    {
      // Предупреждения в статусе обмена 1С открывают единую страницу «Карантин
      // товаров» с предвыбранной причиной (FOODLINK-11039). Маршрут БЕЗ moduleKey:
      // диагностика 1С доступна и при выключенном агрегаторе (в отличие от
      // сайдбар-маршрута aggregator-quarantine, который гейтится aggregator_enabled).
      path: "/admin/onec-problem-products",
      name: "onec-problem-products",
      component: () => import("@/views/aggregator/quarantine/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/staffs",
      name: "staffs",
      component: () => import("@/views/staff/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "staffs",
      },
    },
    // {
    //   path: "/admin/staff/:id?",
    //   name: "staff",
    //   component: () => import("@/views/staff/open.vue"),
    //   meta: {
    //     authRequired: true, // Чтобы требовать авторизацию для входа
    //     header: true,
    //     aside: true,
    //   },
    // },
    // DEPRECATED: content (landing_back) не используется
    // {
    //   path: "/admin/content/:link?/:id?",
    //   name: "content",
    //   component: () => import("@/views/content/index.vue"),
    //   meta: {
    //     authRequired: true,
    //     header: true,
    //     aside: true,
    //   },
    // },
    {
      path: "/admin/clients",
      name: "clients",
      component: () => import("@/views/clients/index.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        section: "clients",
      },
    },
    {
      path: "/admin/client/:id?",
      name: "client",
      component: () => import("@/views/clients/open.vue"),
      meta: {
        authRequired: true, // Чтобы требовать авторизацию для входа
        header: true,
        aside: true,
        section: "clients",
      },
    },
    {
      path: "/admin/brands",
      name: "brands",
      component: () => import("@/views/brands/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/brands/create",
      name: "brand-create",
      component: () => import("@/views/brands/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/brands/:id/edit",
      name: "brand-edit",
      component: () => import("@/views/brands/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/operating-costs",
      name: "operating-costs",
      component: () => import("@/views/operating-costs/index.vue"),
      meta: { authRequired: true, header: true, aside: true, accesses: ["admin"] },
    },
    {
      path: "/admin/kpi-targets",
      name: "kpi-targets",
      component: () => import("@/views/kpi-targets/index.vue"),
      meta: { authRequired: true, header: true, aside: true, accesses: ["admin"] },
    },
    {
      path: "/admin/alert-rules",
      name: "alert-rules",
      component: () => import("@/views/alert-rules/index.vue"),
      meta: { authRequired: true, header: true, aside: true, accesses: ["admin"] },
    },
    {
      path: "/admin/cancel-reasons",
      name: "cancel-reasons",
      component: () => import("@/views/cancel-reasons/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/cancel-reasons/create",
      name: "cancel-reason-create",
      component: () => import("@/views/cancel-reasons/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/cancel-reasons/:id/edit",
      name: "cancel-reason-edit",
      component: () => import("@/views/cancel-reasons/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/order-cancel-reasons",
      name: "order-cancel-reasons",
      component: () => import("@/views/order-cancel-reasons/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/order-cancel-reasons/create",
      name: "order-cancel-reason-create",
      component: () => import("@/views/order-cancel-reasons/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/order-cancel-reasons/:id/edit",
      name: "order-cancel-reason-edit",
      component: () => import("@/views/order-cancel-reasons/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/explain-reasons",
      name: "explain-reasons",
      component: () => import("@/views/explain-reasons/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/explain-reasons/create",
      name: "explain-reason-create",
      component: () => import("@/views/explain-reasons/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/explain-reasons/:id/edit",
      name: "explain-reason-edit",
      component: () => import("@/views/explain-reasons/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/faq",
      name: "faq",
      component: () => import("@/views/faq/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/faq/create",
      name: "faq-create",
      component: () => import("@/views/faq/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/faq/:id/edit",
      name: "faq-edit",
      component: () => import("@/views/faq/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/cities",
      name: "cities",
      component: () => import("@/views/cities/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/cities/create",
      name: "city-create",
      component: () => import("@/views/cities/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/cities/:id/edit",
      name: "city-edit",
      component: () => import("@/views/cities/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/vacancies",
      name: "vacancies",
      component: () => import("@/views/vacancies/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "vacancies",
      },
    },
    {
      path: "/admin/vacancies/create",
      name: "vacancy-create",
      component: () => import("@/views/vacancies/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "vacancies",
      },
    },
    {
      path: "/admin/vacancies/:id/edit",
      name: "vacancy-edit",
      component: () => import("@/views/vacancies/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "vacancies",
      },
    },
    {
      path: "/admin/pages",
      name: "pages",
      component: () => import("@/views/pages/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "pages",
      },
    },
    {
      path: "/admin/pages/create",
      name: "page-create",
      component: () => import("@/views/pages/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "pages",
      },
    },
    {
      path: "/admin/pages/:id/edit",
      name: "page-edit",
      component: () => import("@/views/pages/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "pages",
      },
    },
    {
      path: "/admin/printers",
      name: "printers",
      component: () => import("@/views/printers/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/printers/create",
      name: "printers-create",
      component: () => import("@/views/printers/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/printers/:id/edit",
      name: "printers-edit",
      component: () => import("@/views/printers/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/replace-reasons",
      name: "replace-reasons",
      component: () => import("@/views/replace-reasons/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/replace-reasons/create",
      name: "replace-reason-create",
      component: () => import("@/views/replace-reasons/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/replace-reasons/:id/edit",
      name: "replace-reason-edit",
      component: () => import("@/views/replace-reasons/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/stories",
      name: "stories",
      component: () => import("@/views/stories/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "stories",
      },
    },
    {
      path: "/admin/stories/create",
      name: "story-create",
      component: () => import("@/views/stories/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "stories",
      },
    },
    {
      path: "/admin/stories/:id/edit",
      name: "story-edit",
      component: () => import("@/views/stories/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "stories",
      },
    },
    {
      path: "/admin/preorders",
      name: "preorders",
      component: () => import("@/views/preorders/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
      },
    },
    {
      path: "/admin/product-issue-reports",
      name: "product-issue-reports",
      component: () => import("@/views/productIssueReports/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
      },
    },
    {
      path: "/admin/merchant-sales",
      name: "merchant-sales",
      component: () => import("@/views/merchantSales/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "merchant-sales",
      },
    },
    {
      path: "/admin/merchant-sales/create",
      name: "merchant-sale-create",
      component: () => import("@/views/merchantSales/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "merchant-sales",
      },
    },
    {
      path: "/admin/merchant-sales/:id/edit",
      name: "merchant-sale-edit",
      component: () => import("@/views/merchantSales/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "merchant-sales",
      },
    },
    {
      path: "/admin/merchant-product-groups",
      name: "merchant-product-groups",
      component: () => import("@/views/merchantProductGroups/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-product-groups",
      },
    },
    {
      path: "/admin/merchant-product-groups/create",
      name: "merchant-product-groups-create",
      component: () => import("@/views/merchantProductGroups/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-product-groups",
      },
    },
    {
      path: "/admin/merchant-product-groups/:id/edit",
      name: "merchant-product-groups-edit",
      component: () => import("@/views/merchantProductGroups/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-product-groups",
      },
    },
    {
      path: "/admin/product-group-filters",
      name: "product-group-filters",
      component: () => import("@/views/productGroupFilters/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/product-group-filters/create",
      name: "product-group-filters-create",
      component: () => import("@/views/productGroupFilters/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/product-group-filters/:id/edit",
      name: "product-group-filters-edit",
      component: () => import("@/views/productGroupFilters/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
      },
    },
    {
      path: "/admin/merchant-reviews",
      name: "merchant-reviews",
      component: () => import("@/views/merchantReviews/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-reviews",
      },
    },
    {
      path: "/admin/merchant-reviews/create",
      name: "merchant-review-create",
      component: () => import("@/views/merchantReviews/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "merchant-reviews",
      },
    },
    {
      path: "/admin/merchant-reviews/:id",
      name: "merchant-review",
      component: () => import("@/views/merchantReviews/open.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-reviews",
      },
    },
    {
      path: "/admin/product-reviews",
      name: "product-reviews",
      component: () => import("@/views/productReviews/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "product-reviews",
      },
    },
    {
      path: "/admin/product-reviews/create",
      name: "product-review-create",
      component: () => import("@/views/productReviews/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "product-reviews",
      },
    },
    {
      path: "/admin/product-reviews/:id",
      name: "product-review",
      component: () => import("@/views/productReviews/open.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "product-reviews",
      },
    },
    {
      path: "/admin/order-reviews",
      name: "order-reviews",
      component: () => import("@/views/orderReviews/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
      },
    },
    {
      path: "/admin/order-reviews/:id",
      name: "order-review",
      component: () => import("@/views/orderReviews/open.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
      },
    },
    {
      path: "/admin/merchant-banners",
      name: "merchant-banners",
      component: () => import("@/views/merchantBanners/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-banners",
      },
    },
    {
      path: "/admin/merchant-banners/create",
      name: "merchant-banner-create",
      component: () => import("@/views/merchantBanners/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-banners",
      },
    },
    {
      path: "/admin/merchant-banners/:id/edit",
      name: "merchant-banner-edit",
      component: () => import("@/views/merchantBanners/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-banners",
      },
    },
    {
      path: "/admin/merchant-promocodes",
      name: "merchant-promocodes",
      component: () => import("@/views/merchantPromocodes/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-promocodes",
      },
    },
    {
      path: "/admin/merchant-promocodes/create",
      name: "merchant-promocode-create",
      component: () => import("@/views/merchantPromocodes/create.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-promocodes",
      },
    },
    {
      path: "/admin/merchant-promocodes/:id/edit",
      name: "merchant-promocode-edit",
      component: () => import("@/views/merchantPromocodes/edit.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin", "shop"],
        section: "merchant-promocodes",
      },
    },
    {
      path: "/admin/announcements",
      name: "announcements",
      component: () => import("@/views/announcements/index.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "announcements",
      },
    },
    {
      path: "/admin/announcements/create",
      name: "announcement-create",
      component: () => import("@/views/announcements/open.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "announcements",
      },
    },
    {
      path: "/admin/announcements/:id/edit",
      name: "announcement-edit",
      component: () => import("@/views/announcements/open.vue"),
      meta: {
        authRequired: true,
        header: true,
        aside: true,
        accesses: ["admin"],
        section: "announcements",
      },
    },
    // {
    //   path: "/test",
    //   name: "test",
    //   component: () => import("@/views/products/index.vue"),
    //   meta: {
    //     authRequired: true, // Чтобы требовать авторизацию для входа
    //     header: true,
    //     aside: true,
    //     breadcrumbs: [
    //       {
    //         to: {
    //           name: "desktop",
    //         },
    //         title: "Главная",
    //       },
    //       {
    //         title: "Tест",
    //       },
    //     ],
    //   },
    // },
    {
      // Тупиковая страница для пользователя, чьей роли не выдан ни один раздел
      // (guard.deniedFallback). Без header/aside: сайдбар для такой роли пуст.
      path: "/admin/no-access",
      name: "no-access",
      component: () => import("@/views/no-access/index.vue"),
      meta: {
        authRequired: true,
        header: false,
        aside: false,
      },
    },
    ...settingsRoutes,
    // ...patientsRoutes,
    ...authRoutes,
  ],
});

guard(router);

// Обработка ошибок загрузки chunk'ов после деплоя новой версии
router.onError((error, to) => {
  if (
    error.message.includes("Failed to fetch dynamically imported module") ||
    error.message.includes("Importing a module script failed")
  ) {
    // Предотвращаем бесконечный цикл перезагрузок
    const lastReload = sessionStorage.getItem("chunk_reload_time");
    const now = Date.now();

    if (!lastReload || now - parseInt(lastReload) > 10000) {
      sessionStorage.setItem("chunk_reload_time", now.toString());
      window.location.href = to.fullPath;
    } else {
      console.error("Chunk loading failed after reload. Please clear cache and refresh.");
    }
  }
});

export default router;
