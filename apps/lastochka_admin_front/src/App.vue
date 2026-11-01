<template>
  <AsideComponent v-if="showAside" />
  <div id="content">
    <HeaderComponent v-if="showHeader" />
    <RouterView v-slot="{ Component }">
      <keep-alive :include="['CategoriesPage']">
        <component :is="Component" />
      </keep-alive>
    </RouterView>
  </div>
  <ModalsComponent />
</template>

<script>
import HeaderComponent from "@/components/header/index.vue";
import AsideComponent from "@/components/AsideComponent.vue";
import ModalsComponent from "@/components/modals/index.vue";

export default {
  name: "App",
  computed: {
    showHeader() {
      return !!this.$route.meta.header;
    },
    showAside() {
      return !!this.$route.meta.aside;
    },
  },
  components: {
    ModalsComponent,
    AsideComponent,
    HeaderComponent,
  },
};
</script>

<style lang="stylus">
@import "@/styles/style.styl";
#app {
  min-height 100vh
  display flex
  justify-content flex-start
  align-items stretch
}

#content {
  width 100%
  // Без min-width 0 flex-элемент не уже min-content содержимого: широкая
  // таблица (список заказов при 100%) распирала страницу вправо (MID-343)
  min-width 0
  display flex
  flex-direction column
}
</style>
