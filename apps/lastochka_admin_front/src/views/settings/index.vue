<template>
  <main class="page--main settings-page">
    <div class="settings-page__header">
      <h2>{{ pageTitle }}</h2>
    </div>

    <!-- Табы -->
    <div class="settings-page__tabs">
      <button
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'general' }"
        @click="activeTab = 'general'"
      >
        Общие
      </button>
      <button
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'integrations' }"
        @click="goToIntegrations"
      >
        Интеграции
      </button>
      <button
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'menu' }"
        @click="activeTab = 'menu'"
      >
        Разделы меню
      </button>
      <button
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'checkboxes' }"
        @click="activeTab = 'checkboxes'"
      >
        Чекбоксы
      </button>
      <button
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'modules' }"
        @click="activeTab = 'modules'"
      >
        Модули
      </button>
      <div
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'fields' }"
        @click="activeTab = 'fields'"
      >
        Поля сущностей
      </div>
      <div
        class="settings-page__tab"
        :class="{ 'settings-page__tab--active': activeTab === 'columns' }"
        @click="activeTab = 'columns'"
      >
        Колонки таблиц
      </div>
    </div>

    <!-- Таб: Общие -->
    <div v-if="activeTab === 'general'" class="settings-page__content">
      <!-- Секция: Логотип -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Логотип админ-панели</h3>
        <p class="settings-page__section-desc">
          Отображается в боковом меню. Рекомендуемый формат: PNG или SVG, до 200×80px.
        </p>

        <div class="settings-page__logo-block">
          <!-- Превью -->
          <div class="settings-page__logo-preview">
            <img
              v-if="logoUrl"
              :src="logoUrl"
              alt="Логотип"
              class="settings-page__logo-img"
            />
            <div v-else class="settings-page__logo-placeholder">
              <IconComponent name="image-line" />
              <span>Логотип не загружен</span>
            </div>
          </div>

          <!-- Кнопки -->
          <div class="settings-page__logo-actions">
            <label class="btn btn--green settings-page__logo-upload-btn">
              <IconComponent name="upload-line" />
              <span>{{ logoUrl ? 'Заменить' : 'Загрузить' }}</span>
              <input
                type="file"
                accept="image/png,image/svg+xml,image/jpeg"
                @change="uploadLogo"
                ref="fileInput"
                style="display: none"
              />
            </label>
            <button
              v-if="logoUrl"
              class="btn btn--red-outline"
              @click="removeLogo"
            >
              <IconComponent name="delete-bin-line" />
              <span>Удалить</span>
            </button>
          </div>
        </div>

        <!-- Saving indicator -->
        <div v-if="saving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Название проекта -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Название проекта</h3>
        <p class="settings-page__section-desc">
          Отображается в заголовке вкладки браузера и на странице авторизации.
        </p>
        <div class="settings-page__title-block">
          <input
            type="text"
            v-model="projectTitle"
            placeholder="Название проекта"
            class="settings-page__title-input"
            @input="onTitleInput"
          />
        </div>
        <div v-if="titleSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Название сервиса в SMS -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Название сервиса в SMS</h3>
        <p class="settings-page__section-desc">
          Подставляется в текст SMS с кодом подтверждения и паролем сотрудника.
          Операторы связи блокируют сообщения с кодом, если в тексте нет названия
          сервиса, сайта или приложения.
        </p>
        <div class="settings-page__title-block">
          <input
            type="text"
            v-model="smsServiceName"
            placeholder="Например: Портал Турбо"
            class="settings-page__title-input"
            @input="onSmsServiceNameInput"
          />
        </div>
        <p class="settings-page__section-desc settings-page__sms-preview">
          Пример SMS: {{ smsPreview }}
        </p>
        <p
          v-if="smsPreview.length > 70"
          class="settings-page__section-desc settings-page__sms-warning"
        >
          Длина сообщения — {{ smsPreview.length }} символов. Дороже одной SMS:
          в кириллице один сегмент — 70 символов. Укоротите название.
        </p>
        <div v-if="smsServiceNameSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Основной цвет -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Основной цвет</h3>
        <p class="settings-page__section-desc">
          Применяется к кнопкам, ссылкам и акцентным элементам интерфейса.
        </p>

        <div class="settings-page__color-block">
          <input
            type="color"
            :value="primaryColor"
            @input="onColorPickerInput"
            class="settings-page__color-picker"
          />
          <div class="settings-page__color-hex-wrap">
            <span class="settings-page__color-hash">#</span>
            <input
              type="text"
              :value="primaryColorHex"
              @input="onHexInput"
              @blur="onHexBlur"
              maxlength="6"
              placeholder="FF4C30"
              class="settings-page__color-hex"
            />
          </div>
          <div
            class="settings-page__color-preview"
            :style="{ backgroundColor: primaryColor }"
          ></div>
          <button
            v-if="primaryColor !== defaultColor"
            class="settings-page__color-reset"
            @click="resetColor"
          >
            Сбросить
          </button>
        </div>

        <div v-if="colorSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Настройки отображения -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Настройки отображения</h3>
        <p class="settings-page__section-desc">
          Управление видимостью элементов на страницах админ-панели.
        </p>

        <div class="settings-page__display-row">
          <label class="settings-page__menu-toggle">
            <input type="checkbox" v-model="hideOrdersShop" @change="saveHideOrdersShop" />
            <span class="settings-page__menu-toggle-slider"></span>
          </label>
          <span>Скрыть магазин на странице заказов</span>
        </div>
        <div v-if="hideOrdersShopSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Сотрудники -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Сотрудники</h3>
        <p class="settings-page__section-desc">Требовать почту при создании и редактировании сотрудника.</p>
        <div class="settings-page__display-row">
          <label class="settings-page__menu-toggle">
            <input type="checkbox" v-model="staffEmailRequired" @change="saveStaffEmailRequired" />
            <span class="settings-page__menu-toggle-slider"></span>
          </label>
          <span>Почта обязательна для заполнения</span>
        </div>
        <div v-if="staffEmailRequiredSaving" class="settings-page__saving">Сохранение...</div>
      </div>

      <!-- Секция: Безопасность -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Безопасность</h3>
        <p class="settings-page__section-desc">
          Двухфакторная аутентификация (2FA) при входе в админ-панель. При включении все
          администраторы и магазины при следующем входе настроят 2FA через приложение-аутентификатор.
        </p>
        <div class="settings-page__display-row">
          <label class="settings-page__menu-toggle">
            <input type="checkbox" v-model="twoFactorEnabled" @change="saveTwoFactorEnabled" />
            <span class="settings-page__menu-toggle-slider"></span>
          </label>
          <span>Включить двухфакторную аутентификацию</span>
        </div>
        <div v-if="twoFactorSaving" class="settings-page__saving">Сохранение...</div>
      </div>

      <!-- Секция: Матрица доступов -->
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Роли и доступы</h3>
        <p class="settings-page__section-desc">
          Гибкое управление ролями и видимостью разделов для каждой роли. При включении появляется вкладка
          «Роли и доступы», а доступ к разделам начинает определяться матрицей (в меню и в API).
        </p>
        <div class="settings-page__display-row">
          <label class="settings-page__menu-toggle">
            <input type="checkbox" v-model="roleMatrixEnabled" @change="saveRoleMatrixEnabled" />
            <span class="settings-page__menu-toggle-slider"></span>
          </label>
          <span>Включить матрицу доступов по ролям</span>
        </div>
        <div v-if="roleMatrixSaving" class="settings-page__saving">Сохранение...</div>
      </div>

      <!-- Секция: Порог бесплатной доставки (маркетплейс) -->
      <div
        v-if="isMarketplaceMode"
        class="settings-page__section"
      >
        <h3 class="settings-page__section-title">
          Порог бесплатной доставки (маркетплейс), ₽
        </h3>
        <p class="settings-page__section-desc">
          Сумма заказа, при которой доставка становится бесплатной для всех магазинов.
          Используется в режиме «Скрытый маркетплейс».
        </p>
        <div class="settings-page__title-block">
          <input
            type="number"
            min="0"
            step="1"
            v-model="marketplaceFreeShippingThreshold"
            placeholder="0"
            class="settings-page__title-input"
            @input="onMarketplaceThresholdInput"
          />
        </div>
        <div v-if="marketplaceThresholdSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Срок ожидания доплаты (collector_payment) -->
      <div v-if="isCollectorPaymentEnabled" class="settings-page__section">
        <h3 class="settings-page__section-title">Срок ожидания доплаты, ч</h3>
        <p class="settings-page__section-desc">
          Сколько часов заказ ожидает доплату после неуспешного списания разницы.
          По истечении срока заказ автоматически отменяется, холд возвращается покупателю.
        </p>
        <div class="settings-page__title-block">
          <input
            type="number"
            min="1"
            step="1"
            v-model="surchargeWaitHours"
            placeholder="24"
            class="settings-page__title-input"
            @input="onSurchargeWaitHoursInput"
          />
        </div>
        <div v-if="surchargeWaitHoursSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>

      <!-- Секция: Срок ожидания оплаты (draft_orders_until_payment) -->
      <div v-if="isDraftOrdersUntilPaymentEnabled" class="settings-page__section">
        <h3 class="settings-page__section-title">Срок ожидания оплаты, мин</h3>
        <p class="settings-page__section-desc">
          Сколько минут заказ ждёт подтверждения оплаты картой онлайн. Всё это
          время товар удерживается в остатке. По истечении срока неоплаченный
          заказ закрывается, товар возвращается в остаток, корзина покупателя
          остаётся нетронутой.
        </p>
        <div class="settings-page__title-block">
          <input
            type="number"
            min="1"
            step="1"
            v-model="draftOrderTtlMinutes"
            placeholder="15"
            class="settings-page__title-input"
            @input="onDraftOrderTtlInput"
          />
        </div>
        <div v-if="draftOrderTtlSaving" class="settings-page__saving">
          Сохранение...
        </div>
      </div>
    </div>

    <!-- Таб: Разделы меню -->
    <div v-if="activeTab === 'menu'" class="settings-page__content">
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Разделы меню</h3>
        <p class="settings-page__section-desc">
          Управление видимостью, названием, порядком и группировкой пунктов бокового меню.
          Папки — организационные разделы: сами никуда не ведут, служат только для группировки пунктов.
        </p>

        <div class="settings-page__menu-folder-form">
          <input
            type="text"
            v-model="newFolderTitle"
            placeholder="Название папки"
            class="settings-page__menu-title-input settings-page__menu-folder-input"
          />
          <input
            type="text"
            v-model="newFolderIcon"
            placeholder="folder-line"
            class="settings-page__menu-title-input settings-page__menu-folder-input"
          />
          <IconComponent :name="newFolderIcon || 'folder-line'" />
          <button
            class="btn btn--md"
            :disabled="!newFolderTitle.trim() || creatingFolder"
            @click="createFolder"
          >
            {{ creatingFolder ? 'Создание...' : '+ Добавить папку' }}
          </button>
        </div>

        <div v-if="menuLoading" class="settings-page__saving">Загрузка...</div>

        <table v-else class="settings-page__menu-table">
          <thead>
            <tr>
              <th>Иконка</th>
              <th>Название</th>
              <th>Видимость</th>
              <th>Папка</th>
              <th>Порядок</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="item in displayMenuItems"
              :key="item.name"
              :class="{ 'settings-page__menu-row--child': item.parent_id, 'settings-page__menu-row--folder': item.is_folder }"
            >
              <td class="settings-page__menu-icon-cell">
                <IconComponent :name="item.icon" />
              </td>
              <td>
                <input
                  type="text"
                  class="settings-page__menu-title-input"
                  :value="item.title"
                  @blur="onTitleBlur(item, $event)"
                  @keydown.enter="$event.target.blur()"
                />
              </td>
              <td>
                <label v-if="!item.is_folder" class="settings-page__menu-toggle">
                  <input
                    type="checkbox"
                    :checked="item.is_visible"
                    @change="onVisibilityChange(item, $event)"
                  />
                  <span class="settings-page__menu-toggle-slider"></span>
                </label>
                <span v-else>—</span>
              </td>
              <td>
                <select
                  v-if="!item.is_folder"
                  class="settings-page__menu-parent-select"
                  :value="parentFolderName(item)"
                  @change="onParentChange(item, $event)"
                >
                  <option value="">— без папки —</option>
                  <option v-for="folder in folderOptions" :key="folder.id" :value="folder.name">
                    {{ folder.title }}
                  </option>
                </select>
                <span v-else>—</span>
              </td>
              <td class="settings-page__menu-order-cell">
                <button
                  class="settings-page__menu-arrow"
                  :disabled="isFirstSibling(item)"
                  @click="moveItem(item, -1)"
                >
                  <IconComponent name="arrow-up-s-line" />
                </button>
                <button
                  class="settings-page__menu-arrow"
                  :disabled="isLastSibling(item)"
                  @click="moveItem(item, 1)"
                >
                  <IconComponent name="arrow-down-s-line" />
                </button>
              </td>
              <td>
                <button
                  v-if="item.is_folder"
                  class="settings-page__menu-delete-btn"
                  @click="deleteFolder(item)"
                >
                  Удалить
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Таб: Чекбоксы -->
    <div v-if="activeTab === 'checkboxes'" class="settings-page__content">
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Настройка чекбоксов</h3>
        <p class="settings-page__section-desc">
          Управление видимостью и названиями чекбоксов в формах создания и редактирования.
        </p>

        <div v-if="checkboxesLoading" class="settings-page__saving">Загрузка...</div>

        <template v-else>
          <div
            v-for="(sectionTitle, sectionKey) in checkboxSections"
            :key="sectionKey"
            class="settings-page__checkbox-section"
          >
            <h4 class="settings-page__checkbox-section-title" @click="toggleSection(sectionKey)">
              <IconComponent :name="openSections[sectionKey] ? 'arrow-down-s-line' : 'arrow-right-s-line'" />
              {{ sectionTitle }}
            </h4>
            <div v-if="openSections[sectionKey]" class="settings-page__checkbox-list">
              <div
                v-for="item in checkboxesConfig[sectionKey]"
                :key="item.field"
                class="settings-page__checkbox-row"
              >
                <label class="settings-page__menu-toggle">
                  <input type="checkbox" v-model="item.enabled" />
                  <span class="settings-page__menu-toggle-slider"></span>
                </label>
                <input
                  type="text"
                  class="settings-page__checkbox-label-input"
                  v-model="item.label"
                  :placeholder="item.field"
                />
                <span class="settings-page__checkbox-field-name">{{ item.field }}</span>
                <label
                  v-if="item.custom && sectionKey === 'Product'"
                  class="settings-page__checkbox-filterable"
                >
                  <input type="checkbox" v-model="item.filterable" />
                  <span>Фильтр на витрине</span>
                </label>
                <button
                  v-if="item.custom"
                  class="settings-page__checkbox-remove"
                  title="Удалить чекбокс"
                  @click="removeCustomCheckbox(sectionKey, item.field)"
                >
                  ×
                </button>
              </div>

              <div class="settings-page__checkbox-add">
                <input
                  type="text"
                  class="settings-page__checkbox-label-input"
                  v-model="newCheckboxFor(sectionKey).label"
                  placeholder="Название нового чекбокса"
                  @input="syncNewCheckboxField(sectionKey)"
                />
                <input
                  type="text"
                  class="settings-page__checkbox-label-input"
                  v-model="newCheckboxFor(sectionKey).field"
                  placeholder="слаг (a-z, 0-9, _)"
                  @input="newCheckboxFor(sectionKey).fieldTouched = true"
                />
                <label v-if="sectionKey === 'Product'" class="settings-page__checkbox-filterable">
                  <input type="checkbox" v-model="newCheckboxFor(sectionKey).filterable" />
                  <span>Фильтр на витрине</span>
                </label>
                <button class="btn btn--md" @click="addCustomCheckbox(sectionKey)">Добавить чекбокс</button>
                <span v-if="newCheckboxErrors[sectionKey]" class="settings-page__checkbox-error">
                  {{ newCheckboxErrors[sectionKey] }}
                </span>
              </div>
            </div>
          </div>

          <div class="settings-page__checkbox-actions">
            <button
              class="btn btn--md"
              :disabled="checkboxesSaving"
              @click="saveCheckboxes"
            >
              {{ checkboxesSaving ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Таб: Модули -->
    <div v-if="activeTab === 'modules'" class="settings-page__content">
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Модули проекта</h3>
        <p class="settings-page__section-desc">
          Включение и отключение функциональных модулей системы.
        </p>

        <div v-if="modulesLoading" class="settings-page__saving">Загрузка...</div>

        <template v-else>
          <div
            v-for="item in modulesConfig"
            :key="item.key"
            class="settings-page__module-row"
          >
            <!-- Select-type модуль -->
            <template v-if="item.type === 'select'">
              <div class="settings-page__module-info">
                <div class="settings-page__module-label-static">{{ item.label }}</div>
                <select
                  class="settings-page__module-select"
                  v-model="item.value"
                >
                  <option
                    v-for="opt in item.options"
                    :key="opt.value"
                    :value="opt.value"
                  >
                    {{ opt.label }}
                  </option>
                </select>
                <div
                  v-if="getSelectedOptionDescription(item)"
                  class="settings-page__module-desc-static"
                >
                  {{ getSelectedOptionDescription(item) }}
                </div>
                <div
                  v-if="item.description"
                  class="settings-page__module-desc-static settings-page__module-desc-warn"
                >
                  {{ item.description }}
                </div>
              </div>
              <span class="settings-page__module-key">{{ item.key }}</span>
            </template>

            <!-- Boolean toggle модуль -->
            <template v-else>
              <label class="settings-page__menu-toggle">
                <input type="checkbox" v-model="item.enabled" />
                <span class="settings-page__menu-toggle-slider"></span>
              </label>
              <div class="settings-page__module-info">
                <input
                  type="text"
                  class="settings-page__module-label-input"
                  v-model="item.label"
                  :placeholder="item.key"
                />
                <input
                  type="text"
                  class="settings-page__module-desc-input"
                  v-model="item.description"
                  placeholder="Описание модуля"
                />
              </div>
              <span class="settings-page__module-key">{{ item.key }}</span>
            </template>
          </div>

          <div class="settings-page__checkbox-actions">
            <span v-if="modulesSaveErrors.length" class="settings-page__checkbox-error">
              {{ modulesSaveErrors.join(" ") }}
            </span>
            <button
              class="btn btn--md"
              :disabled="modulesSaving"
              @click="saveModules"
            >
              {{ modulesSaving ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Таб: Поля сущностей -->
    <div v-if="activeTab === 'fields'" class="settings-page__content">
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Видимость полей</h3>
        <p class="settings-page__section-desc">
          Управление видимостью полей в формах создания и редактирования сущностей.
        </p>

        <div v-if="fieldsLoading" class="settings-page__saving">Загрузка...</div>

        <template v-else>
          <div
            v-for="(sectionTitle, sectionKey) in fieldsSections"
            :key="sectionKey"
            class="settings-page__checkbox-section"
          >
            <h4 class="settings-page__checkbox-section-title" @click="toggleFieldsSection(sectionKey)">
              <IconComponent :name="openFieldsSections[sectionKey] ? 'arrow-down-s-line' : 'arrow-right-s-line'" />
              {{ sectionTitle }}
            </h4>
            <div v-if="openFieldsSections[sectionKey]" class="settings-page__checkbox-list">
              <div
                v-for="item in fieldsConfig[sectionKey]"
                :key="item.field"
                class="settings-page__checkbox-row"
              >
                <label class="settings-page__menu-toggle">
                  <input type="checkbox" v-model="item.enabled" />
                  <span class="settings-page__menu-toggle-slider"></span>
                </label>
                <input
                  type="text"
                  class="settings-page__checkbox-label-input"
                  v-model="item.label"
                  :placeholder="item.field"
                />
                <span class="settings-page__checkbox-field-name">{{ item.field }}</span>
              </div>
            </div>
          </div>

          <div class="settings-page__checkbox-actions">
            <button
              class="btn btn--md"
              :disabled="fieldsSaving"
              @click="saveFields"
            >
              {{ fieldsSaving ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
        </template>
      </div>
    </div>

    <!-- Таб: Колонки таблиц -->
    <div v-if="activeTab === 'columns'" class="settings-page__content">
      <div class="settings-page__section">
        <h3 class="settings-page__section-title">Колонки таблиц</h3>
        <p class="settings-page__section-desc">
          Какие колонки показывать в таблицах разделов и в каком порядке.
          Служебные колонки (выбор строк, индикатор, кнопки действий, сумма
          заказа) можно двигать, но не скрывать.
        </p>

        <div v-if="columnsLoading" class="settings-page__saving">Загрузка...</div>

        <template v-else>
          <div
            v-for="(sectionTitle, tableKey) in columnsSections"
            :key="tableKey"
            class="settings-page__checkbox-section"
          >
            <h4 class="settings-page__checkbox-section-title" @click="toggleColumnsSection(tableKey)">
              <IconComponent :name="openColumnsSections[tableKey] ? 'arrow-down-s-line' : 'arrow-right-s-line'" />
              {{ sectionTitle }}
            </h4>
            <table v-if="openColumnsSections[tableKey]" class="settings-page__menu-table">
              <thead>
                <tr>
                  <th>Колонка</th>
                  <th>Видимость</th>
                  <th>Порядок</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(item, index) in columnsConfig[tableKey]" :key="item.field">
                  <td>
                    {{ item.label }}
                    <span v-if="item.system" class="settings-page__columns-system">служебная</span>
                  </td>
                  <td>
                    <label class="settings-page__menu-toggle">
                      <input
                        type="checkbox"
                        v-model="item.enabled"
                        :disabled="item.system"
                      />
                      <span class="settings-page__menu-toggle-slider"></span>
                    </label>
                  </td>
                  <td class="settings-page__menu-order-cell">
                    <button
                      class="settings-page__menu-arrow"
                      :disabled="index === 0"
                      @click="moveColumn(tableKey, index, -1)"
                    >
                      <IconComponent name="arrow-up-s-line" />
                    </button>
                    <button
                      class="settings-page__menu-arrow"
                      :disabled="index === columnsConfig[tableKey].length - 1"
                      @click="moveColumn(tableKey, index, 1)"
                    >
                      <IconComponent name="arrow-down-s-line" />
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="settings-page__checkbox-actions">
            <button
              class="btn btn--md btn--gray"
              :disabled="columnsSaving"
              @click="resetColumns"
            >
              Сбросить к стандартным
            </button>
            <button
              class="btn btn--md"
              :disabled="columnsSaving"
              @click="saveColumns"
            >
              {{ columnsSaving ? 'Сохранение...' : 'Сохранить' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </main>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import ADMIN_SETTING from "@/graphql/queries/adminSetting.graphql";
import SAVE_ADMIN_SETTING from "@/graphql/mutations/SaveAdminSetting.graphql";
import { useDefaultStore } from "@/stores/default.js";
import MENU_ITEMS from "@/graphql/queries/menuItems.graphql";
import UPDATE_MENU_ITEM from "@/graphql/mutations/UpdateMenuItem.graphql";
import REORDER_MENU_ITEMS from "@/graphql/mutations/ReorderMenuItems.graphql";
import CREATE_MENU_FOLDER from "@/graphql/mutations/CreateMenuFolder.graphql";
import DELETE_MENU_FOLDER from "@/graphql/mutations/DeleteMenuFolder.graphql";
import ASSIGN_MENU_ITEM_TO_FOLDER from "@/graphql/mutations/AssignMenuItemToFolder.graphql";
import ENTITY_CHECKBOXES from "@/graphql/queries/entityCheckboxes.graphql";
import SAVE_ENTITY_CHECKBOXES from "@/graphql/mutations/SaveEntityCheckboxes.graphql";
import { useCheckboxConfigStore, CHECKBOX_DEFAULTS } from "@/stores/checkboxConfig.js";
import PROJECT_MODULES from "@/graphql/queries/projectModules.graphql";
import SAVE_PROJECT_MODULES from "@/graphql/mutations/SaveProjectModules.graphql";
import { useModuleConfigStore, MODULE_DEFAULTS } from "@/stores/moduleConfig.js";
import ENTITY_FIELDS from "@/graphql/queries/entityFields.graphql";
import SAVE_ENTITY_FIELDS from "@/graphql/mutations/SaveEntityFields.graphql";
import { useFieldVisibilityConfigStore, FIELD_DEFAULTS } from "@/stores/fieldVisibilityConfig.js";
import TABLE_COLUMNS from "@/graphql/queries/tableColumns.graphql";
import SAVE_TABLE_COLUMNS from "@/graphql/mutations/SaveTableColumns.graphql";
import {
  useTableColumnsConfigStore,
  TABLE_COLUMN_DEFAULTS,
  TABLE_TITLES,
} from "@/stores/tableColumnsConfig.js";
import { writeBootCache } from "@/utils/bootCache.js";
import { useMenuStore } from "@/stores/menu.js";
import { transliterate } from "@/utils.js";

export default {
  name: "SettingsPage",
  inject: ["apollo"],
  components: { IconComponent },
  data() {
    return {
      activeTab: this.$route.query.tab || "general",
      logoValue: null,
      saving: false,
      projectTitle: "",
      titleSaving: false,
      titleSaveTimeout: null,
      smsServiceName: "",
      smsServiceNameSaving: false,
      smsServiceNameSaveTimeout: null,
      primaryColor: "#FF4C30",
      defaultColor: "#FF4C30",
      colorSaving: false,
      colorSaveTimeout: null,
      menuItems: [],
      menuLoading: false,
      newFolderTitle: "",
      newFolderIcon: "",
      creatingFolder: false,
      checkboxesConfig: {
        Product: [],
        Category: [],
        MerchantProductGroup: [],
        Story: [],
        Brand: [],
        MerchantBanner: [],
        MerchantSale: [],
        Shop: [],
      },
      checkboxesLoading: false,
      checkboxesSaving: false,
      openSections: {
        Product: true,
        Category: false,
        MerchantProductGroup: false,
        Story: false,
        Brand: false,
        MerchantBanner: false,
        MerchantSale: false,
        Shop: false,
      },
      checkboxSections: {
        Product: "Товары",
        Category: "Категории",
        MerchantProductGroup: "Группы товаров магазина",
        Story: "Истории",
        Brand: "Бренды",
        MerchantBanner: "Баннеры магазина",
        MerchantSale: "Акции магазина",
        Shop: "Магазины",
      },
      // Черновики формы «Добавить чекбокс» — по ключу на каждую сущность,
      // инициализируются сразу (ленивое создание в рендере = мутация state во время рендера)
      newCheckbox: Object.fromEntries(
        [
          "Product",
          "Category",
          "MerchantProductGroup",
          "Story",
          "Brand",
          "MerchantBanner",
          "MerchantSale",
          "Shop",
        ].map((e) => [e, { label: "", field: "", filterable: false, fieldTouched: false }])
      ),
      newCheckboxErrors: {},
      hideOrdersShop: false,
      hideOrdersShopSaving: false,
      twoFactorEnabled: false,
      twoFactorSaving: false,
      staffEmailRequired: false,
      staffEmailRequiredSaving: false,
      modulesConfig: [],
      modulesLoading: false,
      modulesSaving: false,
      // Причина отказа сохранения модулей (например, price_tiers меняет только суперадминистратор)
      modulesSaveErrors: [],
      marketplaceFreeShippingThreshold: "",
      marketplaceThresholdSaving: false,
      marketplaceThresholdSaveTimeout: null,
      surchargeWaitHours: null,
      surchargeWaitHoursSaving: false,
      surchargeWaitHoursSaveTimeout: null,
      draftOrderTtlMinutes: null,
      draftOrderTtlSaving: false,
      draftOrderTtlSaveTimeout: null,
      fieldsConfig: { Shop: [], Category: [] },
      fieldsLoading: false,
      fieldsSaving: false,
      openFieldsSections: { Shop: true, Category: true },
      fieldsSections: { Shop: "Магазин", Category: "Категория" },
      columnsConfig: { products: [], orders: [] },
      columnsLoading: false,
      columnsSaving: false,
      openColumnsSections: { products: true, orders: true },
      columnsSections: TABLE_TITLES,
      roleMatrixEnabled: false,
      roleMatrixSaving: false,
    };
  },
  computed: {
    pageTitle() {
      return useMenuStore().titleFor(this.$route.name, "Настройки");
    },
    mediaEndpoint() {
      return useDefaultStore().MEDIA_ENDPOINT;
    },
    logoUrl() {
      if (!this.logoValue) return null;
      return this.mediaEndpoint + this.logoValue;
    },
    primaryColorHex() {
      return this.primaryColor.replace("#", "").toUpperCase();
    },
    smsPreview() {
      const service = (this.smsServiceName || "").trim();
      if (!service) return "Код 82342. Никому не сообщайте.";
      return `Код 82342 для входа в ${service}. Никому не сообщайте.`;
    },
    isMarketplaceMode() {
      const moduleStore = useModuleConfigStore();
      return moduleStore.getModuleValue("marketplace_mode") === "marketplace";
    },
    isCollectorPaymentEnabled() {
      return useModuleConfigStore().isModuleEnabled("collector_payment");
    },
    isDraftOrdersUntilPaymentEnabled() {
      return useModuleConfigStore().isModuleEnabled("draft_orders_until_payment");
    },
    displayMenuItems() {
      const roots = this.menuItems
        .filter((item) => !item.parent_id)
        .sort((a, b) => a.position - b.position);
      const result = [];
      roots.forEach((root) => {
        result.push(root);
        if (root.is_folder) {
          const children = this.menuItems
            .filter((item) => item.parent_id === root.id)
            .sort((a, b) => a.position - b.position);
          result.push(...children);
        }
      });
      return result;
    },
    folderOptions() {
      return this.menuItems.filter((item) => item.is_folder);
    },
  },
  watch: {
    activeTab(val) {
      if (val !== this.$route.query.tab) {
        this.$router.replace({ query: { tab: val } });
      }
      if (val === 'menu' && this.menuItems.length === 0) {
        this.loadMenuItems();
      }
      if (val === 'checkboxes' && this.checkboxesConfig.Product.length === 0) {
        this.loadCheckboxes();
      }
      if (val === 'modules' && this.modulesConfig.length === 0) {
        this.loadModules();
      }
      if (val === 'fields' && this.fieldsConfig.Shop.length === 0) {
        this.loadFields();
      }
      if (val === 'columns' && this.columnsConfig.products.length === 0) {
        this.loadColumns();
      }
    },
  },
  mounted() {
    this.loadLogo();
    this.loadTitle();
    this.loadSmsServiceName();
    this.loadColor();
    this.loadHideOrdersShop();
    this.loadTwoFactorEnabled();
    this.loadStaffEmailRequired();
    this.loadRoleMatrixEnabled();
    this.loadMarketplaceFreeShippingThreshold();
    this.loadSurchargeWaitHours();
    this.loadDraftOrderTtlMinutes();
    if (this.activeTab === 'menu') this.loadMenuItems();
    if (this.activeTab === 'checkboxes') this.loadCheckboxes();
    if (this.activeTab === 'modules') this.loadModules();
    if (this.activeTab === 'fields') this.loadFields();
    if (this.activeTab === 'columns') this.loadColumns();
  },
  beforeUnmount() {
    if (this.colorSaveTimeout) clearTimeout(this.colorSaveTimeout);
    if (this.titleSaveTimeout) clearTimeout(this.titleSaveTimeout);
    if (this.marketplaceThresholdSaveTimeout) {
      clearTimeout(this.marketplaceThresholdSaveTimeout);
    }
    if (this.surchargeWaitHoursSaveTimeout) {
      clearTimeout(this.surchargeWaitHoursSaveTimeout);
    }
    if (this.draftOrderTtlSaveTimeout) {
      clearTimeout(this.draftOrderTtlSaveTimeout);
    }
  },
  methods: {
    loadLogo() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "admin_logo" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting) {
            this.logoValue = data.adminSetting.value;
          }
        })
        .catch((err) => {
          console.error("Error loading admin logo setting:", err);
        });
    },
    loadTitle() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "admin_title" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting && data.adminSetting.value) {
            this.projectTitle = data.adminSetting.value;
          }
        })
        .catch(() => {});
    },
    onTitleInput() {
      if (this.titleSaveTimeout) clearTimeout(this.titleSaveTimeout);
      this.titleSaveTimeout = setTimeout(() => {
        this.saveTitle();
      }, 800);
    },
    loadSmsServiceName() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "sms_service_name" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting && data.adminSetting.value) {
            this.smsServiceName = data.adminSetting.value;
          }
        })
        .catch(() => {});
    },
    onSmsServiceNameInput() {
      if (this.smsServiceNameSaveTimeout) clearTimeout(this.smsServiceNameSaveTimeout);
      this.smsServiceNameSaveTimeout = setTimeout(() => {
        this.saveSmsServiceName();
      }, 800);
    },
    saveSmsServiceName() {
      this.smsServiceNameSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "sms_service_name",
            value: this.smsServiceName,
            type: "string",
          },
        })
        .catch((err) => {
          console.error("Error saving sms service name:", err);
        })
        .finally(() => {
          this.smsServiceNameSaving = false;
        });
    },
    saveTitle() {
      this.titleSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "admin_title",
            value: this.projectTitle,
            type: "string",
          },
        })
        .then(() => {
          document.title = this.projectTitle || "Admin";
        })
        .catch((err) => {
          console.error("Error saving title:", err);
        })
        .finally(() => {
          this.titleSaving = false;
        });
    },
    uploadLogo(event) {
      const file = event.target.files[0];
      if (!file) return;

      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "admin_logo",
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
          this.logoValue = data.saveAdminSetting.value;
          this.updateFavicon(this.mediaEndpoint + data.saveAdminSetting.value);
        })
        .catch((err) => {
          console.error("Error uploading logo:", err);
        })
        .finally(() => {
          this.saving = false;
          if (this.$refs.fileInput) {
            this.$refs.fileInput.value = "";
          }
        });
    },
    removeLogo() {
      this.saving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "admin_logo",
            value: null,
            type: "file",
          },
        })
        .then(() => {
          this.logoValue = null;
          this.updateFavicon("/public/assets/favicons/favicon.ico");
        })
        .catch((err) => {
          console.error("Error removing logo:", err);
        })
        .finally(() => {
          this.saving = false;
        });
    },
    loadColor() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "admin_primary_color" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting && data.adminSetting.value) {
            this.primaryColor = data.adminSetting.value;
          }
        })
        .catch(() => {});
    },
    loadHideOrdersShop() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "hide_orders_shop" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting) {
            this.hideOrdersShop = data.adminSetting.value === "1";
          }
        })
        .catch(() => {});
    },
    saveHideOrdersShop() {
      this.hideOrdersShopSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "hide_orders_shop",
            value: this.hideOrdersShop ? "1" : "0",
            type: "string",
          },
        })
        .catch((err) => {
          console.error("Error saving hide_orders_shop:", err);
        })
        .finally(() => {
          this.hideOrdersShopSaving = false;
        });
    },
    loadStaffEmailRequired() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "staff_email_required" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting) {
            this.staffEmailRequired = data.adminSetting.value === "1";
          }
        })
        .catch(() => {});
    },
    saveStaffEmailRequired() {
      this.staffEmailRequiredSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "staff_email_required",
            value: this.staffEmailRequired ? "1" : "0",
            type: "boolean",
          },
        })
        .catch((err) => {
          console.error("Error saving staff_email_required:", err);
        })
        .finally(() => {
          this.staffEmailRequiredSaving = false;
        });
    },
    loadTwoFactorEnabled() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "admin_2fa_enabled" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting) {
            this.twoFactorEnabled = data.adminSetting.value === "1";
          }
        })
        .catch(() => {});
    },
    saveTwoFactorEnabled() {
      this.twoFactorSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "admin_2fa_enabled",
            value: this.twoFactorEnabled ? "1" : "0",
            type: "boolean",
          },
        })
        .catch((err) => {
          console.error("Error saving admin_2fa_enabled:", err);
        })
        .finally(() => {
          this.twoFactorSaving = false;
        });
    },
    loadRoleMatrixEnabled() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "role_access_matrix_enabled" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting) {
            this.roleMatrixEnabled = data.adminSetting.value === "1";
          }
        })
        .catch(() => {});
    },
    saveRoleMatrixEnabled() {
      this.roleMatrixSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "role_access_matrix_enabled",
            value: this.roleMatrixEnabled ? "1" : "0",
            type: "boolean",
          },
        })
        .catch((err) => console.error("Error saving role_access_matrix_enabled:", err))
        .finally(() => {
          this.roleMatrixSaving = false;
        });
    },
    onColorPickerInput(e) {
      this.primaryColor = e.target.value;
      this.applyColor(this.primaryColor);
      this.debouncedSaveColor();
    },
    onHexInput(e) {
      let val = e.target.value.replace(/[^0-9a-fA-F]/g, "").slice(0, 6);
      if (val.length === 6) {
        this.primaryColor = "#" + val;
        this.applyColor(this.primaryColor);
        this.debouncedSaveColor();
      }
    },
    onHexBlur(e) {
      let val = e.target.value.replace(/[^0-9a-fA-F]/g, "").slice(0, 6);
      if (val.length === 6) {
        this.primaryColor = "#" + val;
        this.applyColor(this.primaryColor);
        this.saveColor(this.primaryColor);
      }
    },
    resetColor() {
      this.primaryColor = this.defaultColor;
      this.applyColor(this.defaultColor);
      this.saveColor(this.defaultColor);
    },
    applyColor(color) {
      const root = document.documentElement;
      root.style.setProperty("--main", color);
      const r = parseInt(color.slice(1, 3), 16);
      const g = parseInt(color.slice(3, 5), 16);
      const b = parseInt(color.slice(5, 7), 16);
      root.style.setProperty("--main-o9", `rgba(${r}, ${g}, ${b}, 0.9)`);
      root.style.setProperty("--main-o15", `rgba(${r}, ${g}, ${b}, 0.15)`);
      root.style.setProperty("--main-o05", `rgba(${r}, ${g}, ${b}, 0.05)`);
    },
    debouncedSaveColor() {
      if (this.colorSaveTimeout) clearTimeout(this.colorSaveTimeout);
      this.colorSaveTimeout = setTimeout(() => {
        this.saveColor(this.primaryColor);
      }, 500);
    },
    saveColor(color) {
      this.colorSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "admin_primary_color",
            value: color,
            type: "string",
          },
        })
        .then(() => {})
        .catch((err) => {
          console.error("Error saving color:", err);
        })
        .finally(() => {
          this.colorSaving = false;
        });
    },
    updateFavicon(url) {
      const link = document.querySelector("link[rel='icon']");
      if (link) {
        link.href = url;
      }
    },
    goToIntegrations() {
      this.$router.push({ name: "integrations" });
    },
    loadMenuItems() {
      this.menuLoading = true;
      this.apollo()
        .admin.query({
          query: MENU_ITEMS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const moduleStore = useModuleConfigStore();
          const moduleKeyMap = {
            "product-group-filters": "product_group_filters",
            "preorders": "preorders",
            "brands": "brands",
            "product-issue-reports": "product_issue_reports",
            "assembly-zones": "assembly_zones",
          };
          this.menuItems = data.menuItems
            .filter((item) => {
              const moduleKey = moduleKeyMap[item.name];
              if (!moduleKey) return true;
              return moduleStore.isModuleEnabled(moduleKey);
            })
            .map((item) => ({ ...item }));
        })
        .catch((err) => {
          console.error("Error loading menu items:", err);
        })
        .finally(() => {
          this.menuLoading = false;
        });
    },
    onTitleBlur(item, event) {
      const newTitle = event.target.value.trim();
      if (!newTitle || newTitle === item.title) return;
      item.title = newTitle;
      this.apollo()
        .admin.mutate({
          mutation: UPDATE_MENU_ITEM,
          variables: { name: item.name, title: newTitle },
        })
        .catch((err) => {
          console.error("Error updating menu item title:", err);
        });
    },
    onVisibilityChange(item, event) {
      const newValue = event.target.checked;
      item.is_visible = newValue;
      this.apollo()
        .admin.mutate({
          mutation: UPDATE_MENU_ITEM,
          variables: { name: item.name, is_visible: newValue },
        })
        .catch((err) => {
          console.error("Error updating menu item visibility:", err);
        });
    },
    siblingsOf(item) {
      return this.menuItems
        .filter((i) => i.parent_id === item.parent_id)
        .sort((a, b) => a.position - b.position);
    },
    isFirstSibling(item) {
      return this.siblingsOf(item)[0] === item;
    },
    isLastSibling(item) {
      const siblings = this.siblingsOf(item);
      return siblings[siblings.length - 1] === item;
    },
    moveItem(item, direction) {
      const siblings = this.siblingsOf(item);
      const index = siblings.indexOf(item);
      const newIndex = index + direction;
      if (newIndex < 0 || newIndex >= siblings.length) return;

      const temp = siblings[index];
      siblings[index] = siblings[newIndex];
      siblings[newIndex] = temp;

      siblings.forEach((sibling, i) => {
        sibling.position = i;
      });

      this.apollo()
        .admin.mutate({
          mutation: REORDER_MENU_ITEMS,
          variables: {
            items: [
              { name: siblings[index].name, position: siblings[index].position },
              { name: siblings[newIndex].name, position: siblings[newIndex].position },
            ],
          },
        })
        .catch((err) => {
          console.error("Error reordering menu items:", err);
        });
    },
    createFolder() {
      const title = this.newFolderTitle.trim();
      if (!title) return;
      this.creatingFolder = true;
      this.apollo()
        .admin.mutate({
          mutation: CREATE_MENU_FOLDER,
          variables: { title, icon: this.newFolderIcon.trim() || null },
        })
        .then(({ data }) => {
          this.menuItems.push(data.createMenuFolder);
          this.newFolderTitle = "";
          this.newFolderIcon = "";
        })
        .catch((err) => {
          console.error("Error creating menu folder:", err);
        })
        .finally(() => {
          this.creatingFolder = false;
        });
    },
    deleteFolder(folder) {
      if (!confirm(`Удалить папку «${folder.title}»? Пункты меню внутри неё вернутся на верхний уровень.`)) return;
      this.apollo()
        .admin.mutate({
          mutation: DELETE_MENU_FOLDER,
          variables: { name: folder.name },
        })
        .then(() => {
          this.loadMenuItems();
        })
        .catch((err) => {
          console.error("Error deleting menu folder:", err);
        });
    },
    parentFolderName(item) {
      const folder = this.menuItems.find(
        (i) => i.is_folder && String(i.id) === String(item.parent_id)
      );
      return folder ? folder.name : "";
    },
    onParentChange(item, event) {
      const parentName = event.target.value || null;
      this.apollo()
        .admin.mutate({
          mutation: ASSIGN_MENU_ITEM_TO_FOLDER,
          variables: { name: item.name, parentName },
        })
        .then(() => {
          this.loadMenuItems();
        })
        .catch((err) => {
          console.error("Error assigning menu item to folder:", err);
        });
    },
    toggleSection(section) {
      this.openSections[section] = !this.openSections[section];
    },
    newCheckboxFor(entity) {
      return this.newCheckbox[entity];
    },
    syncNewCheckboxField(entity) {
      const draft = this.newCheckboxFor(entity);
      if (!draft.fieldTouched) {
        draft.field = transliterate(draft.label);
      }
    },
    addCustomCheckbox(entity) {
      const draft = this.newCheckboxFor(entity);
      const label = draft.label.trim();
      const field = draft.field.trim();

      if (!label) {
        this.newCheckboxErrors[entity] = "Введите название";
        return;
      }
      if (!/^[a-z][a-z0-9_]{1,49}$/.test(field)) {
        this.newCheckboxErrors[entity] = "Слаг: латиница в нижнем регистре, цифры и _, от 2 до 50 символов";
        return;
      }
      if ((this.checkboxesConfig[entity] || []).some((c) => c.field === field)) {
        this.newCheckboxErrors[entity] = `Слаг «${field}» уже занят`;
        return;
      }

      const item = { field, label, enabled: true, custom: true };
      if (entity === "Product") {
        item.filterable = Boolean(draft.filterable);
      }
      this.checkboxesConfig[entity].push(item);
      this.newCheckbox[entity] = { label: "", field: "", filterable: false, fieldTouched: false };
      this.newCheckboxErrors[entity] = "";
    },
    removeCustomCheckbox(entity, field) {
      if (!confirm(`Удалить чекбокс «${field}»? Проставленные значения перестанут отображаться.`)) {
        return;
      }
      this.checkboxesConfig[entity] = this.checkboxesConfig[entity].filter((c) => c.field !== field);
    },
    loadCheckboxes() {
      this.checkboxesLoading = true;
      this.apollo()
        .admin.query({
          query: ENTITY_CHECKBOXES,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const parsed = JSON.parse(data.entityCheckboxes);
          const result = {};
          for (const entity of Object.keys(this.checkboxSections)) {
            const defaults = CHECKBOX_DEFAULTS[entity] || {};
            const existing = parsed[entity] || [];
            const allowedFields = Object.keys(defaults);
            // Builtin-поля фильтруются по дефолтам, custom-чекбоксы сохраняются как есть
            const filtered = existing.filter((c) => c.custom === true || allowedFields.includes(c.field));
            const existingFields = filtered.map((c) => c.field);
            for (const [field, label] of Object.entries(defaults)) {
              if (!existingFields.includes(field)) {
                filtered.push({ field, label, enabled: true });
              }
            }
            result[entity] = filtered;
          }
          this.checkboxesConfig = result;
        })
        .catch((err) => {
          console.error("Error loading checkboxes config:", err);
          for (const entity of Object.keys(this.checkboxSections)) {
            const defaults = CHECKBOX_DEFAULTS[entity] || {};
            this.checkboxesConfig[entity] = Object.entries(defaults).map(
              ([field, label]) => ({ field, label, enabled: true })
            );
          }
        })
        .finally(() => {
          this.checkboxesLoading = false;
        });
    },
    saveCheckboxes() {
      this.checkboxesSaving = true;
      const config = JSON.stringify(this.checkboxesConfig);
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ENTITY_CHECKBOXES,
          variables: { config },
        })
        .then(() => {
          const checkboxStore = useCheckboxConfigStore();
          checkboxStore.setConfig(config);
        })
        .catch((err) => {
          console.error("Error saving checkboxes config:", err);
        })
        .finally(() => {
          this.checkboxesSaving = false;
        });
    },
    toggleColumnsSection(table) {
      this.openColumnsSections[table] = !this.openColumnsSections[table];
    },
    /** Копия дефолтов — конфиг правится по месту, дефолты должны остаться целыми. */
    defaultColumnsConfig() {
      return Object.fromEntries(
        Object.entries(TABLE_COLUMN_DEFAULTS).map(([table, columns]) => [
          table,
          columns.map((c) => ({ ...c })),
        ])
      );
    },
    loadColumns() {
      this.columnsLoading = true;
      this.apollo()
        .admin.query({
          query: TABLE_COLUMNS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const parsed = JSON.parse(data.tableColumns);
          const result = {};
          for (const [table, defaults] of Object.entries(TABLE_COLUMN_DEFAULTS)) {
            const rows = Array.isArray(parsed[table]) && parsed[table].length
              ? parsed[table]
              : defaults;
            result[table] = rows.map((c) => ({ ...c }));
          }
          this.columnsConfig = result;
        })
        .catch((err) => {
          console.error("Error loading table columns config:", err);
          this.columnsConfig = this.defaultColumnsConfig();
        })
        .finally(() => {
          this.columnsLoading = false;
        });
    },
    moveColumn(table, index, direction) {
      const list = this.columnsConfig[table];
      const target = index + direction;
      if (!list || target < 0 || target >= list.length) return;
      const [moved] = list.splice(index, 1);
      list.splice(target, 0, moved);
    },
    resetColumns() {
      this.columnsConfig = this.defaultColumnsConfig();
    },
    saveColumns() {
      this.columnsSaving = true;
      const config = JSON.stringify(this.columnsConfig);
      this.apollo()
        .admin.mutate({
          mutation: SAVE_TABLE_COLUMNS,
          variables: { config },
        })
        .then(() => {
          // Store — чтобы таблицы перерисовались без перезагрузки, boot-кэш —
          // чтобы после F5 не мигнул предыдущий состав колонок из localStorage.
          useTableColumnsConfigStore().setConfig(config);
          writeBootCache("tableColumns", config);
        })
        .catch((err) => {
          console.error("Error saving table columns config:", err);
        })
        .finally(() => {
          this.columnsSaving = false;
        });
    },
    toggleFieldsSection(section) {
      this.openFieldsSections[section] = !this.openFieldsSections[section];
    },
    loadFields() {
      this.fieldsLoading = true;
      this.apollo()
        .admin.query({
          query: ENTITY_FIELDS,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const parsed = JSON.parse(data.entityFields);
          const result = {};
          for (const [entity, defaults] of Object.entries(FIELD_DEFAULTS)) {
            const existing = parsed[entity] || [];
            const allowedFields = Object.keys(defaults);
            const filtered = existing.filter((c) => allowedFields.includes(c.field));
            const existingFields = filtered.map((c) => c.field);
            for (const [field, label] of Object.entries(defaults)) {
              if (!existingFields.includes(field)) {
                filtered.push({ field, label, enabled: true });
              }
            }
            result[entity] = filtered;
          }
          this.fieldsConfig = result;
        })
        .catch((err) => {
          console.error("Error loading entity fields config:", err);
          for (const [entity, defaults] of Object.entries(FIELD_DEFAULTS)) {
            this.fieldsConfig[entity] = Object.entries(defaults).map(
              ([field, label]) => ({ field, label, enabled: true })
            );
          }
        })
        .finally(() => {
          this.fieldsLoading = false;
        });
    },
    saveFields() {
      this.fieldsSaving = true;
      const config = JSON.stringify(this.fieldsConfig);
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ENTITY_FIELDS,
          variables: { config },
        })
        .then(() => {
          const fieldsStore = useFieldVisibilityConfigStore();
          fieldsStore.setConfig(config);
        })
        .catch((err) => {
          console.error("Error saving entity fields config:", err);
        })
        .finally(() => {
          this.fieldsSaving = false;
        });
    },
    loadModules() {
      this.modulesLoading = true;
      this.apollo()
        .admin.query({
          query: PROJECT_MODULES,
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const parsed = JSON.parse(data.projectModules);
          const result = [];
          const allKeys = new Set([
            ...Object.keys(MODULE_DEFAULTS),
            ...Object.keys(parsed),
          ]);
          for (const key of allKeys) {
            const saved = parsed[key];
            const defaults = MODULE_DEFAULTS[key];
            const type = saved?.type || defaults?.type;
            if (type === "select") {
              const options =
                (Array.isArray(saved?.options) && saved.options.length
                  ? saved.options
                  : defaults?.options) || [];
              result.push({
                key,
                label: saved?.label || defaults?.label || key,
                type: "select",
                value:
                  saved?.value ||
                  defaults?.value ||
                  (options[0]?.value ?? null),
                options,
                description: saved?.description || defaults?.description || "",
              });
            } else {
              result.push({
                key,
                label: saved?.label || defaults?.label || key,
                enabled: saved?.enabled ?? defaults?.enabled ?? false,
                description: saved?.description || defaults?.description || "",
              });
            }
          }
          this.modulesConfig = result;
        })
        .catch((err) => {
          console.error("Error loading modules config:", err);
          this.modulesConfig = Object.entries(MODULE_DEFAULTS).map(
            ([key, val]) => ({ key, ...val })
          );
        })
        .finally(() => {
          this.modulesLoading = false;
        });
    },
    saveModules() {
      this.modulesSaving = true;
      this.modulesSaveErrors = [];
      const configObj = {};
      for (const item of this.modulesConfig) {
        if (item.type === "select") {
          configObj[item.key] = {
            label: item.label,
            type: "select",
            value: item.value,
            options: item.options,
            description: item.description,
          };
        } else {
          configObj[item.key] = {
            label: item.label,
            enabled: item.enabled,
            description: item.description,
          };
        }
      }
      const config = JSON.stringify(configObj);
      this.apollo()
        .admin.mutate({
          mutation: SAVE_PROJECT_MODULES,
          variables: { config },
        })
        .then(() => {
          const moduleStore = useModuleConfigStore();
          moduleStore.setConfig(config);
        })
        .catch((err) => {
          console.error("Error saving modules config:", err);
          // Отказ сервера (MID-308: флаг price_tiers меняет только суперадминистратор) раньше
          // уходил только в консоль. Показываем причину и перечитываем модули с сервера, чтобы
          // переключатели отражали то, что реально сохранено.
          const messages = (err?.graphQLErrors ?? []).flatMap((e) =>
            e.extensions?.category === "validation"
              ? Object.values(e.extensions.validation).flat()
              : [e.message]
          );
          this.modulesSaveErrors = messages.length ? messages : ["Не удалось сохранить модули"];
          this.loadModules();
        })
        .finally(() => {
          this.modulesSaving = false;
        });
    },
    getSelectedOptionDescription(item) {
      if (!item || item.type !== "select" || !Array.isArray(item.options)) {
        return "";
      }
      const selected = item.options.find((opt) => opt.value === item.value);
      return selected?.description || "";
    },
    loadMarketplaceFreeShippingThreshold() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "marketplace_free_shipping_threshold" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting && data.adminSetting.value) {
            this.marketplaceFreeShippingThreshold = data.adminSetting.value;
          }
        })
        .catch(() => {});
    },
    onMarketplaceThresholdInput() {
      if (this.marketplaceThresholdSaveTimeout) {
        clearTimeout(this.marketplaceThresholdSaveTimeout);
      }
      this.marketplaceThresholdSaveTimeout = setTimeout(() => {
        this.saveMarketplaceFreeShippingThreshold();
      }, 800);
    },
    saveMarketplaceFreeShippingThreshold() {
      this.marketplaceThresholdSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "marketplace_free_shipping_threshold",
            value: String(this.marketplaceFreeShippingThreshold ?? ""),
            type: "string",
          },
        })
        .catch((err) => {
          console.error(
            "Error saving marketplace_free_shipping_threshold:",
            err
          );
        })
        .finally(() => {
          this.marketplaceThresholdSaving = false;
        });
    },
    loadSurchargeWaitHours() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "surcharge_wait_hours" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting && data.adminSetting.value) {
            this.surchargeWaitHours = data.adminSetting.value;
          }
        })
        .catch(() => {});
    },
    onSurchargeWaitHoursInput() {
      if (this.surchargeWaitHoursSaveTimeout) clearTimeout(this.surchargeWaitHoursSaveTimeout);
      this.surchargeWaitHoursSaveTimeout = setTimeout(() => this.saveSurchargeWaitHours(), 800);
    },
    saveSurchargeWaitHours() {
      this.surchargeWaitHoursSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "surcharge_wait_hours",
            value: String(this.surchargeWaitHours ?? ""),
            type: "string",
          },
        })
        .catch((e) => console.error(e))
        .finally(() => {
          this.surchargeWaitHoursSaving = false;
        });
    },
    loadDraftOrderTtlMinutes() {
      this.apollo()
        .admin.query({
          query: ADMIN_SETTING,
          variables: { key: "draft_order_ttl_minutes" },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.adminSetting && data.adminSetting.value) {
            this.draftOrderTtlMinutes = data.adminSetting.value;
          }
        })
        .catch(() => {});
    },
    onDraftOrderTtlInput() {
      if (this.draftOrderTtlSaveTimeout) clearTimeout(this.draftOrderTtlSaveTimeout);
      this.draftOrderTtlSaveTimeout = setTimeout(() => this.saveDraftOrderTtlMinutes(), 800);
    },
    saveDraftOrderTtlMinutes() {
      this.draftOrderTtlSaving = true;
      this.apollo()
        .admin.mutate({
          mutation: SAVE_ADMIN_SETTING,
          variables: {
            key: "draft_order_ttl_minutes",
            value: String(this.draftOrderTtlMinutes ?? ""),
            type: "string",
          },
        })
        .catch((e) => console.error(e))
        .finally(() => {
          this.draftOrderTtlSaving = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.settings-page
  &__header
    display flex
    justify-content space-between
    align-items center
    margin-bottom 24px

    h2
      font-size 1.5rem
      font-weight 600
      color var(--dark)

  &__tabs
    display flex
    gap 0
    border-bottom 2px solid var(--gray-dark)
    margin-bottom 24px

  &__tab
    padding 12px 24px
    font-size 0.875rem
    font-weight 500
    color var(--dark-light)
    background none
    border none
    border-bottom 2px solid transparent
    margin-bottom -2px
    cursor pointer
    transition color 0.2s, border-color 0.2s

    &:hover
      color var(--dark)

    &--active
      color var(--green)
      border-bottom-color var(--green)

  &__content
    background var(--white)
    border-radius 12px
    border 1px solid var(--gray-dark)
    padding 32px

  &__section
    margin-bottom 32px

    &:last-child
      margin-bottom 0

  &__section-title
    font-size 1.125rem
    font-weight 600
    color var(--dark)
    margin-bottom 8px

  &__section-desc
    font-size 0.875rem
    color var(--dark-light)
    margin-bottom 20px

  &__title-block
    display flex
    align-items center
    gap 12px

  &__sms-preview
    margin-top 12px
    margin-bottom 0

  &__sms-warning
    margin-top 8px
    margin-bottom 0
    color var(--red)

  &__title-input
    padding 10px 14px
    border 1px solid var(--gray-dark)
    border-radius 8px
    font-size 0.9375rem
    width 300px
    outline none
    transition border-color 0.2s
    &:focus
      border-color var(--primary)

  &__logo-block
    display flex
    align-items flex-start
    gap 24px

  &__logo-preview
    width 200px
    height 80px
    border 2px dashed var(--gray-dark)
    border-radius 8px
    display flex
    align-items center
    justify-content center
    overflow hidden
    background var(--gray-3)

  &__logo-img
    max-width 100%
    max-height 100%
    object-fit contain

  &__logo-placeholder
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

  &__logo-actions
    display flex
    flex-direction column
    gap 8px

  &__logo-upload-btn
    display inline-flex
    align-items center
    gap 6px
    cursor pointer

    .icon
      width 16px
      height 16px

  &__saving
    margin-top 12px
    font-size 0.875rem
    color var(--dark-light)

  &__color-block
    display flex
    align-items center
    gap 16px

  &__color-picker
    width 48px
    height 48px
    border none
    border-radius 8px
    cursor pointer
    padding 0
    background none

    &::-webkit-color-swatch-wrapper
      padding 0

    &::-webkit-color-swatch
      border-radius 8px
      border 2px solid var(--gray-dark)

    &::-moz-color-swatch
      border-radius 8px
      border 2px solid var(--gray-dark)

  &__color-hex-wrap
    display flex
    align-items center
    border 1px solid var(--gray-dark)
    border-radius 8px
    overflow hidden
    height 48px

  &__color-hash
    padding 0 0 0 12px
    font-size 0.875rem
    color var(--dark-light)
    font-family monospace
    user-select none

  &__color-hex
    width 80px
    height 100%
    border none
    outline none
    font-size 0.875rem
    font-family monospace
    padding 0 12px 0 4px
    text-transform uppercase
    color var(--dark)
    background transparent

  &__color-preview
    width 48px
    height 48px
    border-radius 8px
    border 2px solid var(--gray-dark)
    flex-shrink 0

  &__color-reset
    padding 8px 16px
    border 1px solid var(--gray-dark)
    border-radius 8px
    background var(--white)
    color var(--dark-light)
    font-size 0.813rem
    cursor pointer
    transition background 0.2s

    &:hover
      background var(--gray-3)

  &__menu-table
    width 100%
    border-collapse collapse

    th
      text-align left
      font-size 0.75rem
      font-weight 500
      color var(--dark-light)
      padding 8px 12px
      border-bottom 1px solid var(--gray-dark)

    td
      padding 8px 12px
      border-bottom 1px solid var(--gray-3)
      vertical-align middle

  &__menu-icon-cell
    width 48px

    .icon
      width 20px
      height 20px

      svg path
        fill var(--dark-light)

  &__menu-title-input
    width 100%
    border 1px solid transparent
    border-radius 6px
    padding 6px 8px
    font-size 0.875rem
    color var(--dark)
    background transparent
    transition border-color 0.2s

    &:hover
      border-color var(--gray-dark)

    &:focus
      outline none
      border-color var(--green)
      background var(--white)

  &__menu-toggle
    position relative
    display inline-block
    width 40px
    height 22px
    cursor pointer

    input
      opacity 0
      width 0
      height 0

  &__menu-toggle-slider
    position absolute
    top 0
    left 0
    right 0
    bottom 0
    background var(--gray-dark)
    border-radius 22px
    transition background 0.2s

    &:before
      content ''
      position absolute
      width 16px
      height 16px
      left 3px
      bottom 3px
      background var(--white)
      border-radius 50%
      transition transform 0.2s

    input:checked + &
      background var(--green)

    input:checked + &:before
      transform translateX(18px)

    // Служебные колонки скрыть нельзя — тумблер выключен и должен читаться
    // как неактивный, иначе выглядит как обычный переключатель, который «не работает».
    input:disabled + &
      opacity 0.45
      cursor not-allowed

  &__menu-order-cell
    width 80px
    white-space nowrap

  &__menu-arrow
    background none
    border 1px solid var(--gray-dark)
    border-radius 6px
    width 32px
    height 32px
    display inline-flex
    align-items center
    justify-content center
    cursor pointer
    margin-right 4px
    transition background 0.2s

    .icon
      width 16px
      height 16px

      svg path
        fill var(--dark)

    &:hover:not(:disabled)
      background var(--gray-3)

    &:disabled
      opacity 0.3
      cursor not-allowed

  &__menu-folder-form
    display flex
    align-items center
    gap 8px
    margin-bottom 16px

    .icon
      width 20px
      height 20px

      svg path
        fill var(--dark-light)

  &__menu-folder-input
    width 200px

  &__menu-parent-select
    width 100%
    border 1px solid var(--gray-dark)
    border-radius 6px
    padding 6px 8px
    font-size 0.875rem
    color var(--dark)
    background var(--white)

  &__menu-row--child
    .settings-page__menu-icon-cell
      padding-left 32px

  &__menu-row--folder
    background var(--gray-3)

  &__menu-delete-btn
    background none
    border 1px solid var(--red)
    border-radius 6px
    padding 6px 12px
    font-size 0.75rem
    color var(--red)
    cursor pointer
    transition background 0.2s

    &:hover
      background var(--red)
      color var(--white)

  &__checkbox-section
    margin-bottom 24px

    &:last-child
      margin-bottom 0

  &__checkbox-section-title
    display flex
    align-items center
    gap 8px
    font-size 1rem
    font-weight 600
    color var(--dark)
    cursor pointer
    padding 8px 0
    user-select none

    .icon
      width 20px
      height 20px

      svg path
        fill var(--dark)

    &:hover
      color var(--green)

  &__checkbox-list
    display flex
    flex-direction column
    gap 12px
    padding-left 28px
    margin-top 8px

  &__checkbox-row
    display flex
    align-items center
    gap 16px

  &__checkbox-label-input
    flex 1
    border 1px solid transparent
    border-radius 6px
    padding 6px 8px
    font-size 0.875rem
    color var(--dark)
    background transparent
    transition border-color 0.2s

    &:hover
      border-color var(--gray-dark)

    &:focus
      outline none
      border-color var(--green)
      background var(--white)

  &__checkbox-field-name
    font-size 0.75rem
    color var(--dark-light)
    font-family monospace
    min-width 160px

  &__checkbox-filterable
    display flex
    align-items center
    gap 6px
    font-size 0.8125rem
    white-space nowrap

  &__checkbox-remove
    border none
    background none
    font-size 1.25rem
    line-height 1
    cursor pointer
    opacity 0.6

    &:hover
      opacity 1

  &__checkbox-add
    display flex
    align-items center
    gap 8px
    margin-top 12px
    flex-wrap wrap

  &__checkbox-error
    color #e53935
    font-size 0.8125rem

  &__checkbox-actions
    display flex
    justify-content flex-end
    gap 12px
    margin-top 24px
    padding-top 16px
    border-top 1px solid var(--gray-dark)

  &__columns-system
    margin-left 8px
    font-size 0.75rem
    color var(--dark-light)

  &__display-row
    display flex
    align-items center
    gap 16px
    padding 12px 0
    font-size 0.875rem
    color var(--dark)

  &__module-row
    display flex
    align-items flex-start
    gap 16px
    padding 16px 0
    border-bottom 1px solid var(--gray-3)

    &:last-child
      border-bottom none

  &__module-info
    flex 1
    display flex
    flex-direction column
    gap 4px

  &__module-label-input
    border 1px solid transparent
    border-radius 6px
    padding 6px 8px
    font-size 0.875rem
    font-weight 500
    color var(--dark)
    background transparent
    transition border-color 0.2s

    &:hover
      border-color var(--gray-dark)

    &:focus
      outline none
      border-color var(--green)
      background var(--white)

  &__module-desc-input
    border 1px solid transparent
    border-radius 6px
    padding 4px 8px
    font-size 0.813rem
    color var(--dark-light)
    background transparent
    transition border-color 0.2s

    &:hover
      border-color var(--gray-dark)

    &:focus
      outline none
      border-color var(--green)
      background var(--white)

  &__module-key
    font-size 0.75rem
    color var(--dark-light)
    font-family monospace
    min-width 100px
    padding-top 8px

  &__module-label-static
    font-size 0.9375rem
    font-weight 500
    color var(--dark)
    margin-bottom 8px

  &__module-select
    padding 8px 12px
    border 1px solid var(--gray-dark)
    border-radius 6px
    font-size 0.875rem
    color var(--dark)
    background var(--white)
    outline none
    min-width 280px
    max-width 100%
    cursor pointer
    transition border-color 0.2s

    &:focus
      border-color var(--green)

  &__module-desc-static
    margin-top 8px
    font-size 0.813rem
    color var(--dark-light)
    line-height 1.4

  &__module-desc-warn
    color var(--red)
    opacity 0.85
</style>
