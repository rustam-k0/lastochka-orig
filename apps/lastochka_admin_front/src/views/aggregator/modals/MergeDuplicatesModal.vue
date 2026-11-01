<template>
  <div class="default-modal merge-modal">
    <ModalHeader @close="$emit('close')" title="Объединение платформенных карточек" />
    <div class="default-modal__body merge-modal__body">
      <InputComponent
        title="ID целевой карточки (куда сливаем)"
        type="number"
        required
        v-model="form.targetId.value"
        :errors="form.targetId.errors"
      />
      <InputComponent
        title="ID карточек-источников (через запятую)"
        placeholder="напр. 12, 15"
        required
        v-model="form.ids.value"
        :errors="form.ids.errors"
      />

      <button class="btn btn--sm merge-modal__preview-btn" @click="preview" :disabled="loading">
        Превью объединения
      </button>

      <div v-if="previewResult" class="merge-modal__preview">
        <p>Перенесётся офферов: <b>{{ previewResult.offersCount }}</b></p>
        <p>Суммарный остаток: <b>{{ previewResult.stocksSum }}</b></p>
        <div v-if="previewResult.conflicts.length" class="merge-modal__conflicts">
          <p class="merge-modal__conflicts-title">Конфликты (поставщик уже на целевой):</p>
          <ul>
            <li v-for="c in previewResult.conflicts" :key="c.offerId">{{ c.message }}</li>
          </ul>
        </div>
        <p v-else class="merge-modal__no-conflicts">Конфликтов нет.</p>
      </div>
    </div>
    <div class="merge-modal__footer">
      <button @click="$emit('close')" class="btn btn--white btn--md">Отмена</button>
      <button @click="confirm" class="btn btn--md" :disabled="loading || !previewResult">
        Объединить
      </button>
    </div>
  </div>
</template>

<script>
import ModalHeader from "@/components/modals/components/ModalHeader.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import { useDefaultStore } from "@/stores/default.js";
import PREVIEW from "@/graphql/pages/Aggregator/previewMergePlatformProducts.graphql";
import MERGE from "@/graphql/pages/Aggregator/mergeDuplicatePlatformProducts.graphql";
import RESOLVE_QUARANTINE from "@/graphql/pages/Aggregator/resolveQuarantine.graphql";

export default {
  name: "MergeDuplicatesModal",
  inject: ["apollo"],
  components: { InputComponent, ModalHeader },
  props: {
    callback: Function,
    sourceId: Number,
    candidateIds: { type: Array, default: () => [] },
    quarantineId: Number,
  },
  data() {
    return {
      loading: false,
      previewResult: null,
      form: {
        targetId: { value: null, errors: [] },
        ids: { value: null, errors: [] },
      },
    };
  },
  mounted() {
    if (this.candidateIds && this.candidateIds.length) {
      // Первая карточка — целевая; источники — остальные (target в источники не включаем).
      this.form.targetId.value = this.candidateIds[0];
      this.form.ids.value = this.candidateIds.slice(1).join(", ");
    } else if (this.sourceId) {
      this.form.ids.value = String(this.sourceId);
    }
  },
  methods: {
    parsedIds() {
      return String(this.form.ids.value || "")
        .split(",")
        .map((x) => parseInt(x.trim(), 10))
        .filter((x) => !Number.isNaN(x));
    },
    preview() {
      this.form.targetId.errors = [];
      this.form.ids.errors = [];
      const ids = this.parsedIds();
      const targetId = parseInt(this.form.targetId.value, 10);
      if (!targetId || !ids.length) {
        if (!targetId) this.form.targetId.errors = ["Укажите ID целевой карточки"];
        if (!ids.length) this.form.ids.errors = ["Укажите ID источников"];
        return;
      }
      this.loading = true;
      this.apollo()
        .admin.mutate({
          mutation: PREVIEW,
          variables: { ids, targetId },
        })
        .then(({ data }) => {
          this.previewResult = data.previewMergePlatformProducts;
          this.loading = false;
        })
        .catch((e) => {
          this.loading = false;
          const msg = e.graphQLErrors?.[0]?.message;
          if (msg) this.form.targetId.errors = [msg];
        });
    },
    confirm() {
      const ids = this.parsedIds();
      const targetId = parseInt(this.form.targetId.value, 10);
      this.loading = true;
      this.apollo()
        .admin.mutate({
          mutation: MERGE,
          variables: { ids, targetId },
        })
        .then(() => {
          if (this.quarantineId) {
            return this.apollo().admin.mutate({
              mutation: RESOLVE_QUARANTINE,
              variables: { id: this.quarantineId },
            });
          }
        })
        .then(() => {
          if (this.callback) this.callback();
          useDefaultStore().modals = [];
        })
        .catch((e) => {
          this.loading = false;
          const msg = e.graphQLErrors?.[0]?.message;
          if (msg) this.form.ids.errors = [msg];
        });
    },
  },
};
</script>

<style lang="stylus">
.merge-modal {
  width 560px

  &__body {
    display flex
    flex-direction column
    gap 12px
  }

  &__preview-btn {
    align-self flex-start
  }

  &__preview {
    padding 12px
    background var(--white-2)
    border-radius 8px
    font-size 0.9375em
  }

  &__conflicts {
    margin-top 8px
    color var(--red)

    &-title {
      font-weight 500
    }
  }

  &__no-conflicts {
    margin-top 8px
    color var(--green)
  }

  &__footer {
    display flex
    gap 16px
    justify-content flex-end
    padding 16px
    border-top 1px solid var(--white-2)
  }
}
</style>
