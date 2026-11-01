<template>
  <form class="page-form" @submit.prevent="submit">
    <div v-if="loadingData" class="page-form__loading">
      <LoadingIndicator />
    </div>

    <template v-else>
      <div class="page-form__block">
        <div class="page-form__block-header">Основная информация</div>
        <div class="page-form__block-content">
          <InputComponent
            v-model="form.title.value"
            :errors="form.title.errors"
            title="Название страницы"
            placeholder="Например: Политика конфиденциальности"
            required
          />
          <InputComponent
            v-model="form.slug.value"
            :errors="form.slug.errors"
            title="Код страницы"
            placeholder="Например: privacy-policy"
            :disabled="isSystem"
          />
          <p v-if="slugHint" class="page-form__hint">{{ slugHint }}</p>
          <p v-if="isSystem" class="page-form__hint page-form__hint--accent">
            Это системная страница: код изменять нельзя, удалить её тоже нельзя.
          </p>
        </div>
      </div>

      <div class="page-form__block">
        <div class="page-form__block-header">Содержимое</div>
        <div class="page-form__block-content">
          <EditorJSComponent
            v-model="form.content.value"
            :errors="form.content.errors"
            title="Текст страницы"
            placeholder="Введите текст страницы"
          />
        </div>
      </div>

      <div class="page-form__block">
        <div class="page-form__block-header">SEO</div>
        <div class="page-form__block-content">
          <InputComponent
            v-model="form.meta_title.value"
            :errors="form.meta_title.errors"
            title="SEO-заголовок"
            placeholder="Заголовок страницы для поисковых систем"
          />
          <TextareaComponent
            v-model="form.meta_description.value"
            :errors="form.meta_description.errors"
            title="SEO-описание"
            placeholder="Краткое описание страницы для поисковых систем"
            rows="3"
          />
          <p class="page-form__hint">Поля нужны сайту, в мобильном приложении не используются.</p>
        </div>
      </div>

      <div class="page-form__block">
        <div class="page-form__block-header">Публикация</div>
        <div class="page-form__block-content">
          <InputComponent
            v-model="form.position.value"
            :errors="form.position.errors"
            title="Порядок отображения"
            placeholder="Чем меньше — тем выше в списке"
            type="number"
          />
          <FlagCheckboxComponent v-model="form.is_published.value" green>
            {{ form.is_published.value ? "Опубликована" : "Не опубликована" }}
          </FlagCheckboxComponent>
          <p class="page-form__hint">Опубликованная страница отдаётся сайту и мобильному приложению.</p>
          <p v-if="publishedAt" class="page-form__hint">Дата первой публикации: {{ publishedAt }}</p>
        </div>
      </div>

      <div class="page-form__actions">
        <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">Отмена</button>
        <button type="submit" class="btn btn--md" :disabled="loading">
          {{ isEdit ? "Сохранить" : "Создать" }}
        </button>
      </div>
    </template>
  </form>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import EditorJSComponent from "@/components/EditorJSComponent.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import PAGE_ITEM from "@/graphql/queries/admin_pages_item.graphql";
import PAGE_CREATE from "@/graphql/mutations/PageCreate.graphql";
import PAGE_UPDATE from "@/graphql/mutations/PageUpdate.graphql";

