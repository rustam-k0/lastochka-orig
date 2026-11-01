import Vue from "vue";
import App from "./App.vue";
import createRouter from "./router.js";
import createStore from "./store";
import createApollo from "./apollo.js";
import * as filters from "./filters.js";
import VueGoodTablePlugin from "vue-good-table";
import Notifications from "vue-notification";
import { VueMaskDirective } from "v-mask";
import VueCookies from "vue-cookies";

Vue.use(VueGoodTablePlugin);
Vue.use(Notifications, {
  componentName: "Notifications",
});
Vue.use(VueCookies);

const router = createRouter();
const store = createStore();
const apolloProvider = createApollo();

Vue.directive("mask", VueMaskDirective);

Object.keys(filters).forEach((key) => {
  Vue.filter(key, filters[key]);
});

new Vue({
  router,
  store,
  apolloProvider,
  render: (h) => h(App),
}).$mount("#app");
