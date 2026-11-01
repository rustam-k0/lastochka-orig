<template>
  <main class="page announcement-page">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="announcement-page__header">
      <button @click="$router.go(-1)" class="btn btn--sm btn--white">
        <IconComponent name="arrow-left-line" />
      </button>
      <div class="announcement-page__header-title">
        <span>{{ isEdit ? "Редактировать уведомление" : "Новое уведомление" }}</span>
      </div>
      <div v-if="isEdit" class="announcement-page__header-actions">
        <button
          v-if="form.is_active.value && !loading"
          @click="sendNotification"
          class="btn btn--sm btn--notification"
          :disabled="notificationLoading"
          :title="notificationLoading ? 'Отправка...' : 'Отправить уведомление'"
        >
          <IconComponent name="notification-2-line" />
          <span v-if="notificationLoading">Отправка...</span>
          <span v-else>Отправить уведомление</span>
        </button>
        <button v-if="!loading" class="btn btn--sm" @click="submit">
          <IconComponent name="checkbox-circle-fill" />
          <span>Сохранить изменения</span>
        </button>
      </div>
      <button v-else @click="submit" class="btn btn--sm">
        <IconComponent name="checkbox-circle-fill" />
        <span>Сохранить</span>
      </button>
    </div>

    <div v-if="isEdit" class="announcement-page__info">
      <div class="announcement-page__status">
        <div class="announcement-page__status-title">Статус уведомления</div>
        <div class="announcement-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_active.value"
            green
            :disabled="loading"
          >
            {{ form.is_active.value ? "Активное" : "Неактивное" }}
          </FlagCheckboxComponent>
        </div>
      </div>
    </div>

    <div v-if="isCreate" class="announcement-page__info">
      <div class="announcement-page__status">
        <div class="announcement-page__status-title">Активность</div>
        <div class="announcement-page__status-action">
          <FlagCheckboxComponent
            v-model="form.is_active.value"
          >
            {{ form.is_active.value ? "Активное" : "Неактивное" }}
          </FlagCheckboxComponent>
        </div>
      </div>
    </div>

    <div class="announcement-page__main">
      <form @submit.prevent="submit" class="announcement-page__form">
        <div class="announcement-page__form-part">
          <div class="announcement-page__form-block">
            <div class="announcement-page__form-header">Основная информация</div>
            <div class="announcement-page__form-content">
              <InputComponent
                title="Заголовок"
                placeholder="Заголовок уведомления"
                :errors="form.title.errors"
                required
                v-model="form.title.value"
              />
              <InputComponent
                title="Краткое описание"
                placeholder="Краткое описание"
                :errors="form.short_description.errors"
                required
                v-model="form.short_description.value"
              />
              <SelectComponent
                title="Промокод"
                placeholder="Выберите промокод"
                label-name="title"
                value-name="id"
                v-model="form.promocode_id.value"
                @update:search="handlePromocodeSearch"
                @blur="blurPromocodeSearch"
                :options="promocodes"
                clearable
                :loading="promocodeLoading"
                :debug="true"
              />
            </div>
          </div>

          <div class="announcement-page__form-block">
            <div class="announcement-page__form-header">Изображение</div>
            <div class="announcement-page__form-content">
              <ImageUpload
                v-model="form.image.value"
                :errors="form.image.errors"
                :ratio="16/9"
                title="Изображение уведомления"
                placeholder="Загрузить изображение"
              />
            </div>
          </div>

          <div class="announcement-page__form-block">
            <div class="announcement-page__form-header">Полное описание</div>
            <div class="announcement-page__form-content">
              <EditorJSComponent
                v-model="form.description.value"
                :errors="form.description.errors"
                title="Описание"
                placeholder="Введите полное описание уведомления"
              />
            </div>
          </div>
        </div>
      </form>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import ImageUpload from "@/components/ImageUpload.vue";
import EditorJSComponent from "@/components/EditorJSComponent.vue";
import ANNOUNCEMENT_ITEM from "@/graphql/queries/announcement_item.graphql";
import ANNOUNCEMENT_CREATE from "@/graphql/mutations/AnnouncementCreate.graphql";
import ANNOUNCEMENT_UPDATE from "@/graphql/mutations/AnnouncementUpdate.graphql";
import { useNotifications } from "@/composables/useNotifications";
import MERCHANT_PROMOCODES from "@/graphql/queries/merchant_promocodes_paginate.graphql";

