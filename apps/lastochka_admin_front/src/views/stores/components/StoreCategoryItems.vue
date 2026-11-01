<template>
  <ul class="category-items">
    <template v-if="draggable">
      <draggable :key="listVersion" v-model="localValue" @update:model-value="update" class="category-items" transition="100">
        <template v-slot:item="{ item }">
          <li>
            <div class="category-items__left">
              <span class="category-items__number">{{ localValue.indexOf(item) + 1 }}</span>
              <IconComponent name="menu-line" class="category-items__drag" />
              <div class="category-items__titles">
                <span class="category-items__title">{{ item.title }}</span>
                <span class="category-items__caption">Категория магазина</span>
              </div>
            </div>
            <div class="category-items__right">
              <div class="category-items__time">
                <InputComponent v-model="item.shop_category.time_to_take" @blur="update" type="number" />
                <span>сек</span>
              </div>
              <button
                type="button"
                class="category-items__delete"
                title="Удалить секцию"
                @click="removeSection(item)"
              >
                <IconComponent name="delete-bin-line" />
              </button>
            </div>
          </li>
        </template>
      </draggable>
    </template>
    <template v-else>
      <li v-for="(item, i) in localValue" :key="i">
        <div class="category-items__inner">
          <span class="category-items__number">
            {{ i + 1 }}
          </span>
          <div class="category-items__left">
            <span>{{ item.title }}</span>
          </div>
        </div>
        <div class="category-items__right">
          <span>{{ item.shop_category.time_to_take }}</span>
          <span>сек</span>
        </div>
      </li>
    </template>
  </ul>
</template>

<script>
import InputComponent from "@/components/inputs/InputComponent.vue";
import IconComponent from "@/components/IconComponent.vue";
import Draggable from "vue3-draggable";
import UPDATE from "@/graphql/pages/Stores/SetShopCategoriesTime.graphql";
import DELETE_SECTION from "@/graphql/pages/Stores/ShopCategorySectionDelete.graphql";

export default {
  name: "StoreCategoryItems",
  inject: ["apollo"],
  components: {
    InputComponent,
    IconComponent,
    Draggable,
  },
  props: {
    draggable: {
      type: Boolean,
      default: false,
    },
    items: Array,
  },
  emits: ["reload"],
  data() {
    return {
      localValue: this.cloneSorted(this.items),
      // vue3-draggable снимает копию modelValue ОДИН раз в setup и не следит за
      // внешними заменами массива. Без принудительного remount через :key список,
      // смонтированный до прихода данных (прямой заход/F5 на ?tab=map), навсегда
      // остаётся пустым, хотя localValue уже заполнен (MID-202).
      listVersion: 0,
    };
  },
  watch: {
    // После reload родителя (добавление / удаление секции) приходит новый массив
    // секций — переинициализируем локальную копию, иначе список остался бы старым.
    // На drag / inline-время родитель НЕ перезапрашивается, поэтому watcher не мешает
    // несохранённым правкам.
    items(val) {
      this.localValue = this.cloneSorted(val);
      this.listVersion++;
    },
  },
  methods: {
    cloneSorted(items) {
      // slice() перед sort — sort мутирует массив на месте, нельзя трогать проп.
      return JSON.parse(
        JSON.stringify((items || []).slice().sort((a, b) => a.shop_category.position - b.shop_category.position))
      );
    },
    buildItems() {
      // Шлём ВЕСЬ список, чтобы position (порядок обхода магазина сборщиком) был
      // консистентным у всех — иначе категории без времени остаются с position=0 и
      // всплывают наверх. Пустое время — это 0 (бэкенд-валидатор допускает min:0).
      return this.localValue.map((item, index) => ({
        category_id: item.id,
        time_to_take: parseInt(item.shop_category.time_to_take) || 0,
        position: index,
      }));
    },
    persist() {
      const items = this.buildItems();
      if (!items.length) {
        return Promise.resolve(true);
      }
      return this.apollo()
        .admin.mutate({
          mutation: UPDATE,
          variables: {
            shop_id: parseInt(this.$route.params.id),
            items: items,
          },
        })
        .then(({ data }) => {
          if (data.SetShopCategoriesTime && data.SetShopCategoriesTime.code === 500) {
            alert("Не удалось сохранить изменения");
            return false;
          }
          return true;
        })
        .catch(() => {
          console.log("error");
          return false;
        });
    },
    update() {
      // Inline-время / drag — сохраняем без reload родителя (чтобы не затирать
      // несохранённые правки формы на других вкладках, см. T13 с зонами).
      this.persist();
    },
    // Вызывается родителем (AssemblyMapTab) при добавлении секции — берём АКТУАЛЬНЫЙ
    // порядок из localValue (учитывает несохранённый drag), добавляем новую запись и
    // сохраняем полным списком через SetShopCategoriesTime (upsert pivot на бэке).
    appendSection(category, time) {
      this.localValue.push({
        id: category.id,
        title: category.title,
        shop_category: {
          time_to_take: parseInt(time) || 0,
          position: this.localValue.length,
        },
      });
      return this.persist().then((ok) => {
        if (ok) {
          this.$emit("reload");
        } else {
          // Откат оптимистичного добавления при ошибке сохранения.
          this.localValue.pop();
        }
        return ok;
      });
    },
    removeSection(item) {
      if (
        !window.confirm(
          "Секция будет удалена вместе с привязкой категории к магазину. Товары категории перестанут отображаться клиенту. Продолжить?"
        )
      ) {
        return;
      }
      this.apollo()
        .admin.mutate({
          mutation: DELETE_SECTION,
          variables: {
            shop_id: parseInt(this.$route.params.id),
            category_id: parseInt(item.id),
          },
        })
        .then(({ data }) => {
          if (data.ShopCategorySectionDelete && data.ShopCategorySectionDelete.code === 500) {
            alert("Не удалось удалить секцию");
            return;
          }
          this.$emit("reload");
        })
        .catch(() => {
          alert("Не удалось удалить секцию");
        });
    },
  },
};
</script>

