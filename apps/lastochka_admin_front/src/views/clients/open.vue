<template>
  <main class="page client-page" v-if="pageInfo">
    <BreadcrumbsComponent :breadcrumbs="breadcrumbs" />
    <div class="client-page__header">
      <div @click="$router.go(-1)" class="client-page__back">
        <IconComponent name="arrow-left-line" />
      </div>
      <div class="client-page__name">
        <span class="client-page__title"> {{ fullName }} </span>
        <div
          v-if="moduleStore.isModuleEnabled('b2b') && pageInfo.is_b2b"
          class="status status--blue client-page__b2b-badge"
        >
          B2B
        </div>
        <div v-if="pageInfo.gender_type_id" class="status status--pink client-page__gender">
          <IconComponent v-if="pageInfo.gender_type_id === 1" name="men-line" />
          <IconComponent v-if="pageInfo.gender_type_id === 2" name="women-line" />
        </div>
      </div>
      <div class="client-page__buttons">
        <button @click="openEditModal" class="btn btn--md btn--white client-page__buttons-edit">
          <IconComponent name="pencil-line" />
          <span>Редактировать</span>
        </button>
      </div>
    </div>
    <ClientActivityComponent :info="pageInfo" @operation-success="pollAfterOperation" />
    <div class="client-page-body">
      <div class="client-page-body__main">
        <ClientInfoComponent :info="pageInfo" />
        <ClientOrdersComponent :clientId="id" />
        <ClientBonusHistory v-if="moduleStore.isModuleEnabled('bonuses')" ref="bonusHistory" :clientId="id" />
      </div>
      <div class="client-page-body__aside">
        <BaseWrapper v-if="moduleStore.isModuleEnabled('b2b')">
          <template #title>B2B-статус</template>
          <FlagCheckboxComponent :modelValue="pageInfo.is_b2b" @update:modelValue="toggleB2b" green>
            B2B-клиент
          </FlagCheckboxComponent>
        </BaseWrapper>
        <!--        <ClientPromoCodeComponent :data="promoCode" @promo-remove="promoRemove" @add-promo="addPromo" />-->
        <!--        <BaseWrapper>-->
        <!--          <template #title>Теги</template>-->
        <!--          <div class="client-page-body__tag">-->
        <!--            <SelectComponent-->
        <!--              :options="tags"-->
        <!--              label-name="title"-->
        <!--              v-model="form.tag.value"-->
        <!--              :errors="form.tag.errors"-->
        <!--              multiple-->
        <!--              clearable-->
        <!--              big-->
        <!--            />-->
        <!--            <div class="client-page-body__tag-blur"></div>-->
        <!--          </div>-->
        <!--        </BaseWrapper>-->
        <!--        <ClientCampaignComponent :data="campaign" @campaign-remove="campaignRemove" />-->
        <!--        <ClientNotifications />-->
        <!--        <ClientSegment :data="segment" />-->
      </div>
    </div>
  </main>
</template>

<script>
import BreadcrumbsComponent from "@/components/BreadcrumbsComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import ClientActivityComponent from "@/views/clients/components/ClientActivityComponent.vue";
import BaseWrapper from "@/components/UI/BaseWrapper.vue";
import ClientInfoComponent from "@/views/clients/components/ClientInfoComponent.vue";
import ClientPromoCodeComponent from "@/views/clients/components/ClientPromoCodeComponent.vue";
import ClientCampaignComponent from "@/views/clients/components/ClientСampaignComponent.vue";
import ClientNotifications from "@/views/clients/components/ClientNotifications.vue";
import ClientSegment from "@/views/clients/components/ClientSegment.vue";
import ClientOrdersComponent from "@/views/clients/components/ClientOrdersComponent.vue";
import ClientBonusHistory from "@/views/clients/components/ClientBonusHistory.vue";
import { useDefaultStore } from "@/stores/default.js";
import { shallowRef } from "vue";
import EditClientModal from "@/views/clients/modals/EditClientModal.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import CLIENT from "@/graphql/pages/Client/admin_clients_item.graphql";
import UPDATE_B2B from "@/graphql/pages/Client/updateClientB2bStatus.graphql";
import FlagCheckboxComponent from "@/components/inputs/FlagCheckboxComponent.vue";
import { useModuleConfigStore } from "@/stores/moduleConfig.js";

