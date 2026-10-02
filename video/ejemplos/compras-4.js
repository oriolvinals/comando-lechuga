/* ═══════════════ COMPRAS DEL MERCADO · opción 4 «Uno a uno» (aprobada) ═══════════════
 * Port of the approved design (comando-lechuga/public/_video-compras-4.html, option 4): same markup, CSS
 * (ejemplos/compras-4.css) and animations, driven by one day's export (data/compras-<date>.json → DATA.compras).
 *   portada 5 s → una ficha por jugador de 3,5 s → outro 5 s (10 + 3,5·N s: hasta 12 fichajes = 52 s en una story);
 *   0 fichajes → «Hoy nadie ha fichado» 5 s + outro 5 s; > 60 s (≥ 15 fichajes, red de seguridad) → the fewest stories ≤ 60 s (portada in the first, outro in the last, «1/2» in the top bar).
 * Sets globalThis.COMPRAS_4 = {parts:[{frames:[{n, dur, kind:'intro'|'player'|'outro', html}]}]}.
 */
{
  const C = DATA.compras;
  const WD = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
  const WDL = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  const MO = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
  const [Y, Mo, Da] = C.date.split('-').map(Number);
  const wd = new Date(Date.UTC(Y, Mo - 1, Da)).getUTCDay();
  const DAY = {label:`${WD[wd]} ${Da} ${MO[Mo - 1]}`, long:`${WDL[wd]} ${Da} ${MO[Mo - 1]}`, short:`${Da} ${MO[Mo - 1]}`};

  /* helpers (as in the design page) */
  const es = (n, dd = 0) => Number(n).toLocaleString('es-ES', {minimumFractionDigits:dd, maximumFractionDigits:dd, useGrouping:'always'});
  const EU = n => es(n) + ' €';
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const d = s => `data-d="${(+s).toFixed(2)}"`;
  const fixD = html => html.replace(/<([a-z0-9]+)([^>]*?)\sdata-d="([\d.]+)"([^>]*)>/gi, (m, tag, a, dl, b) => { let all = a + b; const sc = /\/\s*$/.test(all); if (sc) { all = all.replace(/\/\s*$/, ''); } const out = /style="/.test(all) ? all.replace(/style="/, `style="--d:${dl}s;`) : `${all} style="--d:${dl}s"`; return `<${tag}${out}${sc ? '/' : ''}>`; });
  const sgnEU = n => (n > 0 ? '+' : n < 0 ? '−' : '') + EU(Math.abs(n));
  const sgnPct = p => (p > 0 ? '+' : p < 0 ? '−' : '') + es(Math.abs(p * 100), 1) + '%';
  const tone = b => (b.fav ? 'up' : 'dn');
  const chip = (b, extra = '') => `<span class="chip ${b.fav ? 'c-li' : 'c-ng'}" style="${extra}">${sgnPct(b.pct)}</span>`;
  /* players without an official photo (export gives photo:null): an empty silhouette instead of a broken image */
  const sil = '<div style="position:absolute;inset:0;display:grid;place-items:end center"><div style="width:46%;height:46%;border-radius:50% 50% 0 0;background:var(--b2)"></div></div>';
  const ph = (p, w, h = w, extra = '') => `<div class="ph" style="width:${w}px;height:${h}px;${extra}">${p.photo ? `<img src="${p.photo}" alt="">` : sil}</div>`;
  const cut = (p, style, cls = '') => (p.photo ? `<img class="cut ${cls}" src="${p.photo}" alt="" style="${style}">` : '');
  const cr = (t, sz = 40, extra = '') => (t ? `<img class="cr" style="width:${sz}px;height:${sz}px;${extra}" src="${t.logo}" alt="">` : '');
  const lg = (m, sz = 44, extra = '') => `<img class="lg" style="width:${sz}px;height:${sz}px;${extra}" src="${m.logo}" alt="">`;
  const pc = (pos, cls = '') => `<span class="pc ${pos} ${cls}">${pos}</span>`;
  const ARROW = (sz = 18) => `<svg viewBox="0 0 24 24" width="${sz}" height="${sz}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="square"><path d="M4 12h15M13 6l6 6-6 6"/></svg>`;
  /* rolling counter, as the mock's runCounters: from 0, starts at host --d + 100 ms, 1000 ms ease-out cubic (HtmlVideo recomputes it per frame) */
  const cnt = (to, f = 'int') => `<span data-to="${to}" data-f="${f}" data-dur="1000" data-lead="0.1">${es(Math.round(to))}</span>`;
  const fit = (text, w, max, k = .68) => Math.max(12, Math.min(max, w / Math.max(1, String(text).length * k))).toFixed(1);
  const frTop = tag => `<div class="fr-top"><span class="w">Comando<b> Lechuga</b></span><span class="t">${tag}</span><span class="r">REC</span></div>`;
  const frame = (body, bodyStyle = '', tag = 'Fichajes · ' + DAY.label) => fixD(`<div class="fr">${frTop(tag)}<div class="fr-body" style="${bodyStyle}">${body}</div></div>`);
  /** paid vs value, the line that goes under every price */
  const dline = (b, size = 16, extra = '') => `<div class="dl ${tone(b)}" style="font-size:${size}px;${extra}"><span>${sgnEU(b.diff)}</span>${chip(b)}</div>`;

  const enrich = b => { const diff = b.value == null ? null : b.amount - b.value; return {...b, m:C.managers[b.buyer], diff, pct:b.value ? diff / b.value : null, fav:diff != null && diff <= 0}; };
  const ALL = C.buys.map(enrich).sort((a, b) => b.amount - a.amount);
  const nameFit = (b, w, max) => `font-size:${fit(b.player.name, w, max)}px`;

  /** HqMarketValueDifference (resources/js/components/hq-market-trend-icon.tsx): Lucide trend icon + yesterday-to-today change */
  const LUCIDE = {
    ChevronsUp:'<path d="m17 11-5-5-5 5"/><path d="m17 18-5-5-5 5"/>', ChevronUp:'<path d="m18 15-6-6-6 6"/>', ChevronRight:'<path d="m9 18 6-6-6-6"/>',
    ChevronDown:'<path d="m6 9 6 6 6-6"/>', ChevronsDown:'<path d="m7 6 5 5 5-5"/><path d="m7 13 5 5 5-5"/>',
    TriangleAlert:'<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
  };
  const TREND_DISPLAY = {
    positive_inflection:['TriangleAlert', 'Inflexión positiva: pasó de bajar a subir', true], rise_accelerating_sharply:['ChevronsUp', 'La subida se acelera mucho', true],
    rise_accelerating:['ChevronUp', 'La subida se acelera', true], rise_steady:['ChevronRight', 'Sube a ritmo constante', true],
    rise_decelerating:['ChevronDown', 'La subida se desacelera', true], rise_decelerating_sharply:['ChevronsDown', 'La subida se desacelera mucho', true],
    negative_inflection:['TriangleAlert', 'Inflexión negativa: pasó de subir a bajar', false], fall_decelerating_sharply:['ChevronsUp', 'La bajada se desacelera mucho', false],
    fall_decelerating:['ChevronUp', 'La bajada se desacelera', false], fall_steady:['ChevronRight', 'Baja a ritmo constante', false],
    fall_accelerating:['ChevronDown', 'La bajada se acelera', false], fall_accelerating_sharply:['ChevronsDown', 'La bajada se acelera mucho', false],
  };
  const trendIcon = (trend, sz) => { const t = TREND_DISPLAY[trend]; return t ? `<svg aria-label="${t[1]}" width="${sz}" height="${sz}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.75" stroke-linecap="round" stroke-linejoin="round" style="flex:none;color:var(${t[2] ? '--lime' : '--neg'})">${LUCIDE[t[0]]}</svg>` : ''; };
  const valueDiff = (b, size = 13) => (!b.valueDiff && !b.trend ? '' : `<span style="display:inline-flex;align-items:center;gap:5px;font:600 ${size}px/1 var(--mono);white-space:nowrap;font-variant-numeric:tabular-nums">${trendIcon(b.trend, Math.round(size * 1.17))}${b.valueDiff ? `<span style="color:var(${b.valueDiff > 0 ? '--lime' : '--neg'})">${b.valueDiff > 0 ? '+' : '−'}${EU(Math.abs(b.valueDiff))}</span>` : ''}</span>`);

  /** LaLiga, the seller of every market signing: the official red «L» in a discreet well */
  const laliga = (sz = 44) => `<span style="display:grid;place-items:center;flex:none;width:${sz}px;height:${sz}px;background:var(--well);border:1px solid var(--b2)"><img src="/images/laliga.svg" alt="LaLiga" style="width:${Math.round(sz * .72)}px;height:${Math.round(sz * .72)}px;object-fit:contain"></span>`;

  /** OUTRO (común a todos los vídeos): the Comando Lechuga crest and the motto */
  function outro(dur = 5) {
    return {n:'Outro', dur, kind:'outro', html:fixD(`<div class="fr"><div class="fr-body" style="top:0;justify-content:center;align-items:center;gap:26px;text-align:center">
      <div class="a-fade" style="position:absolute;inset:0;background:radial-gradient(circle at 50% 40%,rgba(196,255,61,.07),transparent 62%)"></div>
      <img class="a-blur" ${d(.1)} src="/images/logo.png" width="300" height="300" style="object-fit:contain;position:relative" alt="Comando Lechuga">
      <div class="H a-up" ${d(.6)} style="font-size:38px;position:relative">1 campeón.<br><b>6 excusas.</b></div>
    </div></div>`)};
  }

  /** opening screen: who was signed (player, position, club), never who bought him */
  function cover(buys) {
    const n = buys.length;
    const title = `<div class="a-up"><div class="H" style="font-size:44px">${n} <b>${n === 1 ? 'fichaje' : 'fichajes'}</b><br>del mercado</div></div>`;
    if (n >= 6) {
      const rows = Math.ceil(n / 3);
      return title + `<div style="flex:1;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));grid-template-rows:repeat(${rows},minmax(0,1fr));gap:5px">${buys.map((b, i) => `<div class="pan a-up" ${d(.4 + i * .1)} style="padding:7px;display:flex;flex-direction:column;justify-content:center;gap:6px;min-height:0">
        <div class="row" style="justify-content:space-between;gap:4px"><div style="position:relative">${ph(b.player, 48)}<span style="position:absolute;left:-1px;top:-1px;background:var(--ink);border:1px solid var(--b2);font:400 15px/1 var(--word);padding:2px 4px;color:var(--paper)">${i + 1}</span></div>${cr(b.player.team, 40)}</div>
        <b style="display:block;${nameFit(b, 88, 15)};font-weight:900;text-transform:uppercase;white-space:nowrap;line-height:1">${esc(b.player.name)}</b>
        <div>${pc(b.player.pos)}</div></div>`).join('')}</div>`;
    }
    if (n >= 3) {
      return title + `<div style="display:flex;flex-direction:column;gap:5px;flex:1">${buys.map((b, i) => `<div class="pan a-left" ${d(.5 + i * .25)} style="flex:1;max-height:104px;display:grid;grid-template-columns:36px 64px minmax(0,1fr) 40px;gap:12px;align-items:center;padding:0 12px">
        <span style="font:400 44px/1 var(--word);color:var(--moss)">${i + 1}</span>${ph(b.player, 64)}
        <div style="min-width:0"><b style="display:block;${nameFit(b, 124, 19)};font-weight:900;text-transform:uppercase;white-space:nowrap">${esc(b.player.name)}</b><div class="row" style="gap:6px;margin-top:6px">${pc(b.player.pos)}<span class="mono ell" style="font-size:11px;color:var(--moss)">${esc(b.player.team?.name)}</span></div></div>
        ${cr(b.player.team, 40)}</div>`).join('')}</div>`;
    }
    return title + `<div style="display:flex;flex-direction:column;gap:6px;flex:1">${buys.map((b, i) => `<div class="pan hud a-up" ${d(.4 + i * .4)} style="flex:1;overflow:hidden;background:linear-gradient(180deg,var(--panel2),var(--well))">
      ${b.player.team ? `<img data-bleed src="${b.player.team.logo}" alt="" class="a-fade" style="position:absolute;right:-30px;top:-20px;width:${n === 1 ? 280 : 190}px;height:${n === 1 ? 280 : 190}px;object-fit:contain;opacity:.08">` : ''}
      <span style="position:absolute;left:12px;top:6px;font:400 ${n === 1 ? 120 : 84}px/1 var(--word);color:transparent;-webkit-text-stroke:2px var(--b3)">${i + 1}</span>
      ${cut(b.player, `right:-10px;bottom:0;height:${n === 1 ? 430 : 205}px;width:${n === 1 ? 320 : 170}px`, 'a-blur')}
      <div style="position:absolute;left:14px;right:14px;bottom:12px"><div class="H" style="${nameFit(b, n === 1 ? 300 : 200, n === 1 ? 58 : 34)};text-shadow:0 2px 14px rgba(0,0,0,.8)">${esc(b.player.name)}</div><div class="row" style="gap:7px;margin-top:7px">${pc(b.player.pos, 'lg2')}${cr(b.player.team, 40)}${b.player.team ? `<span class="mono" style="font-size:12px;font-weight:700;background:rgba(11,13,9,.8);padding:3px 6px">${esc(b.player.team.name)}</span>` : ''}</div></div>
    </div>`).join('')}</div>`;
  }

  /** one screen per signing: giant running number, buyer, paid, the day's value and the difference */
  function playerScreen(b, n, of) {
    const t = b.player.team;
    return frame(`
      <div class="ca-num a-slam" data-bleed ${n > 9 ? 'style="font-size:300px;top:-10px"' : ''}>${n}</div>
      <div class="a-blur" ${d(.05)} style="position:absolute;inset:0" data-bleed>${cut(b.player, 'right:-14px;top:6px;height:330px;width:250px')}</div>
      <div class="a-up" ${d(.2)} style="position:relative;margin-left:auto;text-align:right"><div class="lbl">Fichaje ${n} de ${of}</div></div>
      <div style="margin-top:auto;position:relative;display:flex;flex-direction:column;gap:9px">
        <div class="a-up" ${d(.3)}><div class="H" style="${nameFit(b, 320, 60)};text-shadow:0 2px 14px rgba(0,0,0,.8)">${esc(b.player.name)}</div><div class="row" style="gap:7px;margin-top:7px">${pc(b.player.pos, 'lg2')}${cr(t, 40)}${t ? `<span class="mono" style="font-size:12px;font-weight:700;background:rgba(11,13,9,.8);padding:3px 6px">${esc(t.name)}</span>` : ''}</div></div>
        <div class="pan row a-left" ${d(.5)} style="padding:8px 10px;gap:8px">${laliga(44)}<span style="color:var(--lime);display:flex">${ARROW(18)}</span>${lg(b.m, 44)}<b class="ell fill" style="font-size:16px;font-weight:900;text-transform:uppercase">${esc(b.m.name)}</b></div>
        <div class="pan hud a-up" ${d(.7)} style="padding:12px">
          <div class="lbl">Pagado</div>
          <div class="row" style="align-items:baseline;gap:6px;margin-top:8px"><span class="dot" style="font-size:38px">${cnt(b.amount)}</span><b class="mono" style="font-size:15px">€</b></div>
          ${b.value == null ? '' : `<div class="row a-up" ${d(1.2)} style="justify-content:space-between;margin-top:12px;padding-top:10px;border-top:1px solid var(--b1)"><span class="lbl">Valor ${DAY.short}</span><span style="display:flex;flex-direction:column;align-items:flex-end;gap:6px"><b class="mono" style="font-size:15px;white-space:nowrap">${EU(b.value)}</b>${valueDiff(b)}</span></div>
          <div class="a-wipe" ${d(1.5)} style="margin-top:10px">${dline(b, 18)}</div>`}
        </div>
      </div>`);
  }

  /** 0 signings: a short «empty market» intro; the outro follows */
  function desert() {
    return [
      {n:'Mercado desierto', dur:5, kind:'intro', html:frame(`
        <div class="ca-num a-slam" data-bleed style="left:50px;top:0;font-size:460px">0</div>
        <div style="margin-top:auto;position:relative;display:flex;flex-direction:column;gap:12px">
          <div class="a-up" ${d(.6)}><div class="H" style="font-size:60px">Hoy nadie<br><b>ha fichado</b></div><div class="lbl" style="font-size:12px;margin-top:12px">0 fichajes del mercado</div></div>
          <div class="pan hud row a-up" ${d(1.1)} style="padding:12px"><span class="lbl" style="color:var(--paper)">Gastado en el mercado</span><span class="row" style="margin-left:auto;gap:5px;align-items:baseline"><span class="dot" style="font-size:30px;color:var(--moss)">0</span><b class="mono" style="font-size:13px">€</b></span></div>
        </div>`)},
    ];
  }

  /** the screens of one day, in order, without the outro */
  function screens(buys) {
    const n = buys.length;
    if (!n) { return desert(); }
    const F = [{n:n === 1 ? 'El fichaje del día' : 'Los fichajes del día', dur:5, kind:'intro', html:frame(cover(buys))}];
    buys.forEach((b, i) => F.push({n:`${i + 1} · ${b.player.name}`, dur:3.5, kind:'player', html:playerScreen(b, i + 1, n)}));
    return F;
  }

  /** Instagram stories last 3–60 s: split into the fewest parts ≤ 60 s, as even as possible; the outro closes only the last part */
  function split(F, max = 60) {
    const O = outro();
    const dur = list => list.reduce((s, f) => s + f.dur, 0);
    for (let k = 1; k <= F.length; k++) {
      const base = Math.floor(F.length / k), extra = F.length % k, parts = [];
      let at = 0;
      for (let p = 0; p < k; p++) { const size = base + (p < extra ? 1 : 0); parts.push(F.slice(at, at + size)); at += size; }
      parts[k - 1] = [...parts[k - 1], O];
      if (parts.every(p => dur(p) <= max)) {
        return parts.map((p, i) => ({p:i + 1, k, dur:dur(p), frames:p.map(f => ({n:k > 1 ? `Story ${i + 1}/${k} · ${f.n}` : f.n, dur:f.dur, kind:f.kind, html:k > 1 && f.kind !== 'outro' ? f.html.replace('<span class="r">REC</span>', `<span class="part">${i + 1}/${k}</span><span class="r">REC</span>`) : f.html}))}));
      }
    }
  }

  globalThis.COMPRAS_4 = {parts:split(screens(ALL))};
}
