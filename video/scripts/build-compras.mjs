// Compras del mercado → Remotion stories for one market day. Run by the app (php artisan stories:publish-market-signings),
// which first writes data/compras-<fecha>.json (App\Services\MarketSigningsExport):
//   npm run build:compras -- 2026-10-01   (this script → src/generated/compras-2026-10-01.json + public/mk/ images)
// The frames come from ejemplos/compras-4.js, the approved design (public/_video-compras-4.html, kept out of the repo).
// Output: {id, date, css, parts:[{frames:[{n, dur, kind, html}]}]} — one Remotion composition per part (see src/ComprasRoot.tsx).
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';

const root = path.resolve(path.dirname(new URL(import.meta.url).pathname.replace(/^\/(\w:)/, '$1')), '..');
const APP = path.resolve(root, '..'); // the Laravel app this folder lives in
/* the date is always the exported market's (data/compras-<fecha>.json → its `date`), never today's: it is required */
const arg = process.argv[2];
if (!arg || !/^\d{4}-\d{2}-\d{2}$/.test(arg)) {
    console.error('Usage: npm run build:compras -- <YYYY-MM-DD>   (the exported market day)');
    process.exit(1);
}
const dataFile = path.join(root, `data/compras-${arg}.json`);
if (!fs.existsSync(dataFile)) {
    console.error(`Missing ${dataFile}: run "php artisan stories:publish-market-signings --date=${arg} --dry-run" (it exports it) first.`);
    process.exit(1);
}
const data = JSON.parse(fs.readFileSync(dataFile, 'utf8'));
if (data.date !== arg) {
    console.error(`${dataFile} is the market of ${data.date}, not ${arg}.`);
    process.exit(1);
}
const ctx = { DATA: { compras: data }, console };
vm.createContext(ctx);
vm.runInContext(fs.readFileSync(path.join(root, 'ejemplos/compras-4.js'), 'utf8'), ctx);
const { parts } = ctx.COMPRAS_4;
const css = fs.readFileSync(path.join(root, 'ejemplos/compras-4.css'), 'utf8');
const frames = parts.flatMap((p) => p.frames);

/* copy every image the frames use (players, crests, manager logos, /images/laliga.svg, /images/logo.png…) into public/mk/ */
const srcs = new Set();
for (const f of frames) {
    for (const m of f.html.matchAll(/src="(\/(?:storage\/)?images\/[^"]+)"/g)) {
        srcs.add(m[1]);
    }
}
for (const src of srcs) {
    const from = src.startsWith('/storage/') ? path.join(APP, 'storage/app/public', src.slice('/storage/'.length)) : path.join(APP, 'public', src);
    const to = path.join(root, 'public/mk', src);
    fs.mkdirSync(path.dirname(to), { recursive: true });
    if (fs.existsSync(from)) {
        fs.copyFileSync(from, to);
    } else {
        console.warn('missing image', from);
    }
}
/* Instagram stories: every part 3–60 s */
const durs = parts.map((p) => p.frames.reduce((s, f) => s + f.dur, 0));
if (durs.some((t) => t < 3 || t > 60)) {
    console.error(`Part durations ${durs.join(' / ')} s outside 3–60 s.`);
    process.exit(1);
}
fs.mkdirSync(path.join(root, 'src/generated'), { recursive: true });
const out = path.join(root, `src/generated/compras-${arg}.json`);
fs.writeFileSync(out, JSON.stringify({ id: arg, date: data.date, css, parts: parts.map((p) => ({ frames: p.frames.map((f) => ({ n: f.n, dur: f.dur, kind: f.kind, html: f.html })) })) }));
console.log(`Wrote ${out}: ${parts.length} ${parts.length === 1 ? 'story' : 'stories'} (${durs.join(' s + ')} s), ${frames.length} screens, ${srcs.size} images`);
