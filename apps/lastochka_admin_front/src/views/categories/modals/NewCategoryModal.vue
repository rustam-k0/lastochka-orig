<template>
  <div class="default-modal new-category-modal">
    <div class="default-modal__header">
      <span class="default-modal__title">Новая категория</span>
      <button type="button" class="default-modal__close" @click="$emit('close')">
        <IconComponent name="close-fill" />
      </button>
    </div>
    <!--    <ModalHeader title="Новая категория" />-->
    <div class="default-modal__body new-category-modal__body">
      <InputComponent
        v-model="form.title.value"
        :errors="form.title.errors"
        title="Название категории"
        placeholder="Название категории"
      />
      <CheckboxComponent
        v-if="!isChild && checkboxStore.isEnabled('Category', 'important')"
        v-model="form.important.value"
        green
        class="new-category-modal__important"
      >{{ checkboxStore.getLabel('Category', 'important') }}</CheckboxComponent>
      <CheckboxComponent
        v-if="checkboxStore.isEnabled('Category', 'is_cooking')"
        v-model="form.is_cooking.value"
        green
        class="new-category-modal__important"
      >{{ checkboxStore.getLabel('Category', 'is_cooking') }}</CheckboxComponent>
      <CustomCheckboxesField
        entity="Category"
        v-model="form.custom_checkboxes.value"
        class="new-category-modal__important"
      />
      <div class="new-category-modal__wrapper">
        <p>Фото категории для каталога</p>
        <div class="new-category-modal__images">
          <ImageUploadComponent
            v-model="form.site_catalog_img.value"
            text="Загрузить фото"
            class="image-catalog"
          />
        </div>
      </div>
      <p class="new-category-modal_msg">
        <IconComponent name="information-line" />
        Вы можете загрузить лишь один формат фото из представленных
      </p>
      <div class="new-category-modal__wrapper">
        <p>Фото категории для сайта</p>
        <div class="new-category-modal__images">
          <ImageUploadComponent
            v-model="form.site_square_img.value"
            text="Загрузить фото"
            class="category-square-site"
          />
          <ImageUploadComponent
            v-model="form.site_rect_img.value"
            text="Загрузить фото"
            class="category-rect-site"
          />
        </div>
      </div>
      <div class="new-category-modal__wrapper">
        <p>Фото категории для приложения</p>
        <div class="new-category-modal__images">
          <ImageUploadComponent
            v-model="form.mobile_column_img.value"
            text="Загрузить фото"
            class="category-rect-column"
          />
          <ImageUploadComponent
            v-model="form.mobile_rect_img.value"
            text="Загрузить фото"
            class="category-square-mob"
          />
          <ImageUploadComponent
            v-model="form.mobile_square_img.value"
            text="Загрузить фото"
            class="category-rect-mob"
          />
        </div>
      </div>

      <SelectComponent
        v-model="form.parent.value"
        :errors="form.parent.errors"
        :options="parentCategories"
        label-name="title"
        title="Родительская подкатегория"
        placeholder="Родительская подкатегория"
        :clearable="true"
      />
      <!--      <button class="btn btn&#45;&#45;md">Добавить еще</button>-->
    </div>
    <div class="default-modal__footer">
      <button @click.prevent="remove" v-if="id" class="btn btn--danger btn--md">Удалить</button>
      <button @click.prevent="save" class="btn btn--main btn--md btn--save">Сохранить</button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import SelectComponent from "@/components/inputs/select/input.vue";
