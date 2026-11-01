<template>
  <div class="sync-batch-button">
    <button
      class="sync-batch-button__btn"
      :disabled="isInProgress || !shopId"
      @click="onClick"
    >
      <span v-if="isInProgress">Синхронизация…</span>
      <span v-else>Синхронизировать</span>
    </button>
    <div v-if="batch && isInProgress" class="sync-batch-button__progress">
      Прогресс: {{ batch.progress_done }} / {{ batch.progress_total }}
    </div>
    <div v-else-if="batch && batch.status === 'COMPLETED'" class="sync-batch-button__status sync-batch-button__status--ok">
      Синхронизация завершена
    </div>
    <div v-else-if="batch && batch.status === 'FAILED'" class="sync-batch-button__status sync-batch-button__status--err">
      Ошибка: {{ batch.error || "неизвестная" }}
    </div>
  </div>
</template>

<script>
import SYNC_BATCH from "../graphql/syncBatch.graphql";
import SYNC_CATEGORIES_1C from "../graphql/syncCategories1c.graphql";

const POLL_INTERVAL_MS = 2000;

export default {
  name: "SyncBatchButton",
  inject: ["apollo"],
  props: {
    shopId: { type: [Number, String], default: null },
  },
  emits: ["completed"],
  data() {
    return {
      batch: null,
      pollTimer: null,
      starting: false,
    };
  },
  computed: {
    isInProgress() {
      const status = this.batch?.status;
      return this.starting || status === "PENDING" || status === "IN_PROGRESS";
    },
  },
  watch: {
    shopId: {
      immediate: true,
      handler(val, oldVal) {
        if (val !== oldVal) {
          this.stopPolling();
          this.batch = null;
        }
        if (val) {
          this.fetchBatch();
        }
      },
    },
  },
  beforeUnmount() {
    this.stopPolling();
  },
  methods: {
    fetchBatch() {
      if (!this.shopId) return;
      this.apollo()
        .admin.query({
          query: SYNC_BATCH,
          variables: { shop_id: parseInt(this.shopId) },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          const prevStatus = this.batch?.status;
          this.batch = data?.category_1c_sync_batch || null;
          if (this.isInProgress) {
            this.startPolling();
          } else {
            this.stopPolling();
            if (
              prevStatus &&
              ["PENDING", "IN_PROGRESS"].includes(prevStatus) &&
              this.batch?.status === "COMPLETED"
            ) {
              this.$emit("completed");
            }
          }
        })
        .catch((error) => {
          console.error("SyncBatchButton: fetchBatch error", error);
        });
    },
    startPolling() {
      if (this.pollTimer) return;
      this.pollTimer = setInterval(() => this.fetchBatch(), POLL_INTERVAL_MS);
    },
    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },
    onClick() {
      if (!this.shopId || this.isInProgress) return;
      this.starting = true;
      this.apollo()
        .admin.mutate({
          mutation: SYNC_CATEGORIES_1C,
          variables: { shop_id: parseInt(this.shopId) },
        })
        .then(({ data }) => {
          this.batch = data?.sync_categories_1c || this.batch;
          this.startPolling();
        })
        .catch((error) => {
          console.error("SyncBatchButton: sync mutation error", error);
          alert("Не удалось запустить синхронизацию: " + (error?.message || ""));
        })
        .finally(() => {
          this.starting = false;
        });
    },
  },
};
</script>

<style lang="stylus" scoped>
.sync-batch-button
  display flex
  flex-direction column
  gap 6px
  align-items flex-start

  &__btn
    background var(--green, #28a745)
    color var(--white, #fff)
    border none
    padding 8px 16px
    border-radius 4px
    cursor pointer
    font-size 14px
    transition opacity 0.15s

    &:hover:not(:disabled)
      opacity 0.9

    &:disabled
      opacity 0.6
      cursor not-allowed

  &__progress
    font-size 12px
    color var(--dark-light, #555)

  &__status
    font-size 12px
    padding 4px 8px
    border-radius 4px

    &--ok
      background var(--green-light, #d4edda)
      color var(--green-dark, #155724)

    &--err
      background var(--red-light, #f8d7da)
      color var(--red-dark, #721c24)
</style>
