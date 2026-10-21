/**
 * SignalAlgo Pro v4: chart overlays + Signal Inspector.
 * Renders the /dashboard/analyze payload on the Lightweight Charts instance created by the page:
 * trend ribbon, EMA21-50 cloud, EMA50, key levels, squeeze box, risk/reward zones,
 * signal labels with measured outcomes, the stats strip and the inspector panel.
 * Read-only except the explicit "Place Trade" and "Alert me" buttons.
 */
(function () {
    'use strict';

    const state = {
        chart: null,
        candleSeries: null,
        ribbon: null,
        ema50: null,
        cloudFast: null,
        rewardZone: null,
        riskZone: null,
        levelLines: [],
        data: null,
        toggles: loadToggles(),
    };

    /** Chart display toggles, remembered per browser. */
    function loadToggles() {
        const defaults = { results: true, filtered: true, shadow: false, levels: true, swings: true, trades: true, sr: true, sound: true };
        try {
            return Object.assign(defaults, JSON.parse(localStorage.getItem('sap_chart_toggles') || '{}'));
        } catch (e) {
            return defaults;
        }
    }

    function saveToggles() {
        try { localStorage.setItem('sap_chart_toggles', JSON.stringify(state.toggles)); } catch (e) { /* storage unavailable */ }
    }

    /** Put the server markers on the chart, keeping only the kinds the user has switched on. */
    function applyMarkers() {
        if (!state.candleSeries || !state.data) return;
        const t = state.toggles;
        const markers = (state.data.markers || [])
            .filter((m) => (m.kind !== 'result' || t.results) && (m.kind !== 'shadow' || t.shadow) && (m.kind !== 'filtered' || t.filtered))
            .map((m) => ({ time: m.time, position: m.position, shape: m.shape, color: m.color, text: m.text, size: m.size }))
            .concat(t.trades ? tradeMarkers() : (tradesNote(''), []))
            .concat(t.sr ? srMarkers() : [])
            .sort((a, b) => a.time - b.time);
        state.candleSeries.setMarkers(markers);
        drawTradeLines();
        drawSrZones();
    }

    /* ---------- S/R reversal signals (sweep of a support / resistance zone + reversal candle) ---------- */

    function srMarkers() {
        const candles = state.data.candles || [];
        const times = new Set(candles.map((c) => c.time));
        return (state.data.sr_signals || []).filter((s) => times.has(s.time)).map((s) => ({
            time: s.time,
            position: s.side === 'LONG' ? 'belowBar' : 'aboveBar',
            shape: 'square',
            color: s.side === 'LONG' ? (s.confirmed ? '#a855f7' : 'rgba(168,85,247,0.55)') : (s.confirmed ? '#f97316' : 'rgba(249,115,22,0.55)'),
            text: `S/R ${s.side === 'LONG' ? 'BUY' : 'SELL'}${s.confirmed ? '' : '?'} ${Number(s.rr).toFixed(1)}R`,
            size: 1,
        }));
    }

    /** Support / resistance zones as faint lines (with the S/R toggle). */
    function drawSrZones() {
        (state.srLines || []).forEach((line) => { try { state.candleSeries.removePriceLine(line); } catch (e) { /* already gone */ } });
        state.srLines = [];
        if (!state.toggles.sr || !state.data) return;
        (state.data.sr_zones || []).forEach((z) => {
            state.srLines.push(state.candleSeries.createPriceLine({
                price: z.price, color: 'rgba(168,85,247,0.35)', lineWidth: Math.min(4, Math.max(1, Math.round(z.touches / 2))), lineStyle: 1,
                axisLabelVisible: false, title: `S/R x${z.touches}`,
            }));
        });
    }

    /* ---------- "My trades": the bot's own entries and exits on this coin ---------- */

    /** Time of the candle that contains unix time t (null when it is before the loaded candles). */
    function barAt(t) {
        const candles = state.data.candles || [];
        if (!t || !candles.length || t < candles[0].time) return null;
        let lo = 0, hi = candles.length - 1;
        while (lo < hi) {
            const mid = (lo + hi + 1) >> 1;
            if (candles[mid].time <= t) lo = mid; else hi = mid - 1;
        }
        return candles[lo].time;
    }

    function currentTrades() {
        const tr = state.trades;
        return tr && state.data && tr.symbol === state.data.symbol ? tr.list : [];
    }

    /** "My trades" note next to the toggle: how many are shown, or why none are. */
    function tradesNote(text) {
        const note = el('sapTradesNote');
        if (note) note.textContent = state.toggles.trades && text ? `(${text})` : '';
    }

    function tradeMarkers() {
        const out = [];
        const list = currentTrades();
        let shown = 0, older = 0;
        list.forEach((tr) => {
            const long = tr.side === 'LONG';
            const inAt = barAt(tr.opened_at);
            if (inAt || barAt(tr.closed_at)) shown++; else older++;
            const tag = (tr.mode === 'paper' ? '[P] ' : '') + (tr.setup === 'SWING_SAR' ? `SAR ${tr.interval || ''} `.replace('  ', ' ') : (tr.setup === 'LAB' ? 'LAB 4h ' : 'BRK '));
            // Entry vs the chart signal: "worse" = paid more on a long / sold lower on a short.
            const slip = tr.signal_price ? (tr.entry - tr.signal_price) / tr.signal_price * 100 * (long ? 1 : -1) : null;
            const gap = slip === null ? '' : ` (signal ${fmt(tr.signal_price)}, ${Math.abs(slip).toFixed(2)}% ${slip > 0 ? 'worse' : 'better'})`;
            if (inAt) out.push({ time: inAt, position: long ? 'belowBar' : 'aboveBar', shape: long ? 'arrowUp' : 'arrowDown', color: '#38bdf8', text: `${tag}IN #${tr.id} ${fmt(tr.entry)}${gap}`, size: 2 });
            const outAt = tr.status === 'OPEN' ? null : barAt(tr.closed_at);
            if (outAt) {
                const win = Number(tr.pnl) >= 0;
                const roe = tr.roe === null ? '' : ` (${win ? '+' : ''}${Number(tr.roe).toFixed(1)}%)`;
                out.push({ time: outAt, position: long ? 'aboveBar' : 'belowBar', shape: 'circle', color: win ? '#22c55e' : '#ef4444', text: `${tag}OUT #${tr.id} ${win ? '+' : '-'}$${Math.abs(Number(tr.pnl)).toFixed(2)}${roe}`, size: 2 });
            }
        });
        if (state.trades && state.data && state.trades.symbol === state.data.symbol) {
            const info = state.trades.info || {};
            const others = (info.open_symbols || []).filter((s) => s !== state.data.symbol);
            tradesNote(list.length === 0 ? `none for ${state.data.symbol} in 7 days${info.open_any !== undefined ? `; open trades: ${others.length ? others.join(', ') : 'none'}` : ''}`
                : `${shown} shown${older ? `, ${older} older than the chart` : ''}`);
        }
        return out;
    }

    /** Entry and current stop lines of the open trade(s) on this coin. */
    function drawTradeLines() {
        (state.tradeLines || []).forEach((line) => { try { state.candleSeries.removePriceLine(line); } catch (e) { /* already gone */ } });
        state.tradeLines = [];
        if (!state.toggles.trades) return;
        currentTrades().filter((tr) => tr.status === 'OPEN').forEach((tr) => {
            state.tradeLines.push(state.candleSeries.createPriceLine({ price: tr.entry, color: 'rgba(56,189,248,0.9)', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: `#${tr.id} entry` }));
            if (tr.sl > 0) {
                const safe = (tr.sl - tr.entry) * (tr.side === 'LONG' ? 1 : -1) >= 0;
                state.tradeLines.push(state.candleSeries.createPriceLine({ price: tr.sl, color: safe ? 'rgba(34,197,94,0.9)' : 'rgba(239,68,68,0.9)', lineWidth: 1, lineStyle: 2, axisLabelVisible: true, title: `#${tr.id} stop${safe ? ' (profit locked)' : ''}` }));
            }
        });
    }

    function syncToggleInputs() {
        Object.entries(state.toggles).forEach(([name, on]) => {
            const input = document.getElementById(`sapToggle-${name}`);
            if (input) input.checked = !!on;
        });
    }

    const fmt = (v) => {
        if (v === null || v === undefined || isNaN(Number(v))) return '--';
        const n = Number(v);
        return n >= 1 ? n.toFixed(4).replace(/0+$/, '').replace(/\.$/, '') : n.toPrecision(5);
    };
    const pct = (v) => (v === null || v === undefined) ? '--' : `${Number(v).toFixed(1)}%`;
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const el = (id) => document.getElementById(id);

    function ensureSeries(chart, candleSeries) {
        if (state.chart === chart && state.ribbon) return;
        state.chart = chart;
        state.candleSeries = candleSeries;

        state.ribbon = chart.addLineSeries({ lineWidth: 3, title: 'Trend', priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
        state.ema50 = chart.addLineSeries({ color: 'rgba(249, 115, 22, 0.8)', lineWidth: 1, title: 'EMA 50', priceLineVisible: false, lastValueVisible: false });
        state.cloudFast = chart.addAreaSeries({ lineColor: 'rgba(0,0,0,0)', topColor: 'rgba(56, 189, 248, 0.06)', bottomColor: 'rgba(56, 189, 248, 0.0)', priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
        state.rewardZone = chart.addBaselineSeries({ priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false, lineWidth: 1 });
        state.riskZone = chart.addBaselineSeries({ priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false, lineWidth: 1 });
    }

    function clearLevels() {
        state.levelLines.forEach((line) => { try { state.candleSeries.removePriceLine(line); } catch (e) { /* already gone */ } });
        state.levelLines = [];
    }

    function drawOverlays(data) {
        if (!state.chart) return;

        // Trend ribbon: green in an uptrend, red in a downtrend (per-point colours)
        state.ribbon.setData((data.trend_ribbon || []).map((p) => ({ time: p.time, value: p.value, color: p.trend === 'up' ? 'rgba(16,185,129,0.85)' : 'rgba(239,68,68,0.85)' })));
        state.ema50.setData(data.ema50 || []);
        state.cloudFast.setData(data.ema21 || []);

        // Key levels and squeeze box (toggle: Levels)
        clearLevels();
        (state.toggles.levels ? (data.levels || []) : []).forEach((lvl) => {
            state.levelLines.push(state.candleSeries.createPriceLine({
                price: lvl.price,
                color: lvl.type === 'resistance' ? 'rgba(244,63,94,0.35)' : 'rgba(34,197,94,0.35)',
                lineWidth: Math.min(4, lvl.touches),
                lineStyle: 0,
                axisLabelVisible: false,
                title: `${lvl.type === 'resistance' ? 'R' : 'S'} x${lvl.touches}`,
            }));
        });
        if (data.breakout_box && state.toggles.levels) {
            ['high', 'low'].forEach((edge) => state.levelLines.push(state.candleSeries.createPriceLine({
                price: data.breakout_box[edge], color: 'rgba(250,204,21,0.7)', lineWidth: 1, lineStyle: 2, axisLabelVisible: true,
                title: `Squeeze ${edge}`,
            })));
        }

        drawZones(data.signal, data.candles || []);
    }

    /**
     * Shaded reward (entry -> TP2) and risk (entry -> stop) zones from the signal candle to now.
     */
    function drawZones(signal, candles) {
        if (!signal || !candles.length) {
            state.rewardZone.setData([]);
            state.riskZone.setData([]);
            return;
        }

        const isLong = signal.direction === 'LONG';
        const points = candles.filter((c) => c.time >= signal.time).map((c) => c.time);
        if (!points.length) return;

        const green = { line: 'rgba(16,185,129,0.6)', fill: 'rgba(16,185,129,0.12)' };
        const red = { line: 'rgba(239,68,68,0.6)', fill: 'rgba(239,68,68,0.14)' };

        state.rewardZone.applyOptions({
            baseValue: { type: 'price', price: Number(signal.entry) },
            topLineColor: isLong ? green.line : 'rgba(0,0,0,0)', topFillColor1: isLong ? green.fill : 'rgba(0,0,0,0)', topFillColor2: isLong ? green.fill : 'rgba(0,0,0,0)',
            bottomLineColor: isLong ? 'rgba(0,0,0,0)' : green.line, bottomFillColor1: isLong ? 'rgba(0,0,0,0)' : green.fill, bottomFillColor2: isLong ? 'rgba(0,0,0,0)' : green.fill,
        });
        state.riskZone.applyOptions({
            baseValue: { type: 'price', price: Number(signal.entry) },
            topLineColor: isLong ? 'rgba(0,0,0,0)' : red.line, topFillColor1: isLong ? 'rgba(0,0,0,0)' : red.fill, topFillColor2: isLong ? 'rgba(0,0,0,0)' : red.fill,
            bottomLineColor: isLong ? red.line : 'rgba(0,0,0,0)', bottomFillColor1: isLong ? red.fill : 'rgba(0,0,0,0)', bottomFillColor2: isLong ? red.fill : 'rgba(0,0,0,0)',
        });
        state.rewardZone.setData(points.map((t) => ({ time: t, value: Number(signal.tp2) })));
        state.riskZone.setData(points.map((t) => ({ time: t, value: Number(signal.sl) })));
    }

    function renderStrip(data) {
        const strip = el('sap-stats-strip');
        if (!strip) return;
        const s = data.stats_strip || {};
        strip.innerHTML = s.closed
            ? `Signals in view: <b>${s.signals}</b> · Closed: <b>${s.closed}</b> · Win <b>${pct(s.win_rate)}</b> · Avg <b>${s.avg_r >= 0 ? '+' : ''}${s.avg_r}R</b> · PF <b>${s.profit_factor ?? '--'}</b> <span class="text-slate-500">(fees included, simulated with the live exit rules)</span>`
            : `Signals in view: <b>${s.signals || 0}</b> · no closed signals yet in this window`;
    }

    function trendBadge(trend) {
        const cls = trend === 'UP' ? 'text-emerald-300 bg-emerald-500/10 border-emerald-500/30'
            : trend === 'DOWN' ? 'text-rose-300 bg-rose-500/10 border-rose-500/30'
                : 'text-slate-300 bg-slate-800 border-slate-700';
        return `<span class="px-1.5 py-0.5 rounded border text-[10px] font-bold ${cls}">${esc(trend)}</span>`;
    }

    function statsLine(stats) {
        if (!stats || !stats.n) return '<span class="text-slate-500">No resolved signals yet</span>';
        const exp = Number(stats.expectancy);
        return `<b class="text-white">${pct(stats.win_rate)}</b> win · <b class="${exp >= 0 ? 'text-emerald-300' : 'text-rose-300'}">${exp >= 0 ? '+' : ''}${exp.toFixed(2)}R</b> avg · PF ${stats.profit_factor ?? '--'} · n=${stats.n} <span class="text-slate-500">(${esc(stats.source)})</span>`;
    }

    function renderInspector(data) {
        const box = el('sap-inspector');
        if (!box) return;

        const st = data.state || {};
        const sig = data.signal;
        const statusColor = st.status === 'LONG' ? 'text-emerald-300' : st.status === 'SHORT' ? 'text-rose-300' : 'text-slate-300';

        // 1. State
        el('sap-status').innerHTML = `<span class="${statusColor} font-black">${esc(sig ? sig.direction : (st.status || 'WAIT'))}</span>`;
        el('sap-reason').textContent = sig
            ? `${sig.setup_label} ${sig.direction}, grade ${sig.grade} ${'★'.repeat(sig.stars)}${sig.ai_probability !== null ? ` · AI ${Math.round(sig.ai_probability * 100)}%` : ''}${sig.outcome && sig.outcome !== 'OPEN' ? ` · outcome ${sig.outcome}` : ''}`
            : (st.reason || '');

        // 2. Checklist (signal filters if a signal is active, else current market filters)
        const filters = sig ? sig.filters : (st.checklist || {});
        el('sap-checklist').innerHTML = Object.entries(filters).map(([name, f]) =>
            `<li class="flex items-start gap-2"><span class="${f.pass ? 'text-emerald-400' : 'text-rose-400'} font-bold">${f.pass ? '✓' : '✗'}</span><span><span class="text-slate-300 capitalize">${esc(name.replace('_', ' '))}</span> <span class="text-slate-500">${esc(f.detail)}</span></span></li>`
        ).join('') + (sig && sig.confluences && sig.confluences.length ? `<li class="text-cyan-300 pt-1">Confluence: ${esc(sig.confluences.join(', '))}</li>` : '')
            + (sig && sig.ai_reasons && sig.ai_reasons.length ? `<li class="text-indigo-300">AI factors: ${esc(sig.ai_reasons.join(', '))}</li>` : '');

        // 3. Multi-timeframe trend
        el('sap-mtf').innerHTML = (data.mtf || []).map((m) => `<div class="flex justify-between"><span class="text-slate-400">${esc(m.interval)}</span>${trendBadge(m.trend)}</div>`).join('');

        // 4. Track record + AI model
        el('sap-record').innerHTML = data.setup_stats
            ? `<div>30d: ${statsLine(data.setup_stats.d30)}</div><div>90d: ${statsLine(data.setup_stats.d90)}</div>`
            : '<span class="text-slate-500">Select a signal to see its setup track record.</span>';
        const m = data.model;
        el('sap-model').innerHTML = m
            ? `AI model: holdout AUC <b>${m.auc}</b>, accuracy <b>${(m.accuracy * 100).toFixed(1)}%</b> on ${m.holdout_n} signals (trained ${esc(String(m.trained_at).slice(0, 10))})`
            : 'AI model: inactive until it predicts clearly better than chance on unseen signals. Grades use measured setup stats only.';

        // 5. Position calculator + place trade
        const sz = data.sizing;
        const btn = el('sap-place-trade');
        if (sig && sz) {
            el('sap-sizing').innerHTML = sz.allowed
                ? `Mode <b class="uppercase">${esc(sz.mode)}</b> · balance $${sz.balance} → qty <b>${sz.quantity}</b>, margin <b>$${sz.margin}</b> at ${sz.leverage}x, risk <b>$${sz.risk_usd}</b> (${sz.risk_pct}%)`
                : `<span class="text-amber-300">${esc(sz.reason)}</span>`;
            btn.disabled = !(sz.allowed && sig.tradable && sig.outcome === 'OPEN');
            btn.dataset.direction = sig.direction;
            btn.textContent = `Place ${sig.direction} [${String(data.mode).toUpperCase()}]`;
            btn.className = `w-full py-2 rounded-lg font-bold text-xs transition ${btn.disabled ? 'bg-slate-800 text-slate-500 cursor-not-allowed' : (data.mode === 'live' ? 'bg-rose-600 hover:bg-rose-500 text-white' : 'bg-emerald-600 hover:bg-emerald-500 text-white')}`;
        } else {
            el('sap-sizing').textContent = 'No open signal to size.';
            btn.disabled = true;
            btn.textContent = 'No signal';
            btn.className = 'w-full py-2 rounded-lg font-bold text-xs bg-slate-800 text-slate-500 cursor-not-allowed';
        }

        // 6. Alert me toggle
        const watched = (data.monitored_coins || []).includes(data.symbol);
        const alertBtn = el('sap-alert-toggle');
        alertBtn.dataset.watched = watched ? '1' : '0';
        alertBtn.textContent = watched ? '🔔 Alerts ON for this coin' : '🔕 Alert me on this coin';
        alertBtn.className = `w-full py-1.5 rounded-lg text-xs font-semibold border transition ${watched ? 'border-cyan-500/50 text-cyan-300 bg-cyan-500/10' : 'border-slate-700 text-slate-300 hover:bg-slate-800'}`;

        // 7. Recent signals with outcomes
        el('sap-history').innerHTML = (data.signal_history || []).slice(-8).reverse().map((h) => {
            const r = h.r_multiple !== null ? `${h.r_multiple >= 0 ? '+' : ''}${Number(h.r_multiple).toFixed(2)}R` : (h.unrealized_r !== null ? `open ${h.unrealized_r >= 0 ? '+' : ''}${Number(h.unrealized_r).toFixed(2)}R` : 'open');
            const color = h.r_multiple === null ? 'text-slate-400' : (h.r_multiple > 0 ? 'text-emerald-300' : 'text-rose-300');
            const time = new Date(h.time * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
            return `<button type="button" class="w-full flex justify-between gap-2 px-2 py-1 rounded hover:bg-slate-800 text-left" onclick="SignalAlgoPro.focusSignal(${h.time})">
                <span><span class="${h.side === 'LONG' ? 'text-emerald-400' : 'text-rose-400'} font-bold">${h.side}</span> ${esc(h.setup_label)}${h.is_shadow ? ' <span class="text-slate-500">(shadow)</span>' : ''}</span>
                <span class="text-slate-500">${time}</span><span class="${color} font-mono">${esc(h.outcome)} ${r}</span></button>`;
        }).join('') || '<div class="text-slate-500">No signals in this window.</div>';
    }

    /**
     * Header card: pooled 90-day win rate and expectancy of the core (traded) setups.
     */
    function renderAccuracyCard(all) {
        const card = el('card-accuracy');
        if (!card || !all) return;
        let n = 0; let wins = 0; let sumR = 0;
        Object.values(all).filter((s) => s.active && s.d90 && s.d90.n).forEach((s) => {
            n += s.d90.n;
            wins += s.d90.win_rate / 100 * s.d90.n;
            sumR += s.d90.expectancy * s.d90.n;
        });
        if (!n) {
            card.textContent = 'No data yet';
            el('card-accuracy-sub').textContent = 'Run `php artisan trade:backtest --seed` to seed the track record';
            return;
        }
        const avgR = sumR / n;
        card.innerHTML = `${(wins / n * 100).toFixed(1)}% <span class="text-sm font-normal ${avgR >= 0 ? 'text-emerald-300' : 'text-rose-300'}">${avgR >= 0 ? '+' : ''}${avgR.toFixed(2)}R avg</span>`;
        el('card-accuracy-sub').textContent = `${n} resolved signals of the setups currently traded, fees included`;
    }

    async function postJson(url, body) {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify(body) });
        let json = {};
        try { json = await res.json(); } catch (e) { /* empty body */ }
        return { ok: res.ok, json };
    }

    function notify(message, ok) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, ok);
        } else {
            window.alert(message);
        }
    }

    /* ---------- Big BUY / SELL labels over the chart ---------- */

    /** Signals that get a large label: swing BUY/SELL signals and the strategy's tradable signals. */
    function labelSignals(data) {
        const out = [];
        if (state.toggles.swings) {
            (data.swing_signals || []).forEach((s) => out.push({
                time: s.time, side: s.side, price: s.entry, sl: s.sl, tp1: s.tp1, tp2: s.tp2, text: s.side === 'LONG' ? 'BUY' : 'SELL', kind: 'swing',
                confirmed: s.confirmed !== false, score: s.score || null,
                reason: s.confirmed === false
                    ? `${s.reason}, stop ${s.sl_pct}%: "?" ${s.score ? '(score below the BUY/SELL threshold)' : '(unconfirmed flip)'}, the bot trades it with a tight stop`
                    : s.reason,
            }));
        }
        (data.signal_history || []).filter((h) => h.tradable && !h.is_shadow).forEach((h) => out.push({
            time: h.time, side: h.side, price: h.entry, sl: h.sl, tp1: h.tp1, tp2: h.tp2,
            text: `${h.side === 'LONG' ? 'BUY' : 'SELL'} ${h.grade || ''}`.trim(), reason: h.setup_label, kind: 'strategy', confirmed: true,
        }));
        return out.sort((a, b) => a.time - b.time);
    }

    function ensureLabelLayer() {
        const container = el('signalalgo_canvas_chart');
        if (!container) return null;
        let layer = el('sap-label-layer');
        if (!layer || layer.parentElement !== container.parentElement) {
            if (layer) layer.remove();
            container.parentElement.style.position = container.parentElement.style.position || 'relative';
            layer = document.createElement('div');
            layer.id = 'sap-label-layer';
            layer.style.cssText = 'position:absolute;inset:0;pointer-events:none;overflow:hidden;z-index:5;';
            container.parentElement.appendChild(layer);
        }
        if (!state.labelHooked && state.chart) {
            state.labelHooked = true;
            state.chart.timeScale().subscribeVisibleLogicalRangeChange(() => layoutLabels());
            window.addEventListener('resize', () => layoutLabels());
            // Vertical zoom/scroll has no event: re-place labels a few times a second while visible.
            setInterval(() => { if (document.visibilityState === 'visible') layoutLabels(); }, 400);
        }
        return layer;
    }

    function layoutLabels() {
        const layer = el('sap-label-layer');
        if (!layer) return;
        // Labels belong to this chart only: hide them while the TradingView tab (same parent) is shown.
        const canvas = el('signalalgo_canvas_chart');
        if (!canvas || canvas.classList.contains('hidden') || canvas.offsetParent === null) {
            layer.innerHTML = '';
            layer.style.display = 'none';
            return;
        }
        layer.style.display = '';
        if (!state.chart || !state.candleSeries || !state.data) return;
        const candles = state.data.candles || [];
        const byTime = new Map(candles.map((c) => [c.time, c]));
        const width = layer.clientWidth;
        const items = labelSignals(state.data).filter((s) => byTime.has(s.time));
        const visible = items.map((s) => ({ s, x: state.chart.timeScale().timeToCoordinate(s.time) }))
            .filter((v) => v.x !== null && v.x >= 0 && v.x <= width)
            .slice(-30);
        const newest = items.length ? items[items.length - 1].time : null;

        layer.innerHTML = visible.map(({ s, x }) => {
            const c = byTime.get(s.time);
            const isBuy = s.side === 'LONG';
            const anchorY = state.candleSeries.priceToCoordinate(isBuy ? c.low : c.high);
            if (anchorY === null) return '';
            const bg = isBuy ? '#16a34a' : '#e11d48';
            const top = isBuy ? anchorY + 18 : anchorY - 18 - 34;
            const lineTop = isBuy ? anchorY + 2 : anchorY - 18;
            const pulse = s.time === newest ? 'box-shadow:0 0 0 4px rgba(250,204,21,0.45);' : '';
            const opacity = s.confirmed ? 1 : 0.6;
            return `
                <div style="position:absolute;left:${x - 0.5}px;top:${lineTop}px;width:1px;height:16px;background:${bg};"></div>
                <div title="${esc(s.reason || '')} · SL ${fmt(s.sl)} · TP1 ${fmt(s.tp1)}" onclick="SignalAlgoPro.focusSignal(${s.time})"
                     style="position:absolute;left:${x}px;top:${top}px;transform:translateX(-50%);pointer-events:auto;cursor:pointer;opacity:${opacity};
                            background:${bg};color:#fff;border-radius:6px;padding:2px 7px;font:800 12px/1.25 ui-monospace,monospace;text-align:center;white-space:nowrap;${pulse}">
                    ${isBuy ? '▲' : '▼'} ${esc(s.text)}${s.confirmed ? '' : '?'}${s.score ? ` <span style="font-weight:600;opacity:0.85">${s.score}</span>` : ''}<div style="font-weight:600;font-size:10px;opacity:0.9">${fmt(s.price)}</div>
                </div>`;
        }).join('');
    }

    /* ---------- Live notification when a new signal prints ---------- */

    function beep(isBuy) {
        if (state.toggles.sound === false) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            [0, 0.18].forEach((delay, i) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.frequency.value = isBuy ? (i ? 880 : 660) : (i ? 440 : 660);
                gain.gain.setValueAtTime(0.15, ctx.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + delay + 0.16);
                osc.connect(gain).connect(ctx.destination);
                osc.start(ctx.currentTime + delay);
                osc.stop(ctx.currentTime + delay + 0.17);
            });
        } catch (e) { /* audio unavailable */ }
    }

    function signalToast(sig) {
        let box = el('sap-signal-toasts');
        if (!box) {
            box = document.createElement('div');
            box.id = 'sap-signal-toasts';
            box.style.cssText = 'position:fixed;top:16px;right:16px;z-index:70;display:flex;flex-direction:column;gap:8px;max-width:min(380px,calc(100vw - 32px));';
            document.body.appendChild(box);
        }
        const isBuy = sig.side === 'LONG';
        const card = document.createElement('div');
        card.style.cssText = `background:${isBuy ? '#052e16' : '#4c0519'};border:2px solid ${isBuy ? '#22c55e' : '#f43f5e'};color:#fff;border-radius:12px;padding:12px 14px;font:13px/1.4 ui-monospace,monospace;box-shadow:0 10px 30px rgba(0,0,0,.5);`;
        card.innerHTML = `
            <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                <b style="font-size:15px">${isBuy ? '🟢 BUY' : '🔴 SELL'} ${esc(sig.symbol)} <span style="opacity:.7;font-size:12px">${esc(sig.interval || '')}</span></b>
                <button type="button" data-close style="background:none;border:0;color:#cbd5e1;font-size:16px;cursor:pointer">✕</button>
            </div>
            <div>Entry <b>${fmt(sig.entry)}</b> · SL <b style="color:#fda4af">${fmt(sig.sl)}</b> · TP1 <b style="color:#86efac">${fmt(sig.tp1)}</b></div>
            <div style="opacity:.8;font-size:11px;margin-top:2px">${esc(sig.reason || '')}</div>
            ${sig.symbol !== (state.data && state.data.symbol) ? `<button type="button" data-open style="margin-top:8px;width:100%;padding:6px;border-radius:8px;border:1px solid #475569;background:#0f172a;color:#e2e8f0;cursor:pointer;font-weight:700">Open ${esc(sig.symbol)} chart</button>` : ''}`;
        card.querySelector('[data-close]').onclick = () => card.remove();
        const open = card.querySelector('[data-open]');
        if (open) open.onclick = () => { card.remove(); if (typeof window.selectSymbol === 'function') window.selectSymbol(sig.symbol); else window.location.href = `?symbol=${encodeURIComponent(sig.symbol)}`; };
        box.prepend(card);
        while (box.children.length > 5) box.lastChild.remove();
    }

    function announce(sig) {
        signalToast(sig);
        beep(sig.side === 'LONG');
        if ('Notification' in window && Notification.permission === 'granted') {
            try {
                new Notification(`${sig.side === 'LONG' ? 'BUY' : 'SELL'} ${sig.symbol} ${sig.interval || ''}`, { body: `Entry ${fmt(sig.entry)} · SL ${fmt(sig.sl)} · TP1 ${fmt(sig.tp1)}\n${sig.reason || ''}`, tag: `${sig.symbol}-${sig.time}` });
            } catch (e) { /* notifications unavailable */ }
        }
    }

    /** New signal on the coin being viewed: compare with the last one this browser has seen. */
    function checkNewChartSignal(data) {
        const items = labelSignals(data).filter((s) => s.confirmed);
        if (!items.length) return;
        const newest = items[items.length - 1];
        const key = `sap_last_sig_${data.symbol}_${data.interval}`;
        let seen = null;
        try { seen = Number(localStorage.getItem(key) || 0); } catch (e) { /* storage unavailable */ }
        try { localStorage.setItem(key, String(newest.time)); } catch (e) { /* storage unavailable */ }
        const fresh = Date.now() / 1000 - newest.time < 2 * 3600;
        if (seen && newest.time > seen && fresh) {
            announce(Object.assign({ symbol: data.symbol, interval: data.interval, entry: newest.price }, newest));
            recentSeen[`${data.symbol}:${newest.time}`] = true;
        }
    }

    /** Every coin's new swing signals from the hourly scan (the Telegram list), polled while the page is open. */
    const recentSeen = {};
    async function pollRecentSignals() {
        let since = 0;
        try { since = Number(localStorage.getItem('sap_recent_since') || 0); } catch (e) { /* storage unavailable */ }
        try {
            const res = await fetch(`/signals/recent?since=${since}`, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const json = await res.json();
            const list = json.signals || [];
            const first = !since;
            list.forEach((s) => {
                const k = `${s.symbol}:${s.time}`;
                if (!first && !recentSeen[k]) announce(s);
                recentSeen[k] = true;
            });
            if (json.now) { try { localStorage.setItem('sap_recent_since', String(json.now)); } catch (e) { /* storage unavailable */ } }
        } catch (e) { /* offline */ }
    }

    function updateAlertButton() {
        const btn = el('sap-alerts-btn');
        if (!btn) return;
        const granted = 'Notification' in window && Notification.permission === 'granted';
        btn.textContent = `${state.toggles.sound === false ? '🔕' : '🔔'} ${granted ? 'Alerts on' : 'Enable alerts'}`;
    }

    window.SignalAlgoPro = {
        /** Called by the page after each /dashboard/analyze response. */
        render(data, chart, candleSeries) {
            if (!data || !data.success) return;
            state.data = data;
            if (chart && candleSeries) {
                ensureSeries(chart, candleSeries);
                drawOverlays(data);
                applyMarkers();
                if (ensureLabelLayer()) layoutLabels();
            }
            checkNewChartSignal(data);
            if (!state.recentPoller) {
                state.recentPoller = setInterval(() => { if (document.visibilityState === 'visible') pollRecentSignals(); }, 60000);
                pollRecentSignals();
            }
            updateAlertButton();
            syncToggleInputs();
            renderStrip(data);
            renderInspector(data);
            renderAccuracyCard(data.all_setup_stats);
        },

        /** Re-place (or hide) the BUY/SELL labels, e.g. after switching between the Signals Chart and TradingView. */
        relayout() {
            layoutLabels();
        },

        /** The bot's trades on {symbol} (from /signals/chart-trades), shown with the "My trades" toggle. */
        setTrades(symbol, list, info) {
            state.trades = { symbol, list: Array.isArray(list) ? list : [], info: info || {} };
            applyMarkers();
        },

        /** Shown next to the "My trades" toggle when the trades could not be loaded. */
        setTradesStatus(text) {
            state.trades = null;
            applyMarkers();
            tradesNote(text);
        },

        /** Chart toggle checkbox handler: results / filtered / shadow / levels / swings / trades. */
        setToggle(name, on) {
            state.toggles[name] = !!on;
            saveToggles();
            if (name === 'levels' && state.data) {
                drawOverlays(state.data);
            }
            applyMarkers();
            layoutLabels();
        },

        /** "Enable alerts": ask for browser notifications once; afterwards the button mutes/unmutes the beep. */
        async enableAlerts() {
            if ('Notification' in window && Notification.permission === 'default') {
                try { await Notification.requestPermission(); } catch (e) { /* unsupported */ }
            } else {
                state.toggles.sound = state.toggles.sound === false;
                saveToggles();
            }
            updateAlertButton();
            if (state.toggles.sound !== false) beep(true);
        },

        focusSignal(time) {
            if (!state.data || typeof window.loadSignalAt !== 'function') return;
            window.loadSignalAt(time);
        },

        async placeTrade() {
            const data = state.data;
            const btn = el('sap-place-trade');
            if (!data || !data.signal || btn.disabled) return;

            const sz = data.sizing || {};
            const confirmText = `${String(data.mode).toUpperCase()} ${data.signal.direction} ${data.symbol}\nEntry ~${fmt(data.signal.entry)}, stop ${fmt(data.signal.sl)}\nRisk $${sz.risk_usd} (${sz.risk_pct}% of equity)${data.mode === 'live' ? '\n\nThis places a REAL order with REAL money.' : ''}\n\nContinue?`;
            if (!window.confirm(confirmText)) return;

            btn.disabled = true;
            const { ok, json } = await postJson('/api/execute-radar-trade', { symbol: data.symbol, direction: data.signal.direction });
            notify(json.message || (ok ? 'Order placed.' : 'Order rejected.'), ok && json.success);
            btn.disabled = false;
        },

        async toggleAlert() {
            const data = state.data;
            if (!data) return;
            const watched = el('sap-alert-toggle').dataset.watched === '1';
            const url = watched ? '/daemon/monitored-coins/remove' : '/daemon/monitored-coins/add';
            const { ok, json } = await postJson(url, { symbol: data.symbol });
            notify(json.message || (ok ? 'Updated.' : 'Could not update alerts.'), ok);
            if (ok && json.coins) {
                data.monitored_coins = json.coins;
                renderInspector(data);
            }
        },
    };
})();
