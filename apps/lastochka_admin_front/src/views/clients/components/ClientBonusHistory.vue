<template>
  <BaseWrapper>
    <template #title>История баллов</template>
    <TableComponent
      v-if="!loadError"
      class="client-bonus-history__table"
      :columns="columns"
      :rows="rows.data || []"
      :loading="loading"
      :total="rows.paginatorInfo.total"
      :first="first"
      :page="page"
      :selectable="false"
      pagination
      @update:page="handlePaginate"
      @update:count="handleFirstCount"
    >
      <template #col="{ col, row }">
        <span v-if="col.field === 'occurred_at'" class="rows__left">
          {{ formatDate(row.occurred_at || row.created_at) }}
        </span>
        <span v-if="col.field === 'type'" class="rows__left">
          {{ typeLabel(row.type) }}
        </span>
        <span v-if="col.field === 'amount'" class="rows__left">
          {{ signedAmount(row) }}
        </span>
        <span v-if="col.field === 'balance_after'" class="rows__left">
          {{ row.balance_after === null ? "—" : row.balance_after }}
        </span>
        <span v-if="col.field === 'source'" class="rows__left">
          {{ sourceLabel(row.source) }}
        </span>
        <span v-if="col.field === 'description'" class="rows__left">
          {{ row.description || "" }}
        </span>
        <span v-if="col.field === 'admin'" class="rows__left">
          {{ adminName(row.admin) }}
        </span>
        <template v-if="col.field === 'status'">
          <div v-if="row.status === 'applied'" class="status status--green">
            <span>Применено</span>
          </div>
          <div v-else-if="row.status === 'pending'" class="status status--orange">
            <span>В обработке</span>
          </div>
          <div v-else class="status status--red" :title="row.reject_message || ''">
            <span>Отклонено: {{ rejectLabel(row.reject_reason) }}</span>
          </div>
        </template>
      </template>
    </TableComponent>
    <div v-else class="client-bonus-history__error-text">
      {{ loadError }}
    </div>
  </BaseWrapper>
</template>

<script>
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import TableComponent from "@/components/table/index.vue";
import HISTORY from "@/graphql/pages/Client/admin_client_bonus_transactions_paginate.graphql";
import dayjs from "dayjs";

const TYPE_LABELS = {
  earn: "Начисление",
  spend: "Списание",
  refund: "Возврат",
  cancel: "Отмена начисления",
};

const SOURCE_LABELS = {
  manual: "Вручную",
  online: "Заказ",
  offline: "Касса",
  initial: "Стартовый",
};

const REJECT_LABELS = {
  insufficient_balance: "недостаточно доступных баллов",
  user_not_resolvable: "клиент не найден в магазине",
  invalid_payload: "некорректные параметры",
};

export default {
  name: "ClientBonusHistory",
  inject: ["apollo"],
  components: { TableComponent, BaseWrapper },
  props: {
    clientId: {
      type: Number,
      required: true,
    },
  },
  mounted() {
    this.get();
  },
  data() {
    return {
      loading: false,
      loadError: null,
      first: 25,
      page: 1,
      columns: [
        { title: "Дата", field: "occurred_at", show: true },
        { title: "Операция", field: "type", show: true },
        { title: "Баллы", field: "amount", show: true, width: "90px" },
        { title: "Баланс после", field: "balance_after", show: true, width: "120px" },
        { title: "Источник", field: "source", show: true },
        { title: "Комментарий", field: "description", show: true },
        { title: "Автор", field: "admin", show: true },
        { title: "Статус", field: "status", show: true, system: true },
      ],
      rows: {
        data: [],
        paginatorInfo: { total: 0 },
      },
    };
  },
  methods: {
    typeLabel(type) {
      return TYPE_LABELS[type] || type;
    },
    sourceLabel(source) {
      return SOURCE_LABELS[source] || source;
    },
    rejectLabel(reason) {
      return REJECT_LABELS[reason] || reason || "причина не указана";
    },
    signedAmount(row) {
      const sign = ["spend", "cancel"].includes(row.type) ? "−" : "+";
      return `${sign}${row.amount}`;
    },
    adminName(admin) {
      if (!admin) {
        return "";
      }
      return [admin.surname, admin.name].filter(Boolean).join(" ");
    },
    formatDate(date) {
      return date ? dayjs(date).format("DD.MM.YYYY HH:mm") : "";
    },
    handlePaginate(value) {
      this.page = value;
      this.get();
    },
    handleFirstCount(value) {
      this.first = value;
      this.page = 1;
      this.get();
    },
    refresh() {
      this.page = 1;
      this.get();
    },
    get() {
      if (this.loading) {
        return;
      }
      this.loading = true;
      this.loadError = null;

      this.apollo()
        .admin.query({
          query: HISTORY,
          variables: {
            client_id: this.clientId,
            page: this.page,
            first: this.first,
          },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          if (data.admin_client_bonus_transactions_paginate) {
            this.rows = data.admin_client_bonus_transactions_paginate;
          }
        })
        .catch((error) => {
          console.error("Error loading client bonus history:", error);
          this.loadError = "Не удалось загрузить историю баллов";
        })
        .finally(() => {
          this.loading = false;
        });
    },
  },
};
</script>

<style lang="stylus">
.client-bonus-history__table {
  width 100%
}

.client-bonus-history__error-text {
  font-size 0.875rem
  color var(--red)
}
</style>
