import { defineConfig, loadEnv } from "vite";
import vue from "@vitejs/plugin-vue";
import stylusAliasesPlugin from "./plugins/stylus-aliases.js";
import path from "path";
import rupture from "rupture";
import graphql from "@rollup/plugin-graphql";

const env = loadEnv("", process.cwd(), "");
// Vite 4 не меняет хэш входного файла, когда меняется только CSS ленивого раздела: имя index-*.js остаётся прежним,
// браузер берёт его из кэша (assets кэшируются на полгода) и грузит удалённый CSS. Номер сборки в имени
// входного файла даёт новое имя на каждой выкладке (CI_COMMIT_SHORT_SHA в CI, иначе время сборки).
const buildId = process.env.CI_COMMIT_SHORT_SHA || Date.now().toString(36);
// https://vitejs.dev/config/
export default defineConfig({
  plugins: [vue(), stylusAliasesPlugin(), graphql()],
  server: {
    port: env.PORT,
  },
  css: {
    preprocessorOptions: {
      stylus: {
        use: [rupture()],
      },
    },
  },
  build: {
    rollupOptions: {
      output: {
        entryFileNames: `assets/[name]-[hash]-${buildId}.js`,
      },
    },
  },
  resolve: {
    alias: {
      "@": path.join(__dirname, "./src"),
      "~": path.join(__dirname, "./node_modules"),
    },
  },
});
