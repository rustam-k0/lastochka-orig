<template>
  <div class="o-p__aside">
    <BaseWrapper>
      <template #title>
        <div>Сводка</div>
      </template>
      <template #default>
        <ul class="o-p__list">
          <li class="o-p__select" @click="active = !active">
            <template v-for="item in rowStatuses" :key="item.id">
              <div v-if="item.id === statusId" class="o-p__select--selected">
                <span v-if="item.id === 5 && page.type_id === 1">Выдан</span>
                <span v-else-if="item.id === 3 && courierAssignmentEnabled && assignedCouriers.length"
                  >Назначен курьер</span
                >
                <span v-else>{{ item.title }}</span>
              </div>
            </template>
            <IconComponent v-if="canIChangeStatus && canChangeStatus" name="arrow-down-s-line" />
          </li>
          <template v-if="page.type_id === 1 && page.status_id === 3 && canChangeStatus">
            <li class="o-p__pickup-confirm">
              <button class="btn btn--success" @click="confirmPickup">Заказ выдан</button>
            </li>
          </template>
          <!-- Самовывоз: отдельный блок, не зависит от canIChangeStatus —
               даты/сотрудника нужно видеть и после того, как заказ выдан. -->
          <template v-if="page.type_id === 1">
            <li>
              <div>
                <IconComponent name="store-2-line" />
                <span>Магазин выдачи</span>
              </div>
              <span>{{ page.shop?.title || "—" }}</span>
            </li>
            <li>
              <div>
                <IconComponent name="shopping-bag-line" />
                <span>Статус выдачи</span>
              </div>
              <span>{{ pickupStatusLabel }}</span>
            </li>
            <li v-if="page.pickup_ready_at">
              <div>
                <IconComponent name="time-line" />
                <span>Готов к выдаче</span>
              </div>
              <span>{{ dateTimeFormat(page.pickup_ready_at) }}</span>
            </li>
            <li v-if="page.picked_up_at">
              <div>
                <IconComponent name="calendar-check-line" />
                <span>Выдан</span>
              </div>
              <span>{{ dateTimeFormat(page.picked_up_at) }}</span>
            </li>
            <li v-if="page.picked_up_by">
              <div>
                <IconComponent name="user-line" />
                <span>Выдачу подтвердил</span>
              </div>
              <span>{{ formatPickedUpBy }}</span>
            </li>
          </template>
          <!-- Доставка: отдельный блок, не зависит от canIChangeStatus —
               курьера нужно видеть и после того, как заказ доставлен/отменён. -->
          <template v-if="page.type_id === 2">
            <li v-if="page.client_info?.address">
              <div>
                <IconComponent name="map-pin-line" />
                <span>Адрес доставки</span>
              </div>
              <span>{{ page.client_info.address }}</span>
            </li>
            <li>
              <div>
                <IconComponent name="truck-line" />
                <span>Статус доставки</span>
              </div>
              <span>{{ deliveryStatusLabel }}</span>
            </li>
            <template v-if="heavyOrdersModuleEnabled">
              <li v-if="page.is_heavy">
                <div>
                  <IconComponent name="error-warning-line" />
                  <span>Тяжелый заказ</span>
                </div>
                <span v-if="assignedCouriers.length > 1" class="o-p__joint-badge"
                  >Совместная доставка</span
                >
                <span v-else>Да</span>
              </li>
              <li v-if="assignedCouriers.length">
                <div>
                  <IconComponent name="group-line" />
                  <span>Количество курьеров</span>
                </div>
                <span>{{ assignedCouriers.length }}</span>
              </li>
              <li
                v-for="(assigned, index) in assignedCouriers"
                :key="assigned.id"
                @click.stop="
                  index === 0 && statusId === 4 && canAssignCourier && !paymentBlocksCourier
                    ? openCouriersModal()
                    : null
                "
              >
                <div>
                  <IconComponent name="user-line" />
                  <span>Курьер</span>
                </div>
                <router-link
                  v-if="index === 0 && statusId === 4 && canAssignCourier && !paymentBlocksCourier"
                  to=""
                  >{{ formatAssignedCourier(assigned) }}</router-link
                >
                <span v-else>{{ formatAssignedCourier(assigned) }}</span>
              </li>
              <li v-if="canAddCourier" @click.stop="openAddCourierModal">
                <div>
                  <IconComponent name="user-add-line" />
                  <span>Добавить курьера</span>
                </div>
                <router-link to="">Выбрать</router-link>
              </li>
            </template>
            <template v-if="!heavyOrdersModuleEnabled || !assignedCouriers.length">
              <template v-if="statusId === 4 && canAssignCourier && !paymentBlocksCourier">
                <li @click.stop="openCouriersModal">
                  <div>
                    <IconComponent name="user-line" />
                    <span>Курьер</span>
                  </div>
                  <router-link v-if="courier" to=""> {{ formatCourier }} </router-link>
                  <span v-else>Не выбран курьер</span>
                </li>
              </template>
              <template v-else>
                <li>
                  <div>
                    <IconComponent name="user-line" />
                    <span>Курьер</span>
                  </div>
                  <router-link v-if="courier" to=""> {{ formatCourier }} </router-link>
                  <span v-else>Не выбран курьер</span>
                </li>
              </template>
            </template>
            <li v-if="page.delivery_zone">
              <div>
                <IconComponent name="map-line" />
                <span>Зона доставки</span>
              </div>
              <span>{{ page.delivery_zone.title }}</span>
            </li>
          </template>
          <template v-if="canIChangeStatus">
            <li v-if="active && canChangeStatus && !(page.type_id === 1 && page.status_id === 3) && !(paymentBlocksCourier && page.status_id === 3)" class="o-p__drop-list">
              <ul>
                <template v-for="item in rowStatuses" :key="item.id">
                  <li v-if="item.id === statusId + 1 && canIChangeStatus" class="btn" @click="changeStatus">
                    {{ item.title }}
                  </li>
                </template>
              </ul>
            </li>
          </template>
          <!-- Сборщик: отдельный блок, не зависит от canIChangeStatus —
               исполнителя нужно видеть и после того, как заказ доставлен/отменён.
               При зонах сборки (подзаказы) сборщик назначается на КАЖДЫЙ подзаказ
               отдельно (см. OrderPrepare в delivery_back) — показываем списком. -->
          <template v-if="hasChildOrders">
            <li v-for="entry in resolvedSubCollectors" :key="entry.child.id">
              <div>
                <IconComponent name="archive-line" />
                <span>Сборщик {{ entry.child.label }}</span>
              </div>
              <router-link v-if="entry.collector" :to="{ name: 'staffs' }">{{ formatCollectorName(entry.collector) }} </router-link>
              <span v-else>Не выбран сборщик</span>
            </li>
          </template>
          <template v-else-if="statusId === 2 && canAssignCollector">
            <li @click.stop="openCollectorsModal">
              <div>
                <IconComponent name="archive-line" />
                <span>Сборщик</span>
              </div>
              <router-link v-if="collector" :to="{ name: 'staffs' }">{{ formatCollector }} </router-link>
              <span v-else>Не выбран сборщик</span>
            </li>
          </template>
          <template v-else>
            <li>
              <div>
                <IconComponent name="archive-line" />
                <span>Сборщик</span>
              </div>
              <router-link v-if="collector && isAdmin" :to="{ name: 'staffs' }"
                >{{ formatCollector }}
              </router-link>
              <span v-else-if="collector && !isAdmin">{{ formatCollector }} </span>
              <span v-else>Не выбран сборщик</span>
            </li>
          </template>
          <template v-if="page.shop">
            <li>
              <div>
                <IconComponent name="user-line" />
                <span>Магазин</span>
              </div>
              <span
                >{{ page.shop.title
                }}<span v-if="page.type_id === 1 && page.shop.address"> · {{ page.shop.address }}</span></span
              >
            </li>
          </template>
          <template v-if="page.type_id">
            <li>
              <div>
                <IconComponent name="car-line" />
                <span>Способ доставки</span>
              </div>
              <span>{{ deliveryTypeName }}</span>
            </li>
          </template>
          <li v-if="page.pickup_only_required">
            <div>
              <IconComponent name="error-warning-line" />
              <span>Ограничение доставки</span>
            </div>
            <span>Доступен только самовывоз</span>
          </li>
          <li>
            <div>
              <IconComponent name="calendar-line" />
              <span>Дата создания</span>
            </div>
            <span>{{ dateFormat(page.created_at) }} {{ timeFormat(page.created_at) }}</span>
          </li>
          <li>
            <div>
              <IconComponent name="bank-card-line" />
              <span>Оплата</span>
            </div>
            <span>{{ PAYMENT_TYPES[page.payment_type_id] || "—" }} · {{ paymentStatusLabel }}</span>
          </li>
          <!-- Наличные: платформа факт оплаты сама не фиксирует — отмечает сотрудник,
               принявший деньги (OrderMarkPaid в purchase_back, право orders.mark_paid) -->
          <li v-if="canMarkPaid" @click.stop="markPaid">
            <div>
              <IconComponent name="hand-coin-line" />
              <span>Оплата наличными</span>
            </div>
            <router-link to="">{{ markingPaid ? "Сохраняем…" : "Отметить оплаченным" }}</router-link>
          </li>
        </ul>
      </template>
    </BaseWrapper>
    <BaseWrapper>
      <template #title>
        <div>Клиент</div>
      </template>
      <template #default>
        <ul v-if="page.client_info" class="o-p__contact-info">
          <li v-if="page.client_info.name && page.client_info.surname">
            <div class="o-p__contact-info-title">Имя</div>
            <router-link to="">{{ page.client_info.name }} {{ page.client_info.surname }}</router-link>
          </li>
          <li v-if="page.client_info.phone">
            <div class="o-p__contact-info-title">Номер телефона</div>
            <div class="o-p__contact-info-description">{{ page.client_info.phone }}</div>
          </li>
          <li v-if="page.client_info.email">
            <div class="o-p__contact-info-title">Email</div>
            <div class="o-p__contact-info-description">{{ page.client_info.email }}</div>
          </li>
          <li
            v-if="
              page.slot_start_date &&
              page.slot_end_date &&
              (!checkoutWithoutSlotsEnabled || page.type_id === 1)
            "
          >
            <div class="o-p__contact-info-title">
              {{ page.type_id === 1 ? "Дата и время самовывоза" : "Дата и время доставки" }}
            </div>
            <div class="o-p__contact-info-wrapper">
              <span class="o-p__contact-info-description">
                {{ dateFormat(page.slot_start_date) }}
              </span>
              <span class="o-p__contact-info-description">
                {{ timeFormat(page.slot_start_date) }} - {{ timeFormat(page.slot_end_date) }}
              </span>
            </div>
          </li>
          <li
            v-if="
              page.client_info.entrance ||
              page.client_info.intercom ||
              page.client_info.floor ||
              page.client_info.flat
            "
          >
            <div class="o-p__contact-info-title">Детали заказа</div>
            <div class="o-p__contact-info-description">Подъезд: {{ page.client_info.entrance }}</div>
            <div class="o-p__contact-info-description">Домофон: {{ page.client_info.intercom }}</div>
            <div class="o-p__contact-info-description">Этаж: {{ page.client_info.floor }}</div>
            <div class="o-p__contact-info-description">Квартира: {{ page.client_info.flat }}</div>
          </li>
          <li v-if="page.client_comment">
            <div class="o-p__contact-info-title">Комментарий</div>
            <div class="o-p__contact-info-description">
              {{ page.client_comment }}
            </div>
          </li>
        </ul>
      </template>
    </BaseWrapper>
    <OrderObjectAddress
      v-if="page && page.client_info && page.client_info.object_data"
      :data="page.client_info.object_data"
    />
  </div>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import IconComponent from "@/components/IconComponent.vue";
