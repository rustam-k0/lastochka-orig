<template>
  <form class="explain-reason-form" @submit.prevent="submit">
    <div v-if="loadingData" class="explain-reason-form__loading">
      <LoadingIndicator />
    </div>

    <template v-else>
      <InputComponent
        v-model="form.title.value"
        :errors="form.title.errors"
        title="Причина уточнения"
        placeholder="Например: Упаковка товара повреждена"
        required
      />

      <InputComponent
        v-model="form.position.value"
        :errors="form.position.errors"
        title="Позиция сортировки"
        placeholder="Чем меньше — тем выше в списке"
        type="number"
      />

      <label class="explain-reason-form__checkbox">
        <input type="checkbox" v-model="form.is_active.value" />
        <span>Активна</span>
      </label>

      <div class="explain-reason-form__actions">
        <button type="button" class="btn btn--white btn--md" @click="$emit('cancel')">
          Отмена
        </button>
        <button type="submit" class="btn btn--md" :disabled="loading">
          {{ isEdit ? "Сохранить" : "Создать" }}
        </button>
      </div>
    </template>
  </form>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import REASON_ITEM from "@/graphql/queries/admin_explain_reason_item.graphql";
import REASON_CREATE from "@/graphql/mutations/ExplainReasonCreate.graphql";
import REASON_UPDATE from "@/graphql/mutations/ExplainReasonUpdate.graphql";

export default {
  name: "ExplainReasonForm",
  inject: ["apollo"],
  components: {
    InputComponent,
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
        title: { value: "", errors: [] },
        position: { value: null, errors: [] },
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
          query: REASON_ITEM,
          variables: { id: parseInt(this.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const reason = data.explainReason;
          if (reason) {
            this.form.title.value = reason.title;
            this.form.position.value = reason.position;
            this.form.is_active.value = reason.is_active;
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
        title: this.form.title.value,
        is_active: this.form.is_active.value,
        position:
          this.form.position.value === null || this.form.position.value === ""
            ? undefined
            : parseInt(this.form.position.value),
      };

      if (this.isEdit) {
        variables.id = parseInt(this.id);
      }

      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? REASON_UPDATE : REASON_CREATE,
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
.explain-reason-form {
  display flex
  flex-direction column
  gap 20px
  max-width 480px

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
