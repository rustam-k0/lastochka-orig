<template>
  <main class="page--main cities-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="cities-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="cities-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить город</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="cities-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!cities.length" class="cities-page__empty">
      <IconComponent name="map-pin-line" />
      <h3>Нет городов</h3>
      <p>Добавьте города, чтобы привязывать к ним магазины и фильтровать выдачу в приложении</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать город</span>
      </button>
    </div>

    <div v-else class="cities-page__table-wrapper">
      <table class="cities-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Название</th>
            <th>Координаты</th>
            <th>По умолчанию</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="city in cities" :key="city.id">
            <td>{{ city.id }}</td>
            <td>{{ city.name }}</td>
            <td>{{ coordinates(city) }}</td>
            <td>
              <span v-if="city.is_default" class="cities-page__badge">По умолчанию</span>
              <button v-else class="cities-page__link-btn" @click="makeDefault(city)">
                Сделать основным
              </button>
            </td>
            <td>
              <label class="cities-page__toggle" @click.prevent="toggleActive(city)">
                <input type="checkbox" :checked="city.is_active" />
                <span class="cities-page__toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="cities-page__row-actions">
                <button class="cities-page__action-btn" @click="openEdit(city)" title="Редактировать">
                  <IconComponent name="pencil-line" />
                </button>
                <button
                  class="cities-page__action-btn cities-page__action-btn--delete"
                  @click="confirmDelete(city)"
                  title="Удалить"
                >
                  <IconComponent name="delete-bin-line" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import CITIES from "@/graphql/queries/admin_cities.graphql";
import CITY_DELETE from "@/graphql/mutations/CityDelete.graphql";
import CITY_UPDATE from "@/graphql/mutations/CityUpdate.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "CitiesListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
  },
  data() {
    return {
      cities: [],
      loading: true,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Города");
    },
    breadcrumbs() {
      return [{ to: { name: "cities" }, title: this.pageTitle }];
    },
  },
  mounted() {
    this.get();
  },
  methods: {
    get() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: CITIES,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          // Город по умолчанию — первым, остальные по алфавиту: тот же порядок,
          // что и в витрине (GET /v1/cities).
          this.cities = [...(data.allCities || [])].sort(
            (a, b) =>
              Number(b.is_default) - Number(a.is_default) || (a.name || "").localeCompare(b.name || "", "ru")
          );
        })
        .catch((error) => {
          console.error("Error fetching cities:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    coordinates(city) {
      if (city.latitude == null || city.longitude == null) {
        return "Не заданы";
      }
      return `${city.latitude}, ${city.longitude}`;
    },
    openCreate() {
      this.$router.push({ name: "city-create" });
    },
    openEdit(city) {
      this.$router.push({ name: "city-edit", params: { id: city.id } });
    },
    confirmDelete(city) {
      if (confirm(`Удалить город "${city.name}"?`)) {
        this.deleteCity(city);
      }
    },
    deleteCity(city) {
      this.apollo()
        .admin.mutate({
          mutation: CITY_DELETE,
          variables: { id: parseInt(city.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting city:", error);
          alert("Ошибка удаления города");
        });
    },
    toggleActive(city) {
      this.update(city, { is_active: !city.is_active }, "Ошибка изменения статуса");
    },
    makeDefault(city) {
      this.update(city, { is_default: true }, "Ошибка назначения города по умолчанию");
    },
    update(city, fields, errorMessage) {
      this.apollo()
        .admin.mutate({
          mutation: CITY_UPDATE,
          variables: {
            id: parseInt(city.id),
            ...fields,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error(errorMessage, error);
          alert(errorMessage);
        });
    },
  },
};
</script>

<style lang="stylus">
.cities-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px

    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }

  &__actions {
    display flex
    gap 12px
  }

  &__loading {
    display flex
    justify-content center
    padding 48px
  }

  &__empty {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center

    > .icon {
      width 64px
      height 64px
      margin-bottom 16px

      svg path {
        fill var(--gray-light)
      }
    }

    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 8px
    }

    p {
      font-size 0.875rem
      color var(--dark-light)
      margin-bottom 24px
    }
  }

  &__table-wrapper {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    overflow hidden
  }

  &__table {
    width 100%
    border-collapse collapse

    th, td {
      padding 16px
      text-align left
      font-size 0.875rem
      border-bottom 1px solid var(--gray-dark)
    }

    th {
      font-weight 600
      color var(--dark-light)
      background var(--gray-3)
    }

    td {
      color var(--dark)
    }

    tbody tr {
      transition background 0.2s

      &:hover {
        background var(--gray-3)
      }

      &:last-child td {
        border-bottom none
      }
    }
  }

  &__badge {
    display inline-flex
    align-items center
    padding 4px 10px
    border-radius 20px
    background var(--main)
    color var(--white)
    font-size 0.75rem
    font-weight 500
  }

  &__link-btn {
    background none
    border none
    padding 0
    cursor pointer
    font-size 0.813rem
    color var(--dark-light)
    text-decoration underline

    &:hover {
      color var(--main)
    }
  }

  &__toggle {
    display inline-flex
    cursor pointer

    input {
      display none
    }
  }

  &__toggle-slider {
    width 40px
    height 20px
    background var(--gray-dark)
    border-radius 20px
    position relative
    transition background 0.2s

    &::after {
      content ''
      position absolute
      width 16px
      height 16px
      background var(--white)
      border-radius 50%
      top 2px
      left 2px
      transition left 0.2s
    }

    input:checked + & {
      background var(--green)

      &::after {
        left 22px
      }
    }
  }

  &__row-actions {
    display flex
    gap 8px
  }

  &__action-btn {
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border-radius 8px
    border 1px solid var(--gray-dark)
    background var(--white)
    cursor pointer
    transition all 0.2s

    .icon {
      width 18px
      height 18px

      svg path {
        fill var(--dark)
      }
    }

    &:hover {
      border-color var(--pink)

      .icon svg path {
        fill var(--pink)
      }
    }

    &--delete {
      &:hover {
        border-color var(--red)

        .icon svg path {
          fill var(--red)
        }
      }
    }
  }
}
</style>