import OrderObjectAddress from "@/components/orders/OrderObjectAddress.vue";
import OPTIONS from "@/graphql/pages/Order/options.graphql";
import SUBMIT from "@/graphql/mutations/OrderGoToNextStep.graphql";
import COURIER_ADD from "@/graphql/mutations/OrderCourierAdd.graphql";
import OrderPickupConfirm from "@/graphql/mutations/OrderPickupConfirm.graphql";
import OrderMarkPaid from "@/graphql/mutations/OrderMarkPaid.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";
import CouriersModal from "@/views/orders/modals/CouriersModal.vue";
import CollectorsModal from "@/views/orders/modals/CollectorsModal.vue";
import dayjs from "dayjs";
import customParseFormat from "dayjs/plugin/customParseFormat";
import { useAuthStore } from "@/stores/auth.js";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";
import { DELIVERY_TYPES } from '@/constants/deliveryTypes.js';
import { PAYMENT_TYPES } from '@/constants/paymentTypes.js';
dayjs.extend(customParseFormat);
export default {
  name: "OrderPageAside",

  inject: ["apollo"],
  mounted() {
    this.get();
  },
  props: {
    collector: {
      type: Object,
    },
    subCollectors: {
      type: Array,
      default: () => [],
    },
    courier: {
      type: Object,
    },
    statusId: {
      type: Number,
    },
    page: {
      type: Object,
    },
    shop_id: Number,
  },

  data() {
    return {
      active: false,
      // [] а не null: списки грузятся асинхронно (get), а модалки смены
      // исполнителя могут открыться раньше ответа — null.map() ронял их
      couriers: [],
      collectors: [],
      markingPaid: false,
      PAYMENT_TYPES,
      rowStatuses: [
        {
          id: 1,
          title: "Создан",
        },
        {
          id: 2,
          title: "Заказ на сборке",
        },
        {
          id: 3,
          title: "Собрано",
        },
        {
          id: 4,
          title: "В доставке",
        },
        {
          id: 5,
          title: "Доставлен",
        },
        {
          id: 6,
          title: "Отменён",
        },
      ],
    };
  },

  methods: {
    formatCollectorName(collector) {
      return collector
        ? `${collector.surname ? collector.surname[0].toUpperCase() : ""} ${
            collector.name ? collector.name[0].toUpperCase() : ""
          }`
        : null;
    },
    openCouriersModal() {
      useDefaultStore().modals.push({
        component: shallowRef(CouriersModal),
        options: {
          couriers: this.couriers,
          courier: this.courier,
          statusId: this.statusId,
          callback: this.$emit("update"),
        },
      });
    },
    timeFormat(date) {
      return dayjs(date, "YYYY-MM-DD HH:mm:ss").format("HH:mm");
    },
    dateFormat(date) {
      return dayjs(date, "YYYY-MM-DD HH:mm:ss").format("DD.MM.YYYY");
    },
    dateTimeFormat(date) {
      return dayjs(date, "YYYY-MM-DD HH:mm:ss").format("DD.MM.YYYY HH:mm");
    },
    openCollectorsModal() {
      useDefaultStore().modals.push({
        component: shallowRef(CollectorsModal),
        options: {
          collectors: this.collectors,
          collector: this.collector,
          statusId: this.statusId,
          callback: this.$emit("update"),
        },
      });
    },
    formatAssignedCourier(courier) {
      return `${courier.surname || ""} ${courier.name || ""}`.trim();
    },
    openAddCourierModal() {
      useDefaultStore().modals.push({
        component: shallowRef(CouriersModal),
        options: {
          couriers: this.couriers,
          mode: "add",
          callback: (courier_id) => {
            this.addCourier(courier_id);
          },
        },
      });
    },
    addCourier(courier_id) {
      if (!courier_id) return;
      this.apollo()
        .purchase.mutate({
          mutation: COURIER_ADD,
          variables: { id: this.id, courier_id },
        })
        .then(() => {
          this.$emit("update");
        })
        .catch(() => {
          console.log("error");
        });
    },
    get() {
      // Списки курьеров/сборщиков нужны и для перехода статуса (модалка
      // выбора в changeStatus), и для смены исполнителя
      if (!this.loading && (this.canChangeStatus || this.canAssignCourier || this.canAssignCollector)) {
        this.loading = true;
        this.apollo()
          .admin.query({
            query: OPTIONS,
            variables: {
              shop_id: this.page.shop?.id,
            },
          })
          .then(({ data }) => {
            if (data && data.couriers && data.collectors) {
              this.couriers = data.couriers;
              this.collectors = data.collectors;
              this.loading = false;
            }
          })
          .catch(() => {
            this.loading = false;
            console.warn("error");
          });
      }
    },
    changeStatus() {
      // Блокируем назначение курьера: самовывоз ИЛИ неоплаченный онлайн-заказ (collector_payment)
      if ((this.page.type_id === 1 || this.paymentBlocksCourier) && this.statusId === 3) {
        return;
      }
      switch (this.statusId) {
        case 1:
          useDefaultStore().modals.push({
            component: shallowRef(CollectorsModal),
            options: {
              collectors: this.collectors,
              callback: (collector_id) => {
                this.nextStatus(collector_id);
              },
            },
          });
          break;
        case 3: {
          // Курьер уже назначен (флоу courier_assignment: сборщик или прошлый
          // клик админа) — «В доставке» стартует его рейс, повторный выбор не
          // нужен. Раньше клик снова открывал модалку и вешал на заказ ещё
          // одного курьера, а статус не двигался (MID-242).
          const assignedCourierId = this.courier?.id || this.assignedCouriers[0]?.id;
          if (assignedCourierId) {
            this.nextStatus(this.collector?.id, assignedCourierId);
            break;
          }
          useDefaultStore().modals.push({
            component: shallowRef(CouriersModal),
            options: {
              couriers: this.couriers,
              callback: (courier_id) => {
                this.nextStatus(this.collector?.id, courier_id);
              },
            },
          });
          break;
        }
        default:
          this.nextStatus();
          break;
      }
    },
    nextStatus(collector_id, courier_id) {
      this.apollo()
        .purchase.mutate({
          mutation: SUBMIT,
          variables: {
            collector_id: collector_id || this.collector?.id,
            courier_id: courier_id || this.courier?.id,
            id: this.id,
          },
        })
        .then(() => {
          this.saving = false;
          this.$emit("update");
        })
        .catch(() => {
          console.log("error");
        });
    },
    async confirmPickup() {
      if (!confirm(`Подтвердить выдачу заказа №${this.page.number}?`)) return;

      try {
        const { data } = await this.apollo().purchase.mutate({
          mutation: OrderPickupConfirm,
          variables: { id: this.page.id },
        });

        if (data.OrderPickupConfirm.code === 200) {
          this.$emit("update");
        } else {
          alert(data.OrderPickupConfirm.message);
        }
      } catch (e) {
        alert('Ошибка: ' + e.message);
      }
    },
    async markPaid() {
      if (this.markingPaid) return;
      if (!confirm(`Отметить заказ №${this.page.number} оплаченным наличными?`)) return;

      this.markingPaid = true;
      try {
        const { data } = await this.apollo().purchase.mutate({
          mutation: OrderMarkPaid,
          variables: { id: this.page.id },
        });

        if (data.OrderMarkPaid.code === 200) {
          this.$emit("update");
        } else {
          alert(data.OrderMarkPaid.message);
        }
      } catch (e) {
        alert("Ошибка: " + e.message);
      } finally {
        this.markingPaid = false;
      }
    },
  },

  computed: {
    id() {
      return parseInt(this.$route.params.id);
    },
    formatCollector() {
      return this.formatCollectorName(this.collector);
    },
    hasChildOrders() {
      return !!(this.page.childOrders && this.page.childOrders.length);
    },
    resolvedSubCollectors() {
      // Админ может продвинуть статус кнопкой "Далее" на родительском заказе —
      // тогда Assembly создаётся на order_id родителя, а не подзаказа (см.
      // OrderGoToNextStep в purchase_back). Сборщик самообслуживанием в
      // мобильном приложении берёт в работу конкретный подзаказ — тогда Assembly
      // привязана к его id. Показываем то, что нашлось для подзаказа, иначе —
      // общий сборщик заказа.
      return this.subCollectors.map((entry) => ({
        ...entry,
        collector: entry.collector || this.collector,
      }));
    },
    isAdmin() {
      return useAuthStore().isAdminRole;
    },
    canChangeStatus() {
      return useAuthStore().canOrderAction("orders.change_status");
    },
    canAssignCourier() {
      return useAuthStore().canOrderAction("orders.assign_courier");
    },
    canAssignCollector() {
      return useAuthStore().canOrderAction("orders.assign_collector");
    },
    canMarkPaid() {
      // Ручная отметка оплаты: только наличные (2), ещё не оплачен, не отменён,
      // не подзаказ (оплата фиксируется на родителе и каскадом на подзаказы)
      return (
        this.page?.payment_type_id === 2 &&
        !this.page?.is_paid &&
        this.page?.status_id !== 6 &&
        !this.page?.parent_order_id &&
        useAuthStore().canOrderAction("orders.mark_paid")
      );
    },
    paymentStatusLabel() {
      if (this.page?.payment_status === "awaiting_surcharge") return "Ожидает доплату";
      if (!this.page?.is_paid) return "Не оплачено";
      const paidAt = this.page?.paid_at;
      return paidAt ? `Оплачено ${this.dateFormat(paidAt)} ${this.timeFormat(paidAt)}` : "Оплачено";
    },
    courierAssignmentEnabled() {
      return useModuleConfigStore().isModuleEnabled("courier_assignment");
    },
    checkoutWithoutSlotsEnabled() {
      // Модуль «Оформление заказа без слотов» (MID-256): у доставки слот заказа
      // технический (момент оформления) — блок «Дата и время доставки» не
      // показываем. У самовывоза слот реальный (время выдачи) — показываем всегда
      return useModuleConfigStore().isModuleEnabled("checkout_without_slots");
    },
    heavyOrdersModuleEnabled() {
      // Флоу тяжёлых заказов активен при heavy_orders ИЛИ courier_assignment
      // (совместная доставка входит в «Назначение курьера сборщиком»)
      const modules = useModuleConfigStore();
      return modules.isModuleEnabled("heavy_orders") || modules.isModuleEnabled("courier_assignment");
    },
    assignedCouriers() {
      return this.page?.couriers || [];
    },
    paymentBlocksCourier() {
      // Модуль collector_payment: онлайн-заказ без успешного списания не отдаём курьеру
      return (
        useModuleConfigStore().isModuleEnabled("collector_payment") &&
        this.page?.payment_type_id === 1 &&
        !this.page?.is_paid
      );
    },
    canAddCourier() {
      // Доп. курьер: тяжёлый заказ, доставка в пути или собрано, право назначения
      return (
        this.heavyOrdersModuleEnabled &&
        this.page?.is_heavy &&
        [3, 4].includes(this.statusId) &&
        this.canAssignCourier &&
        !this.paymentBlocksCourier &&
        this.assignedCouriers.length >= 1
      );
    },
    formatCourier() {
      return this.courier
        ? `${this.courier.surname ? this.courier.surname[0].toUpperCase() : ""} ${
            this.courier.name ? this.courier.name[0].toUpperCase() : ""
          }`
        : null;
    },
    canIChangeStatus() {
      return this.statusId < 5;
    },
    deliveryTypeName() {
      return DELIVERY_TYPES[this.page.type_id] || DELIVERY_TYPES[2]
    },
    pickupStatusLabel() {
      const labels = {
        1: "Ожидает сборки",
        2: "Собирается",
        3: "Готов к выдаче",
        4: "Готов к выдаче",
        5: "Выдан",
        6: "Отменён",
      };
      return labels[this.page.status_id] || "—";
    },
    deliveryStatusLabel() {
      return this.rowStatuses.find((s) => s.id === this.page.status_id)?.title || "—";
    },
    formatPickedUpBy() {
      const user = this.page.picked_up_by;
      if (!user) return null;
      return [user.surname, user.name].filter(Boolean).join(" ") || `ID ${user.id}`;
    },
  },

  components: {
    IconComponent,
    BaseWrapper,
    OrderObjectAddress,
  },
};
</script>

