<template>
  <main class="main-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
  </main>
</template>

<script>
// import dayjs from "dayjs";
import { useDefaultStore } from "@/stores/default.js";
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";

export default {
  name: "DesktopPage",
  inject: ["apollo"],
  data: () => ({
    loading: false,
    record_indicators: {},
    breadcrumbs: [
      {
        to: {
          name: "products",
        },
        title: "Товары",
      },
    ],
  }),
  created() {},
  computed: {
    statuses() {
      return [
        {
          title: "Ожидание",
          code: useDefaultStore().types.CODE_WAITING,
        },
        {
          title: "Подтверждено",
          code: useDefaultStore().types.CODE_CONFIRMED,
        },
        {
          title: "Пришел",
          code: useDefaultStore().types.CODE_CAME,
        },
        {
          title: "Не пришел",
          code: useDefaultStore().types.CODE_NOT_CAME,
        },
      ];
    },
  },
  methods: {},
  components: {
    BreadcrumbsComponent,
  },
};
</script>

<style lang="stylus">
.main-page {
  padding 16px 32px
  height 100%
  width 100%
  padding-right 0
  padding-bottom 0
}
</style>
