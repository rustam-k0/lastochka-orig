@php
  $title = $data['title'];

  // Поля для формы
  $inputs = isset($data['form']) && count($data['form']) ? '' : false;
  if (isset($data['form']) && count($data['form'])) {
    foreach ($data['form'] as $item) {
        $inputs .= view('frontend.inputs.'. $item['type'] .'.markup', [
          'data' => $item,
          'form' => 'form'
        ])->render();
    }
  }

  $columns = '';
  foreach ($data['columns'] as $column) {
    $columns .= view('frontend.list.columns.'. $column['type'], [
      'data' => $column,
      'global' => $data,
    ])->render();
  }

  // Изменения в верстке в случае если есть пагинация
  $page = '';
  $first = '';
  $rows = ':rows="list"';
  $total = '';
  $pagination = '';
  if (isset($data['pagination']) && $data['pagination']) {
    $page = ':page="page"';
    $first = ':first="first"';
    $rows = ':rows="list.data"';
    $total = ':total="list.paginatorInfo.total"';
    $pagination = '@change="changeFirst" @paginate="changePage" pagination';
  }
@endphp
<template>
  <main class="main">
    <BreadcrumbsComponent title="{{ $title }}" :links="breadcrumbs" />
    <div class="page">
      <div class="container list">
        @if($inputs)
          <FiltersRow :loading="loading" @reset="reset" @submit="submit">
            {!! $inputs !!}
          </FiltersRow>
        @endif
        <TableComponent
          :columns="columns"
          :initialSortBy="vgtFormattedOrdering"
          @sort="changeSort"
          {!! $page !!}
          {!! $first !!}
          {!! $rows !!}
          {!! $total !!}
          {!! $pagination !!}
        >
          <div slot="empty" class="td-container">
            <span class="table-span">
              <LoadingIndicator v-if="loading" />
              <template v-else>Данных нет</template>
            </span>
          </div>
          <template slot="table-row" slot-scope="{ row, column }">
            {!! $columns !!}
          </template>
        </TableComponent>
      </div>
    </div>
  </main>
</template>
