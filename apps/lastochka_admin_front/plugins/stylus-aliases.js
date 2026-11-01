import path from "path";

function transform(src, id, aliasConfig = []) {
  // Фикс алиасов и невозможности stylus обработать относительне пути, почему-то
  const oldImports = src.match(/@import ['"][^'"]*['"]/g);
  if (!oldImports) return src;
  const newImports = oldImports.map((oldImport) => {
    const pathReg = new RegExp(`['"](~?[^'"]*)['"]`); // Все что в кавычках
    const aliasPaths = pathReg.exec(oldImport);
    if (!aliasPaths) return oldImport; // Если в коде нет алиасов не трогаем
    // Замена алиасов реальными путями
    for (const { find, replacement } of aliasConfig) {
      let aliasPath = aliasPaths[1];
      if (aliasPath.startsWith("~")) aliasPath = aliasPath.slice(1);
      const hasAlias =
        typeof find === "string" ? new RegExp(`^~?${find}`).test(aliasPath) : find.test(aliasPath);
      if (!hasAlias) continue;
      const absolutePath = aliasPath.replace(find, replacement); // Абсолютный путь файла указанного в @import
      const curFilePath = path.resolve(id.split("?", 2)[0], "../"); // Абсолютный путь текущего файла
      let newImportPath = path.relative(curFilePath, absolutePath).split(path.sep).join("/");
      if (newImportPath[0] !== ".") newImportPath = "./" + newImportPath; // Фикс если относительный путь получился прямо из корня - 'styles/...' -> './styles/...'
      return oldImport.replace(aliasPaths[1], newImportPath);
    }
    return oldImport;
  });
  oldImports.forEach((oldImport, i) => {
    src = src.replace(oldImport, newImports[i]);
  });
  return src;
}

export default function stylusAliasesPlugin() {
  let aliasConfig;
  return {
    enforce: "pre",
    name: "stylus-aliases-plugin",
    configResolved(viteConfig) {
      aliasConfig = viteConfig.resolve.alias || [];
    },
    transform(src, id) {
      if (/\.(styl|stylus)$/.test(id)) {
        return {
          code: transform(src, id, aliasConfig),
          map: null,
        };
      }
    },
  };
}
