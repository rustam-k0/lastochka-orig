<template>
  <main class="page--main roles-page">
    <div class="roles-page__header">
      <h2>{{ pageTitle }}</h2>
    </div>
    <div class="roles-page__content">
      <RolesMatrixTab />
    </div>
  </main>
</template>

<script>
import RolesMatrixTab from "@/views/settings/RolesMatrixTab.vue";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "RolesPage",
  components: { RolesMatrixTab },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Роли и доступы");
    },
  },
};
</script>

<style lang="stylus">
.roles-page
  &__header
    display flex
    align-items center
    margin-bottom 24px

    h2
      font-size 1.5rem
      font-weight 600
      color var(--dark)

  &__content
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
</style>
