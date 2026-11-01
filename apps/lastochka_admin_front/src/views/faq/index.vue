<template>
  <main class="page--main faq-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="faq-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="faq-page__actions">
        <button v-if="faqs.length" @click="openPositionModal" class="btn btn--white btn--md">
          <IconComponent name="drag-move-line" />
          <span>Изменить позицию</span>
        </button>
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить вопрос</span>
        </button>
      </div>
    </div>

    <div v-if="loading" class="faq-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!faqs.length" class="faq-page__empty">
      <IconComponent name="question-answer-line" />
      <h3>Нет вопросов</h3>
      <p>Создайте вопросы и ответы, которые увидят клиенты в мобильном приложении</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать вопрос</span>
      </button>
    </div>

    <div v-else class="faq-page__table-wrapper">
      <table class="faq-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Вопрос</th>
            <th>Активность</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="faq in faqs" :key="faq.id">
            <td>{{ faq.id }}</td>
            <td class="faq-page__question-cell">{{ faq.question }}</td>
            <td>
              <label class="faq-page__toggle" @click.prevent="toggleActive(faq)">
                <input type="checkbox" :checked="faq.is_active" />
                <span class="faq-page__toggle-slider"></span>
              </label>
            </td>
            <td>
              <div class="faq-page__row-actions">
                <button class="faq-page__action-btn" @click="openEdit(faq)" title="Редактировать">
                  <IconComponent name="pencil-line" />
                </button>
                <button
                  class="faq-page__action-btn faq-page__action-btn--delete"
                  @click="confirmDelete(faq)"
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
import FAQS from "@/graphql/queries/admin_faqs.graphql";
import FAQ_DELETE from "@/graphql/mutations/FaqDelete.graphql";
import FAQ_UPDATE from "@/graphql/mutations/FaqUpdate.graphql";
import { useMenuStore } from "@/stores/menu.js";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";
import ChangePositionModal from "./modals/ChangePositionModal.vue";

export default {
  name: "FaqListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
  },
  data() {
    return {
      faqs: [],
      loading: true,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Вопросы и ответы");
    },
    breadcrumbs() {
      return [{ to: { name: "faq" }, title: this.pageTitle }];
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
          query: FAQS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.faqs = [...(data.allFaqs || [])].sort(
            (a, b) => (a.position ?? Infinity) - (b.position ?? Infinity) || a.id - b.id
          );
        })
        .catch((error) => {
          console.error("Error fetching faqs:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    openCreate() {
      this.$router.push({ name: "faq-create" });
    },
    openPositionModal() {
      useDefaultStore().modals.push({
        component: shallowRef(ChangePositionModal),
        options: {
          callback: this.get,
        },
      });
    },
    openEdit(faq) {
      this.$router.push({ name: "faq-edit", params: { id: faq.id } });
    },
    confirmDelete(faq) {
      if (confirm(`Удалить вопрос "${faq.question}"?`)) {
        this.deleteFaq(faq);
      }
    },
    deleteFaq(faq) {
      this.apollo()
        .admin.mutate({
          mutation: FAQ_DELETE,
          variables: { id: parseInt(faq.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting faq:", error);
          alert("Ошибка удаления вопроса");
        });
    },
    toggleActive(faq) {
      this.apollo()
        .admin.mutate({
          mutation: FAQ_UPDATE,
          variables: {
            id: parseInt(faq.id),
            is_active: !faq.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling faq:", error);
          alert("Ошибка изменения статуса");
        });
    },
  },
};
</script>

<style lang="stylus">
.faq-page {
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

  &__question-cell {
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
