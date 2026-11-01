import { createApp } from "vue";
import { createPinia } from "pinia";
import * as Sentry from "@sentry/vue";

import App from "./App.vue";
import router from "./router";
import apollo from "./apollo";

const pinia = createPinia();
const app = createApp(App);

app.provide("apollo", apollo);

// Глобальный обработчик ошибок загрузки chunk'ов (fallback)
app.config.errorHandler = (error) => {
  if (
    error.message?.includes("Failed to fetch dynamically imported module") ||
    error.message?.includes("Importing a module script failed")
  ) {
    const lastReload = sessionStorage.getItem("chunk_reload_time");
    const now = Date.now();

    if (!lastReload || now - parseInt(lastReload) > 10000) {
      sessionStorage.setItem("chunk_reload_time", now.toString());
      window.location.reload();
    }
  } else {
    console.error(error);
  }
};

// GlitchTip выдаёт ключ в UUID-форме с дефисами, а DSN_REGEX Sentry SDK
// требует \w+ в публичном ключе — с дефисами init молча отключает отправку
// («Invalid Sentry Dsn» в консоли). Канонический DSN GlitchTip — хекс без
// дефисов (см. /api/0/projects/.../keys/ поле dsn.public), нормализуем сами,
// чтобы ошибочное значение в .env любого форка не выключало error tracking.
const normalizeSentryDsn = (dsn) =>
  (dsn || "").replace(/^(https?:\/\/)([^@]+)@/, (_, proto, key) => proto + key.replace(/-/g, "") + "@");

Sentry.init({
  app,
  dsn: normalizeSentryDsn(import.meta.env.VITE_SENTRY_DSN),
  environment: import.meta.env.VITE_SENTRY_ENVIRONMENT,
  tracesSampleRate: 0,
  replaysSessionSampleRate: 0,
  replaysOnErrorSampleRate: 0,
  // Протухшие чанки после деплоя: router.onError и errorHandler выше сами
  // перезагружают страницу — событие в трекере только шум
  ignoreErrors: [
    "Failed to fetch dynamically imported module",
    "Importing a module script failed",
    // Шум click-to-call-расширения Chrome (CtcExt) у операторов: оно само рвёт
    // свой fetch на каждой навигации и не ловит rejection, а наш
    // onunhandledrejection-хендлер записывает это как ошибку админки.
    // Современный Chrome такую строку не генерирует (свои аборты —
    // «signal is aborted without reason» / «TypeError: Failed to fetch»),
    // так что точная маска не скроет наши ошибки.
    /^AbortError: The user aborted a request\.$/,
  ],
});

app.use(pinia);
app.use(router);

app.mount("#app");
