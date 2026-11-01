<template>
  <div id="app">
    <HeaderComponent v-if="!$route.meta.hideComponents" />
    <Transition mode="out-in" name="page">
      <RouterView :key="$route.fullPath" />
    </Transition>
    <ModalsComponent />
    <Notifications position="bottom right" />
  </div>
</template>

<script>
import HeaderComponent from "./components/header";
import ModalsComponent from "./components/modals";

export default {
  name: "App",
  components: {
    ModalsComponent,
    HeaderComponent,
  },
};
</script>
