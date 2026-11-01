<template>
  <main class="page--main app-settings-page">
    <div class="app-settings-page__header">
      <h2>{{ pageTitle }}</h2>
    </div>

    <div class="app-settings-page__content">
      <!-- Секция: Баннер при запуске -->
      <div class="app-settings-page__section">
        <h3 class="app-settings-page__section-title">Баннер при запуске приложения</h3>
        <p class="app-settings-page__section-desc">
          Показывается в мобильном приложении при запуске. Форматы: PNG, JPEG или WebP.
        </p>

        <div class="app-settings-page__banner-block">
          <!-- Превью -->
          <div class="app-settings-page__banner-preview">
            <img
              v-if="bannerUrl"
              :src="bannerUrl"
              alt="Баннер"
              class="app-settings-page__banner-img"
            />
            <div v-else class="app-settings-page__banner-placeholder">
              <IconComponent name="image-line" />
              <span>Баннер не загружен</span>
            </div>
          </div>

          <!-- Кнопки -->
          <div class="app-settings-page__banner-actions">
            <label class="btn btn--green app-settings-page__banner-upload-btn">
              <IconComponent name="upload-line" />
              <span>{{ bannerUrl ? "Заменить" : "Загрузить" }}</span>
              <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                @change="uploadBanner"
                ref="fileInput"
                style="display: none"
              />
            </label>
            <button
              v-if="bannerUrl"
              class="btn btn--red-outline"
              @click="removeBanner"
            >
              <IconComponent name="delete-bin-line" />
              <span>Удалить</span>
            </button>
          </div>
        </div>

        <div v-if="saving" class="app-settings-page__saving">Сохранение...</div>
      </div>

      <!-- Секция: Дополнительная информация в карточке товара -->
      <div class="app-settings-page__section">
        <h3 class="app-settings-page__section-title">
          Дополнительная информация в карточке товара
        </h3>
        <p class="app-settings-page__section-desc">
          Тексты пунктов блока «Дополнительная информация» в мобильном приложении.
          Пункт с пустым описанием в приложении не показывается.
        </p>

        <div class="app-settings-page__info-fields">
          <TextareaComponent
            v-for="field in infoFields"
            :key="field.key"
            :title="field.title"
            :placeholder="field.title"
            v-model="field.value"
            rows="6"
          />
        </div>

        <div class="app-settings-page__info-actions">
          <button
            class="btn btn--green"
            :disabled="infoSaving || !infoDirty"
            @click="saveInfoFields"
          >
            <span>{{ infoSaving ? "Сохранение..." : "Сохранить" }}</span>
          </button>
        </div>
      </div>

      <!-- Секция: Контакты приложения (MENDEV-15) -->
      <div class="app-settings-page__section">
        <h3 class="app-settings-page__section-title">Контакты приложения</h3>
        <p class="app-settings-page__section-desc">
          Телефоны, почты, соцсети и ссылки на мобильные приложения. Отдаются
          витриной в общем запросе приложения (блок contacts).
        </p>

        <div class="app-settings-page__contacts">
          <div class="app-settings-page__list-block">
            <span class="app-settings-page__list-title">Телефоны</span>
            <div v-for="(phone, i) in contacts.phones" :key="'ph' + i" class="app-settings-page__list-row">
              <InputComponent v-model="contacts.phones[i]" placeholder="+7 800 100-20-30" />
              <button class="btn btn--red-outline" @click="contacts.phones.splice(i, 1)">
                <IconComponent name="delete-bin-line" />
              </button>
            </div>
            <button class="btn btn--white btn--sm" @click="contacts.phones.push('')">
              <IconComponent name="add-line" />
              <span>Добавить телефон</span>
            </button>
          </div>

          <div class="app-settings-page__list-block">
            <span class="app-settings-page__list-title">Почты</span>
            <div v-for="(email, i) in contacts.emails" :key="'em' + i" class="app-settings-page__list-row">
              <InputComponent v-model="contacts.emails[i]" placeholder="sales@example.ru" />
              <button class="btn btn--red-outline" @click="contacts.emails.splice(i, 1)">
                <IconComponent name="delete-bin-line" />
              </button>
            </div>
            <button class="btn btn--white btn--sm" @click="contacts.emails.push('')">
              <IconComponent name="add-line" />
              <span>Добавить почту</span>
            </button>
          </div>

          <div class="app-settings-page__list-block">
            <span class="app-settings-page__list-title">Соцсети</span>
            <div v-for="(social, i) in contacts.socials" :key="'so' + i" class="app-settings-page__social-row">
              <InputComponent v-model="social.name" placeholder="Название (ВКонтакте)" />
              <InputComponent v-model="social.url" placeholder="Ссылка (https://vk.com/...)" />
              <InputComponent v-model="social.logo" placeholder="URL логотипа (опционально)" />
              <button class="btn btn--red-outline" @click="contacts.socials.splice(i, 1)">
                <IconComponent name="delete-bin-line" />
              </button>
            </div>
            <button class="btn btn--white btn--sm" @click="contacts.socials.push({ name: '', url: '', logo: '' })">
              <IconComponent name="add-line" />
              <span>Добавить соцсеть</span>
            </button>
          </div>

          <div class="app-settings-page__list-block">
            <span class="app-settings-page__list-title">Ссылки на мобильные приложения</span>
            <InputComponent v-model="contacts.appLinks.appStore" title="App Store" placeholder="https://apps.apple.com/app/..." />
            <InputComponent v-model="contacts.appLinks.googlePlay" title="Google Play" placeholder="https://play.google.com/store/apps/..." />
            <InputComponent v-model="contacts.appLinks.ruStore" title="RuStore" placeholder="https://www.rustore.ru/catalog/app/..." />
          </div>
        </div>

        <div class="app-settings-page__info-actions">
          <button
            class="btn btn--green"
            :disabled="contactsSaving || !contactsDirty"
            @click="saveContacts"
          >
            <span>{{ contactsSaving ? "Сохранение..." : "Сохранить контакты" }}</span>
          </button>
        </div>
      </div>

      <!-- Секция: Опции оформления заказа (MENDEV-17) -->
      <div class="app-settings-page__section">
        <h3 class="app-settings-page__section-title">Опции оформления заказа</h3>
        <p class="app-settings-page__section-desc">
          Чекбоксы на экране оформления в приложении (например «Охладить перед
          доставкой»). Выбранные опции сохраняются в заказе и видны сборщику.
          Код — латиницей, менять код существующей опции не стоит: он попадает
          в снапшоты уже созданных заказов.
        </p>

        <div class="app-settings-page__list-block">
          <div v-for="(option, i) in checkoutOptions" :key="'co' + i" class="app-settings-page__option-row">
            <InputComponent v-model="option.code" placeholder="chill_before_delivery" title="Код" />
            <InputComponent v-model="option.label" placeholder="Охладить перед доставкой" title="Название" />
            <CheckboxComponent v-model="option.enabled" green>Включена</CheckboxComponent>
            <button class="btn btn--red-outline" @click="checkoutOptions.splice(i, 1)">
              <IconComponent name="delete-bin-line" />
            </button>
          </div>
          <button
            class="btn btn--white btn--sm"
            @click="checkoutOptions.push({ code: '', label: '', enabled: true })"
          >
            <IconComponent name="add-line" />
            <span>Добавить опцию</span>
          </button>
        </div>

        <div class="app-settings-page__info-actions">
          <button
            class="btn btn--green"
            :disabled="checkoutSaving || !checkoutDirty"
            @click="saveCheckoutOptions"
          >
            <span>{{ checkoutSaving ? "Сохранение..." : "Сохранить опции" }}</span>
          </button>
        </div>
      </div>
    </div>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import ADMIN_SETTING from "@/graphql/queries/adminSetting.graphql";