export default {
  name: "AnnouncementOpen",
  inject: ["apollo"],

  async mounted() {
    this.notifications = useNotifications();
    console.log("Apollo client:", this.apollo());
    console.log("User:", this.$store?.getters?.user || "Нет store");
    console.log("Route:", this.$route.name, "ID:", this.id);

    if (this.isEdit && this.id) {
      await this.get();
    }
    await this.getPromocodes();
  },

  data() {
    return {
      loading: false,
      promocodes: [],
      promocodeLoading: false,
      promocodeSearchDebounce: null,
      promocodeSearch: null,
      notificationLoading: false,
      notifications: null,
      form: {
        title: {
          value: "",
          errors: [],
        },
        short_description: {
          value: "",
          errors: [],
        },
        description: {
          value: null,
          errors: [],
        },
        promocode_id: {
          value: null,
          errors: [],
        },
        is_active: {
          value: true,
          errors: [],
        },
        image: {
          value: null,
          errors: [],
        },
      },
    };
  },

  computed: {
    id() {
      return this.$route.params.id ? parseInt(this.$route.params.id) : null;
    },
    isCreate() {
      return this.$route.name === 'announcement-create';
    },
    isEdit() {
      return this.$route.name === 'announcement-edit';
    },
    breadcrumbs() {
      return [
        { title: "Главная", to: { name: "desktop" } },
        { title: "Уведомления об акциях", to: { name: "announcements" } },
        {
          title: this.isEdit ? "Редактирование" : "Создание",
          to: this.$route
        },
      ];
    },
  },

  methods: {
    async sendNotification() {
      if (!confirm(`Отправить push-уведомление "${this.form.title.value}" всем пользователям?`)) {
        return;
      }
      try {
        this.notificationLoading = true;
        await this.notifications.sendAnnouncementNotification(
          this.apollo(),
          this.id,
          { target: 'ALL_USERS' }
        );
      } catch (error) {
        console.error("Ошибка при отправке уведомления:", error);
      } finally {
        this.notificationLoading = false;
      }
    },

    handlePromocodeSearch(search) {
      if (this.promocodeSearchDebounce) {
        clearTimeout(this.promocodeSearchDebounce);
      }
      this.promocodeSearch = search;
      this.promocodeSearchDebounce = setTimeout(() => {
        this.getPromocodes();
      }, 500);
    },

    blurPromocodeSearch() {
      if (this.promocodeSearch && !this.promocodes.length) {
        this.promocodeSearch = null;
        this.getPromocodes();
      }
    },

    async getPromocodes() {
      this.promocodeLoading = true;
      try {
        const variables = {
          first: 50,
          page: 1,
          search: this.promocodeSearch,
          is_active: true, // Показываем только активные промокоды
        };

        console.log("Загружаем промокоды с параметрами:", variables);

        const { data } = await this.apollo().admin.query({
          query: MERCHANT_PROMOCODES,
          variables,
          fetchPolicy: 'network-only', // Всегда загружать с сервера
        });

        console.log("Полученные промокоды:", data);
        this.promocodes = data.merchantPromocodes.data;
      } catch (error) {
        console.error("Ошибка при загрузке промокодов:", error);
      } finally {
        this.promocodeLoading = false;
      }
    },

    collectData() {
      // Если promocode_id это объект, берем из него id, иначе оставляем как есть
      const promocodeId = this.form.promocode_id.value
        ? (typeof this.form.promocode_id.value === 'object'
           ? this.form.promocode_id.value.id
           : this.form.promocode_id.value)
        : null;

      return {
        title: this.form.title.value,
        short_description: this.form.short_description.value,
        description: this.form.description.value ? JSON.stringify(this.form.description.value) : "",
        promocode_id: promocodeId,
        is_active: this.form.is_active.value,
        image: this.form.image.value,
      };
    },

    async get() {
      this.loading = true;
      try {
        const { data } = await this.apollo().admin.query({
          query: ANNOUNCEMENT_ITEM,
          variables: { id: this.id },
        });

        const announcement = data.announcement;

        this.form.title.value = announcement.title || "";
        this.form.short_description.value = announcement.short_description || "";
        // Парсим JSON строку обратно в объект для EditorJS
        try {
          this.form.description.value = announcement.description
            ? (typeof announcement.description === 'string'
               ? JSON.parse(announcement.description)
               : announcement.description)
            : null;
        } catch (e) {
          console.error("Ошибка парсинга JSON description:", e);
          this.form.description.value = null;
        }

        // Если есть связанный промокод, устанавливаем весь объект для корректной работы SelectComponent
        this.form.promocode_id.value = announcement.promocode || announcement.promocode_id;
        this.form.is_active.value = announcement.is_active;
        this.form.image.value = announcement.image;


      } catch (error) {
        console.error("Ошибка при загрузке уведомления:", error);
        alert("Ошибка при загрузке данных уведомления");
      } finally {
        this.loading = false;
      }
    },

    async submit() {
      if (this.loading) return;

      this.clearErrors();

      if (!this.validateForm()) {
        return;
      }

      this.loading = true;
      const variables = this.collectData();


      try {
        if (this.id) {
          await this.apollo().admin.mutate({
            mutation: ANNOUNCEMENT_UPDATE,
            variables: { id: this.id, ...variables },
          });
          alert("Уведомление успешно обновлено");
        } else {
          const { data } = await this.apollo().admin.mutate({
            mutation: ANNOUNCEMENT_CREATE,
            variables,
          });
          alert("Уведомление успешно создано");
          this.$router.push({
            name: "announcement-edit",
            params: { id: data.announcementCreate.id },
          });
        }
      } catch (error) {
        console.error("Ошибка при сохранении:", error);
        if (error.graphQLErrors) {
          console.error("GraphQL ошибки:", error.graphQLErrors);
          error.graphQLErrors.forEach(err => {
            if (err.extensions && err.extensions.validation) {
              Object.keys(err.extensions.validation).forEach(field => {
                console.error(`Поле ${field}:`, err.extensions.validation[field]);
              });
            }
          });
          this.handleGraphQLErrors(error.graphQLErrors);
        } else {
          alert("Произошла ошибка при сохранении: " + error.message);
        }
      } finally {
        this.loading = false;
      }
    },

    validateForm() {
      let isValid = true;

      if (!this.form.title.value?.trim()) {
        this.form.title.errors = ["Поле обязательно для заполнения"];
        isValid = false;
      }

      if (!this.form.short_description.value?.trim()) {
        this.form.short_description.errors = ["Поле обязательно для заполнения"];
        isValid = false;
      }

      if (!this.form.description.value) {
        this.form.description.errors = ["Поле обязательно для заполнения"];
        isValid = false;
      }

      return isValid;
    },

    clearErrors() {
      Object.keys(this.form).forEach((key) => {
        this.form[key].errors = [];
      });
    },

    handleGraphQLErrors(errors) {
      errors.forEach((err) => {
        if (err.extensions.category === "validation") {
          Object.entries(err.extensions.validation).forEach(([key, messages]) => {
            if (this.form[key]) {
              this.form[key].errors = messages;
            }
          });
        } else {
          alert(err.message || "Произошла ошибка");
        }
      });
    },
  },

  components: {
    BreadcrumbsComponent,
    InputComponent,
    SelectComponent,
    FlagCheckboxComponent,
    IconComponent,
    ImageUpload,
    EditorJSComponent,
  },
};
</script>

