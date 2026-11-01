<template>
  <main class="page--main story-create-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="story-create-page__header">
      <h2>Создание истории</h2>
    </div>
    
    <div class="story-create-page__content">
      <StoryForm
        :loading="loading"
        :isEdit="false"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import StoryForm from "./components/StoryForm.vue";
import STORY_CREATE from "@/graphql/mutations/StoryCreate.graphql";
import { useDefaultStore } from "@/stores/default.js";

export default {
  name: "StoryCreatePage",
  inject: ["apollo"],
  components: {
    BreadcrumbsComponent,
    StoryForm,
  },
  data() {
    return {
      loading: false,
      breadcrumbs: [
        { to: { name: "stories" }, title: "Истории" },
        { title: "Создание" },
      ],
    };
  },
  methods: {
    handleSubmit(formData) {
      this.loading = true;
      
      this.apollo()
        .admin.mutate({
          mutation: STORY_CREATE,
          variables: formData,
        })
        .then(({ data }) => {
          if (data.StoryCreate?.id) {
            this.$router.push({ name: "stories" });
          } else {
            throw new Error("Ошибка создания");
          }
        })
        .catch((error) => {
          console.error("Error creating story:", error);
          alert(error.message || "Ошибка создания истории");
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
.story-create-page {
  &__header {
    margin-bottom 24px
    
    h2 {
      font-size 1.5rem
      font-weight 600
      color var(--dark)
    }
  }
  
  &__content {
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px
  }
}
</style>
