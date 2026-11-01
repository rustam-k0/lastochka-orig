<template>
  <form class="city-form" @submit.prevent="submit">
    <div v-if="loadingData" class="city-form__loading">
      <LoadingIndicator />
    </div>

    <template v-else>
      <InputComponent
        v-model="form.name.value"
        :errors="form.name.errors"
        title="Название"
        placeholder="Например: Красноярск"
        required
      />

      <div class="city-form__coordinates">
        <InputComponent
          v-model.number="form.latitude.value"
          :errors="form.latitude.errors"
          title="Широта центра города"
          placeholder="с.ш, например 56.0184"
          type="number"
          step="any"
        />
        <InputComponent
          v-model.number="form.longitude.value"
          :errors="form.longitude.errors"
          title="Долгота центра города"
          placeholder="в.д, например 92.8672"
          type="number"
          step="any"
        />
      </div>

      <CheckboxComponent v-model="form.is_active.value" :errors="form.is_active.errors" green>
        Активен
      </CheckboxComponent>

      <CheckboxComponent v-model="form.is_default.value" :errors="form.is_default.errors" green>
        Город по умолчанию
      </CheckboxComponent>
      <span class="city-form__hint">
        Город по умолчанию может быть только один: при сохранении флаг снимется с прежнего города.
      </span>

      <div class="city-form__actions">
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
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import { resetErrors, parseGqlErrors } from "@/utils.js";
import CITY_ITEM from "@/graphql/queries/admin_city_item.graphql";
import CITY_CREATE from "@/graphql/mutations/CityCreate.graphql";
import CITY_UPDATE from "@/graphql/mutations/CityUpdate.graphql";

export default {
  name: "CityForm",
  inject: ["apollo"],
  components: {
    InputComponent,
    CheckboxComponent,
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
        name: { value: "", errors: [] },
        latitude: { value: null, errors: [] },
        longitude: { value: null, errors: [] },
        is_active: { value: true, errors: [] },
        is_default: { value: false, errors: [] },
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
          query: CITY_ITEM,
          variables: { id: parseInt(this.id) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const city = data.city;
          if (city) {
            this.form.name.value = city.name;
            this.form.latitude.value = city.latitude;
            this.form.longitude.value = city.longitude;
            this.form.is_active.value = city.is_active;
            this.form.is_default.value = city.is_default;
          }
        })
        .catch((error) => {
          console.error("Error fetching city:", error);
        })
        .finally(() => {
          this.loadingData = false;
        });
    },
    submit() {
      this.resetErrors();
      this.loading = true;

      const variables = {
        name: this.form.name.value,
        // Пустое поле координат = «не задано»: отправляем null, а не 0,
        // иначе город получил бы координаты в Гвинейском заливе.
        latitude:
          this.form.latitude.value === "" || this.form.latitude.value == null
            ? null
            : parseFloat(this.form.latitude.value),
        longitude:
          this.form.longitude.value === "" || this.form.longitude.value == null
            ? null
            : parseFloat(this.form.longitude.value),
        is_active: this.form.is_active.value,
        is_default: this.form.is_default.value,
      };

      if (this.isEdit) {
        variables.id = parseInt(this.id);
      }

      this.apollo()
        .admin.mutate({
          mutation: this.isEdit ? CITY_UPDATE : CITY_CREATE,
          variables,
        })
        .then(() => {
          this.$emit("success");
        })
        .catch(({ graphQLErrors }) => {
          this.parseGqlErrors(graphQLErrors);
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.city-form {
  display flex
  flex-direction column
  gap 20px
  max-width 640px

  &__loading {
    display flex
    justify-content center
    padding 48px
  }

  &__coordinates {
    display grid
    grid-template-columns repeat(2, 1fr)
    gap 16px
  }

  &__hint {
    font-weight 400
    font-size 0.75rem
    color var(--dark-light)
    margin-top -12px
  }

  &__actions {
    display flex
    gap 12px
    margin-top 8px
  }
}
</style>
