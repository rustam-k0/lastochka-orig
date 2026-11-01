@php
  // Изменения в коде в случае если страница только для редактирования
  $shouldPutIdWhenGetting = '';
  $shouldCheckForIdWhenGetting = '';
  $shouldCheckForIdWhenSettingTitle = "'{$data['editName']}'";
  $shouldCheckForIdWhenSubmitting = 'UPDATE';
  $shouldCheckForIdWhenNotifying = "message = data['{$data['graphql']['update']['name']}'].message";
  if (isset($data['editOnly']) && $data['editOnly'] === false) {
    $shouldPutIdWhenGetting = 'variables: { id: parseInt(this.$route.params.id), },';
    $shouldCheckForIdWhenGetting = 'if (!this.$route.params.id) { return; }';
    $shouldCheckForIdWhenSettingTitle = "this.\$route.params.id ? '{$data['editName']}' : '{$data['addName']}'";
    $shouldCheckForIdWhenSubmitting = 'this.$route.params.id ? UPDATE : CREATE';
    $shouldCheckForIdWhenNotifying = "if (this.\$route.params.id) {
      message = data.{$data['graphql']['update']['name']}.message;
    } else {
      message = data.{$data['graphql']['create']['name']}.message;
    }";
  }

  // Код для created apollo запроса на обработку полученных данных
  $receiveFixes = \App\Console\Commands\GeneratorStart::collectReceiveFixes($data['form'], false);
  $receiveFixes .= \App\Console\Commands\GeneratorStart::collectReceiveFixes($data['aside'], false);

  // Поля в data()
  $dataFields = \App\Console\Commands\GeneratorStart::collectDataFields($data['form'], false);
  $dataFields .= \App\Console\Commands\GeneratorStart::collectDataFields($data['aside'], false);

  // Поля формы в data()
  $dataFields .= "form: {";
  $dataFields .= \App\Console\Commands\GeneratorStart::collectFormFields($data['form']);
  $dataFields .= \App\Console\Commands\GeneratorStart::collectFormFields($data['aside']);
  $dataFields .= "},";

  // Код для sumbit запроса на форматирование данных перед отправкой
  $sendFixes = \App\Console\Commands\GeneratorStart::collectSendFixes($data['form'], false);
  $sendFixes .= \App\Console\Commands\GeneratorStart::collectSendFixes($data['aside'], false);

  // Редирект после sumbit
  $redirectTo = 'this.$router.push({ name: "home" });';
  if (isset($data['redirectTo'])) {
    if ($data['redirectTo'] === false) {
      $redirectTo = '';
    } else {
      $redirectTo = "this.\$router.push({ name: '{$data['redirectTo']}' });";
    }
  }
@endphp
<script>
import BreadcrumbsComponent from "@/components/Breadcrumbs.vue";
import AsideComponent from "@/components/AsideComponent.vue";
@include('frontend.imports', $data)
export default {
  name: "{{ $data['name'] }}OpenPage",
  created() {
    this.$apollo
      .query({
        query: GET,
        {!! $shouldPutIdWhenGetting !!}
      })
      .then(({ data }) => {
        this.loading = false;
        {!! $shouldCheckForIdWhenGetting !!}
        this.object = data.{{ $data['graphql']['get']['name'] }};
        Object.keys(this.form).forEach((key) => {
          this.form[key].value = this.object[key];
        });
        {!! $receiveFixes !!}
      })
      .catch(() => {
        this.loading = false;
      });
  },
  data() {
    return {
      saving: false,
      loading: true,
      object: {},
      {!! $dataFields !!}
    };
  },
  computed: {
    pageTitle() {
      return {!! $shouldCheckForIdWhenSettingTitle !!};
    },
  },
  methods: {
    getEditorJSValueForSubmit(value) {
      let val = undefined;
      if (typeof value === "string") {
        val = value;
      } else if (typeof value === "object" && !!value) {
        val = JSON.stringify(value);
      }
      return val;
    },
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
        if (this.object.id) {
          variables.id = this.object.id
        }
        {!! $sendFixes !!}
        this.$apollo
          .mutate({
            mutation: {!! $shouldCheckForIdWhenSubmitting !!},
            variables: variables,
          })
          .then(({ data }) => {
            this.saving = false;
            let message;
            {!! $shouldCheckForIdWhenNotifying !!}
            this.$notify({
              title: "Сохранено",
              text: message,
              type: "success",
            });
            this.$apollo.provider.defaultClient.resetStore();
            {!! $redirectTo !!}
          })
          .catch(({ graphQLErrors }) => {
            this.saving = false;
            this.parseGqlErrors(graphQLErrors);
          });
      }
    },
  },
  components: {
    AsideComponent,
    BreadcrumbsComponent,
    @include('frontend.components', $data)
  },
};
</script>
