## Requirements

- Node.js 18 LTS (recommended)

Note: the Node.js version is not pinned in this repository (no `engines` in `package.json`, no `.nvmrc` / `.node-version`).

## Project Setup

```sh
npm install --legacy-peer-deps
```

### Compile and Hot-Reload for Development

```sh
npm run dev
```

### Compile and Minify for Production

```sh
npm run build
```

### Run Unit Tests with [Vitest](https://vitest.dev/)

```sh
npm run test:unit
```

### Lint with [ESLint](https://eslint.org/)

```sh
npm run lint
```
