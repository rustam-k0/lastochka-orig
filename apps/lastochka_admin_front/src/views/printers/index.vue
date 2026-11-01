<template>
  <main class="page--main printers-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="printers-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="printers-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить принтер</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="printers-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!printers.length" class="printers-page__empty">
      <IconComponent name="printer-line" />
      <h3>Нет принтеров</h3>
      <p>Добавьте сетевые принтеры, которые будут доступны для печати из приложения</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Добавить принтер</span>
      </button>
    </div>

    <div v-else class="printers-page__table-wrapper">
      <table class="printers-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Название</th>
            <th>IP-адрес</th>
            <th>Порт</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="printer in printers" :key="printer.id">
            <td>{{ printer.id }}</td>
            <td class="printers-page__title-cell">{{ printer.title }}</td>
            <td>{{ printer.ip_address }}</td>
            <td>{{ printer.port }}</td>
            <td>
              <label class="printers-page__toggle" @click.prevent="toggleActive(printer)">
                <input type="checkbox" :checked="printer.is_active" />
                <span class="printers-page__toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="printers-page__row-actions">
                <button class="printers-page__action-btn" @click="openEdit(printer)" title="Редактировать">
                  <IconComponent name="pencil-line" />
                </button>
                <button
                  class="printers-page__action-btn printers-page__action-btn--delete"
                  @click="confirmDelete(printer)"
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
import PRINTERS from "@/graphql/queries/admin_printers.graphql";
import PRINTER_DELETE from "@/graphql/mutations/PrinterDelete.graphql";
import PRINTER_UPDATE from "@/graphql/mutations/PrinterUpdate.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "PrintersListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
  },
  data() {
    return {
      printers: [],
      loading: true,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Принтеры печати");
    },
    breadcrumbs() {
      return [{ to: { name: "printers" }, title: this.pageTitle }];
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
          query: PRINTERS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.printers = [...(data.allPrinters || [])].sort((a, b) => a.id - b.id);
        })
        .catch((error) => {
          console.error("Error fetching printers:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openCreate() {
      this.$router.push({ name: "printers-create" });
    },
    openEdit(printer) {
      this.$router.push({ name: "printers-edit", params: { id: printer.id } });
    },
    confirmDelete(printer) {
      if (confirm(`Удалить принтер "${printer.title}"?`)) {
        this.deletePrinter(printer);
      }
    },
    deletePrinter(printer) {
      this.apollo()
        .admin.mutate({
          mutation: PRINTER_DELETE,
          variables: { id: parseInt(printer.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting printer:", error);
          alert("Ошибка удаления принтера");
        });
    },
    toggleActive(printer) {
      this.apollo()
        .admin.mutate({
          mutation: PRINTER_UPDATE,
          variables: {
            id: parseInt(printer.id),
            is_active: !printer.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling printer:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.printers-page {
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

  &__title-cell {
    max-width 480px
    overflow hidden
    text-overflow ellipsis
    white-space nowrap
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
