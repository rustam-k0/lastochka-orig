@php
  $title = $data['title'];
  $value = '';
  foreach ($data['fields'] as $item) {
    $excluded = collect(['row', 'column', 'array']);
    if (isset($item['editable']) && $item['editable'] && !$excluded->contains($item['type'])) {
      $value .= view('frontend.inputs.'. $item['type'] .'.markup', [
        'data' => $item,
        'form' => 'form'
      ])->render();
    } else {
      $value .= view('frontend.inputs.'. $item['type'] .'.inline', [
        'data' => $item,
        'onlyInline' => false,
      ])->render();
    }
  }

  $canSave = true;
  if (isset($data['canSave'])) {
      $canSave = $data['canSave'];
  }
@endphp
<template>
  <div class="modal-body form-modal">
    <div class="modal-body__header">
      <span class="modal-body__title">{{ $title }}</span>
      <button type="button" class="modal-body__close" @click="$emit('close')">
        <IconComponent name="close-line" category="system" class="icon" />
      </button>
    </div>
    <div class="modal-body__content">
      <div class="form__inputs">
        {!! $value !!}
      </div>
      @if($canSave)
        <button type="button" @click="submit" class="btn btn--main btn--small">
          <LoadingIndicator v-if="saving" title="Подождите" />
          <span v-else>Сохранить</span>
        </button>
      @endif
    </div>
  </div>
</template>
