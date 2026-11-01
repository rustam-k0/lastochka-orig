@php
  // Поля для формы
  $inputs = '';
  foreach ($data['form'] as $item) {
      $inputs .= view('frontend.inputs.'. $item['type'] .'.markup', [
        'data' => $item,
        'form' => 'form'
      ])->render();
  }
  // Поля для сайдбара
  $asideInputs = '';
  foreach ($data['aside'] as $item) {
      $asideInputs .= view('frontend.inputs.'. $item['type'] .'.markup', [
        'data' => $item,
        'form' => 'form'
      ])->render();
  }
@endphp
<template>
  <main class="main">
    <BreadcrumbsComponent :title="pageTitle"/>
    <div class="page">
      <div class="container form">
        <div class="form__inputs">
          {!! $inputs !!}
        </div>
        <AsideComponent :loading="saving" @reset="reset" @submit="submit">
          {!! $asideInputs !!}
        </AsideComponent>
      </div>
    </div>
  </main>
</template>
