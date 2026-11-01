<template>
  <div class="modal-body form-modal">
    <div class="modal-body__header">
      <span class="modal-body__title">Просмотр</span>
      <button type="button" class="modal-body__close" @click="$emit('close')">
        <IconComponent name="close-line" category="system" class="icon" />
      </button>
    </div>
    <div class="modal-body__content">
      <div class="form__inputs">
        <InputComponent
          v-model.trim="form['title'].value"
          :errors="form['title'].errors"
          title="Заголовок"
          autofocus
        />
        <InputComponent
          v-model.trim="form['price'].value"
          :errors="form['price'].errors"
          title="Цена"
          type="number"
          :max="10"
          :min="0"
        />
        <TextareaComponent
          v-model.trim="form['description'].value"
          :errors="form['description'].errors"
          title="Описание"
          rows="5"
        />
        <div class="form__item">
          <b>Статья</b>
          <EditorJSRenderer :text="object['article'] | getArticle" />
        </div>
        <ImageCropComponent
          v-model.trim="form['head_img'].value"
          :errors="form['head_img'].errors"
          title="Главная картинка"
        />
        <div class="form__row">
          <div class="form__item">
            <b>Дата обучения</b>
            <span>{{ object["study_period"] | formatDate }}</span>
          </div>
          <FileUploadComponent
            :uploaded="form['video'].value"
            @change="form['video'].value = $event"
            title="Видео"
          />
        </div>
        <div class="form__item">
          <b>Модули</b>
          <div class="form__inputs">
            <div class="form__col">
              <div class="form__item">
                <b>Главная картинка</b>
                <a
                  v-if="object['head_img']"
                  target="_blank"
                  class="img"
                  rel="nofollow"
                  :href="object['head_img'].path"
                >
                  <img :src="object['head_img'].path" alt="Главная картинка" />
                </a>
              </div>
              <div class="form__row">
                <div class="form__item">
                  <b>Тег модуля</b>
                  <span v-if="object['moduleTag']">{{
                    object["moduleTag"].map((v) => v[title]).join(", ")
                  }}</span>
                </div>
                <div class="form__item">
                  <b>Дата обучения</b>
                  <span>{{ object["study_period"] | formatDate }}</span>
                </div>
                <div class="form__item">
                  <b>Видео</b>
                  <a
                    target="_blank"
                    class="link"
                    v-if="object['video']"
                    rel="nofollow"
                    :href="object['video'].path"
                  >
                    <span>{{ object["video"].origin_name }}</span>
                    <IconComponent category="system" name="external-link-line" />
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <button type="button" @click="submit" class="btn btn--main btn--small">
        <LoadingIndicator v-if="saving" title="Подождите" />
        <span v-else>Сохранить</span>
      </button>
    </div>
  </div>
</template>

<script>
import IconComponent from "@/components/IconComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
import EditorJSRenderer from "@/components/EditorJSComponent.vue";
import InputComponent from "@/components/inputs/InputComponent.vue";
import TextareaComponent from "@/components/inputs/TextareaComponent.vue";
import ImageCropComponent from "@/components/inputs/ImageCropComponent.vue";
import FileUploadComponent from "@/components/inputs/FileUploadComponent.vue";
import moment from "moment";
import UPDATE from "@/graphql/generated/homemodal/CourseUpdate.graphql";