import SAVE_ADMIN_SETTING from "@/graphql/mutations/SaveAdminSetting.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useMenuStore } from "@/stores/menu.js";

export default {
  name: "AppSettingsPage",
  inject: ["apollo"],
  components: { IconComponent, TextareaComponent, InputComponent, CheckboxComponent },
  data() {
    return {
      bannerValue: null,
      saving: false,
      infoFields: [
        {
          key: "product_info_delivery_payment",
          title: "Доставка и оплата",
          value: "",
          original: "",
        },
        {
          key: "product_info_return_policy",
          title: "О возврате",
          value: "",
          original: "",
        },
        {
          key: "product_info_defective_product",
          title: "Если привезли не тот товар или товар с браком",
          value: "",
          original: "",
        },
      ],
      infoSaving: false,
      // Контакты приложения (MENDEV-15)
      contacts: {
        phones: [],
        emails: [],
        socials: [],
        appLinks: { appStore: "", googlePlay: "", ruStore: "" },
      },
      contactsOriginal: "",
      contactsSaving: false,
      // Опции оформления заказа (MENDEV-17)
      checkoutOptions: [],
      checkoutOriginal: "",
      checkoutSaving: false,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Настройки приложения");
    },
    mediaEndpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
    bannerUrl() {
      if (!this.bannerValue) return null;
      return this.mediaEndpoint + this.bannerValue;
    },
    infoDirty() {
      return this.infoFields.some((f) => f.value !== f.original);
    },
    contactsDirty() {
      return JSON.stringify(this.contacts) !== this.contactsOriginal;
    },
    checkoutDirty() {
      return JSON.stringify(this.checkoutOptions) !== this.checkoutOriginal;
    },
  },
  mounted() {
    this.loadBanner();
    this.loadInfoFields();
    this.loadContacts();
    this.loadCheckoutOptions();
  },
  methods: {
    loadBanner() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "app_launch_banner" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.bannerValue = data.adminSetting?.value || null;
        })
        .catch(() => {});
    },
    uploadBanner(event) {
      const file = event.target.files[0];
      if (!file) return;

      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "app_launch_banner",
            type: "file",
            file: file,
          },
          context: {
            fetchOptions: {
              useUpload: true,
              onProgress: () => {},
              onAbortPossible: () => {},
            },
          },
        })
        .then(({ data }) => {
          this.bannerValue = data.saveAdminSetting.value;
        })
        .catch((err) => {
          console.error("Error uploading banner:", err);
        })
        .finally(() => {
          this.saving = false;
          if (this.$refs.fileInput) {
            this.$refs.fileInput.value = "";
          }
        });
    },
    loadInfoFields() {
      this.infoFields.forEach((field) => {
        this.apollo()
          .admin.query({
            query: ADMIN_SETTING,
            variables: { key: field.key },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            field.value = data.adminSetting?.value || "";
            field.original = field.value;
          })
          .catch(() => {});
      });
    },
    saveInfoFields() {
      const changed = this.infoFields.filter((f) => f.value !== f.original);
      if (!changed.length) return;

      this.infoSaving = true;
      Promise.all(
        changed.map((field) =>
          this.apollo()
            .admin.mutate({
              mutation: SAVE_ADMIN_SETTING,
              variables: {
                key: field.key,
                value: field.value.trim() ? field.value : null,
                type: "string",
              },
            })
            .then(({ data }) => {
              field.value = data.saveAdminSetting.value || "";
              field.original = field.value;
            })
        )
      )
        .catch((err) => {
          console.error("Error saving additional info:", err);
        })
        .finally(() => {
          this.infoSaving = false;
        });
    },
    loadJsonSetting(key) {
      return this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const raw = data.adminSetting?.value;
          if (!raw) return null;
          try {
            return JSON.parse(raw);
          } catch (e) {
            return null;
          }
        })
        .catch(() => null);
    },
    saveJsonSetting(key, value) {
      return this.apollo().admin.mutate({
        mutation: SAVE_ADMIN_SETTING,
        variables: { key, value: JSON.stringify(value), type: "json" },
      });
    },
    loadContacts() {
      Promise.all([
        this.loadJsonSetting("contact_phones"),
        this.loadJsonSetting("contact_emails"),
        this.loadJsonSetting("social_networks"),
        this.loadJsonSetting("app_links"),
      ]).then(([phones, emails, socials, appLinks]) => {
        this.contacts.phones = Array.isArray(phones) ? phones : [];
        this.contacts.emails = Array.isArray(emails) ? emails : [];
        this.contacts.socials = Array.isArray(socials)
          ? socials.map((s) => ({ name: s.name || "", url: s.url || "", logo: s.logo || "" }))
          : [];
        this.contacts.appLinks = {
          appStore: appLinks?.appStore || appLinks?.app_store || "",
          googlePlay: appLinks?.googlePlay || appLinks?.google_play || "",
          ruStore: appLinks?.ruStore || appLinks?.ru_store || "",
        };
        this.contactsOriginal = JSON.stringify(this.contacts);
      });
    },
    saveContacts() {
      this.contactsSaving = true;

      const phones = this.contacts.phones.map((p) => p.trim()).filter(Boolean);
      const emails = this.contacts.emails.map((e) => e.trim()).filter(Boolean);
      const socials = this.contacts.socials
        .filter((s) => s.url.trim())
        .map((s) => {
          const item = { name: s.name.trim(), url: s.url.trim() };
          if (s.logo.trim()) item.logo = s.logo.trim();
          return item;
        });
      const appLinks = {};
      if (this.contacts.appLinks.appStore.trim()) appLinks.appStore = this.contacts.appLinks.appStore.trim();
      if (this.contacts.appLinks.googlePlay.trim()) appLinks.googlePlay = this.contacts.appLinks.googlePlay.trim();
      if (this.contacts.appLinks.ruStore.trim()) appLinks.ruStore = this.contacts.appLinks.ruStore.trim();

      Promise.all([
        this.saveJsonSetting("contact_phones", phones),
        this.saveJsonSetting("contact_emails", emails),
        this.saveJsonSetting("social_networks", socials),
        this.saveJsonSetting("app_links", appLinks),
      ])
        .then(() => this.loadContacts())
        .catch((err) => {
          console.error("Error saving contacts:", err);
        })
        .finally(() => {
          this.contactsSaving = false;
        });
    },
    loadCheckoutOptions() {
      this.loadJsonSetting("checkout_options").then((options) => {
        // Настройки ещё нет — показываем дефолт витрины, чтобы редактирование
        // начиналось с фактически действующей опции, а не с пустого списка
        this.checkoutOptions = Array.isArray(options)
          ? options.map((o) => ({
              code: o.code || "",
              label: o.label || "",
              enabled: o.enabled !== false,
            }))
          : [{ code: "chill_before_delivery", label: "Охладить перед доставкой", enabled: true }];
        this.checkoutOriginal = JSON.stringify(this.checkoutOptions);
      });
    },
    saveCheckoutOptions() {
      this.checkoutSaving = true;

      const options = this.checkoutOptions
        .filter((o) => o.code.trim() && o.label.trim())
        .map((o) => ({
          code: o.code.trim(),
          label: o.label.trim(),
          enabled: !!o.enabled,
        }));

      this.saveJsonSetting("checkout_options", options)
        .then(() => this.loadCheckoutOptions())
        .catch((err) => {
          console.error("Error saving checkout options:", err);
        })
        .finally(() => {
          this.checkoutSaving = false;
        });
    },
    removeBanner() {
      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "app_launch_banner",
            value: null,
            type: "file",
          },
        })
        .then(() => {
          this.bannerValue = null;
        })
        .catch((err) => {
          console.error("Error removing banner:", err);
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.app-settings-page
  &__header
    margin-bottom 24px

  &__section
    background var(--white)
    border-radius 12px
    padding 24px
    margin-bottom 16px
    max-width 720px

  &__section-title
    font-size 1rem
    font-weight 600
    margin-bottom 4px

  &__section-desc
    color var(--dark-light)
    font-size 0.8125rem
    margin-bottom 16px

  &__banner-block
    display flex
    align-items flex-start
    gap 24px

  &__banner-preview
    width 220px
    height 320px
    border 2px dashed var(--gray-dark)
    border-radius 8px
    display flex
    align-items center
    justify-content center
    overflow hidden
    background var(--gray-3)

  &__banner-img
    max-width 100%
    max-height 100%
    object-fit contain

  &__banner-placeholder
    display flex
    flex-direction column
    align-items center
    gap 4px
    color var(--dark-light)
    font-size 0.75rem

    .icon
      width 32px
      height 32px

      svg path
        fill var(--gray-light)

  &__banner-actions
    display flex
    flex-direction column
    gap 8px

  &__banner-upload-btn
    display inline-flex
    align-items center
    gap 6px
    cursor pointer

  &__info-fields
    display flex
    flex-direction column
    gap 16px
    margin-bottom 16px

  &__info-actions
    display flex

  &__saving
    margin-top 12px
    color var(--dark-light)
    font-size 0.8125rem

  &__contacts
    display flex
    flex-direction column
    gap 24px
    margin-bottom 16px

  &__list-block
    display flex
    flex-direction column
    gap 8px
    margin-bottom 8px

  &__list-title
    font-size 0.875rem
    font-weight 600

  &__list-row
    display flex
    align-items center
    gap 8px

    .input
      flex 1

  &__social-row
    display grid
    grid-template-columns 1fr 1.5fr 1.5fr auto
    align-items center
    gap 8px

  &__option-row
    display grid
    grid-template-columns 1fr 1.5fr auto auto
    align-items end
    gap 12px
</style>
