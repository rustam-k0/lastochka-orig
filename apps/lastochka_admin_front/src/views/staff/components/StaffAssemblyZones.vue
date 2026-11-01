<template>
  <div class="staff-zones">
    <div class="staff-zones__controls">
      <SelectComponent
        v-model="selectedShop"
        :options="shops"
        label-name="title"
        title="Магазин"
        placeholder="Выберите магазин"
        @update:model-value="onShopChange"
      />
      <InputComponent
        v-model="selectedDate"
        title="Дата (пусто — постоянная привязка)"
        type="date"
        @update:model-value="onDateChange"
      />
    </div>

    <div v-if="!selectedShop" class="staff-zones__empty">
      <p>Выберите магазин, чтобы назначить сборщикам зоны сборки</p>
    </div>

    <template v-else>
      <div class="staff-zones__bulk">
        <SelectComponent
          v-model="bulkZone"
          :options="zones"
          label-name="title"
          title="Зона для назначения"
          placeholder="Выберите зону"
          clearable
        />
        <button
          class="btn btn--main btn--sm"
          :disabled="!selectedUserIds.length || !bulkZone"
          @click="assignBulk"
        >
          Назначить выбранным
        </button>
        <button
          class="btn btn--white btn--sm"
          :disabled="!selectedUserIds.length"
          @click="detachBulk"
        >
          Снять зону
        </button>
      </div>

      <div v-if="!collectors.length" class="staff-zones__empty">
        <p>В этом магазине нет сборщиков</p>
      </div>

      <table v-else class="staff-zones__table">
        <thead>
          <tr>
            <th class="staff-zones__check">
              <CheckboxComponent :model-value="allSelected" @update:model-value="toggleAll" />
            </th>
            <th>Сборщик</th>
            <th>Текущая зона{{ selectedDate ? ` на ${selectedDate}` : "" }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="user in collectors" :key="user.id">
            <td class="staff-zones__check">
              <CheckboxComponent
                :model-value="selectedUserIds.includes(user.id)"
                @update:model-value="toggleUser(user.id)"
              />
            </td>
            <td>{{ fullName(user) }}</td>
            <td>
              <span v-if="currentZone(user.id)" class="staff-zones__badge">
                <span
                  class="staff-zones__badge-color"
                  :style="{ background: currentZone(user.id).color || 'transparent' }"
                ></span>
                {{ currentZone(user.id).title }}
                <span v-if="isPermanentZone(user.id)" class="staff-zones__badge-note">постоянная</span>
              </span>
              <span v-else class="staff-zones__badge staff-zones__badge--empty">все заказы</span>
            </td>
          </tr>
        </tbody>
      </table>
    </template>
  </div>
</template>

<script>
import SelectComponent from "@/components/inputs/select/input.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import SHOPS from "@/graphql/queries/shops_list.graphql";
import ZONES from "@/graphql/queries/assembly_zones.graphql";
import COLLECTORS from "@/graphql/pages/Staffs/collectors.graphql";
import BINDINGS from "@/graphql/pages/Staffs/user_assembly_zones.graphql";
import BULK_ASSIGN from "@/graphql/pages/Staffs/UserAssemblyZoneBulkAssign.graphql";

export default {
  name: "StaffAssemblyZones",
  inject: ["apollo"],
  components: { SelectComponent, InputComponent, CheckboxComponent },
  data() {
    return {
      shops: [],
      zones: [],
      collectors: [],
      bindings: [],
      selectedShop: null,
      selectedDate: "",
      bulkZone: null,
      selectedUserIds: [],
    };
  },
  mounted() {
    this.loadShops();
    this.loadZones();
  },
  computed: {
    allSelected() {
      return this.collectors.length > 0 && this.selectedUserIds.length === this.collectors.length;
    },
  },
  methods: {
    fullName(user) {
      return [user.surname, user.name, user.patronymic].filter(Boolean).join(" ") || `#${user.id}`;
    },
    loadShops() {
      this.apollo()
        .admin.query({ query: SHOPS, fetchPolicy: "network-only" })
        .then(({ data }) => {
          this.shops = data.shops || [];
        })
        .catch(() => {});
    },
    loadZones() {
      this.apollo()
        .admin.query({ query: ZONES, fetchPolicy: "network-only" })
        .then(({ data }) => {
          // В привязке предлагаем только активные зоны.
          this.zones = (data.assembly_zones || []).filter((z) => z.is_active !== false);
        })
        .catch(() => {});
    },
    onShopChange() {
      this.selectedUserIds = [];
      if (!this.selectedShop) {
        this.collectors = [];
        this.bindings = [];
        return;
      }
      this.loadCollectors();
      this.loadBindings();
    },
    onDateChange() {
      // Перезагружать привязки не нужно (все привязки магазина уже в памяти),
      // достаточно пересчитать текущую зону — это делает currentZone() реактивно.
    },
    loadCollectors() {
      this.apollo()
        .admin.query({
          query: COLLECTORS,
          variables: { shop_id: this.selectedShop.id },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.collectors = data.collectors || [];
        })
        .catch(() => {});
    },
    loadBindings() {
      this.apollo()
        .admin.query({
          query: BINDINGS,
          variables: { shop_id: this.selectedShop.id },
          fetchPolicy: "network-only",
        })
        .then(({ data }) => {
          this.bindings = data.user_assembly_zones || [];
        })
        .catch(() => {});
    },
    bindingFor(userId) {
      const list = this.bindings.filter((b) => b.user_id === userId);
      // Приоритет: привязка на выбранную дату → постоянная (date = null).
      if (this.selectedDate) {
        const byDate = list.find((b) => b.date === this.selectedDate);
        if (byDate) return byDate;
      }
      return list.find((b) => !b.date) || null;
    },
    currentZone(userId) {
      const binding = this.bindingFor(userId);
      if (!binding) return null;
      return this.zonesById(binding.assembly_zone_id);
    },
    isPermanentZone(userId) {
      const binding = this.bindingFor(userId);
      return binding ? !binding.date : false;
    },
    zonesById(id) {
      return this.zones.find((z) => z.id === id) || null;
    },
    toggleUser(id) {
      const idx = this.selectedUserIds.indexOf(id);
      if (idx === -1) this.selectedUserIds.push(id);
      else this.selectedUserIds.splice(idx, 1);
    },
    toggleAll(value) {
      this.selectedUserIds = value ? this.collectors.map((u) => u.id) : [];
    },
    mutateBulk(zoneId) {
      this.apollo()
        .admin.mutate({
          mutation: BULK_ASSIGN,
          variables: {
            user_ids: this.selectedUserIds,
            shop_id: this.selectedShop.id,
            assembly_zone_id: zoneId,
            date: this.selectedDate || null,
          },
        })
        .then(() => {
          this.selectedUserIds = [];
          this.bulkZone = null;
          this.loadBindings();
        })
        .catch((error) => {
          alert(error.message || "Ошибка сохранения привязки");
        });
    },
    assignBulk() {
      if (!this.selectedUserIds.length || !this.bulkZone) return;
      this.mutateBulk(this.bulkZone.id);
    },
    detachBulk() {
      if (!this.selectedUserIds.length) return;
      this.mutateBulk(null);
    },
  },
};
</script>

<style lang="stylus">
.staff-zones {
  display flex
  flex-direction column
  gap 20px

  &__controls {
    display flex
    gap 16px
    flex-wrap wrap
    align-items flex-end
    max-width 640px
  }

  &__bulk {
    display flex
    gap 12px
    align-items flex-end
    flex-wrap wrap
    padding 16px
    background var(--white)
    border-radius 12px
    border 1px solid var(--white-2)
  }

  &__empty {
    color var(--gray-2)
    padding 24px 0
  }

  &__table {
    width 100%
    max-width 720px
    border-collapse collapse

    th, td {
      text-align left
      padding 12px
      border-bottom 1px solid var(--white-2)
      font-size 0.875rem
    }

    th {
      color var(--gray-2)
      font-weight 500
    }
  }

  &__check {
    width 48px
  }

  &__badge {
    display inline-flex
    align-items center
    gap 8px
    font-size 0.8125rem
  }

  &__badge--empty {
    color var(--gray-2)
  }

  &__badge-color {
    width 12px
    height 12px
    border-radius 50%
    border 1px solid var(--white-2)
    flex-shrink 0
  }

  &__badge-note {
    font-size 0.6875rem
    color var(--gray-2)
    background var(--white-2)
    border-radius 6px
    padding 2px 6px
  }
}
</style>