<style lang="stylus">
.o-p {
  &__aside {
    position sticky
    top 0
    display flex
    flex-direction column
    gap 16px

    a {
      color var(--blue-2)
      font-size 0.875rem
      text-decoration underline
      line-height 20px
    }
  }

  &__select {
    cursor pointer
    color var(--purple)
    background var(--purple-light)

    .icon:not(.remix-arrow-down-s-line-icon) {

      svg path {
        fill var(--purple)
      }
    }

    &--selected {
      font-weight 600
    }
  }

  &__drop-list {
    display flex
    flex-direction column
    width 100%
    gap 5px
    //padding 0 !important
    //margin-top 4px

    ul {
      width 100%
    }
  }

  &__list {
    display flex
    flex-direction column
    gap 4px

    li {
      display flex
      justify-content space-between
      align-items center
      padding 16px
      border-radius 16px
      font-size 0.813rem
      cursor pointer
      line-height 20px
      gap 12px

      // Подпись не сжимается в столбик из-за длинного значения (адрес, ФИО)
      > div {
        display flex
        align-items center
        gap 4px
        flex-shrink 0
        max-width 55%
      }

      > span,
      > a {
        min-width 0
        text-align right
        overflow-wrap anywhere
      }

      .icon svg {
        width 20px
        height 20px
      }
    }
  }

  &__pickup-confirm {
    padding 12px 16px !important

    .btn--success {
      width 100%
      background var(--green)
      color var(--white)
      border-radius 10px
      padding 10px 16px
      font-weight 600
      font-size 0.875rem
      cursor pointer
      border none

      &:hover {
        opacity 0.9
      }
    }
  }

  &__contact-info {
    display flex
    flex-direction column
    gap 8px

    li {
      display flex
      flex-direction column
      gap 8px
      padding 8px 16px
      border 1px solid var(--gray-light)
      border-radius 8px

    }
  }

  &__contact-info-title {
    color var(--dark)
    font-weight 500
    font-size 0.875rem
  }

  &__contact-info-link {
    color var(--blue-2)
    font-size 0.875rem
    text-decoration underline
    line-height 20px
  }

  &__contact-info-wrapper {
    display flex
    gap 8px
  }

  &__joint-badge {
    color #ff9500
    font-weight 600
  }
}
</style>
