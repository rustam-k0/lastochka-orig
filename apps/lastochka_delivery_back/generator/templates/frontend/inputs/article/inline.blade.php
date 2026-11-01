@php
  $title = $data['title'];
  $field = $data['field'];
@endphp
<div class="form__item">
  <b>{{ $title }}</b>
  <EditorJSRenderer :text="object['{{ $field }}'] | getArticle" />
</div>
