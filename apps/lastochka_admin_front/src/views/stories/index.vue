<template>
  <main class="page--main stories-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="stories-page__header">
      <h2>{{ pageTitle }}</h2>
      <div class="stories-page__actions">
        <button @click="openCreate" class="btn btn--md">
          <IconComponent name="add-circle-line" />
          <span>Добавить историю</span>
        </button>
      </div>
    </div>

    <!-- Фильтры -->
    <div class="stories-page__filters">
      <div class="stories-page__search">
        <IconComponent name="search-line" />
        <input 
          type="text"
          v-model="search"
          placeholder="Поиск по названию..."
          @input="debouncedSearch"
        />
      </div>
      <div class="stories-page__filter-buttons">
        <button 
          v-for="filter in statusFilters" 
          :key="filter.value"
          class="stories-page__filter-btn"
          :class="{ 'stories-page__filter-btn--active': statusFilter === filter.value }"
          @click="statusFilter = filter.value"
        >
          {{ filter.label }}
        </button>
      </div>
    </div>

    <!-- Загрузка -->
    <div v-if="loading" class="stories-page__loading">
      <LoadingIndicator />
    </div>

    <!-- Пустое состояние -->
    <div v-else-if="!stories.length" class="stories-page__empty">
      <IconComponent name="film-line" />
      <h3>Нет историй</h3>
      <p>Создайте первую историю для отображения пользователям</p>
      <button @click="openCreate" class="btn btn--md">
        <IconComponent name="add-circle-line" />
        <span>Создать историю</span>
      </button>
    </div>

    <!-- Список историй -->
    <section v-else class="stories-page__list">
      <StoryCard
        v-for="(story, index) in stories"
        :key="story.id"
        :data="story"
        @edit="openEdit(story)"
        @delete="confirmDelete(story)"
        @toggle-active="toggleActive(story)"
        @dragstart="onDragStart(index, $event)"
        @dragover="onDragOver(index, $event)"
        @drop="onDrop(index)"
      />
    </section>

    <!-- Пагинация -->
    <div v-if="paginatorInfo.lastPage > 1" class="stories-page__pagination">
      <button 
        :disabled="page <= 1"
        @click="page--"
        class="btn btn--white btn--sm"
      >
        Назад
      </button>
      <span>{{ page }} из {{ paginatorInfo.lastPage }}</span>
      <button 
        :disabled="page >= paginatorInfo.lastPage"
        @click="page++"
        class="btn btn--white btn--sm"
      >
        Вперёд
      </button>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import StoryCard from "./components/StoryCard.vue";
