import { loadFont } from '@remotion/fonts';
import { staticFile } from 'remotion';
import { MarketSigningsCompositions } from '../market-signings/Compositions';

/**
 * Every video's compositions, one folder per video (market-signings/ …), with the shared fonts in public/fonts.
 */
loadFont({
    family: 'Chivo',
    url: staticFile('fonts/chivo-variable.woff2'),
    weight: '100 900',
});
loadFont({
    family: 'Chivo Mono',
    url: staticFile('fonts/chivo-mono-variable.woff2'),
    weight: '100 900',
});
loadFont({
    family: 'Doto',
    url: staticFile('fonts/doto-variable.woff2'),
    weight: '100 900',
});
loadFont({
    family: 'Anton',
    url: staticFile('fonts/anton-400.woff2'),
    weight: '400',
});

export function Root() {
    return <MarketSigningsCompositions />;
}
