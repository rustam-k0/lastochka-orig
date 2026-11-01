<template>
  <div class="order-sync-status" v-if="orderNumber">
    <button
      type="button"
      class="order-sync-status__btn"
      :class="`order-sync-status__btn--${current.color}`"
      @click="modalOpen = true"
    >
      1С: {{ current.label }}
    </button>

    <div v-if="modalOpen" class="order-sync-status__overlay" @click.self="modalOpen = false">
      <div class="order-sync-status__modal">
        <header class="order-sync-status__modal-header">
          <h3>История синхронизации с 1С</h3>
          <button type="button" class="order-sync-status__close" @click="modalOpen = false">×</button>
        </header>

        <table class="order-sync-status__table">
          <thead>
            <tr>
              <th>№ попытки</th>
              <th>Статус</th>
              <th>Дата и время</th>
              <th>Сообщение об ошибке</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!logs.length">
              <td colspan="4">Не синхронизирован</td>
            </tr>
            <tr v-for="log in logs" :key="log.id">
              <td>{{ log.attempt_number }}</td>
              <td>{{ statusMap[log.status] ? statusMap[log.status].label : log.status }}</td>
              <td>{{ formatDate(log.created_at) }}</td>
              <td>{{ log.status === "error" ? log.message || "—" : "—" }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script>
import ORDER_SYNC_LOGS from "@/graphql/pages/Order/order_sync_logs.graphql";

const STATUS_MAP = {
  success: { label: "Успешно синхронизирован", color: "green" },
  sent: { label: "Отправлен в 1С", color: "blue" },
  pending_retry: { label: "Ожидает повторной синхронизации", color: "yellow" },
  error: { label: "Ошибка отправки", color: "red" },
};

export default {
  name: "OrderSyncStatus",
  inject: ["apollo"],
  props: {
    orderNumber: {
      type: [String, Number],
      default: null,
    },
  },
  data() {
    return {
      logs: [],
      modalOpen: false,
      statusMap: STATUS_MAP,
    };
  },
  computed: {
    current() {
      const last = this.logs[0];
      if (last && STATUS_MAP[last.status]) {
        return STATUS_MAP[last.status];
      }
      return { label: "Не синхронизирован", color: "gray" };
    },
  },
  watch: {
    orderNumber: {
      immediate: true,
      handler() {
        this.fetch();
      },
    },
  },
  methods: {
    fetch() {
      if (!this.orderNumber) {
        this.logs = [];
        return;
      }
      this.apollo()
        .admin.query({
          query: ORDER_SYNC_LOGS,
          variables: { order_number: String(this.orderNumber) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.logs = (data && data.orderSyncLogs) || [];
        })
        .catch(() => {
          this.logs = [];
        });
    },
    formatDate(value) {
      if (!value) return "—";
      const d = new Date(value);
      if (isNaN(d.getTime())) return value;
      return d.toLocaleString("ru-RU", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    },
  },
};
</script>

<style scoped>
.order-sync-status__btn {
  border: none;
  border-radius: 6px;
  padding: 6px 12px;
  font-size: 13px;
  color: #fff;
  cursor: pointer;
}
.order-sync-status__btn--green { background: #1a9c54; }
.order-sync-status__btn--blue { background: #2563eb; }
.order-sync-status__btn--yellow { background: #d4a000; }
.order-sync-status__btn--red { background: #dc2626; }
.order-sync-status__btn--gray { background: #6b7280; }

.order-sync-status__overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}
.order-sync-status__modal {
  background: #fff;
  border-radius: 10px;
  padding: 20px;
  width: min(720px, 92vw);
  max-height: 80vh;
  overflow: auto;
}
.order-sync-status__modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
.order-sync-status__close {
  border: none;
  background: transparent;
  font-size: 22px;
  line-height: 1;
  cursor: pointer;
}
.order-sync-status__table {
  width: 100%;
  border-collapse: collapse;
}
.order-sync-status__table th,
.order-sync-status__table td {
  border-bottom: 1px solid #eee;
  padding: 8px 10px;
  text-align: left;
  font-size: 13px;
}
</style>
