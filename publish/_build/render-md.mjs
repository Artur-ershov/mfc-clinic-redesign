// Рендер всех .md в папке publish/ в .html рядом с исходником.
// Запуск:  npm i marked@14 && node publish/_build/render-md.mjs
// Исходные .md остаются на месте — .html генерируется поверх.
import { readdir, readFile, writeFile } from "node:fs/promises";
import { join, relative, dirname, basename } from "node:path";
import { fileURLToPath } from "node:url";
import { marked } from "marked";

const ROOT = join(dirname(fileURLToPath(import.meta.url)), "..");

const CSS = `
:root{
  --bg:#faf8f4; --fg:#1c1a17; --muted:#6b6660; --line:#e0dbd2; --card:#fff;
  --accent:#3f706a; --accent-soft:#eaf1ef; --warn:#8a6d3b; --warn-soft:#fbf3e2;
  --bad:#8c3a34; --bad-soft:#fbeceb;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
}
@media (prefers-color-scheme:dark){
  :root{--bg:#16171a; --fg:#e8e6e1; --muted:#9c968d; --line:#2e3035; --card:#1d1f23;
  --accent:#7fb3aa; --accent-soft:#1e2b29; --warn:#c9a35e; --warn-soft:#2a2417;
  --bad:#d98b83; --bad-soft:#2b1c1a;}
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);
  font:16px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",sans-serif}
.wrap{max-width:48rem;margin:0 auto;padding:2.4rem 1.25rem 6rem}
.nav{color:var(--muted);font-size:.9rem;margin:0 0 2rem;font-family:var(--mono)}
h1{font-size:2rem;line-height:1.2;margin:.4em 0 .5em;letter-spacing:-.015em}
h2{font-size:1.35rem;margin:2.2em 0 .7em;letter-spacing:-.01em;padding-top:.4em;border-top:1px solid var(--line)}
h3{font-size:1.1rem;margin:1.8em 0 .5em}
h4{font-size:1rem;margin:1.5em 0 .4em;color:var(--muted)}
p,ul,ol{margin:0 0 1em}
li{margin:.3em 0}
a{color:var(--accent)}
hr{border:0;border-top:1px solid var(--line);margin:2.4rem 0}
blockquote{margin:1.2em 0;padding:.1em 1.1em;border-left:3px solid var(--accent);
  background:var(--accent-soft);border-radius:0 8px 8px 0;color:var(--fg)}
code{font-family:var(--mono);font-size:.88em;background:var(--card);
  border:1px solid var(--line);border-radius:5px;padding:.1em .35em}
pre{background:var(--card);border:1px solid var(--line);border-radius:10px;
  padding:1rem 1.1rem;overflow-x:auto}
pre code{border:0;padding:0;background:none;font-size:.85rem;line-height:1.55}
table{border-collapse:collapse;width:100%;font-size:.92rem;margin:1.2em 0;display:block;overflow-x:auto}
th,td{border:1px solid var(--line);padding:.5rem .7rem;text-align:left;vertical-align:top}
th{background:var(--card);font-weight:700}
img{max-width:100%;height:auto}
@media (max-width:640px){.wrap{padding:1.6rem 1rem 4rem}h1{font-size:1.6rem}}
`;

function page({ title, nav, body }) {
  return `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>${title}</title>
<style>${CSS}</style>
</head>
<body>
<div class="wrap">
<p class="nav">${nav}</p>
${body}
</div>
</body>
</html>
`;
}

async function walk(dir) {
  const out = [];
  for (const e of await readdir(dir, { withFileTypes: true })) {
    if (e.name === "_build" || e.name.startsWith(".")) continue;
    const p = join(dir, e.name);
    if (e.isDirectory()) out.push(...(await walk(p)));
    else if (e.name.endsWith(".md")) out.push(p);
  }
  return out;
}

// README.md в корне publish/ — служебный, в набор не рендерится
const files = (await walk(ROOT)).filter((f) => relative(ROOT, f) !== "README.md");
for (const file of files) {
  const src = await readFile(file, "utf-8");
  const rel = relative(ROOT, file);
  const depth = rel.split("/").length - 1;
  const up = depth === 0 ? "./" : "../".repeat(depth);

  // .md-ссылки между документами набора ведут на сгенерированные .html
  let html = marked.parse(src);
  html = html.replace(/href="([^"]+)\.md(#[^"]*)?"/g, (m, p, hash) =>
    /^https?:/.test(p) ? m : `href="${p}.html${hash || ""}"`,
  );

  const h1 = src.match(/^#\s+(.+)$/m);
  const title = (h1 ? h1[1] : basename(file, ".md")).replace(/[#*`⚠️]/g, "").trim();

  const section = rel.split("/")[0];
  const sectionHref = depth > 1 ? "../".repeat(depth - 1) : "./";
  const nav =
    `<a href="${up}">← весь набор</a>` +
    (depth > 0 ? ` · <a href="${sectionHref}">${section}/</a>` : "");

  await writeFile(file.replace(/\.md$/, ".html"), page({ title, nav, body: html }));
  console.log("rendered", rel);
}
console.log(`\n${files.length} файлов`);
