// Конфиг для Lume (https://lume.land) — делает эту папку самостоятельным сайтом.
//
//   deno task lume            # сборка в ./_site
//   deno task lume --serve    # локальный просмотр
//
// Всё содержимое — уже готовый статический HTML, шаблонизатор не нужен: файлы
// просто копируются как есть. Исходные .md лежат рядом с .html, чтобы их можно
// было читать и править; .html пересобирается скриптом _build/render-md.mjs.
//
// Если у вас УЖЕ есть сайт на Lume и вы кладёте эти папки внутрь него —
// этот файл не нужен. Добавьте в свой _config.ts две строки:
//
//   site.copy("next-move");
//   site.copy("mfc");
//
// и папки уедут в сборку нетронутыми.

import lume from "lume/mod.ts";

const site = lume({
  src: ".",
  dest: "_site",
});

site.copy([
  ".html",
  ".md",
  ".css",
  ".js",
  ".mjs",
  ".svg",
  ".png",
  ".jpg",
  ".jpeg",
  ".webp",
  ".woff2",
  ".ico",
]);

// служебное в сборку не едет
site.ignore("_build", "README.md");

export default site;
