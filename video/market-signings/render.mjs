// Final render of the «Compras del mercado» stories for one day (only after the stills are reviewed):
//   npm run render:market-signings -- 2026-10-01   → market-signings/out/market-signings-2026-10-01.mp4, or market-signings-<id>-p1.mp4, -p2… when split
// H.264 + AAC audio (the per-kind sound effects), 1080×1920 30 fps; checks moov before mdat (faststart) and the 100 MB limit.
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(
    path.dirname(new URL(import.meta.url).pathname.replace(/^\/(\w:)/, '$1')),
    '..',
);
const id = process.argv[2];
const built = id && path.join(root, `market-signings/generated/market-signings-${id}.json`);

if (!id || !fs.existsSync(built)) {
    console.error(
        id
            ? `Missing ${built}: run "npm run build:market-signings -- ${id}" first.`
            : 'Usage: npm run render:market-signings -- <YYYY-MM-DD>   (the exported market day, already built)',
    );
    process.exit(1);
}

const spec = JSON.parse(
    fs.readFileSync(
        path.join(root, `market-signings/generated/market-signings-${id}.json`),
        'utf8',
    ),
);
const n = spec.parts.length;
/* REMOTION_CONCURRENCY (set by the app on the small server) caps Chrome tabs so the render never starves the syncs */
const concurrency = process.env.REMOTION_CONCURRENCY
    ? ` --concurrency=${process.env.REMOTION_CONCURRENCY}`
    : '';

for (let p = 1; p <= n; p++) {
    const comp = n === 1 ? `MarketSignings-${id}` : `MarketSignings-${id}-p${p}`;
    const out = path.join(
        root,
        `market-signings/out/${n === 1 ? `market-signings-${id}` : `market-signings-${id}-p${p}`}.mp4`,
    );
    const t0 = Date.now();
    execSync(
        `npx remotion render src/index.ts ${comp} "${out}" --codec=h264 --audio-codec=aac${concurrency}`,
        { cwd: root, stdio: 'inherit' },
    );
    const secs = ((Date.now() - t0) / 1000).toFixed(1);
    /* Instagram Stories needs the moov atom before mdat (faststart): verify the top-level atom order */
    const buf = fs.readFileSync(out);
    const atoms = [];

    for (let o = 0; o + 8 <= buf.length;) {
        const size = buf.readUInt32BE(o);
        atoms.push(buf.toString('ascii', o + 4, o + 8));

        if (size < 8) {
            break;
        }

        o += size;
    }

    const ok =
        atoms.indexOf('moov') !== -1 &&
        atoms.indexOf('moov') < atoms.indexOf('mdat');
    const mb = buf.length / 1048576;
    console.log(
        `${out}\nsize: ${mb.toFixed(2)} MB${mb > 100 ? ' — OVER 100 MB' : ''} · render ${secs} s\natoms: ${atoms.join(' > ')}\nfaststart: ${ok ? 'OK' : 'NO — moov after mdat'}`,
    );
}
