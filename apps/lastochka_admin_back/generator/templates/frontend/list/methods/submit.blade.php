@php
  $listReset = "this.list = [];";
  $initialVariables = "{ ordering: this.ordering, }";
  if (isset($data['pagination']) && $data['pagination']) {
    $listReset = "this.list.data = [];";
    $initialVariables = "{ first: this.first, page: this.page, ordering: this.ordering, }";
  }
  $sendFixes = '';
  if (isset($data['form']) && count($data['form'])) {
    $sendFixes = \App\Console\Commands\GeneratorStart::collectSendFixes($data['form'], false);
  }
  $queryName = $data['graphql']['get']['name']
@endphp
submit() {
  if (!this.loading) {
    this.loading = true;
    {!! $listReset !!}
    let variables = {!! $initialVariables !!};
    @if(isset($data['form']) && count($data['form']))
      Object.keys(this.form).forEach((k) => {
        variables[k] = this.form[k].value !== null ? this.form[k].value : undefined;
      });
    @endif
    {!! $sendFixes !!}
    this.$apollo
      .query({
        query: GET,
        variables: variables,
      })
      .then(({ data }) => {
        if (data && data['{{ $queryName }}']) {
          this.list = data['{{ $queryName }}'];
        }
        this.loading = false;
      })
      .catch(() => {});
  }
},
