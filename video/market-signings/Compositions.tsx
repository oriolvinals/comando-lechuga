import React from 'react';
import { Composition } from 'remotion';
import { HtmlScreen, HtmlVideo, htmlVideoFrames } from '../src/HtmlVideo';
import type { HtmlVideoSpec } from '../src/HtmlVideo';

/**
 * «Compras del mercado» compositions, registered by src/Root.tsx.
 * Every generated/market-signings-<id>.json built by build.mjs →
 *   MarketSignings-<id> when the day fits in one story, or MarketSignings-<id>-p1, -p2… when it is split into stories ≤ 60 s,
 *   plus MarketSigningsScreen-<id>-<n> per screen (n counts across parts) for the review stills.
 */
const FPS = 30;

interface MarketSigningsBuild {
    id?: string;
    date: string;
    css: string;
    parts?: { frames: HtmlVideoSpec['frames'] }[];
    /** builds of the earlier variant A: a single part */
    frames?: HtmlVideoSpec['frames'];
}

declare const require: {
    context: (
        dir: string,
        deep: boolean,
        re: RegExp,
    ) => { keys(): string[]; (key: string): MarketSigningsBuild };
};
const ctx = require.context('./generated', false, /^\.\/market-signings-[\w-]+\.json$/);
const builds = ctx.keys().map((k) => ctx(k));

/** the stories of one build as HtmlVideo specs */
const storiesOf = (b: MarketSigningsBuild): HtmlVideoSpec[] =>
    (b.parts ?? [{ frames: b.frames ?? [] }]).map((p) => ({
        date: b.date,
        css: b.css,
        frames: p.frames,
    }));

export function MarketSigningsCompositions() {
    return (
        <>
            {builds.map((b) => {
                const id = b.id ?? b.date;
                const stories = storiesOf(b);
                const screens = stories.flatMap((s) =>
                    s.frames.map((_, i) => ({ spec: s, index: i })),
                );

                return (
                    <React.Fragment key={id}>
                        {stories.map((s, p) => (
                            <Composition
                                key={p}
                                id={
                                    stories.length === 1
                                        ? `MarketSignings-${id}`
                                        : `MarketSignings-${id}-p${p + 1}`
                                }
                                component={HtmlVideo}
                                durationInFrames={htmlVideoFrames(s, FPS)}
                                fps={FPS}
                                width={1080}
                                height={1920}
                                defaultProps={{ spec: s }}
                            />
                        ))}
                        {screens.map((sc, i) => (
                            <Composition
                                key={`s${i}`}
                                id={`MarketSigningsScreen-${id}-${i + 1}`}
                                component={HtmlScreen}
                                durationInFrames={Math.round(
                                    sc.spec.frames[sc.index].dur * FPS,
                                )}
                                fps={FPS}
                                width={1080}
                                height={1920}
                                defaultProps={{
                                    spec: sc.spec,
                                    index: sc.index,
                                }}
                            />
                        ))}
                    </React.Fragment>
                );
            })}
        </>
    );
}
