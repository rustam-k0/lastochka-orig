<template>
  <main class="page--main staff-item-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="staff-item__header">
      <router-link :to="{ name: 'staff' }" class="btn btn--white btn--sm"
        ><IconComponent name="arrow-left-s-line"
      /></router-link>
      <h2>Поляков Владислав Андреевич</h2>
    </div>
    <StaffInfoComponent />
    <StaffStatisticsComponent />
    <StaffActivityComponent />
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import StaffInfoComponent from "@/views/staff/components/StaffInfoComponent.vue";
import StaffStatisticsComponent from "@/views/staff/components/StaffStatisticsComponent.vue";
import StaffActivityComponent from "@/views/staff/components/StaffActivityComponent.vue";
export default {
  name: "StaffItemPage",
  data() {
    return {
      breadcrumbs: [
        {
          to: {
            name: "staff",
          },
          title: "Сотрудники",
        },
        {
          title: "Поляков Владислав Андреевич",
        },
      ],
    };
  },
  components: {
    StaffActivityComponent,
    StaffStatisticsComponent,
    StaffInfoComponent,
    IconComponent,
    BreadcrumbsComponent,
  },
};
</script>

<style lang="stylus">
.staff-item-page {
  .staff-item__header {
    display flex
    gap 8px
    align-items: center;

    h2 {
      margin 0
    }
  }
}
</style>