export default {
  name: "ClientPage",
  inject: ["apollo"],

  mounted() {
    this.get();
  },
  beforeUnmount() {
    this.pollTimers.forEach((timer) => clearTimeout(timer));
  },
  data() {
    return {
      loading: false,
      pageInfo: null,
      pollTimers: [],
      tags: [
        { id: 1, title: "мило" },
        { id: 2, title: "я" },
        { id: 3, title: "небо" },
        { id: 4, title: "мода" },
      ],
      promoCode: [
        { name: "9k0t-jH5z", active: true },
        { name: "4lYM-kFw8", active: false },
        { name: "nOOr-C7lr", active: false },
      ],
      campaign: [{ title: "Акция дня" }, { title: "1+1" }, { title: "После 21.00 30% скидка" }],
      segment: [{ title: "Новички" }, { title: "Без покупок" }],
      form: {
        tag: {
          value: [
            { id: 1, title: "мило" },
            { id: 2, title: "я" },
            { id: 3, title: "небо" },
          ],
          errors: [],
        },
      },
    };
  },
  methods: {
    get() {
      if (!this.loading) {
        this.loading = true;

        this.apollo()
          .admin.query({
            query: CLIENT,
            variables: {
              id: this.id,
            },
            fetchPolicy: "network-only",
          })
          .then(({ data }) => {
            if (data.admin_clients_item) {
              this.pageInfo = data.admin_clients_item;
              this.loading = false;
              // this.removing = null;
            }
          })
          .catch(() => {
            console.log("error");
            this.loading = false;
            // this.removing = null;
          });
      }
    },
    // Операция применяется асинхронно через RabbitMQ: факт возвращается за
    // 2-5 секунд. Перезапрашиваем карточку трижды, чтобы pending сам стал
    // применённым без обновления страницы руками.
    pollAfterOperation() {
      this.get();
      this.$refs.bonusHistory?.refresh();
      this.pollTimers.forEach((timer) => clearTimeout(timer));
      this.pollTimers = [1, 2, 3].map((attempt) =>
        setTimeout(() => {
          this.loading = false;
          this.get();
          this.$refs.bonusHistory?.refresh();
        }, attempt * 2000)
      );
    },
    openEditModal() {
      useDefaultStore().modals.push({
        component: shallowRef(EditClientModal),
        options: {
          clientData: { ...this.pageInfo },
          callback: () => {
            this.loading = false;
            this.get();
          },
        },
      });
    },
    promoRemove() {
      console.log("ok");
    },
    campaignRemove() {
      console.log("ok");
    },
    addPromo(value) {
      console.log(value);
    },
    toggleB2b(value) {
      this.apollo()
        .admin.mutate({
          mutation: UPDATE_B2B,
          variables: {
            clientId: this.id,
            isB2b: value,
          },
        })
        .then(() => {
          this.pageInfo.is_b2b = value;
        })
        .catch(() => {
          console.log("error updating B2B status");
        });
    },
  },
  computed: {
    moduleStore() {
      return useModuleConfigStore();
    },
    id() {
      return parseInt(this.$route.params.id);
    },
    fullName() {
      return `${this.pageInfo?.name ?? ""} ${this.pageInfo?.surname ?? ""}`;
    },
    breadcrumbs() {
      return [
        {
          to: {
            name: "clients",
          },
          title: "Клиенты",
        },
        {
          title: this.fullName,
        },
      ];
    },
  },
  components: {
    FlagCheckboxComponent,
    SelectComponent,
    ClientOrdersComponent,
    ClientBonusHistory,
    ClientSegment,
    ClientNotifications,
    ClientCampaignComponent,
    ClientPromoCodeComponent,
    ClientInfoComponent,
    BaseWrapper,
    ClientActivityComponent,
    IconComponent,
    BreadcrumbsComponent,
  },
};
</script>

<style lang="stylus">
.client-page {

  &__header {
    display flex
    align-items center
    gap 16px
  }

  &__title {
    font-family var(--font-bold)
    font-size: 1.5rem
    font-weight: 500;
    line-height: 34px
  }

  &__back {
    cursor pointer
    width 34px
    height 34px
    background var(--white)
    border: 1px solid var(--gray-light);
    border-radius: 6px;
    display flex
    justify-content center
    align-items center

    & .icon svg {
      width 16px
      height 16px
    }
  }

  &__name {
    display flex
    gap 8px
  }

  &__b2b-badge {
    height 36px
    padding 0 12px
    display flex
    align-items center
    font-weight 600
    font-size 0.8125em
  }

  &__gender {

    width 36px
    height 36px

    & .icon {
      width 20px
      height 20px
    }
  }

  &__buttons {
    display flex
    gap 16px
    margin-left auto
  }

  &__buttons-unload {
    padding: 0 36.5px
  }

  &__buttons-edit {
    padding: 0 20px
  }
}

.client-page-body {
  display grid
  grid-template-columns 3fr 1fr
  grid-gap 16px

  &__main {
    display flex
    flex-direction column
    gap 16px
  }

  &__aside {
    display flex
    flex-direction column
    gap 16px
  }

  &__tag {
    position relative
  }

  &__tag-blur {
    width 100%
    height 32px
    background: linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, #FFFFFF 46.88%);
    position absolute
    bottom: 0
    z-index 2
  }

}
</style>
