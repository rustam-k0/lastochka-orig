@php
  $title = $data['title'];
  $field = $data['field'];
  $value  = "{{ object['$field'].origin_name }}";
@endphp
<div class="form__item">
  <b>{{ $title }}</b>
  <a v-if="object['{{ $field }}']" target="_blank" class="img" rel="nofollow" :href="object['{{ $field }}'].path">
    <img :src="object['{{ $field }}'].path" alt="{{ $title }}" />
  </a>
</div>
