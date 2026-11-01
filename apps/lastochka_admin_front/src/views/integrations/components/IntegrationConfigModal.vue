<template>
  <div class="integration-modal" @mousedown.self="$emit('close')">
    <div class="integration-modal__box">
      <div class="integration-modal__header">
        <h2>{{ modalTitle }}</h2>
        <button class="integration-modal__close" @click="$emit('close')">
          <IconComponent name="close-fill" />
        </button>
      </div>

      <div class="integration-modal__body">
        <!-- Выбор драйвера -->
        <div class="integration-modal__field">
          <label class="integration-modal__label">
            Драйвер
            <span>*</span>
          </label>
          <div class="integration-modal__select-wrap">
            <select v-model="selectedDriver" class="integration-modal__select" @change="onDriverChange">
              <option value="" disabled>Выберите драйвер</option>
              <option v-for="driver in descriptor.drivers" :key="driver.name" :value="driver.name">
                {{ driver.label }}
              </option>
            </select>
          </div>
        </div>

        <!-- Пояснение для OTP -->
        <div v-if="selectedDriver === 'otp'" class="integration-modal__hint">
          SMS не отправляются. OTP-код возвращается в ответе API. Подходит для разработки и тестирования,
          когда SMS-сервис не подключён.
        </div>

        <!-- Пояснение для мобильных приложений -->
        <div
          v-if="descriptor.type === 'courier_app' || descriptor.type === 'client_app'"
          class="integration-modal__hint"
        >
          Service Account JSON можно получить в Firebase Console → Project Settings → Service accounts →
          Generate new private key. Запросите файл у мобильного разработчика.
        </div>

        <!-- Ссылка на инструкцию для SmsAero -->
        <a
          v-if="selectedDriver === 'smsaero'"
          href="/public/guides/smsaero-setup.pdf"
          target="_blank"
          class="integration-modal__guide-link"
        >
          Инструкция по подключению SmsAero
        </a>

        <!-- Ссылка на инструкцию для 1С -->
        <a
          v-if="descriptor.type === '1c'"
          href="/public/guides/1c-setup-guide.pdf"
          target="_blank"
          class="integration-modal__guide-link"
        >
          Инструкция по подключению 1С
        </a>

        <!-- Ссылка на инструкцию для платёжной системы -->
        <a
          v-if="descriptor.type === 'payment'"
          href="/public/guides/yookassa-setup-guide.pdf"
          target="_blank"
          class="integration-modal__guide-link"
        >
          Инструкция по подключению ЮKassa
        </a>

        <!-- Webhook URL для платёжной системы -->
        <div v-if="descriptor.type === 'payment'" class="integration-modal__field">
          <label class="integration-modal__label">Webhook URL (для настройки в ЮKassa)</label>
          <div class="integration-modal__url-row">
            <input
              type="text"
              :value="webhookUrl"
              readonly
              class="integration-modal__url-input"
              ref="webhookUrlInput"
            />
            <button
              class="integration-modal__copy-btn"
              @click="copyWebhookUrl"
              :title="webhookUrlCopied ? 'Скопировано!' : 'Копировать'"
            >
              {{ webhookUrlCopied ? "✓" : "📋" }}
            </button>
          </div>
        </div>

        <!-- Динамические поля credentials -->
        <template v-if="currentSchema.length">
          <template v-for="field in currentSchema" :key="field.field">
            <!-- Select-поле -->
            <div v-if="field.type === 'select' && field.options" class="integration-modal__field">
              <label class="integration-modal__label">
                {{ field.label }}
                <span v-if="field.required">*</span>
              </label>
              <div class="integration-modal__select-wrap">
                <select
                  class="integration-modal__select"
                  :value="credentials[field.field] || ''"
                  @change="credentials[field.field] = $event.target.value"
                >
                  <option value="" disabled>{{ field.placeholder || "Выберите" }}</option>
                  <option
                    v-for="opt in field.options"
                    :key="opt.value || opt"
                    :value="opt.value || opt"
                    :disabled="isOptionDisabled(field, opt)"
                  >
                    {{ opt.label || opt }}{{ isOptionDisabled(field, opt) ? " (заполните креды)" : "" }}
                  </option>
                </select>
              </div>
            </div>
            <!-- Checkbox-поле -->
            <div v-else-if="field.type === 'checkbox'" class="integration-modal__checkbox">
              <CheckboxComponent
                :model-value="!!credentials[field.field]"
                @update:model-value="credentials[field.field] = $event"
                :green="true"
              >
                <span>{{ field.label }}</span>
              </CheckboxComponent>
            </div>
            <!-- File-поле (загрузка JSON) -->
            <div v-else-if="field.type === 'file'" class="integration-modal__field">
              <label class="integration-modal__label">
                {{ field.label }}
                <span v-if="field.required">*</span>
              </label>
              <div class="integration-modal__file-wrap">
                <input
                  type="file"
                  accept=".json"
                  class="integration-modal__file-input"
                  :ref="'file_' + field.field"
                  @change="onFileSelect($event, field.field)"
                />
                <button class="integration-modal__file-btn" @click="$refs['file_' + field.field][0].click()">
                  {{
                    fileNames[field.field] ||
                    (allCredentials[selectedDriver]
                      ? "Файл загружен. Нажмите для замены"
                      : field.placeholder || "Выбрать файл")
                  }}
                </button>
              </div>
            </div>
            <!-- Number-поле -->
            <InputComponent
              v-else-if="field.type === 'number'"
              :title="field.label"
              type="number"
              :placeholder="field.placeholder || ''"
              :required="field.required"
              :model-value="credentials[field.field] ?? ''"
              @update:model-value="credentials[field.field] = $event !== '' ? Number($event) : ''"
            />
            <!-- Text/Password-поле -->
            <InputComponent
              v-else
              :title="field.label"
              :type="field.type === 'password' ? 'password' : 'text'"
              :placeholder="
                field.type === 'password' && allCredentials[selectedDriver]
                  ? 'Сохранено. Оставьте пустым или введите новый'
                  : field.placeholder || ''
              "
              :required="field.required && !(field.type === 'password' && allCredentials[selectedDriver])"
              :model-value="credentials[field.field] || ''"
              @update:model-value="credentials[field.field] = $event"
            />
          </template>
        </template>

        <!-- Телефон для теста (только для SMS) -->
        <InputComponent
          v-if="isSmsType"
          title="Телефон для теста"
          type="text"
          placeholder="+79991234567"
          :required="false"
          :model-value="testPhone"
          @update:model-value="testPhone = $event"
        />

        <!-- Webhook CDEK (только для delivery_cdek) -->
        <div v-if="descriptor.type === 'delivery_cdek'" class="integration-modal__cdek-webhook">
          <div class="integration-modal__field">
            <label class="integration-modal__label">Webhook URL для CDEK</label>
            <div class="integration-modal__url-row">
              <input
                type="text"
                :value="cdekWebhookUrl"
                readonly
                class="integration-modal__url-input"
                ref="cdekWebhookUrlInput"
              />
              <button
                class="integration-modal__copy-btn"
                @click="copyCdekWebhookUrl"
                :title="cdekWebhookCopied ? 'Скопировано!' : 'Копировать'"
              >
                {{ cdekWebhookCopied ? "✓" : "📋" }}
              </button>
            </div>
          </div>
          <button
            class="btn btn--white btn--md integration-modal__cdek-webhook-btn"
            :disabled="webhookLoading"
            @click="registerCdekWebhook"
          >
            <LoadingIndicator v-if="webhookLoading" title="" />
            <span v-else>Зарегистрировать webhook в CDEK</span>
          </button>
          <div
            v-if="webhookResult"
            class="integration-modal__test-result"
            :class="
              webhookResult.success
                ? 'integration-modal__test-result--success'
                : 'integration-modal__test-result--error'
            "
          >
            {{ webhookResult.message }}
            <template v-if="webhookResult.success && webhookResult.url">
              <br />URL: {{ webhookResult.url }}
            </template>
          </div>
        </div>

        <!-- Активация (не для 1С) -->
        <div class="integration-modal__checkbox" v-if="!isOneCType">
          <CheckboxComponent :model-value="isActive" @update:model-value="isActive = $event" :green="true">
            <span>Активировать</span>
          </CheckboxComponent>
        </div>

        <!-- Результат теста (не для 1С) -->
        <div v-if="testResult && !isOneCType" class="integration-modal__test-result" :class="testResultClass">
          {{ testResult.message }}
        </div>

        <!-- Блок статуса обмена 1С -->
        <OneCExchangeStatusBlock v-if="isOneCType" :shopId="shop?.id || null" />

        <!-- Синхронизация категорий из 1С (только для 1С-интеграции) -->
        <div class="integration-modal__checkbox" v-if="isOneCType">
          <CheckboxComponent
            :model-value="syncCategoriesEnabled"
            @update:model-value="syncCategoriesEnabled = $event"
            :green="true"
          >
            <span>Синхронизировать категории из 1С</span>
          </CheckboxComponent>
        </div>

        <!-- Синхронизация изображений из 1С (только для 1С-интеграции) -->
        <div class="integration-modal__checkbox" v-if="isOneCType">
          <CheckboxComponent
            :model-value="syncImagesEnabled"
            @update:model-value="syncImagesEnabled = $event"
            :green="true"
          >
            <span>Синхронизировать изображения из 1С</span>
          </CheckboxComponent>
        </div>

        <!-- Удаление товаров, не попавших в полную выгрузку 1С (только для 1С-интеграции) -->
        <div class="integration-modal__checkbox" v-if="isOneCType">
          <CheckboxComponent
            :model-value="deactivateMissingEnabled"
            @update:model-value="deactivateMissingEnabled = $event"
            :green="true"
          >
            <span>Удалять товары, не попавшие в полную выгрузку 1С</span>
          </CheckboxComponent>
        </div>
        <div v-if="isOneCType && deactivateMissingEnabled" class="integration-modal__hint">
          После каждой полной выгрузки каталога товары, которых в ней не оказалось (например, с признаком
          «Не выгружать на сайт» в 1С), удаляются из курьерки и витрины. Вернутся сами, как только 1С снова
          пришлёт их. Работает, только если узел обмена 1С отправляет команду деактивации после полной выгрузки.
        </div>
      </div>

      <div class="integration-modal__footer">
        <button
          v-if="!isOneCType"
          class="btn btn--white btn--md"
          :disabled="!canTest || testing"
          @click="testConnection"
        >
          <LoadingIndicator v-if="testing" title="" />
          <span v-else>Проверить подключение</span>
        </button>

        <button class="btn btn--md" :disabled="!canSave || saving" @click="save">
          <LoadingIndicator v-if="saving" title="" />
          <span v-else>Сохранить</span>
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import OneCExchangeStatusBlock from "./OneCExchangeStatusBlock.vue";
import SAVE_INTEGRATION_CONFIG from "@/graphql/mutations/SaveIntegrationConfig.graphql";
import TEST_INTEGRATION_CONFIG from "@/graphql/mutations/TestIntegrationConfig.graphql";
import REGISTER_CDEK_WEBHOOK from "@/graphql/mutations/RegisterCdekWebhook.graphql";