import STORIES_PAGINATE from "@/graphql/queries/admin_stories_paginate.graphql";
import STORY_DELETE from "@/graphql/mutations/StoryDelete.graphql";
import STORY_UPDATE from "@/graphql/mutations/StoryUpdate.graphql";
import STORY_CHANGE_POSITION from "@/graphql/mutations/StoryChangePosition.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "StoriesListPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    StoryCard,
  },
  data() {
    return {
      stories: [],
      loading: true,
      search: "",
      statusFilter: "all",
      page: 1,
      first: 20,
      paginatorInfo: {
        currentPage: 1,
        lastPage: 1,
        total: 0,
      },
      searchTimeout: null,
      draggedIndex: null,
      statusFilters: [
        { value: "all", label: "Все" },
        { value: "active", label: "Активные" },
        { value: "inactive", label: "Неактивные" },
      ],
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Истории");
    },
    breadcrumbs() {
      return [{ to: { name: "stories" }, title: this.pageTitle }];
    },
    isActiveFilter() {
      if (this.statusFilter === "active") return true;
      if (this.statusFilter === "inactive") return false;
      return undefined;
    },
  },
  watch: {
    page() {
      this.get();
    },
    statusFilter() {
      this.page = 1;
      this.get();
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
          query: STORIES_PAGINATE,
          variables: {
            first: this.first,
            page: this.page,
            is_active: this.isActiveFilter,
            search: this.search || undefined,
            ordering: [{ orderColumn: "position", orderBy: "ASC" }],
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.stories = data.admin_stories_paginate?.data || [];
          this.paginatorInfo = data.admin_stories_paginate?.paginatorInfo || {};
        })
        .catch((error) => {
          console.error("Error fetching stories:", error);
        })
        .finally(() => {
          this.loading = false;
        });
    },
    debouncedSearch() {
      clearTimeout(this.searchTimeout);
      this.searchTimeout = setTimeout(() => {
        this.page = 1;
        this.get();
      }, 300);
    },
    openCreate() {
      this.$router.push({ name: "story-create" });
    },
    openEdit(story) {
      this.$router.push({ name: "story-edit", params: { id: story.id } });
    },
    confirmDelete(story) {
      if (confirm(`Удалить историю "${story.title}"?`)) {
        this.deleteStory(story);
      }
    },
    deleteStory(story) {
      this.apollo()
        .admin.mutate({
          mutation: STORY_DELETE,
          variables: { id: parseInt(story.id) },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error deleting story:", error);
          alert("Ошибка удаления истории");
        });
    },
    toggleActive(story) {
      this.apollo()
        .admin.mutate({
          mutation: STORY_UPDATE,
          variables: {
            id: parseInt(story.id),
            is_active: !story.is_active,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error toggling story:", error);
          alert("Ошибка изменения статуса");
        });
    },
    onDragStart(index, event) {
      this.draggedIndex = index;
      event.dataTransfer.effectAllowed = "move";
    },
    onDragOver(index, event) {
      event.preventDefault();
      event.dataTransfer.dropEffect = "move";
    },
    onDrop(targetIndex) {
      if (this.draggedIndex === null || this.draggedIndex === targetIndex) return;
      
      const story = this.stories[this.draggedIndex];
      const newPosition = this.stories[targetIndex].position;
      
      this.apollo()
        .admin.mutate({
          mutation: STORY_CHANGE_POSITION,
          variables: {
            id: parseInt(story.id),
            position: newPosition,
          },
        })
        .then(() => {
          this.get();
        })
        .catch((error) => {
          console.error("Error changing position:", error);
        });
      
      this.draggedIndex = null;
    },
  },
};
</script>

<style lang="stylus">
.stories-page {
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
  
  &__filters {
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px
    gap 16px
    flex-wrap wrap
  }
  
  &__search {
    display flex
    align-items center
    gap 8px
    padding 12px 16px
    background var(--white)
    border 1px solid var(--gray-dark)
    border-radius 8px
    width 320px
    
    .icon {
      width 18px
      height 18px
      flex-shrink 0
      
      svg path {
        fill var(--dark-light)
      }
    }
    
    input {
      border none
      outline none
      width 100%
      font-size 0.875rem
      
      &::placeholder {
        color var(--dark-light)
      }
    }
  }
  
  &__filter-buttons {
    display flex
    gap 8px
  }
  
  &__filter-btn {
    padding 10px 20px
    border 1px solid var(--gray-dark)
    border-radius 8px
    background var(--white)
    font-size 0.875rem
    color var(--dark)
    cursor pointer
    transition all 0.2s
    
    &:hover {
      border-color var(--pink)
    }
    
    &--active {
      background var(--pink)
      border-color var(--pink)
      color var(--white)
    }
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
  
  &__list {
    display grid
    grid-template-columns repeat(5, 1fr)
    gap 20px
    
    +below(1800px) {
      grid-template-columns repeat(4, 1fr)
    }
    
    +below(1400px) {
      grid-template-columns repeat(3, 1fr)
    }
    
    +below(1000px) {
      grid-template-columns repeat(2, 1fr)
    }
    
    +below(600px) {
      grid-template-columns 1fr
    }
  }
  
  &__pagination {
    display flex
    justify-content center
    align-items center
    gap 16px
    margin-top 32px
    
    span {
      font-size 0.875rem
      color var(--dark)
    }
  }
}
</style>