import GET from "@/graphql/pages/Category/admin_categories_item.graphql";
import CATEGORIES from "@/graphql/queries/categories.graphql";
import CheckboxComponent from "@/components/inputs/CheckboxComponent.vue";
import CustomCheckboxesField from "@/components/inputs/CustomCheckboxesField.vue";
import UPDATE from "@/graphql/pages/Category/CategoryUpdate.graphql";
import CREATE from "@/graphql/pages/Category/CategoryCreate.graphql";
import REMOVE from "@/graphql/mutations/EntityDelete.graphql";
import { useDefaultStore } from "@/stores/default.js";
import { useCheckboxConfigStore } from "@/stores/checkboxConfig.js";
import ImageUploadComponent from "@/components/ImageUpload.vue";
import { parseCustomCheckboxes } from "@/utils.js";
// import ModalHeader from "@/components/modals/components/ModalHeader.vue";
export default {
  name: "NewCategoryModal",
  inject: ["apollo"],
  props: {
    id: {
      type: Number,
    },
    callback: Function,
  },
  components: {
    ImageUploadComponent,
    CheckboxComponent,
    CustomCheckboxesField,
    // ModalHeader,
    SelectComponent,
    InputComponent,
    IconComponent,
  },
  data() {
    return {
      parentCategories: [],
      form: {
        title: {
          value: null,
          errors: [],
        },
        parent: {
          value: null,
          errors: [],
        },
        important: {
          value: false,
          errors: [],
        },
        is_cooking: {
          value: null,
          errors: null,
        },
        custom_checkboxes: {
          value: {},
          errors: [],
        },
        site_catalog_img: {
          value: null,
          errors: [],
        },
        site_square_img: {
          value: null,
          errors: [],
        },
        site_rect_img: {
          value: null,
          errors: [],
        },
        mobile_column_img: {
          value: null,
          errors: [],
        },
        mobile_square_img: {
          value: null,
          errors: [],
        },
        mobile_rect_img: {
          value: null,
          errors: [],
        },
      },
    };
  },
  mounted() {
    this.get();
  },
  methods: {
    get() {
      let variables = {
        id: this.id,
      };
      this.apollo()
        .admin.query({
          query: this.id ? GET : CATEGORIES,
          variables: variables,
        })
        .then(({ data }) => {
          if (data.admin_categories_item && this.id) {
            let info = data.admin_categories_item;
            Object.keys(this.form).forEach((key) => {
              this.form[key].value = info[key];
            });
            this.form.custom_checkboxes.value = parseCustomCheckboxes(info.custom_checkboxes);
          }
          this.parentCategories = data.categories;
        })
        .catch(() => {
          console.log("error");
        });
    },
    save() {
      let variables = {};
      Object.keys(this.form).forEach((key) => {
        variables[key] = this.form[key].value ? this.form[key].value : undefined;
      });
      this.id ? (variables["id"] = this.id) : null;
      variables["parent"] = this.form.parent.value ? this.form.parent.value.id : null;
      variables["important"] = this.isChild ? false : this.form.important.value;
      variables.custom_checkboxes = JSON.stringify(this.form.custom_checkboxes.value || {});
      this.apollo()
        .admin.mutate({
          mutation: this.id ? UPDATE : CREATE,
          variables: variables,
        })
        .then(() => {
          if (this.callback) {
            this.callback();
          }
          useDefaultStore().modals = [];
        })
        .catch(() => {
          console.log("error");
        });
    },
    remove() {
      if (confirm("Вы уверены?")) {
        this.apollo()
          .admin.mutate({
            mutation: REMOVE,
            variables: {
              id: this.id,
              table: "categories",
            },
          })
          .then(() => {
            if (this.callback) {
              this.callback();
            }
            useDefaultStore().modals = [];
          });
      }
    },
  },
  computed: {
    checkboxStore() {
      return useCheckboxConfigStore();
    },
    isChild() {
      return !!this.form.parent.value;
    },
  },
};
</script>

<style lang="stylus">
.new-category-modal {

  display flex
  flex-direction column
  background-color var(--white)
  border-radius: 16px;
  max-width 608px

  &__body {
    gap 16px
  }

  p {
    font-weight: 400;
    font-size: 0.8125em;
    line-height: 20px;
    color: var(--dark);
    margin 0
  }
  &_msg {
    display: flex;
    align-items: center;
    gap 8px
    padding 16px 11px
    width: 100%;
    background: var(--blue-light);
    border-radius: 16px;
    svg{
      max-height: 24px;
    }
  }

  & .btn--save {
    margin-left auto
  }

   .textarea__field-label {
     border 1px solid var(--white-2)
   }

  &__wrapper {
    display flex
    flex-direction column
    gap 16px
  }

  &__images {
    display flex
    gap 16px
  }

  & .image-catalog {
      width 80px
      height 80px
  }

  & .category-square-site, .category-square-mob {
      width 117px
      height 117px
  }

  & .category-rect-site {
      width 226px
      height 117px
  }

  & .category-rect-mob {
    width 160px
    height 117px
  }

  & .category-rect-column {
      width 73px
      height 117px
  }

  &__important {

    & .checkbox__field {
      font-style: normal;
      font-weight: 400;
      font-size: 0.813rem
      line-height: 140%;
      color: var(--black-2)
    }
  }
}
</style>
