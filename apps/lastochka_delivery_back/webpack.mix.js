const mix = require("laravel-mix");
const path = require("path");

const SRC = "./resources/src";
const DIST = "./public/distro";

mix.copyDirectory(`${SRC}/assets/fonts`, `${DIST}/fonts`);
mix.copyDirectory(`${SRC}/assets/svg`, `${DIST}/svg`);
mix.copyDirectory(`${SRC}/assets/images`, `${DIST}/images`);

mix
  .stylus(`${SRC}/styles/style.styl`, `${DIST}/css/style.min.css`, {
    stylusOptions: {
      use: [require("rupture")(), require("nib")()],
      import: [
        path.resolve(__dirname, `${SRC}/styles/global/nib-fix.styl`),
        "~nib/lib/nib/index.styl",
        "~rupture/rupture/index.styl",
      ],
    },
  })
  .js(`${SRC}/app.js`, `${DIST}/js/bundle.min.js`)
  .vue({
    version: 2,
  })
  .sourceMaps()
  .options({
    processCssUrls: false,
  })
  .alias({
    "~": path.resolve(__dirname, "./node_modules"),
    "@": path.resolve(__dirname, "./resources/src"),
  })
  .override((webpackConfig) => {
    webpackConfig.module.rules.push({
      test: /\.(graphql|gql)$/,
      exclude: /node_modules/,
      loader: "graphql-tag/loader",
    });
  });