export default {
  name: "IntegrationConfigModal",
  inject: ["apollo"],
  components: {
    IconComponent,
    InputComponent,
    CheckboxComponent,
    LoadingIndicator,
    OneCExchangeStatusBlock,
  },
  emits: ["save", "close"],
  props: {
    descriptor: {
      type: Object,
      required: true,
    },
    config: {
      type: Object,
      default: null,
    },
    shop: {
      type: Object,
      default: null,
    },
  },
  data() {
    // credentials теперь хранятся per driver: { smsaero: {...}, otp: {} }
    const allCredentials = this.config?.credentials ? JSON.parse(this.config.credentials) : {};

    // Достать credentials текущего драйвера
    const currentDriver = this.config?.driver || "";
    const driverCredentials = currentDriver ? { ...(allCredentials[currentDriver] || {}) } : {};

    // Очистить password-поля — они замаскированы
    if (currentDriver) {
      const driver = this.descriptor.drivers.find((d) => d.name === currentDriver);
      if (driver) {
        for (const field of driver.credentials_schema || []) {
          if (field.type === "password" || field.type === "file") {
            delete driverCredentials[field.field];
          }
        }
      }
    }

    return {
      selectedDriver: currentDriver,
      allCredentials, // все credentials всех драйверов (для переключения)
      credentials: driverCredentials,
      isActive: this.config?.is_active || false,
      syncCategoriesEnabled: this.config?.sync_categories_enabled || false,
      syncImagesEnabled: this.config?.sync_images_enabled || false,
      deactivateMissingEnabled: this.config?.deactivate_missing_enabled || false,
      testPhone: "",
      testing: false,
      saving: false,
      testResult: null,
      webhookUrlCopied: false,
      fileNames: {},
      webhookLoading: false,
      webhookResult: null,
      cdekWebhookCopied: false,
    };
  },
  computed: {
    modalTitle() {
      if (this.shop) return "1С: " + this.shop.title;
      return this.descriptor.label;
    },
    isOneCType() {
      return this.descriptor.type === "1c";
    },
    isPaymentType() {
      return this.descriptor.type === "payment";
    },
    isDeliveryType() {
      return this.descriptor.type === "delivery";
    },
    isSmsType() {
      return this.descriptor.type === "sms";
    },
    webhookUrl() {
      const storefrontUrl = import.meta.env.VITE_STOREFRONT_URL || "";
      return storefrontUrl ? `${storefrontUrl}/api/v1/webhooks/yookassa` : "/api/v1/webhooks/yookassa";
    },
    isCdekType() {
      return this.descriptor.type === "delivery_cdek";
    },
    cdekWebhookUrl() {
      const host = import.meta.env.VITE_HOST_ENDPOINT || window.location.origin;
      return `${host}/action/webhooks/cdek`;
    },
    currentDriverDescriptor() {
      if (!this.selectedDriver) return null;
      return this.descriptor.drivers.find((d) => d.name === this.selectedDriver);
    },
    currentSchema() {
      if (!this.currentDriverDescriptor) return [];
      return this.currentDriverDescriptor.credentials_schema || [];
    },
    canTest() {
      return this.selectedDriver && this.hasRequiredFields;
    },
    canSave() {
      return this.selectedDriver && this.hasRequiredFields;
    },
    hasRequiredFields() {
      return this.currentSchema
        .filter((f) => f.required)
        .every((f) => {
          // Password/file-поля не обязательны если credentials этого драйвера уже сохранены
          if ((f.type === "password" || f.type === "file") && this.allCredentials[this.selectedDriver])
            return true;
          const v = this.credentials[f.field];
          return v !== undefined && v !== null && String(v).trim() !== "";
        });
    },
    testResultClass() {
      if (!this.testResult) return "";
      return this.testResult.success
        ? "integration-modal__test-result--success"
        : "integration-modal__test-result--error";
    },
  },
  methods: {
    copyWebhookUrl() {
      navigator.clipboard.writeText(this.webhookUrl).then(() => {
        this.webhookUrlCopied = true;
        setTimeout(() => {
          this.webhookUrlCopied = false;
        }, 2000);
      });
    },
    copyCdekWebhookUrl() {
      navigator.clipboard.writeText(this.cdekWebhookUrl).then(() => {
        this.cdekWebhookCopied = true;
        setTimeout(() => {
          this.cdekWebhookCopied = false;
        }, 2000);
      });
    },
    registerCdekWebhook() {
      this.webhookLoading = true;
      this.webhookResult = null;

      this.apollo()
        .admin.mutate({
          mutation: REGISTER_CDEK_WEBHOOK,
          variables: {
            shop_id: this.shop?.id || null,
          },
        })
        .then(({ data }) => {
          this.webhookResult = data.registerCdekWebhook;
        })
        .catch((error) => {
          console.error("Register CDEK webhook error:", error);
          this.webhookResult = {
            success: false,
            message: error.message || "Ошибка регистрации webhook",
          };
        })
        .finally(() => {
          this.webhookLoading = false;
        });
    },
    onFileSelect(event, fieldName) {
      const file = event.target.files[0];
      if (!file) return;

      const reader = new FileReader();
      reader.onload = (e) => {
        this.credentials[fieldName] = e.target.result;
        this.fileNames[fieldName] = file.name;
      };
      reader.readAsText(file);
    },
    onDriverChange() {
      // Восстановить сохранённые text-поля для выбранного драйвера
      const saved = this.allCredentials[this.selectedDriver] || {};
      const driver = this.descriptor.drivers.find((d) => d.name === this.selectedDriver);
      const restored = {};
      if (driver) {
        for (const field of driver.credentials_schema || []) {
          if (field.type !== "password" && field.type !== "file" && saved[field.field]) {
            restored[field.field] = saved[field.field];
          }
          // password-поля не восстанавливаем — они замаскированы
        }
      }
      this.credentials = restored;
      this.testResult = null;
    },
    isOptionDisabled(field, opt) {
      // Блокировать "Боевой" режим если live-креды не заполнены
      if (field.field !== "mode") return false;
      const optValue = opt.value || opt;
      if (optValue !== "live") return false;

      const saved = this.allCredentials[this.selectedDriver] || {};
      const liveShopId = this.credentials["live_shop_id"] || saved["live_shop_id"] || "";
      const liveSecretKey = this.credentials["live_secret_key"] || saved["live_secret_key"] || "";

      return !liveShopId || !liveSecretKey;
    },
    testConnection() {
      this.testing = true;
      this.testResult = null;

      this.apollo()
        .admin.mutate({
          mutation: TEST_INTEGRATION_CONFIG,
          variables: {
            integration_type: this.descriptor.type,
            driver: this.selectedDriver,
            credentials: JSON.stringify(this.credentials),
            test_params: this.testPhone ? JSON.stringify({ phone: this.testPhone }) : null,
            shop_id: this.shop?.id || null,
          },
        })
        .then(({ data }) => {
          this.testResult = data.testIntegrationConfig;
        })
        .catch((error) => {
          console.error("Test integration error:", error);
          this.testResult = {
            success: false,
            message: error.message || "Ошибка проверки подключения",
          };
        })
        .finally(() => {
          this.testing = false;
        });
    },
    save() {
      this.saving = true;

      this.apollo()
        .admin.mutate({
          mutation: SAVE_INTEGRATION_CONFIG,
          variables: {
            integration_type: this.descriptor.type,
            driver: this.selectedDriver,
            credentials: JSON.stringify(this.credentials),
            is_active: this.isOneCType ? true : this.isActive,
            sync_categories_enabled: this.isOneCType ? this.syncCategoriesEnabled : null,
            sync_images_enabled: this.isOneCType ? this.syncImagesEnabled : null,
            deactivate_missing_enabled: this.isOneCType ? this.deactivateMissingEnabled : null,
            shop_id: this.shop?.id || null,
          },
        })
        .then(({ data }) => {
          this.$emit("save", data.saveIntegrationConfig);
        })
        .catch((error) => {
          console.error("Save integration error:", error);
          alert(error.message || "Ошибка сохранения настроек");
        })
        .finally(() => {
          this.saving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.integration-modal
  position fixed
  top 0
  left 0
  right 0
  bottom 0
  background rgba(0, 0, 0, 0.5)
  display flex
  align-items center
  justify-content center
  z-index 100
  padding 24px

  &__box
    background var(--white)
    border-radius 12px
    width 100%
    max-width 560px
    max-height 90vh
    overflow-y auto
    display flex
    flex-direction column

  &__header
    display flex
    justify-content space-between
    align-items center
    padding 24px 24px 0
    gap 16px

    h2
      font-size 1.25rem
      font-weight 600
      color var(--dark)
      margin 0

  &__close
    background none
    border none
    cursor pointer
    width 32px
    height 32px
    display flex
    align-items center
    justify-content center
    border-radius 8px
    flex-shrink 0
    transition background 0.2s

    &:hover
      background var(--gray-3)

    .icon
      width 20px
      height 20px

      svg path
        fill var(--dark)

  &__body
    display flex
    flex-direction column
    gap 16px
    padding 24px

  &__field
    display flex
    flex-direction column
    gap 5px

  &__label
    font-weight 400
    font-size 0.813rem
    line-height 18px
    color var(--dark)

    span
      color var(--red)

  &__select-wrap
    position relative

  &__select
    appearance none
    width 100%
    height 48px
    padding 0 40px 0 16px
    border 1px solid var(--border-color)
    border-radius 5px
    background var(--white)
    font-weight 500
    font-size 0.875rem
    line-height 20px
    color var(--dark)
    cursor pointer
    outline none
    transition border-color 0.2s

    &:focus
      border-color var(--dark)

    &::-ms-expand
      display none

  &__hint
    font-size 0.875rem
    line-height 1.4
    color var(--dark-light, #888)
    padding 12px 16px
    background var(--gray-3)
    border-radius 8px

  &__guide-link
    font-size 0.875rem
    color var(--green)
    text-decoration none
    display inline-flex
    align-items center
    gap 4px
    transition color 0.2s

    &:hover
      text-decoration underline

  &__url-row
    display flex
    gap 8px
    align-items center

  &__url-input
    flex 1
    height 40px
    padding 0 12px
    border 1px solid var(--border-color)
    border-radius 5px
    background var(--gray-3)
    font-family monospace
    font-size 0.813rem
    color var(--dark)
    outline none
    cursor text

  &__copy-btn
    background none
    border 1px solid var(--border-color)
    border-radius 5px
    width 40px
    height 40px
    cursor pointer
    display flex
    align-items center
    justify-content center
    font-size 1rem
    transition background 0.2s

    &:hover
      background var(--gray-3)

  &__file-wrap
    position relative

  &__file-input
    display none

  &__file-btn
    width 100%
    height 48px
    padding 0 16px
    border 1px dashed var(--border-color)
    border-radius 5px
    background var(--white)
    font-weight 500
    font-size 0.875rem
    line-height 20px
    color var(--dark-light)
    cursor pointer
    text-align left
    transition border-color 0.2s, background 0.2s

    &:hover
      border-color var(--dark)
      background var(--gray-3)

  &__checkbox
    padding-top 8px

  &__test-result
    padding 12px 16px
    border-radius 8px
    font-size 0.875rem
    line-height 1.4

    &--success
      background rgba(0, 180, 0, 0.08)
      color var(--green)
      border 1px solid var(--green)

    &--error
      background rgba(255, 0, 0, 0.05)
      color var(--red)
      border 1px solid var(--red)

  &__cdek-webhook
    display flex
    flex-direction column
    gap 12px

  &__cdek-webhook-btn
    align-self flex-start

  &__footer
    display flex
    justify-content flex-end
    gap 12px
    padding 0 24px 24px
    border-top 1px solid var(--gray-dark)
    padding-top 24px

    .btn
      min-width 180px
      display inline-flex
      align-items center
      justify-content center
      gap 8px

    .loading-indicator
      svg circle
        stroke var(--white)

    .btn--white .loading-indicator
      svg circle
        stroke var(--dark)
</style>
