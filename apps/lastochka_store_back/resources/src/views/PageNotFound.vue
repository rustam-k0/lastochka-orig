<template>
  <main class="main" role="main">
    <main class="page page404">
      <div class="container">
        <h1><span>Ошибка 404:</span> Страница не найдена</h1>
        <p>Такой страницы не существует</p>
        <router-link :to="{ name: 'home' }" class="btn btn--main-outline">
          Перейти на главную страницу
        </router-link>
      </div>
    </main>
  </main>
</template>

<script>
export default {
  name: "PageNotFound",
};
</script>

<style lang="stylus">
@import '~@/styles/laravel-mix-fix.styl';

.page404 {
  .container {
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
  }

  h1 {
    font-size: 2.24em;
    margin: 0;
    font-weight: 900;
    background: -webkit-linear-gradient($main_color, darken($main_color, 20%));
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    user-select: none;

    span {
      font-weight: 900;
      background: $dark;
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }
  }

  .btn {
    font-weight: bold;
  }

  p {
    max-width: 300px;
    text-align: center;
    font-size: 1.5em;
    margin: 15px 0 20px;
  }
}
</style>
