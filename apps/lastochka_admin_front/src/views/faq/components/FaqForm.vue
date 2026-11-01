<template>
  <form class="faq-form" @submit.prevent="submit">
    <div v-if="loadingData" class="faq-form__loading">
      <LoadingIndicator />
    </div>

    <template v-else>
      <InputComponent
        v-model="form.question.value"
        :errors="form.question.errors"
        title="Вопрос"
        placeholder="Например: Как оформить заказ?"
        required
      />

      <TextareaComponent
        v-model="form.answer.value"
        :errors="form.answer.errors"
        title="Ответ"
        placeholder="Текст ответа, который увидит клиент"
        rows="6"
        required
      />

      <label class="faq-form__checkbox">
        <input type="checkbox" v-model="form.is_active.value" />
        <span>Активен</span>
      </label>

      <div class="faq-form__actions">
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
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import FAQ_ITEM from "@/graphql/queries/admin_faq_item.graphql";
import FAQ_CREATE from "@/graphql/mutations/FaqCreate.graphql";
import FAQ_UPDATE from "@/graphql/mutations/FaqUpdate.graphql";

export default {
  name: "FaqForm",
  inject: ["apollo"],
  components: {
    InputComponent,
    TextareaComponent,
    LoadingIndicator,
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
      loadingData: false,
      form: {
        question: { value: "", errors: [] },
        answer: { value: "", errors: [] },
        is_active: { value: true, errors: [] },
      },
    };
  },
  computed: {
    isEdit() {
      return !!this.id;
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
          query: FAQ_ITEM,
          variables: { id: parseInt(this.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const faq = data.faq;
          if (faq) {
            this.form.question.value = faq.question;
            this.form.answer.value = faq.answer;
            this.form.is_active.value = faq.is_active;
          }
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    submit() {
      this.resetErrors();
      this.loading = true;

      const variables = {
        question: this.form.question.value,
        answer: this.form.answer.value,
        is_active: this.form.is_active.value,
      };

      if (this.isEdit) {
        variables.id = parseInt(this.id);
      }

      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? FAQ_UPDATE : FAQ_CREATE,
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
.faq-form {
  display flex
  flex-direction column
  gap 20px
  max-width 640px

  &__loading {
    display flex
    justify-content center
    padding 32px
  }

  &__checkbox {
    display inline-flex
    align-items center
    gap 8px
    cursor pointer
    font-size 0.875rem
    color var(--dark)

    input {
      width 18px
      height 18px
      cursor pointer
    }
  }

  &__actions {
    display flex
    gap 12px
    margin-top 8px
  }
}
</style>
