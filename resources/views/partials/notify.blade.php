{{-- App-wide notifications: toasts plus confirm / prompt dialogs, replacing the browser's alert(), confirm() and prompt().
     Notify.toast(msg, type)                      type: success | error | warning | info
     await Notify.confirm({title, message, okText, danger})          -> true / false
     await Notify.prompt({title, message, value, okText, inputType}) -> string / null --}}
<style>
    #afte-toasts { position: fixed; top: 16px; right: 16px; left: 16px; z-index: 9998; display: flex; flex-direction: column; align-items: flex-end; gap: 10px; pointer-events: none; }
    .afte-toast { pointer-events: auto; width: 100%; max-width: 380px; display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border-radius: 14px;
        background: rgba(15, 23, 42, .92); backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); border: 1px solid rgba(148, 163, 184, .18);
        box-shadow: 0 18px 40px -12px rgba(0, 0, 0, .7); color: #e2e8f0; font: 500 13px/1.45 ui-sans-serif, system-ui, sans-serif; position: relative; overflow: hidden;
        animation: afte-in .28s cubic-bezier(.2, .9, .3, 1.2) both; }
    .afte-toast.afte-out { animation: afte-out .22s ease-in both; }
    .afte-toast-icon { flex: none; width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center; font-weight: 800; font-size: 14px; }
    .afte-toast-body { flex: 1; min-width: 0; white-space: pre-line; word-break: break-word; padding-top: 3px; }
    .afte-toast-title { display: block; font-weight: 700; margin-bottom: 1px; }
    .afte-toast-close { flex: none; background: none; border: 0; color: #64748b; font-size: 18px; line-height: 1; cursor: pointer; padding: 2px 4px; }
    .afte-toast-close:hover { color: #e2e8f0; }
    .afte-toast-bar { position: absolute; left: 0; bottom: 0; height: 2px; animation: afte-bar linear forwards; }
    .afte-success .afte-toast-icon { background: rgba(16, 185, 129, .18); color: #34d399; } .afte-success .afte-toast-bar { background: #10b981; } .afte-success { border-color: rgba(16, 185, 129, .35); }
    .afte-error .afte-toast-icon { background: rgba(244, 63, 94, .18); color: #fb7185; } .afte-error .afte-toast-bar { background: #f43f5e; } .afte-error { border-color: rgba(244, 63, 94, .35); }
    .afte-warning .afte-toast-icon { background: rgba(245, 158, 11, .18); color: #fbbf24; } .afte-warning .afte-toast-bar { background: #f59e0b; } .afte-warning { border-color: rgba(245, 158, 11, .35); }
    .afte-info .afte-toast-icon { background: rgba(56, 189, 248, .18); color: #38bdf8; } .afte-info .afte-toast-bar { background: #0ea5e9; } .afte-info { border-color: rgba(56, 189, 248, .35); }

    .afte-modal-wrap { position: fixed; inset: 0; z-index: 9999; display: grid; place-items: center; padding: 16px; background: rgba(2, 6, 23, .7);
        backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); animation: afte-fade .18s ease-out both; }
    .afte-modal-wrap.afte-out { animation: afte-fade-out .16s ease-in both; }
    .afte-modal { width: 100%; max-width: 420px; border-radius: 18px; background: linear-gradient(180deg, #111a2e, #0b1222); border: 1px solid rgba(148, 163, 184, .2);
        box-shadow: 0 30px 70px -20px rgba(0, 0, 0, .85); color: #e2e8f0; font: 14px/1.5 ui-sans-serif, system-ui, sans-serif; padding: 22px; animation: afte-pop .24s cubic-bezier(.2, .9, .3, 1.2) both; }
    .afte-modal-head { display: flex; gap: 12px; align-items: center; margin-bottom: 10px; }
    .afte-modal-icon { flex: none; width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; font-size: 18px; font-weight: 800; background: rgba(56, 189, 248, .15); color: #38bdf8; }
    .afte-modal.afte-danger .afte-modal-icon { background: rgba(244, 63, 94, .15); color: #fb7185; }
    .afte-modal-title { font-size: 16px; font-weight: 700; color: #f8fafc; }
    .afte-modal-msg { color: #94a3b8; white-space: pre-line; word-break: break-word; font-size: 13px; }
    .afte-modal-input { width: 100%; margin-top: 14px; padding: 10px 12px; border-radius: 10px; background: #020617; border: 1px solid #334155; color: #f1f5f9;
        font: 14px ui-monospace, SFMono-Regular, Menlo, monospace; outline: none; }
    .afte-modal-input:focus { border-color: #38bdf8; box-shadow: 0 0 0 3px rgba(56, 189, 248, .2); }
    .afte-modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; }
    .afte-btn { border: 1px solid transparent; border-radius: 10px; padding: 9px 16px; font: 600 13px ui-sans-serif, system-ui, sans-serif; cursor: pointer; transition: filter .15s, background .15s; }
    .afte-btn-cancel { background: transparent; border-color: #334155; color: #cbd5e1; } .afte-btn-cancel:hover { background: #1e293b; }
    .afte-btn-ok { background: linear-gradient(135deg, #0ea5e9, #10b981); color: #fff; } .afte-btn-ok:hover { filter: brightness(1.12); }
    .afte-danger .afte-btn-ok { background: linear-gradient(135deg, #f43f5e, #e11d48); }

    @keyframes afte-in { from { opacity: 0; transform: translateX(24px) scale(.96); } to { opacity: 1; transform: none; } }
    @keyframes afte-out { to { opacity: 0; transform: translateX(24px) scale(.96); } }
    @keyframes afte-bar { from { width: 100%; } to { width: 0; } }
    @keyframes afte-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes afte-fade-out { to { opacity: 0; } }
    @keyframes afte-pop { from { opacity: 0; transform: translateY(10px) scale(.95); } to { opacity: 1; transform: none; } }
    @media (min-width: 640px) { #afte-toasts { left: auto; } }
    @media (prefers-reduced-motion: reduce) { .afte-toast, .afte-modal, .afte-modal-wrap { animation: none; } }
</style>
<script>
    window.Notify = (() => {
        const ICONS = { success: '✓', error: '!', warning: '⚠', info: 'i' };
        const TITLES = { success: 'Success', error: 'Error', warning: 'Warning', info: 'Notice' };

        function el(tag, cls, text) {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (text !== undefined) n.textContent = text;
            return n;
        }

        /** Guess the toast type from leftover emoji prefixes in older messages. */
        function typeOf(message, type) {
            if (type) return type;
            return /^\s*(❌|⚠️?|⛔)/.test(message) ? 'error' : /^\s*✅/.test(message) ? 'success' : 'info';
        }

        function toast(message, type, opts = {}) {
            message = String(message ?? '');
            type = typeOf(message, type);
            message = message.replace(/^\s*(✅|❌|⚠️?|⛔)\s*/, '');
            let box = document.getElementById('afte-toasts');
            if (!box) {
                box = el('div');
                box.id = 'afte-toasts';
                document.body.appendChild(box);
            }
            const duration = opts.duration ?? (type === 'error' ? 8000 : 5000);
            const t = el('div', `afte-toast afte-${type}`);
            t.setAttribute('role', type === 'error' ? 'alert' : 'status');
            const body = el('div', 'afte-toast-body');
            body.append(el('span', 'afte-toast-title', opts.title || TITLES[type]), el('span', '', message));
            const close = el('button', 'afte-toast-close', '×');
            close.type = 'button';
            close.setAttribute('aria-label', 'Close');
            const bar = el('div', 'afte-toast-bar');
            bar.style.animationDuration = duration + 'ms';
            t.append(el('div', 'afte-toast-icon', ICONS[type]), body, close, bar);
            box.appendChild(t);
            while (box.children.length > 5) box.firstChild.remove();

            let timer;
            const dismiss = () => { clearTimeout(timer); t.classList.add('afte-out'); setTimeout(() => t.remove(), 220); };
            const start = () => { timer = setTimeout(dismiss, duration); bar.style.animationPlayState = 'running'; };
            close.onclick = dismiss;
            t.onmouseenter = () => { clearTimeout(timer); bar.style.animationPlayState = 'paused'; };
            t.onmouseleave = start;
            start();
        }

        function dialog({ title = 'Are you sure?', message = '', okText = 'OK', cancelText = 'Cancel', danger = false, input = false, value = '', inputType = 'text' } = {}) {
            return new Promise(resolve => {
                const previous = document.activeElement;
                const wrap = el('div', 'afte-modal-wrap');
                const modal = el('div', 'afte-modal' + (danger ? ' afte-danger' : ''));
                modal.setAttribute('role', 'dialog');
                modal.setAttribute('aria-modal', 'true');
                const head = el('div', 'afte-modal-head');
                head.append(el('div', 'afte-modal-icon', danger ? '!' : '?'), el('div', 'afte-modal-title', title));
                modal.append(head);
                if (message) modal.append(el('div', 'afte-modal-msg', message));
                let field = null;
                if (input) {
                    field = el('input', 'afte-modal-input');
                    field.type = inputType;
                    if (inputType === 'number') field.step = 'any';
                    field.value = value ?? '';
                    modal.append(field);
                }
                const actions = el('div', 'afte-modal-actions');
                const cancel = el('button', 'afte-btn afte-btn-cancel', cancelText);
                const ok = el('button', 'afte-btn afte-btn-ok', okText);
                cancel.type = ok.type = 'button';
                actions.append(cancel, ok);
                modal.append(actions);
                wrap.append(modal);
                document.body.appendChild(wrap);
                (field || (danger ? cancel : ok)).focus();
                if (field) field.select();

                const finish = result => {
                    document.removeEventListener('keydown', onKey, true);
                    wrap.classList.add('afte-out');
                    setTimeout(() => wrap.remove(), 160);
                    if (previous && previous.focus) previous.focus();
                    resolve(result);
                };
                const onKey = e => {
                    if (e.key === 'Escape') { e.preventDefault(); finish(input ? null : false); }
                    if (e.key === 'Enter' && (field || document.activeElement === ok)) { e.preventDefault(); finish(input ? field.value : true); }
                };
                document.addEventListener('keydown', onKey, true);
                ok.onclick = () => finish(input ? field.value : true);
                cancel.onclick = () => finish(input ? null : false);
                wrap.onclick = e => { if (e.target === wrap) finish(input ? null : false); };
            });
        }

        return {
            toast,
            success: (m, o) => toast(m, 'success', o),
            error: (m, o) => toast(m, 'error', o),
            warning: (m, o) => toast(m, 'warning', o),
            info: (m, o) => toast(m, 'info', o),
            confirm: opts => dialog(typeof opts === 'string' ? { message: opts } : opts),
            prompt: opts => dialog({ okText: 'Save', ...(typeof opts === 'string' ? { message: opts } : opts), input: true }),
        };
    })();

    // Any alert() left anywhere (inline handlers, third-party code) shows as a toast instead of a blocking browser box.
    window.alert = message => Notify.toast(message);

    // <form data-confirm="..."> asks with the custom dialog before submitting.
    document.addEventListener('submit', async e => {
        const form = e.target;
        if (!form.dataset || !form.dataset.confirm || form.dataset.confirmed) return;
        e.preventDefault();
        if (await Notify.confirm({ title: form.dataset.confirmTitle || 'Please confirm', message: form.dataset.confirm, okText: form.dataset.confirmOk || 'Yes', danger: 'danger' in form.dataset })) {
            form.dataset.confirmed = '1';
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }
    }, true);
</script>