<style lang="stylus">
.announcement-page {
  &__header {
    display flex
    justify-content space-between
    align-items center
    gap 16px
  }

  &__header-title {
    width 100%
    font-size 24px
    line-height 33px
    font-weight 500
    color #222128
  }

  &__header-actions {
    display flex
    gap 16px
    width 100%
    justify-content flex-end
  }

  &__info {
    display grid
    grid-template-columns repeat(2, 1fr)
    grid-gap 16px
    padding 16px
    border-radius 16px
    border 1px solid #F2F2F2
    background #FFFFFF
    width 100%
  }

  &__status {
    display flex
    justify-content center
    align-items center
    flex-direction column
    background #F7FDFA
    border-radius 16px
    gap 4px
    padding 32px 0
  }

  &__status-title {
    font-weight 400
    font-size 13px
    line-height 18px
    color #373737
  }

  &__status-action {
    display flex
    padding 12px 0
    justify-content center
    align-items center
  }

  &__main {
    width 100%
  }

  &__form {
    display grid
    grid-template-columns repeat(2, 1fr)
    grid-gap 16px
  }

  &__form-part {
    height fit-content
    display flex
    flex-direction column
    gap 32px
    padding 16px
    background white
    border: 1px solid #F2F2F2;
    border-radius: 16px;
  }

  &__form-block {
    display flex
    flex-direction column
    gap 16px
  }

  &__form-header {
    font-family var(--font-bold)
    padding: 4px 0 21px 0
    font-weight: 500;
    font-size: 1.125em;
    color #222128
    border-bottom 1px solid #F2F2F2
  }

  &__form-content {
    display flex
    flex-direction column
    gap 16px
    position relative

    .select {
      z-index 10
      position relative

      &__list {
        z-index 100
      }
    }
  }
}

.btn--notification {
  background linear-gradient(135deg, #667eea 0%, #764ba2 100%)
  color white
  border none

  &:hover {
    background linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%)
  }

  &:disabled {
    background #ccc
    cursor not-allowed
  }
}
</style>