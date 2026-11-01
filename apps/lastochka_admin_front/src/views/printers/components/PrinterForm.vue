<template>
  <form class="printer-form" @submit.prevent="submit">
    <div v-if="loadingData" class="printer-form__loading">
      <LoadingIndicator />
    </div>

    <template v-else>
      <InputComponent
        v-model="form.title.value"
        :errors="form.title.errors"
        title="Название принтера"
        placeholder="Например: Принтер этикеток, зал 1"
        required
      />

      <InputComponent
        v-model="form.ip_address.value"
        :errors="form.ip_address.errors"
        title="IP-адрес"
        placeholder="Например: 192.168.10.21"
        required
      />

      <InputComponent
        v-model="form.port.value"
        :errors="form.port.errors"
        title="Порт"
        placeholder="Например: 9100"
        type="number"
        required
      />

      <label class="printer-form__checkbox">
        <input type="checkbox" v-model="form.is_active.value" />
        <span>Активен</span>
      </label>

      <div class="printer-form__actions">
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
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import PRINTER_ITEM from "@/graphql/queries/admin_printer_item.graphql";
import PRINTER_CREATE from "@/graphql/mutations/PrinterCreate.graphql";
import PRINTER_UPDATE from "@/graphql/mutations/PrinterUpdate.graphql";

export default {
  name: "PrinterForm",
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
        ip_address: { value: "", errors: [] },
        port: { value: null, errors: [] },
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
          query: PRINTER_ITEM,
          variables: { id: parseInt(this.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const printer = data.printer;
          if (printer) {
            this.form.title.value = printer.title;
            this.form.ip_address.value = printer.ip_address;
            this.form.port.value = printer.port;
            this.form.is_active.value = printer.is_active;
          }
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    submit() {
      this.resetErrors();

      // port объявлен в мутации как Int! — пустое значение нельзя отправить
      // на сервер (Apollo вернёт ошибку типа, а не ошибку поля), поэтому
      // проверяем заполненность до запроса.
      const port = this.form.port.value;
      if (port === null || port === "") {
        this.form.port.errors = ["Укажите порт принтера"];
        return;
      }

      this.loading = true;

      const variables = {
        title: this.form.title.value,
        ip_address: this.form.ip_address.value,
        port: parseInt(port),
        is_active: this.form.is_active.value,
      };

      if (this.isEdit) {
        variables.id = parseInt(this.id);
      }

      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? PRINTER_UPDATE : PRINTER_CREATE,
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
.printer-form {
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
