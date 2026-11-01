@php
  // Код для created на обработку данных полученных через props
  $receiveFixes = \App\Console\Commands\GeneratorStart::collectReceiveFixes($data['fields'], false);

  // Поля формы в data()
  $dataFields = "form: {";
  $dataFields .= \App\Console\Commands\GeneratorStart::collectFormFields($data['fields']);
  $dataFields .= "},";

  // Код для sumbit запроса на форматирование данных перед отправкой
  $sendFixes = \App\Console\Commands\GeneratorStart::collectSendFixes($data['fields'], false);

  $canSave = true;
  if (isset($data['canSave'])) {
      $canSave = $data['canSave'];
  }
@endphp
<script>
import IconComponent from "@/components/IconComponent.vue";
@if($canSave)
import LoadingIndicator from "@/components/LoadingIndicator.vue";
@endif
@include('frontend.imports', $data)

export default {
  name: "{{ $data['name'] }}Modal",
  props: {
    object: Object,
  },
  created() {
    Object.keys(this.form).forEach((key) => {
      this.form[key].value = this.object[key];
      {!! $receiveFixes !!}
    });
  },
  data() {
    return {
      saving: false,
      {!! $dataFields !!}
    };
  },
  @if($canSave)
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
      @if(isset($data['graphql']))
      submit() {
        if (!this.saving) {
          this.saving = true;
          let variables = {};
          Object.keys(this.form).forEach((k) => {
            variables[k] = this.form[k].value !== null ? this.form[k].value : undefined;
          });
          variables.id = this.object.id;
          {!! $sendFixes !!}
          this.$apollo
            .mutate({
              mutation: UPDATE,
              variables: variables,
            })
            .then(({ data }) => {
              this.saving = false;
              this.$notify({
                title: "Сохранено",
                text: data['{!! $data['graphql']['update']['name'] !!}'].message,
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
      @endif
    },
  @endif
  components: {
    IconComponent,
    @if($canSave)
    LoadingIndicator,
    @endif
    @include('frontend.components', $data)
  },
};
</script>
