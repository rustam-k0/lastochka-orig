@php
  $title = $data['title'];
  $field = $data['field'];
  $value  = "{{ object['$field'].origin_name }}";
@endphp
<div class="form__item">
  <b>{{ $title }}</b>
  <a target="_blank" class="link" v-if="object['{{ $field }}']" rel="nofollow" :href="object['{{ $field }}'].path">
    <span>{!! $value !!}</span>
    <IconComponent category="system" name="external-link-line" />
  </a>
</div>
