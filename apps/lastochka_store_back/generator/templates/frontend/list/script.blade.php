@php
  // Наличие полей-фильтров
  $inputs = isset($data['form']) && count($data['form']);

  // JS Методы
  $methods = '';

  // Метод получения данных
  $methods .= view('frontend.list.methods.submit', ['data' => $data])->render();

  // Метод сортировки
  $methods .= view('frontend.list.methods.changeSort', ['data' => $data])->render();

  // Метод смены статуса (удалить, скрыть)
  if (isset($data['changeStatus'])) {
    $methods .= view('frontend.list.methods.changeStatus')->render();
  }

  // Метод вызова модалки
  if (isset($data['action']) && $data['action']['type'] === 'modal') {
    $methods .= view('frontend.list.methods.showModal')->render();
  }

  // Метод сброса формы если есть фильтры
  if ($inputs) {
    $methods .= view('frontend.list.methods.reset')->render();
  }

  // Собственные методы
  if (isset($data['methods'])) {
    foreach ($data['methods'] as $methodName) {
      $methods .= view("frontend.list.methods.$methodName", ['data' => $data])->render();
    }
  }



  // Поля data()
  $dataFields = '';

  if ($inputs) {
    // Поля полей фильтра в data()
    $dataFields .= \App\Console\Commands\GeneratorStart::collectDataFields($data['form'], false);

    // Поля формы фильтра в data()
    $dataFields .= "form: {";
    $dataFields .= \App\Console\Commands\GeneratorStart::collectFormFields($data['form']);
    $dataFields .= "},";
  }

  // Хлебные крошки для data()
  if (isset($data['breadcrumbs'])) {
    $array = json_encode($data['breadcrumbs'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $dataFields .= "breadcrumbs: $array," ;
  } else {
    $dataFields .= 'breadcrumbs: [],';
  }

  // Столбцы таблицы для data()
  if (isset($data['columns'])) {
    $dataFields .= "columns: [";
    foreach ($data['columns'] as $column) {
      $dataFields .= "{ ";
      if (isset($column['label'])) {
        $dataFields .= "label: '{$column['label']}',";
      }
      if (isset($column['field'])) {
        $dataFields .= "field: '{$column['field']}',";
      }
      if (isset($column['width'])) {
        $dataFields .= "width: '{$column['width']}',";
      }
      if (isset($column['sortable'])) {
        $sortable = $column['sortable'] ? 'true' : 'false';
        $dataFields .= "sortable: $sortable,";
      }
      $dataFields .= " },";
    }
    $dataFields .= "],";
  }

  // Поле изначальной сортировки в data()
  if (isset($data['initialSorting'])) {
    $dataFields .= "ordering: [{ orderColumn: '{$data['initialSorting']['column']}', orderBy: '{$data['initialSorting']['by']}',},],";
  }
  // Обработка случая когда есть пагинация
  if (isset($data['pagination']) && $data['pagination']) {

    // Поля пагинации в data() а также поле list отвечающее за содержимое таблицы
    $dataFields .= "list: { data: [], paginatorInfo: { total: 0, },}, page: 1, first: 20,";

    // Методы пагинации
    $methods .= view('frontend.list.methods.pagination')->render();
  } else {
    // Поле list в data() если нет пагинации
    $dataFields .= "list: [],";
  }
@endphp
<script>
import BreadcrumbsComponent from "@/components/Breadcrumbs";
import TableComponent from "@/components/TableComponent.vue";
import LoadingIndicator from "@/components/LoadingIndicator.vue";
@if(isset($data['changeStatus']))
import Icon from "@/components/IconComponent.vue";
@endif
@if($inputs)
import FiltersRow from "@/components/FiltersRow.vue";
@endif
@include('frontend.imports', $data)

export default {
  name: "{{ $data['name'] }}ListPage",
  data() {
    return {
      loading: false,
      {!! $dataFields !!}
    };
  },
  computed: {
    vgtFormattedOrdering() {
      return this.ordering.map((o) => ({
        field: o.orderColumn,
        type: o.orderBy.toLowerCase(),
      }));
    },
  },
  mounted() {
    this.submit();
    // const urlParams = new URLSearchParams(window.location.search);
    // history.replaceState(null, "", location.pathname + urlParams);
  },
  methods: {
    {!! $methods !!}
  },
  components: {
    LoadingIndicator,
    TableComponent,
    BreadcrumbsComponent,
    @if(isset($data['changeStatus']))
      Icon,
    @endif
    @if($inputs)
      FiltersRow,
    @endif
    @include('frontend.components', $data)
  },
};
</script>
