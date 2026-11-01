<template>
  <div class="staff-statistics">
    <div class="staff-statistics__header">
      <span>Статистика</span>
      <div class="staff-statistics__tabs">
        <div
          v-for="(item, i) in tabs"
          :key="i"
          class="staff-statistics__tabs-item"
          :class="{ 'staff-statistics__tabs-item--active': item.active }"
        >
          {{ item.title }}
        </div>
      </div>
    </div>
    <div class="staff-statistics__main">
      <div class="staff-statistics__chart">
        <h4>Количество заказов</h4>
        <LineChart />
      </div>
      <div class="staff-statistics__chart">
        <h4>Кол-во хороших отзывов</h4>
        <LineChart />
      </div>
      <div class="staff-statistics__chart">
        <h4>Кол-во плохих отзывов</h4>
        <LineChart />
      </div>
    </div>
  </div>
</template>
<script>
import LineChart from "@/components/ChartComponent.vue";
export default {
  name: "StaffStatisticsComponent",
  data() {
    return {
      tabs: [
        {
          title: "Год",
          active: false,
        },
        {
          title: "Месяц",
          active: true,
        },
        {
          title: "Неделя",
          active: false,
        },
      ],
    };
  },
  components: { LineChart },
};
</script>

<style lang="stylus">
.staff-statistics {
  display flex
  flex-direction column
  background-color var(--white)
  border-radius: 16px;

  &__header {
    display flex
    justify-content space-between
    align-items center
    padding 20px 16px
    border-bottom: 1px solid #E3E8EC;
  }

  &__main {
    display flex
    padding 16px
  }
  &__chart {
    display flex
    flex-direction column

    h4{
      margin 0
      font-weight: 500;
      font-size: 0.8125em;
      line-height: 140%;
      color: var(--dark);
      margin-bottom: 20px;
    }
  }
  &__tabs {
    display flex
    gap 1px
    background: var(--white);
    border-radius: 6px;
    border 1px solid var(--gray-dark)


    &-item {
      padding 7px 25px
      font-weight: 500;
      font-size: 0.75em;
      line-height: 16px;
      color: var(--dark);
      transition var(--transition)

      &:hover {
        background: var(--neutral-800);
        color var(--white)
      }

      &--active {
        background: var(--neutral-800);
        color var(--white)
      }

    }
  }
}
</style>
