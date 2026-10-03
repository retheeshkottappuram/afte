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
    };

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

        // Key levels and squeeze box
        clearLevels();
        (data.levels || []).forEach((lvl) => {
            state.levelLines.push(state.candleSeries.createPriceLine({
                price: lvl.price,
                color: lvl.type === 'resistance' ? 'rgba(244,63,94,0.35)' : 'rgba(34,197,94,0.35)',
                lineWidth: Math.min(4, lvl.touches),
                lineStyle: 0,
                axisLabelVisible: false,
                title: `${lvl.type === 'resistance' ? 'R' : 'S'} x${lvl.touches}`,
            }));
        });
        if (data.breakout_box) {
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

    window.SignalAlgoPro = {
        /** Called by the page after each /dashboard/analyze response. */
        render(data, chart, candleSeries) {
            if (!data || !data.success) return;
            state.data = data;
            if (chart && candleSeries) {
                ensureSeries(chart, candleSeries);
                drawOverlays(data);
            }
            renderStrip(data);
            renderInspector(data);
            renderAccuracyCard(data.all_setup_stats);
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
