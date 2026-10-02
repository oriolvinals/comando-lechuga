import { loadFont } from '@remotion/fonts';
import React from 'react';
import { Composition, staticFile } from 'remotion';
import { HtmlScreen, HtmlVideo, htmlVideoFrames, type HtmlVideoSpec } from './HtmlVideo';

/**
 * «Compras del mercado» compositions, on their own entry point (src/index-compras.ts) so the shared Root stays untouched.
 * Every src/generated/compras-<id>.json built by scripts/build-compras.mjs →
 *   Compras-<id> when the day fits in one story, or Compras-<id>-p1, -p2… when it is split into stories ≤ 60 s,
 *   plus ComprasScreen-<id>-<n> per screen (n counts across parts) for the review stills.
 */
const FPS = 30;

loadFont({ family: 'Chivo', url: staticFile('fonts/chivo-variable.woff2'), weight: '100 900' });
loadFont({ family: 'Chivo Mono', url: staticFile('fonts/chivo-mono-variable.woff2'), weight: '100 900' });
loadFont({ family: 'Doto', url: staticFile('fonts/doto-variable.woff2'), weight: '100 900' });
loadFont({ family: 'Anton', url: staticFile('fonts/anton-400.woff2'), weight: '400' });

interface ComprasBuild {
    id?: string;
    date: string;
    css: string;
    parts?: { frames: HtmlVideoSpec['frames'] }[];
    /** builds of the earlier variant A: a single part */
    frames?: HtmlVideoSpec['frames'];
}

declare const require: { context: (dir: string, deep: boolean, re: RegExp) => { keys(): string[]; (key: string): ComprasBuild } };
const ctx = require.context('./generated', false, /^\.\/compras-[\w-]+\.json$/);
const builds = ctx.keys().map((k) => ctx(k));

/** the stories of one build as HtmlVideo specs */
const storiesOf = (b: ComprasBuild): HtmlVideoSpec[] => (b.parts ?? [{ frames: b.frames ?? [] }]).map((p) => ({ date: b.date, css: b.css, frames: p.frames }));

export function ComprasRoot() {
    return (
        <>
            {builds.map((b) => {
                const id = b.id ?? b.date;
                const stories = storiesOf(b);
                const screens = stories.flatMap((s) => s.frames.map((_, i) => ({ spec: s, index: i })));

                return (
                    <React.Fragment key={id}>
                        {stories.map((s, p) => (
                            <Composition key={p} id={stories.length === 1 ? `Compras-${id}` : `Compras-${id}-p${p + 1}`} component={HtmlVideo} durationInFrames={htmlVideoFrames(s, FPS)} fps={FPS} width={1080} height={1920} defaultProps={{ spec: s }} />
                        ))}
                        {screens.map((sc, i) => (
                            <Composition key={`s${i}`} id={`ComprasScreen-${id}-${i + 1}`} component={HtmlScreen} durationInFrames={Math.round(sc.spec.frames[sc.index].dur * FPS)} fps={FPS} width={1080} height={1920} defaultProps={{ spec: sc.spec, index: sc.index }} />
                        ))}
                    </React.Fragment>
                );
            })}
        </>
    );
}