export default {
  name: "PageForm",
  inject: ["apollo"],
  components: {
    InputComponent,
    TextareaComponent,
    FlagCheckboxComponent,
    LoadingIndicator,
    EditorJSComponent,
  },
  props: {
    id: {
      type: [Number, String],
      default: null,
    },
  },
  emits: ["success", "cancel"],
  data() {
    return {
      loading: false,
      // В режиме редактирования форма не должна успеть отрисоваться до загрузки
      // данных: EditorJSComponent не вызывает destroy() при размонтировании,
      // и мгновенное монтирование/размонтирование в mounted() оставляет висеть
      // брошенный экземпляр редактора.
      loadingData: !!this.id,
      publishedAt: null,
      isSystem: false,
      form: {
        slug: { value: "", errors: [] },
        title: { value: "", errors: [] },
        content: { value: null, errors: [] },
        meta_title: { value: "", errors: [] },
        meta_description: { value: "", errors: [] },
        position: { value: null, errors: [] },
        is_published: { value: false, errors: [] },
      },
    };
  },
  computed: {
    isEdit() {
      return !!this.id;
    },
    slugHint() {
      if (this.isSystem) {
        return null;
      }
      if (this.isEdit) {
        return "Код используется в ссылках сайта и мобильного приложения. Латиница в нижнем регистре, цифры и дефис. Если оставить поле пустым, код останется прежним.";
      }
      return "Код используется в ссылках сайта и мобильного приложения. Латиница в нижнем регистре, цифры и дефис. Если оставить поле пустым, код сформируется из названия.";
    },
  },
  mounted() {
    if (this.id) {
      this.get();
    }
  },
  methods: {
    resetErrors,
    parseGqlErrors,

    get() {
      this.loadingData = true;
      this.apollo()
        .admin.query({
          query: PAGE_ITEM,
          variables: { id: parseInt(this.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const page = data.admin_pages_item;
          if (!page) {
            return;
          }
          this.form.slug.value = page.slug;
          this.form.title.value = page.title;
          // Редактор принимает и строку, и объект — передаём как пришло.
          this.form.content.value = page.content;
          this.form.meta_title.value = page.meta_title || "";
          this.form.meta_description.value = page.meta_description || "";
          this.form.position.value = page.position;
          this.form.is_published.value = page.is_published;
          this.isSystem = page.is_system;
          this.publishedAt = page.published_at
            ? new Date(page.published_at).toLocaleDateString("ru-RU", {
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
                hour: "2-digit",
                minute: "2-digit",
              })
            : null;
        })
        .catch((error) => {
          console.error("Ошибка при загрузке страницы:", error);
        })
        .finally(() => {
          this.loadingData = false;
        });
    },

    nullIfEmpty(value) {
      const trimmed = typeof value === "string" ? value.trim() : value;
      return trimmed === "" || trimmed === undefined ? null : trimmed;
    },

    // Редактор отдаёт объект, а мутация принимает JSON-строку.
    serializeContent() {
      const value = this.form.content.value;
      if (!value) {
        return "";
      }
      return typeof value === "string" ? value : JSON.stringify(value);
    },

    submit() {
      this.resetErrors();
      this.loading = true;

      const slug = this.nullIfEmpty(this.form.slug.value);

      const variables = {
        title: this.form.title.value,
        content: this.serializeContent(),
        meta_title: this.nullIfEmpty(this.form.meta_title.value),
        meta_description: this.nullIfEmpty(this.form.meta_description.value),
        position:
          this.form.position.value === null || this.form.position.value === ""
            ? null
            : parseInt(this.form.position.value),
        is_published: this.form.is_published.value,
      };

      if (this.isEdit) {
        variables.id = parseInt(this.id);
        // Код системной страницы менять нельзя — не отправляем его вовсе.
        // Пустое поле при редактировании значит «код не менять» — тоже не отправляем
        // аргумент вовсе, иначе сервер получит slug: null и потребует значение.
        if (slug !== null && !this.isSystem) {
          variables.slug = slug;
        }
      } else {
        // При создании пустой код — сигнал серверу сгенерировать код из названия.
        variables.slug = slug;
      }

      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? PAGE_UPDATE : PAGE_CREATE,
          variables,
        })
        .then(() => {
          this.$emit("success");
        })
        .catch(({ graphQLErrors }) => {
          if (graphQLErrors) {
            this.parseGqlErrors(graphQLErrors);
          }
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.page-form {
  display flex
  flex-direction column
  gap 24px
  max-width 780px

  &__loading {
    display flex
    justify-content center
    padding 32px
  }

  &__block {
    display flex
    flex-direction column
    gap 16px
  }

  &__block-header {
    font-size 1rem
    font-weight 600
    color var(--dark)
  }

  &__block-content {
    display flex
    flex-direction column
    gap 16px
  }

  &__hint {
    margin 0
    font-size 0.813rem
    color var(--dark-light)

    &--accent {
      color var(--dark)
      font-weight 500
    }
  }

  &__actions {
    display flex
    gap 12px
    margin-top 8px
  }
}
</style>
