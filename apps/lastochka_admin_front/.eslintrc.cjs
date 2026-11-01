/* eslint-env node */
require("@rushstack/eslint-patch/modern-module-resolution");

module.exports = {
  env: {
    node: true,
    browser: true,
    es6: true,
  },
  globals: {
    Atomics: "readonly",
    SharedArrayBuffer: "readonly"
  },
  "extends": [
    "plugin:vue/vue3-essential",
    "eslint:recommended",
    "@vue/eslint-config-prettier"
  ],
  plugins: [
    "vue"
  ],
  rules: {
    "vue/no-textarea-mustache": "off",
    "no-console": process.env.NODE_ENV === "production" ? "warn" : "off",
    "no-debugger": process.env.NODE_ENV === "production" ? "warn" : "off",
    "prettier/prettier": [
      "error",
      {
        endOfLine: "auto",
        printWidth: 110,
      },
    ]
  }
}
