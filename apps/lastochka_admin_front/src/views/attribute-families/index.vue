<template>
  <main class="page--main families-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="families-page__header">
      <h2>{{ pageTitle }}</h2>
      <button class="btn btn--md" @click="$router.push({ name: 'attribute-family-create' })">
        <IconComponent name="add-circle-line" />
        <span>Добавить семейство</span>
      </button>
    </div>

    <div v-if="loading" class="families-page__loading">
      <LoadingIndicator />
    </div>

    <div v-else-if="!families.length" class="families-page__empty">
      <IconComponent name="list-settings-line" />
      <h3>Нет семейств атрибутов</h3>
      <button class="btn btn--md" @click="$router.push({ name: 'attribute-family-create' })">
        <IconComponent name="add-circle-line" />
        <span>Создать семейство</span>
      </button>
    </div>

    <div v-else class="families-page__table-wrapper">
      <table class="families-page__table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Название</th>
            <th>Код</th>
            <th>Кол-во атрибутов</th>
            <th>Системное</th>
            <th>Действия</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="family in families" :key="family.id">
            <td>{{ family.id }}</td>
            <td>{{ family.title }}</td>
            <td>
              <span v-if="family.code" class="families-page__code">{{ family.code }}</span>
              <span v-else class="families-page__muted">—</span>
            </td>
            <td>{{ family.attributes ? family.attributes.length : 0 }}</td>
            <td>
              <span
                class="families-page__badge"
                :class="{ 'families-page__badge--system': family.is_system }"
              >
                {{ family.is_system ? 'Да' : 'Нет' }}
              </span>
            </td>
            <td>
              <div class="families-page__actions">
                <button
                  class="families-page__action-btn"
                  title="Редактировать"
                  @click="$router.push({ name: 'attribute-family-edit', params: { id: family.id } })"
                >
                  <IconComponent name="edit-line" />
                </button>
                <button
                  class="families-page__action-btn families-page__action-btn--danger"
                  title="Удалить"
                  :disabled="family.is_system"
                  @click="confirmDelete(family)"
                >
                  <IconComponent name="delete-bin-line" />
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="paginatorInfo.lastPage > 1" class="families-page__pagination">
        <button
          class="btn btn--sm"
          :disabled="page <= 1"
          @click="page--; loadFamilies()"
        >
          &larr;
        </button>
        <span>{{ page }} / {{ paginatorInfo.lastPage }}</span>
        <button
          class="btn btn--sm"
          :disabled="page >= paginatorInfo.lastPage"
          @click="page++; loadFamilies()"
        >
          &rarr;
        </button>
      </div>
    </div>

    <transition name="fade">
      <div v-if="toast" class="families-page__toast">
        {{ toast }}
      </div>
    </transition>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import ATTRIBUTE_FAMILIES_PAGINATE from "@/graphql/queries/attribute_families_paginate.graphql";
import ATTRIBUTE_FAMILY_DELETE from "@/graphql/mutations/AttributeFamilyDelete.graphql";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "AttributeFamiliesPage",
  inject: ["apollo"],
  components: { BreadcrumbsComponent, IconComponent, LoadingIndicator },
  data() {
    return {
      families: [],
      loading: false,
      toast: null,
      toastTimeout: null,
      page: 1,
      perPage: 50,
      paginatorInfo: {},
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Семейства атрибутов");
    },
    breadcrumbs() {
      return [{ title: this.pageTitle }];
    },
  },
  mounted() {
    this.loadFamilies();
  },
  methods: {
    loadFamilies() {
      this.loading = true;
      this.apollo()
        .admin.query({
          query: ATTRIBUTE_FAMILIES_PAGINATE,
          variables: { first: this.perPage, page: this.page },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.families = data?.attribute_families_paginate?.data || [];
          this.paginatorInfo = data?.attribute_families_paginate?.paginatorInfo || {};
        })
        .catch((err) => {
          console.error("Error loading attribute families:", err);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    confirmDelete(family) {
      if (family.is_system) return;
      if (!confirm(`Удалить семейство «${family.title}»?`)) return;
      this.apollo()
        .admin.mutate({
          mutation: ATTRIBUTE_FAMILY_DELETE,
          variables: { id: parseInt(family.id) },
        })
        .then(() => {
          this.showToast(`Семейство «${family.title}» удалено`);
          this.loadFamilies();
        })
        .catch((error) => {
          console.error("Error deleting attribute family:", error);
          alert(error.message || "Ошибка удаления");
        });
    },
    showToast(message) {
      if (this.toastTimeout) clearTimeout(this.toastTimeout);
      this.toast = message;
      this.toastTimeout = setTimeout(() => { this.toast = null; }, 3000);
    },
  },
  beforeUnmount() {
    if (this.toastTimeout) clearTimeout(this.toastTimeout);
  },
};
</script>

<style lang="stylus">
.families-page
  padding 24px

  &__header
    display flex
    align-items center
    justify-content space-between
    margin-bottom 24px

    h2
      margin 0

  &__loading
    display flex
    justify-content center
    padding 48px

  &__empty
    text-align center
    padding 48px
    color #999

    .icon
      font-size 48px
      margin-bottom 16px

    h3
      margin 0 0 8px

    .btn
      margin-top 16px

  &__table-wrapper
    background #fff
    border-radius 8px
    overflow hidden

  &__table
    width 100%
    border-collapse collapse

    th, td
      padding 12px 16px
      text-align left
      border-bottom 1px solid #eee

    th
      font-weight 600
      color #666
      font-size 13px

    tr:hover td
      background #fafafa

  &__code
    font-family monospace
    font-size 13px
    background #f5f5f5
    padding 2px 8px
    border-radius 4px

  &__muted
    color #ccc

  &__badge
    display inline-block
    padding 2px 10px
    border-radius 12px
    font-size 12px
    font-weight 500
    background #f0f0f0
    color #999

    &--system
      background #e8f5e9
      color #2e7d32

  &__actions
    display flex
    gap 8px

  &__action-btn
    display flex
    align-items center
    justify-content center
    width 36px
    height 36px
    border-radius 8px
    border 1px solid #eee
    background #fff
    cursor pointer
    transition all 0.2s

    .icon
      width 18px
      height 18px

      svg path
        fill #333

    &:hover:not(:disabled)
      border-color var(--primary)

      .icon svg path
        fill var(--primary)

    &--danger:hover:not(:disabled)
      border-color #e55

      .icon svg path
        fill #e55

    &:disabled
      opacity 0.3
      cursor not-allowed

  &__pagination
    display flex
    align-items center
    justify-content center
    gap 16px
    padding 16px

  &__toast
    position fixed
    bottom 32px
    left 50%
    transform translateX(-50%)
    background #333
    color #fff
    padding 12px 24px
    border-radius 8px
    font-size 14px
    z-index 1000
    box-shadow 0 4px 12px rgba(0, 0, 0, 0.15)

.fade-enter-active,
.fade-leave-active
  transition opacity 0.3s

.fade-enter-from,
.fade-leave-to
  opacity 0
</style>
