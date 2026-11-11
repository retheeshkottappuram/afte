/**
 * SignalAlgo PRO chart: NEAR 1H Volatility-Gated Breakout.
 * Draws the /api/chart/near payload (candles, EMA50, breakout level, trail, script signals, bot trades) with
 * Lightweight Charts v4 and keeps the forming candle live from Binance's public kline stream. Read-only.
 */
(function () {
    'use strict';

    const root = document.getElementById('sap-root');
    if (!root || typeof LightweightCharts === 'undefined') return;

    const url = root.dataset.url;
    const symbol = root.dataset.symbol || 'NEARUSDT';
    const $ = (id) => document.getElementById(id);
    const state = { data: null, chart: null, candles: null, volume: null, ema: null, level: null, trail: null, ws: null, retry: 0, refetchTimer: null };
    const toggles = loadToggles();

    function loadToggles() {
        const defaults = { signals: true, trades: true, level: true, trail: true };
        try {
            return Object.assign(defaults, JSON.parse(localStorage.getItem('sap_near_toggles') || '{}'));
        } catch (e) {
            return defaults;
        }
    }

    function saveToggles() {
        try { localStorage.setItem('sap_near_toggles', JSON.stringify(toggles)); } catch (e) { /* storage unavailable */ }
    }

    function esc(text) {
        return String(text ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    function fmt(price) {
        if (price === null || price === undefined || Number.isNaN(Number(price))) return '—';
        const n = Number(price);
        return n.toLocaleString(undefined, { minimumFractionDigits: n >= 1 ? 3 : 4, maximumFractionDigits: n >= 1 ? 4 : 6 });
    }

    function localTime(sec) {
        return new Date(sec * 1000).toLocaleString([], { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function initChart() {
        const el = $('sap-chart');
        state.chart = LightweightCharts.createChart(el, {
            width: el.clientWidth,
            height: el.clientHeight,
            layout: { background: { color: '#090d16' }, textColor: '#94a3b8', fontSize: 12 },
            grid: { vertLines: { color: 'rgba(30, 41, 59, 0.4)' }, horzLines: { color: 'rgba(30, 41, 59, 0.4)' } },
            crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
            rightPriceScale: { borderColor: '#1e293b', scaleMargins: { top: 0.08, bottom: 0.2 } },
            timeScale: { borderColor: '#1e293b', timeVisible: true, secondsVisible: false, rightOffset: 6 },
            localization: { timeFormatter: (t) => localTime(t) },
        });
        state.candles = state.chart.addCandlestickSeries({
            upColor: '#10b981', downColor: '#ef4444', borderUpColor: '#10b981', borderDownColor: '#ef4444', wickUpColor: '#10b981', wickDownColor: '#ef4444',
            priceFormat: { type: 'price', precision: 4, minMove: 0.0001 },
        });
        state.volume = state.chart.addHistogramSeries({ priceFormat: { type: 'volume' }, priceScaleId: '' });
        state.chart.priceScale('').applyOptions({ scaleMargins: { top: 0.84, bottom: 0 } });
        state.ema = state.chart.addLineSeries({ color: '#e879f9', lineWidth: 2, title: 'EMA50', priceLineVisible: false, lastValueVisible: false });
        state.level = state.chart.addLineSeries({
            color: '#fbbf24', lineWidth: 1, lineStyle: LightweightCharts.LineStyle.Dashed, lineType: LightweightCharts.LineType.WithSteps,
            title: 'breakout', priceLineVisible: false, lastValueVisible: true,
        });
        state.trail = state.chart.addLineSeries({ color: '#22d3ee', lineWidth: 2, title: 'trail', priceLineVisible: false, lastValueVisible: false });

        if (typeof ResizeObserver !== 'undefined') {
            new ResizeObserver(() => state.chart.applyOptions({ width: el.clientWidth, height: el.clientHeight })).observe(el);
        }
    }

    function applyMarkers() {
        if (!state.data) return;
        const markers = [];
        if (toggles.signals) markers.push(...(state.data.markers || []));
        if (toggles.trades) markers.push(...(state.data.trade_markers || []));
        // A marker needs a candle at its time (e.g. a bot exit in an hour the chart does not hold yet).
        const times = new Set(state.candleTimes || []);
        state.candles.setMarkers(markers.filter((m) => times.has(m.time)).sort((a, b) => a.time - b.time));
        state.level.applyOptions({ visible: toggles.level });
        state.trail.applyOptions({ visible: toggles.trail });
    }

    function render(data) {
        const first = state.data === null;
        state.data = data;
        const candles = data.candles.slice();
        const volumes = data.volumes.slice();
        if (data.forming && (!candles.length || data.forming.time > candles[candles.length - 1].time)) {
            candles.push({ time: data.forming.time, open: data.forming.open, high: data.forming.high, low: data.forming.low, close: data.forming.close });
            volumes.push({ time: data.forming.time, value: data.forming.volume, color: data.forming.close >= data.forming.open ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)' });
        }
        state.candles.setData(candles);
        state.candleTimes = candles.map((c) => c.time);
        state.volume.setData(volumes);
        state.ema.setData(data.ema || []);
        const level = (data.level || []).slice();
        if (data.next && candles.length) level.push({ time: candles[candles.length - 1].time, value: data.next.level });
        state.level.setData(level.filter((p, i, a) => i === 0 || p.time > a[i - 1].time));
        state.trail.setData(data.trail || []);
        applyMarkers();
        if (first) state.chart.timeScale().setVisibleLogicalRange({ from: Math.max(0, candles.length - 160), to: candles.length + 4 });
        if (candles.length) setPrice(candles[candles.length - 1]);
        renderPanels(data);
        $('sap-loading').classList.add('hidden');
    }

    function setPrice(bar) {
        $('sap-price').textContent = fmt(bar.close);
        const change = bar.open > 0 ? (bar.close / bar.open - 1) * 100 : 0;
        const el = $('sap-candle-change');
        el.textContent = `${change >= 0 ? '+' : ''}${change.toFixed(2)}% this 1h candle`;
        el.className = `text-xs font-mono ${change >= 0 ? 'text-emerald-400' : 'text-rose-400'}`;
    }

    function renderPanels(d) {
        const bot = $('sap-bot');
        bot.textContent = d.bot_enabled ? 'BOT ON' : 'BOT OFF';
        bot.className = `px-2 py-0.5 rounded-lg text-[11px] font-mono font-bold border ${d.bot_enabled ? 'bg-emerald-500/15 text-emerald-300 border-emerald-500/40' : 'bg-slate-800 text-slate-400 border-slate-700'}`;

        const next = d.next || {};
        $('sap-level').textContent = fmt(next.level);
        $('sap-level-note').textContent = next.cross_possible === false ? 'already above: needs a pullback first' : 'a 1h close above it, green';
        $('sap-ema').textContent = fmt(next.ema);
        const last = d.last || {};
        $('sap-ema-note').textContent = last.close != null && next.ema != null ? (last.close > next.ema ? 'price above: uptrend' : 'price below: no entries') : 'close must be above';
        $('sap-rank').textContent = next.rank != null ? Number(next.rank).toFixed(0) : '—';
        $('sap-size').textContent = next.size == null ? 'size —' : (Number(next.size) > 0 ? `size ${Math.round(next.size * 100)}% of balance` : 'no trade (rank ≥ 80)');
        $('sap-atr').textContent = last.atr_pct != null ? `${Number(last.atr_pct).toFixed(2)}%` : '—';

        const open = (d.bot_trades || []).find((t) => t.status === 'OPEN');
        const pos = d.alert_position;
        if (open) {
            $('sap-position').textContent = `BOT LONG ${fmt(open.entry)}`;
            $('sap-position-note').textContent = open.trail ? `trail exit ${fmt(open.trail)} · stop ${fmt(open.stop)}` : `trail not armed · stop ${fmt(open.stop)}`;
        } else if (pos) {
            $('sap-position').textContent = `LONG ${fmt(pos.entry)}`;
            $('sap-position-note').textContent = pos.armed ? `trail exit ${fmt(pos.high - pos.d)}` : `trail arms at ${fmt(pos.entry + pos.d)}`;
        } else {
            $('sap-position').textContent = 'FLAT';
            $('sap-position-note').textContent = 'waiting for a BUY';
        }

        const engine = d.engine || {};
        $('sap-engine').textContent = engine.action ? engine.action.toUpperCase() : '—';
        $('sap-engine').title = engine.message || '';
        $('sap-engine-note').textContent = engine.message || 'no candle handled yet';
        $('sap-engine-note').title = engine.message || '';

        const s = d.stats || {};
        $('sap-stat-trades').textContent = s.trades ?? '—';
        $('sap-stat-win').textContent = s.win_rate != null ? `${s.win_rate}%` : '—';
        $('sap-stat-pf').textContent = s.profit_factor != null ? s.profit_factor : '—';
        $('sap-stat-ret').textContent = s.return_pct != null ? `${s.return_pct >= 0 ? '+' : ''}${s.return_pct}%` : '—';
        $('sap-stat-ret').className = `font-bold ${s.return_pct >= 0 ? 'text-emerald-400' : 'text-rose-400'}`;
        $('sap-trail-source').textContent = d.trail_source || '5m';

        $('sap-signals').innerHTML = (d.signals || []).map((x) => `
            <tr>
                <td class="px-3 py-2 text-slate-400">${esc(localTime(x.time))}</td>
                <td class="px-3 py-2 font-bold ${x.type === 'BUY' ? 'text-emerald-400' : 'text-cyan-300'}">${x.type === 'BUY' ? `▲ BUY ${Math.round(x.size * 100)}%` : '▼ EXIT'}</td>
                <td class="px-3 py-2 text-white">${fmt(x.price)}</td>
                <td class="px-3 py-2 ${x.pct == null ? 'text-slate-300' : (x.pct >= 0 ? 'text-emerald-400' : 'text-rose-400')}">${esc(x.reason)}${x.pct != null ? ` · ${x.pct >= 0 ? '+' : ''}${x.pct}%` : ''}</td>
            </tr>`).join('') || '<tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">No signals in this window.</td></tr>';

        $('sap-trades').innerHTML = (d.bot_trades || []).map((t) => `
            <tr>
                <td class="px-3 py-2 text-slate-400">${esc(t.opened_at ? new Date(t.opened_at).toLocaleString([], { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—')}</td>
                <td class="px-3 py-2 ${t.mode === 'live' ? 'text-amber-300' : 'text-slate-300'}">${esc(String(t.mode).toUpperCase())}</td>
                <td class="px-3 py-2 text-white">${fmt(t.entry)} → ${t.exit != null ? fmt(t.exit) : 'open'}</td>
                <td class="px-3 py-2 ${t.net_pnl == null ? 'text-slate-300' : (t.net_pnl >= 0 ? 'text-emerald-400' : 'text-rose-400')}">${t.status === 'OPEN' ? 'open' : `${t.net_pnl >= 0 ? '+' : '-'}$${Math.abs(Number(t.net_pnl || 0)).toFixed(2)} · ${esc(String(t.reason || '').toLowerCase().replace(/_/g, ' '))}`}</td>
            </tr>`).join('') || '<tr><td colspan="4" class="px-3 py-6 text-center text-slate-500">No bot trades in this window.</td></tr>';
    }

    async function load() {
        try {
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            const data = await res.json();
            if (!res.ok || data.error) throw new Error(data.error || `HTTP ${res.status}`);
            render(data);
        } catch (e) {
            $('sap-loading').classList.remove('hidden');
            $('sap-loading').textContent = `Chart data unavailable (${e.message}). Retrying…`;
            clearTimeout(state.refetchTimer);
            state.refetchTimer = setTimeout(load, 15000);
        }
    }

    function setStream(text, ok) {
        const el = $('sap-stream');
        el.textContent = `live: ${text}`;
        el.className = `px-2 py-0.5 rounded-lg text-[11px] font-mono border ${ok ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30' : 'bg-slate-800 text-slate-400 border-slate-700'}`;
    }

    function stream() {
        if (typeof WebSocket === 'undefined') return;
        const ws = new WebSocket(`wss://fstream.binance.com/ws/${symbol.toLowerCase()}@kline_1h`);
        state.ws = ws;
        ws.onopen = () => { state.retry = 0; setStream('on', true); };
        ws.onmessage = (event) => {
            let k;
            try { k = JSON.parse(event.data).k; } catch (e) { return; }
            if (!k || !state.data) return;
            const bar = { time: Math.floor(Number(k.t) / 1000), open: Number(k.o), high: Number(k.h), low: Number(k.l), close: Number(k.c) };
            try {
                state.candles.update(bar);
                state.volume.update({ time: bar.time, value: Number(k.v), color: bar.close >= bar.open ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)' });
            } catch (e) {
                return; // an older bar than the chart's last one
            }
            setPrice(bar);
            if (k.x) {
                // Candle closed: the server has new signals once Binance serves it; give it a few seconds.
                clearTimeout(state.refetchTimer);
                state.refetchTimer = setTimeout(load, 4000);
            }
        };
        ws.onclose = () => {
            if (state.ws !== ws) return;
            setStream('reconnecting', false);
            state.retry = Math.min(state.retry + 1, 6);
            setTimeout(stream, 1000 * 2 ** state.retry);
        };
        ws.onerror = () => { try { ws.close(); } catch (e) { /* ignore */ } };
    }

    document.querySelectorAll('[data-toggle]').forEach((input) => {
        const key = input.dataset.toggle;
        input.checked = !!toggles[key];
        input.addEventListener('change', () => {
            toggles[key] = input.checked;
            saveToggles();
            applyMarkers();
        });
    });

    initChart();
    load();
    stream();
    // Bot trades and the engine's last decision change between candle closes.
    setInterval(() => { if (document.visibilityState === 'visible') load(); }, 60000);
})();