export default {
  name: "HomeModalModal",
  props: {
    object: Object,
  },
  created() {
    Object.keys(this.form).forEach((key) => {
      this.form[key].value = this.object[key];
      if (this.object["head_img"]) {
        let img = this.object["head_img"];
        this.form["head_img"].value = {
          id: img.id,
          path: img.path,
          origin_name: img.origin_name,
          alt: img.alt,
          size: img.size,
          type: img.type,
        };
      }
      if (this.object["study_period"]) {
        this.form["study_period"].value = {
          start: moment(this.object["study_period_start_date"]).toDate(),
          end: moment(this.object["study_period_end_date"]).toDate(),
        };
      }
      this.form["video"].value = [this.object["video"]].filter((v) => !!v).flat();
      if (this.object["modules"]) {
        this.form["modules"].value = this.object["modules"].map((t) => ({
          id: t.id,
          head_img: { value: t["head_img"], defaultValue: null, errors: [] },
          moduleTag: {
            value: {
              id: t["moduleTag"]["id"],
              title: t["moduleTag"]["title"],
            },
            defaultValue: null,
            errors: [],
          },
          study_period: {
            value: {
              start: moment(t["study_period_start_date"]).toDate(),
              end: moment(t["study_period_end_date"]).toDate(),
            },
            defaultValue: null,
            errors: [],
          },
          video: { value: [t["video"]].flat(), defaultValue: null, errors: [] },
        }));
      }
      if (this.object["modules2"]) {
        this.form["modules2"].value = this.object["modules2"].map((t) => ({
          id: t.id,
          head_img: { value: t["head_img"], defaultValue: null, errors: [] },
          video: { value: [t["video"]].flat(), defaultValue: null, errors: [] },
          article: { value: t["article"], defaultValue: null, errors: [] },
        }));
      }
    });
  },
  data() {
    return {
      saving: false,
      form: {
        title: { value: null, defaultValue: null, errors: [] },
        price: { value: null, defaultValue: null, errors: [] },
        description: { value: null, defaultValue: null, errors: [] },
        article: { value: null, defaultValue: null, errors: [] },
        head_img: { value: null, defaultValue: null, errors: [] },
        study_period: { value: null, defaultValue: null, errors: [] },
        video: { value: [], defaultValue: [], errors: [] },
        modules: { value: [], defaultValue: [], errors: [] },
        modules2: { value: [], defaultValue: [], errors: [] },
      },
    };
  },
  methods: {
    reset() {
      Object.keys(this.form).forEach((key) => {
        this.form[key].value = this.form[key].defaultValue;
        this.form[key].errors = [];
      });
    },
    parseGqlErrors(graphQLErrors, tail) {
      graphQLErrors.forEach((err) => {
        if (err.extensions.category === "validation") {
          Object.keys(err.extensions.validation).forEach((key) => {
            let newKey = key.replace(tail + ".", "");
            if (newKey.indexOf(".") === -1) {
              if (this.form[newKey]) {
                this.form[newKey].errors = err.extensions.validation[key];
              }
            }
          });
        }
      });
    },
    submit() {
      if (!this.saving) {
        this.saving = true;
        let variables = {};
        Object.keys(this.form).forEach((k) => {
          variables[k] = this.form[k].value !== null ? this.form[k].value : undefined;
        });
        variables.id = this.object.id;
        variables["price"] = undefined;
        if (this.form["price"].value) {
          variables["price"] = parseInt(this.form["price"].value);
        }
        variables["article"] = this.getEditorJSValueForSubmit(this.form["article"].value);
        variables["study_period"] = undefined;
        if (this.form["study_period"].value) {
          variables["study_period_start_date"] = moment(this.form["study_period"].value.start).format(
            "YYYY-MM-DD HH:mm:ss"
          );
          variables["study_period_end_date"] = moment(this.form["study_period"].value.end).format(
            "YYYY-MM-DD HH:mm:ss"
          );
        }
        variables["video"] = undefined;
        if (this.form["video"].value.length) {
          variables["video"] = this.form["video"].value[0];
        }
        variables["modules"] = this.form["modules"].value.map((o) => ({
          id: o.id,
          head_img: o["head_img"].value ? o["head_img"].value : undefined,
          moduleTag: o["moduleTag"].value ? o["moduleTag"].value["id"] : undefined,
          study_period_start_date: o["study_period"].value
            ? moment(o["study_period"].value.start).format("YYYY-MM-DD HH:mm:ss")
            : undefined,
          study_period_end_date: o["study_period"].value
            ? moment(o["study_period"].value.end).format("YYYY-MM-DD HH:mm:ss")
            : undefined,
          video: o["video"].value.length ? o["video"].value[0] : undefined,
        }));
        variables["modules2"] = this.form["modules2"].value.map((o) => ({
          id: o.id,
          head_img: o["head_img"].value ? o["head_img"].value : undefined,
          video: o["video"].value.length ? o["video"].value[0] : undefined,
          article: this.getEditorJSValueForSubmit(o["article"].value),
        }));

        this.$apollo
          .mutate({
            mutation: UPDATE,
            variables: variables,
          })
          .then(({ data }) => {
            this.saving = false;
            this.$notify({
              title: "Сохранено",
              text: data["CourseUpdate"].message,
              type: "success",
            });
            this.$apollo.provider.defaultClient.resetStore();
            this.$emit("close");
          })
          .catch(({ graphQLErrors }) => {
            this.saving = false;
            this.parseGqlErrors(graphQLErrors);
          });
      }
    },
  },
  components: {
    IconComponent,
    LoadingIndicator,
    EditorJSRenderer,
    InputComponent,
    TextareaComponent,
    ImageCropComponent,
    FileUploadComponent,
  },
};
</script>