<style lang="stylus">
.category-items {

  display flex
  flex-direction column
  gap 8px

  li {
    display flex
    align-items center
    justify-content space-between
    padding 16px 20px
    border-radius 16px
    border 1px solid var(--gray-light)



    div {
      display flex

      span {
        display flex
        align-items center
        line-height 18px
      }
    }

    .icon {
      cursor move
      width 20px
      height 20px
    }
  }

  &__right {
    display flex
    align-items center
    gap 12px

    span {
      color #8C8C8C
      font-size 0.813em

      &:nth-child(1) {
        color var(--dark)
        font-weight 500
      }
    }

    .input__field {
      width 60px
      height 26px !important
      border-radius 6px
    }

    .input__field-label {
      border 1px solid var(--gray-dark-3)
    }
  }

  &__time {
    display flex
    align-items center
    gap 4px
  }

  &__delete {
    display inline-flex
    align-items center
    justify-content center
    width 32px
    height 32px
    padding 0
    border 1px solid var(--gray-light)
    border-radius 8px
    background var(--white)
    cursor pointer
    transition background .15s, border-color .15s

    &:hover {
      background var(--red-o15, #FDECEC)
      border-color var(--red, #E5484D)
    }

    .icon {
      cursor pointer
      width 18px
      height 18px

      svg path {
        fill var(--red, #E5484D)
      }
    }
  }

  &__left {
    display flex
    align-items center
    gap 10px
    font-size 0.813em
    font-weight 500
  }

  &__titles {
    display flex
    flex-direction column
    gap 2px
  }

  &__title {
    color var(--dark)
    font-weight 500
    line-height 18px
  }

  &__caption {
    color #8C8C8C
    font-size 0.75rem
    font-weight 400
    line-height 16px
  }

  &__inner {
    display flex
    gap 32px
  }

  &__number {
    display inline-flex
    align-items center
    justify-content center
    min-width 24px
    height 24px
    padding 0 6px
    border-radius 12px
    background var(--gray-dark-3)
    color var(--dark)
    font-size 0.813rem
    font-weight 600
    line-height 1
    flex-shrink 0
  }
}
</style>
