<template>
  <main class="page--main story-edit-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="story-edit-page__header">
      <h2>Редактирование истории</h2>
    </div>
    
    <div v-if="loadingData" class="story-edit-page__loading">
      <LoadingIndicator />
    </div>
    
    <div v-else-if="story" class="story-edit-page__content">
      <StoryForm
        :initialData="story"
        :loading="loading"
        :isEdit="true"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
    
    <div v-else class="story-edit-page__error">
      <IconComponent name="error-warning-line" />
      <h3>История не найдена</h3>
      <button @click="$router.push({ name: 'stories' })" class="btn btn--md">
        Вернуться к списку
      </button>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import StoryForm from "./components/StoryForm.vue";
import STORY_ITEM from "@/graphql/queries/admin_stories_item.graphql";
import STORY_UPDATE from "@/graphql/mutations/StoryUpdate.graphql";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "StoryEditPage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    IconComponent,
    LoadingIndicator,
    StoryForm,
  },
  data() {
    return {
      story: null,
      loading: false,
      loadingData: true,
    };
  },
  computed: {
    storyId() {
      return parseInt(this.$route.params.id);
    },
    breadcrumbs() {
      return [
        { to: { name: "stories" }, title: "Истории" },
        { title: this.story?.title || "Редактирование" },
      ];
    },
  },
  mounted() {
    this.fetchStory();
  },
  methods: {
    fetchStory() {
      this.loadingData = true;
      
      this.apollo()
        .admin.query({
          query: STORY_ITEM,
          variables: { id: this.storyId },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.story = data.admin_stories_item;
        })
        .catch((error) => {
          console.error("Error fetching story:", error);
          useDefaultStore().showNotification({
            type: "error",
            message: "Ошибка загрузки истории",
          });
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: STORY_UPDATE,
          variables: {
            id: this.storyId,
            ...formData,
          },
        })
        .then(({ data }) => {
          if (data.StoryUpdate?.id) {
            this.$router.push({ name: "stories" });
          } else {
            throw new Error("Ошибка обновления");
          }
        })
        .catch((error) => {
          console.error("Error updating story:", error);
          alert(error.message || "Ошибка обновления истории");
        })
        .finally(() => {
          this.loading = false;
        });
    },
    handleCancel() {
      this.$router.push({ name: "stories" });
    },
  },
};
</script>

<style lang="stylus">
.story-edit-page {
  &__header {
    margin-bottom 24px
    
    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }
  
  &__loading {
    display flex
    justify-content center
    padding 64px
  }
  
  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
  }
  
  &__error {
    display flex
    flex-direction column
    align-items center
    justify-content center
    padding 64px
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    text-align center
    
    .icon {
      width 64px
      height 64px
      margin-bottom 16px
      
      svg path {
        fill var(--red)
      }
    }
    
    h3 {
      font-size 1.25rem
      color var(--dark)
      margin-bottom 24px
    }
  }
}
</style>
