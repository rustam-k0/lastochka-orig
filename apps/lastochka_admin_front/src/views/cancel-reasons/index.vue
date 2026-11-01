<template>
  <main class="page--main cancel-reasons-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="cancel-reasons-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="cancel-reasons-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить причину</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="cancel-reasons-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!reasons.length" class="cancel-reasons-page__empty">
      <IconComponent name="close-circle-line" />
      <h3>Нет причин отмены</h3>
      <p>Создайте причины, которые сборщик сможет указывать при отмене товара</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать причину</span>
      </button>
    </div>

    <div v-else class="cancel-reasons-page__table-wrapper">
      <table class="cancel-reasons-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Причина</th>
            <th>Позиция</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="reason in reasons" :key="reason.id">
            <td>{{ reason.id }}</td>
            <td>{{ reason.title }}</td>
            <td>{{ reason.position ?? "—" }}</td>
            <td>
              <label class="cancel-reasons-page__toggle" @click.prevent="toggleActive(reason)">
                <input type="checkbox" :checked="reason.is_active" />
                <span class="cancel-reasons-page__toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="cancel-reasons-page__row-actions">
                <button class="cancel-reasons-page__action-btn" @click="openEdit(reason)" title="Редактировать">
                  <IconComponent name="pencil-line" />
                </button>
                <button class="cancel-reasons-page__action-btn cancel-reasons-page__action-btn--delete" @click="confirmDelete(reason)" title="Удалить">
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
import REASONS from "@/graphql/queries/admin_product_cancel_reasons.graphql";
import REASON_DELETE from "@/graphql/mutations/ProductCancelReasonDelete.graphql";
import REASON_UPDATE from "@/graphql/mutations/ProductCancelReasonUpdate.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "CancelReasonsListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
  },
  data() {
    return {
      reasons: [],
      loading: true,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Причины отмены");
    },
    breadcrumbs() {
      return [{ to: { name: "cancel-reasons" }, title: this.pageTitle }];
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
          query: REASONS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.reasons = [...(data.allProductCancelReasons || [])].sort(
            (a, b) => (a.position ?? Infinity) - (b.position ?? Infinity) || a.id - b.id
          );
        })
        .catch((error) => {
          console.error("Error fetching cancel reasons:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openCreate() {
      this.$router.push({ name: "cancel-reason-create" });
    },
    openEdit(reason) {
      this.$router.push({ name: "cancel-reason-edit", params: { id: reason.id } });
    },
    confirmDelete(reason) {
      if (confirm(`Удалить причину "${reason.title}"?`)) {
        this.deleteReason(reason);
      }
    },
    deleteReason(reason) {
      this.apollo()
        .admin.mutate({
          mutation: REASON_DELETE,
          variables: { id: parseInt(reason.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting cancel reason:", error);
          alert("Ошибка удаления причины");
        });
    },
    toggleActive(reason) {
      this.apollo()
        .admin.mutate({
          mutation: REASON_UPDATE,
          variables: {
            id: parseInt(reason.id),
            is_active: !reason.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling cancel reason:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.cancel-reasons-page {
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
