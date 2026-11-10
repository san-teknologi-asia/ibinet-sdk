{{--
    Shared, per-user dashboard board for IDC, IFAS and IBOS.

    Usage (inside an app's @section('content')):
        @include('ibinet::dashboard.board', ['board' => $board, 'note' => 'Scoped to your 3 projects'])

    $board comes from InteractsWithDashboard::dashboardBoard(). The layout is
    the signed-in user's own; nothing done here changes another user's board.
--}}
@php
    $note = $note ?? null;
    $hasWidgets = count($board['picker']) > 0;
@endphp

@push('styles')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridstack@9.2.0/dist/gridstack.min.css" />
    <style>
        /*
         * Chart palette as roles. Light and dark values swap here so the chart
         * code never touches raw hex: categorical slots 1-3 and a one-hue
         * ordinal ramp for genuinely ordered bands (ticket age).
         */
        .dash {
            --surface-1: #ffffff;
            --text-primary: #0b0b0b;
            --text-secondary: #52514e;
            --text-muted: #898781;
            --gridline: #e1e0d9;
            --axis: #c3c2b7;
            --series-1: #2a78d6;
            --series-2: #eb6834;
            --series-3: #1baf7a;
            --ord-1: #86b6ef;
            --ord-2: #5598e7;
            --ord-3: #2a78d6;
            --ord-4: #1c5cab;
        }

        @media (prefers-color-scheme: dark) {
            :root:where(:not([data-bs-theme="light"])) .dash {
                --surface-1: #343b4a;
                --text-primary: #dee2e6;
                --text-secondary: #aab8c5;
                --text-muted: #8391a2;
                --gridline: #3f4655;
                --axis: #4a5265;
                --series-1: #3987e5;
                --series-2: #d95926;
                --series-3: #199e70;
                --ord-1: #9ec5f4;
                --ord-2: #6da7ec;
                --ord-3: #3987e5;
                --ord-4: #256abf;
            }
        }

        :root[data-bs-theme="dark"] .dash {
            --surface-1: #343b4a;
            --text-primary: #dee2e6;
            --text-secondary: #aab8c5;
            --text-muted: #8391a2;
            --gridline: #3f4655;
            --axis: #4a5265;
            --series-1: #3987e5;
            --series-2: #d95926;
            --series-3: #199e70;
            --ord-1: #9ec5f4;
            --ord-2: #6da7ec;
            --ord-3: #3987e5;
            --ord-4: #256abf;
        }

        /* ------------------------------------------------------- toolbar */
        .dash-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: .5rem;
            margin-bottom: .75rem;
        }

        .dash-meta {
            font-size: .75rem;
            color: var(--bs-secondary-color);
            display: flex;
            flex-wrap: wrap;
            gap: .25rem .75rem;
            align-items: center;
        }

        .dash-actions {
            display: flex;
            flex-wrap: wrap;
            gap: .5rem;
            align-items: center;
        }

        .dash-status {
            font-size: .75rem;
            color: var(--bs-secondary-color);
            min-width: 4.5rem;
            text-align: right;
        }

        .dash-status.is-error {
            color: var(--bs-danger);
        }

        .dash .dash-edit-only {
            display: none !important;
        }

        .dash.is-editing .dash-edit-only {
            display: inline-flex !important;
        }

        .dash.is-editing .dash-view-only {
            display: none !important;
        }

        .dash-hint {
            display: none;
            font-size: .8125rem;
            margin-bottom: .75rem;
            padding: .6rem .9rem;
            align-items: center;
            gap: .5rem;
        }

        .dash.is-editing .dash-hint {
            display: flex;
        }

        /* ---------------------------------------------------------- grid */
        .dash .grid-stack {
            background: transparent;
        }

        .dash .grid-stack > .grid-stack-item > .grid-stack-item-content {
            overflow: visible !important;
        }

        .dash.is-editing .grid-stack {
            background-image: radial-gradient(var(--bs-border-color) 1px, transparent 1px);
            background-size: 16px 16px;
            border-radius: 8px;
        }

        .dash .grid-stack-placeholder > .placeholder-content {
            background: rgba(42, 120, 214, .10) !important;
            border: 2px dashed var(--series-1) !important;
            border-radius: 8px;
        }

        /* ---------------------------------------------------------- card */
        .dash-card {
            height: 100%;
            margin: 0;
            display: flex;
            flex-direction: column;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, .06);
            padding: 14px 16px;
            overflow: hidden;
            transition: box-shadow .2s, outline-color .2s;
            outline: 2px solid transparent;
            outline-offset: -2px;
        }

        .dash.is-editing .dash-card {
            cursor: grab;
            outline: 1px dashed var(--bs-border-color);
        }

        .dash.is-editing .dash-card:hover {
            outline-color: var(--series-1);
        }

        .dash-card.is-new {
            animation: dash-flash 1.6s ease-out;
        }

        @keyframes dash-flash {
            0% { outline-color: var(--series-1); box-shadow: 0 0 0 6px rgba(42, 120, 214, .20); }
            100% { box-shadow: 0 2px 4px rgba(0, 0, 0, .06); }
        }

        .dash-card-head {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
        }

        .dash-card-titles {
            flex: 1 1 auto;
            min-width: 0;
        }

        .dash-card-titles a {
            color: inherit;
        }

        .dash-card-titles a:hover {
            text-decoration: underline;
        }

        .dash-remove {
            display: none;
            flex: 0 0 auto;
            width: 26px;
            height: 26px;
            padding: 0;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            line-height: 1;
        }

        .dash.is-editing .dash-remove {
            display: inline-flex;
        }

        .dash-card-body {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .viz-title {
            font-size: .875rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .viz-sub {
            font-size: .75rem;
            color: var(--text-muted);
            margin: .1rem 0 .6rem;
        }

        .viz-sub:empty {
            display: none;
        }

        /* Stat tiles: the number is the chart. */
        .dash-type-stat .viz-title {
            font-size: .8125rem;
            font-weight: 400;
            color: var(--text-secondary);
            margin-bottom: .25rem;
        }

        .stat-value {
            font-size: 2rem;
            line-height: 1.1;
            font-weight: 600;
            color: var(--text-primary);
        }

        .stat-context {
            font-size: .8125rem;
            color: var(--text-muted);
            margin-top: .35rem;
        }

        .viz-canvas-wrap {
            position: relative;
            flex: 1 1 auto;
            min-height: 100px;
        }

        /* Visible values: relief for sub-3:1 series colours, and the table twin. */
        .viz-values {
            display: flex;
            flex-wrap: wrap;
            gap: .25rem .9rem;
            margin-top: .5rem;
            font-size: .75rem;
            color: var(--text-secondary);
        }

        .viz-values .swatch {
            display: inline-block;
            width: .625rem;
            height: .625rem;
            border-radius: 2px;
            margin-right: .35rem;
            vertical-align: -1px;
        }

        .viz-values b {
            color: var(--text-primary);
            font-variant-numeric: tabular-nums;
        }

        .viz-empty {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            color: var(--text-muted);
            font-size: .8125rem;
            text-align: center;
            min-height: 60px;
        }

        .dash-table-wrap {
            flex: 1 1 auto;
            overflow: auto;
            min-height: 0;
        }

        .dash-table {
            font-size: .8125rem;
        }

        .dash-table th {
            font-weight: 600;
            color: var(--text-secondary);
            position: sticky;
            top: 0;
            background: var(--bs-card-bg, var(--bs-body-bg));
        }

        .dash-table td,
        .dash-table th {
            font-variant-numeric: tabular-nums;
        }

        /* Loading placeholder. */
        .dash-skeleton {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            gap: .5rem;
            justify-content: center;
        }

        .dash-skeleton span {
            display: block;
            height: .75rem;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--bs-tertiary-bg) 25%, var(--bs-secondary-bg) 50%, var(--bs-tertiary-bg) 75%);
            background-size: 200% 100%;
            animation: dash-shimmer 1.2s infinite linear;
        }

        @keyframes dash-shimmer {
            from { background-position: 200% 0; }
            to { background-position: -200% 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .dash-skeleton span,
            .dash-card.is-new {
                animation: none;
            }
        }

        /* --------------------------------------------------- empty board */
        .dash-empty-board {
            display: none;
            border: 1px dashed var(--bs-border-color);
            border-radius: 8px;
            padding: 3rem 1rem;
            text-align: center;
            color: var(--bs-secondary-color);
        }

        .dash.is-empty .dash-empty-board {
            display: block;
        }

        .dash-empty-board .mdi {
            font-size: 2.25rem;
            display: block;
            margin-bottom: .5rem;
        }

        /* -------------------------------------------------------- picker */
        .dash-picker-section {
            font-size: .75rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            color: var(--bs-secondary-color);
            margin: 0 0 .5rem;
        }

        .dash-picker-block:not(.d-none) ~ .dash-picker-block .dash-picker-section {
            margin-top: 1.5rem;
        }

        .dash-option {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            text-align: left;
            padding: .85rem 1rem;
            border: 1px solid var(--bs-border-color);
            border-radius: 6px;
            background: var(--bs-body-bg);
            color: var(--bs-body-color);
            transition: border-color .15s, background-color .15s;
        }

        .dash-option:hover,
        .dash-option:focus-visible {
            border-color: var(--bs-primary);
            outline: none;
        }

        .dash-option.is-added {
            border-color: var(--bs-primary);
            background: var(--bs-primary-bg-subtle);
        }

        .dash-option-icon {
            flex: 0 0 auto;
            width: 36px;
            height: 36px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }

        .dash-option-text {
            min-width: 0;
            flex: 1 1 auto;
        }

        .dash-option-title {
            display: flex;
            align-items: center;
            gap: .4rem;
            font-weight: 600;
        }

        .dash-option-desc {
            display: block;
            font-size: .8125rem;
            color: var(--bs-secondary-color);
            margin-top: .15rem;
        }

        .dash-option-added {
            display: none;
            font-size: .6875rem;
            font-weight: 600;
            color: var(--bs-primary);
            margin-left: auto;
            white-space: nowrap;
        }

        .dash-option.is-added .dash-option-added {
            display: inline-flex;
            align-items: center;
            gap: .15rem;
        }
    </style>
@endpush

<div class="dash" id="dash-board">
    <div class="dash-toolbar">
        <div class="dash-meta">
            @if ($note)
                <span>{{ $note }}</span>
            @endif
            <span id="dash-generated">Loading figures&hellip;</span>
        </div>

        <div class="dash-actions">
            <span class="dash-status dash-edit-only" id="dash-status" role="status" aria-live="polite"></span>

            <button type="button" class="btn btn-sm btn-outline-secondary dash-view-only" data-dash-action="refresh">
                <i class="mdi mdi-refresh"></i> Refresh data
            </button>
            @if ($hasWidgets)
                <button type="button" class="btn btn-sm btn-primary dash-view-only d-none d-md-inline-flex align-items-center gap-1"
                    data-dash-action="edit">
                    <i class="mdi mdi-pencil-outline"></i> Customize
                </button>
            @endif

            <button type="button" class="btn btn-sm btn-outline-secondary dash-edit-only align-items-center gap-1"
                data-dash-action="reset">
                <i class="mdi mdi-undo"></i> Reset to default
            </button>
            <button type="button" class="btn btn-sm btn-success dash-edit-only align-items-center gap-1"
                data-dash-action="done">
                <i class="mdi mdi-check"></i> Done
            </button>
            <button type="button" class="btn btn-sm btn-primary dash-edit-only align-items-center gap-1"
                data-dash-action="add">
                <i class="mdi mdi-plus"></i> Add widget
            </button>
        </div>
    </div>

    <div class="alert alert-info dash-hint" role="note">
        <i class="mdi mdi-information-outline"></i>
        <span>Drag a card to move it and drag its edges to resize. Changes save automatically and only
            change <strong>your</strong> dashboard &mdash; other users keep their own.</span>
    </div>

    <div class="dash-empty-board">
        @if ($hasWidgets)
            <i class="mdi mdi-view-dashboard-outline"></i>
            <p class="mb-3">Your dashboard is empty.</p>
            <button type="button" class="btn btn-primary btn-sm" data-dash-action="add">
                <i class="mdi mdi-plus"></i> Add widget
            </button>
        @else
            <i class="mdi mdi-shield-lock-outline"></i>
            <p class="mb-0">Your role has no dashboard widgets yet. Ask an administrator to grant the
                permissions for the data you need.</p>
        @endif
    </div>

    <div class="grid-stack" id="dash-grid"></div>

    {{-- Add-widget picker --}}
    <div class="modal fade" id="dash-picker" tabindex="-1" aria-labelledby="dash-picker-title" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dash-picker-title">Add widget</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="search" class="form-control mb-3" id="dash-picker-search"
                        placeholder="Search widgets&hellip;" aria-label="Search widgets" autocomplete="off">
                    <div id="dash-picker-list"></div>
                    <p class="text-muted text-center my-4 d-none" id="dash-picker-none">No widgets match your search.</p>
                </div>
            </div>
        </div>
    </div>

    <template id="dash-card-tpl">
        <div class="card dash-card">
            <div class="dash-card-head">
                <div class="dash-card-titles">
                    <p class="viz-title" data-title></p>
                    <p class="viz-sub" data-sub></p>
                </div>
                <button type="button" class="btn btn-light dash-remove" data-remove aria-label="Remove widget"
                    title="Remove from my dashboard">
                    <i class="mdi mdi-close"></i>
                </button>
            </div>
            <div class="dash-card-body" data-body></div>
        </div>
    </template>
</div>

<script type="application/json" id="dash-config">@json($board)</script>

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/gridstack@9.2.0/dist/gridstack-all.js"></script>
    <script src="{{ asset('assets/libs/chart.js/Chart.min.js') }}"></script>
    <script>
        (function () {
            'use strict';

            const root = document.getElementById('dash-board');
            if (!root || typeof GridStack === 'undefined' || typeof Chart === 'undefined') {
                return;
            }

            const config = JSON.parse(document.getElementById('dash-config').textContent);

            /** Widget definitions this user may place, by key. */
            const defs = {};
            config.picker.forEach((section) => section.widgets.forEach((w) => { defs[w.key] = w; }));

            const els = {
                grid: document.getElementById('dash-grid'),
                status: document.getElementById('dash-status'),
                generated: document.getElementById('dash-generated'),
                tpl: document.getElementById('dash-card-tpl'),
                picker: document.getElementById('dash-picker'),
                pickerList: document.getElementById('dash-picker-list'),
                pickerSearch: document.getElementById('dash-picker-search'),
                pickerNone: document.getElementById('dash-picker-none'),
            };

            // ------------------------------------------------------ formatting
            const nf = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 });
            const compact = new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 });

            const fmt = (value, format) => {
                if (value === null || value === undefined || value === '') return '—';
                const n = Number(value);
                switch (format) {
                    case 'idr': return 'Rp ' + nf.format(n);
                    case 'percent': return nf.format(n) + '%';
                    case 'hours': return nf.format(n) + ' h';
                    case 'number': return nf.format(n);
                    default: return String(value);
                }
            };

            const tick = (value, format) => {
                const n = Number(value);
                const short = Math.abs(n) >= 10000 ? compact.format(n) : nf.format(n);
                if (format === 'idr') return 'Rp ' + short;
                if (format === 'hours') return short + 'h';
                if (format === 'percent') return short + '%';
                return short;
            };

            // ---------------------------------------------------------- colour
            let C = {};
            const readTokens = () => {
                const css = getComputedStyle(root);
                const t = (name) => css.getPropertyValue(name).trim();
                C = {
                    surface: t('--surface-1'),
                    textMuted: t('--text-muted'),
                    grid: t('--gridline'),
                    axis: t('--axis'),
                    series: [t('--series-1'), t('--series-2'), t('--series-3')],
                    ord: [t('--ord-1'), t('--ord-2'), t('--ord-3'), t('--ord-4')],
                };
                Chart.defaults.global.defaultFontColor = C.textMuted;
            };

            Chart.defaults.global.defaultFontFamily = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
            Chart.defaults.global.defaultFontSize = 11;
            Chart.defaults.global.maintainAspectRatio = false;
            Chart.defaults.global.legend.display = false;
            readTokens();

            // ------------------------------------------------------------ grid
            const grid = GridStack.init({
                column: 12,
                cellHeight: 96,
                margin: 6,
                float: false,
                animate: true,
                staticGrid: true,
                disableOneColumnMode: false,
                oneColumnSize: 768,
                resizable: { handles: 'e, se, s, sw, w' },
                draggable: { handle: '.dash-card', scroll: true },
            }, els.grid);

            const charts = {};
            const payloads = {};
            let editing = false;
            let suspendSave = false;

            const cardFor = (key) => els.grid.querySelector('.dash-card[data-key="' + CSS.escape(key) + '"]');
            const onBoard = (key) => !!cardFor(key);
            const boardKeys = () => grid.engine.nodes.map((n) => n.id);

            const node = (tag, className, text) => {
                const el = document.createElement(tag);
                if (className) el.className = className;
                if (text !== undefined && text !== null) el.textContent = text;
                return el;
            };

            const buildCard = (def) => {
                const card = els.tpl.content.firstElementChild.cloneNode(true);
                card.dataset.key = def.key;
                card.classList.add('dash-type-' + def.type);
                card.querySelector('[data-title]').textContent = def.label;
                card.querySelector('[data-sub]').textContent = def.type === 'stat' ? '' : def.description;
                card.querySelector('[data-remove]').setAttribute('aria-label', 'Remove ' + def.label);
                card.querySelector('[data-remove]').addEventListener('click', () => removeWidget(def.key));
                setLoading(card);
                return card;
            };

            const mount = (item, autoPosition) => {
                const def = defs[item.key];
                if (!def || onBoard(item.key)) return null;

                const opts = { id: item.key, w: item.w || def.w, h: item.h || def.h, minW: def.min_w, minH: def.min_h };
                if (autoPosition) {
                    opts.autoPosition = true;
                } else {
                    opts.x = item.x;
                    opts.y = item.y;
                }

                const el = grid.addWidget(opts);
                el.querySelector('.grid-stack-item-content').appendChild(buildCard(def));
                return el;
            };

            const syncEmpty = () => root.classList.toggle('is-empty', grid.engine.nodes.length === 0);

            // ------------------------------------------------------- rendering
            const setLoading = (card) => {
                const body = card.querySelector('[data-body]');
                body.innerHTML = '<div class="dash-skeleton" aria-hidden="true"><span style="width:60%"></span>' +
                    '<span style="width:85%"></span><span style="width:40%"></span></div>';
            };

            const destroyChart = (key) => {
                if (charts[key]) {
                    charts[key].destroy();
                    delete charts[key];
                }
            };

            const emptyNode = (message) => node('div', 'viz-empty', message);

            const renderError = (body, key, message) => {
                const box = node('div', 'viz-empty');
                box.appendChild(node('span', null, message || 'This widget could not be loaded.'));
                const retry = node('button', 'btn btn-sm btn-link p-0', 'Try again');
                retry.type = 'button';
                retry.addEventListener('click', () => load([key], true));
                box.appendChild(retry);
                body.appendChild(box);
            };

            const renderStat = (body, key, p) => {
                const value = node('div', 'stat-value', fmt(p.value, p.format));
                if (p.value === null || p.value === undefined) value.classList.add('text-muted');
                if (p.tone === 'danger') value.classList.add('text-danger');
                body.appendChild(value);
                if (p.context) body.appendChild(node('div', 'stat-context', p.context));
            };

            const renderReadout = (body, items, format) => {
                if (!items || !items.length) return;
                const wrap = node('div', 'viz-values');
                items.forEach((item) => {
                    const span = document.createElement('span');
                    if (item.series !== undefined && item.series !== null) {
                        const swatch = node('span', 'swatch');
                        swatch.style.background = C.series[item.series];
                        span.appendChild(swatch);
                    }
                    span.appendChild(document.createTextNode(item.label + ' '));
                    span.appendChild(node('b', null, fmt(item.value, item.format || format)));
                    wrap.appendChild(span);
                });
                body.appendChild(wrap);
            };

            const renderChart = (body, key, p) => {
                const labels = p.labels || [];
                // Never more than three series: a fourth would need a hue the
                // validated palette does not have, so the catalog folds instead.
                const series = (p.series || []).slice(0, C.series.length);
                const total = series.reduce((sum, s) => sum + s.data.reduce((a, b) => a + Math.abs(Number(b) || 0), 0), 0);

                if (!labels.length || total === 0) {
                    body.appendChild(emptyNode(p.empty || 'No data yet'));
                    renderReadout(body, p.readout, p.format);
                    return;
                }

                const wrap = node('div', 'viz-canvas-wrap');
                const canvas = document.createElement('canvas');
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', (defs[key] || {}).label || 'Chart');
                wrap.appendChild(canvas);
                body.appendChild(wrap);
                renderReadout(body, p.readout, p.format);

                const line = p.chart === 'line';
                const horizontal = p.chart === 'hbar';
                const multi = series.length > 1;
                const stacked = !!p.stacked;

                const datasets = series.map((s, i) => {
                    const color = C.series[i];
                    if (line) {
                        return {
                            label: s.label, data: s.data, borderColor: color,
                            backgroundColor: 'transparent', fill: false, borderWidth: 2,
                            pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: color,
                            pointBorderColor: C.surface, pointBorderWidth: 2, lineTension: .25,
                        };
                    }
                    return {
                        label: s.label, data: s.data,
                        backgroundColor: p.ordinal && !multi ? labels.map((_, j) => C.ord[Math.min(j, C.ord.length - 1)]) : color,
                        // 2px surface gap between fills, not a border ring.
                        borderColor: C.surface, borderWidth: 2, barPercentage: .7,
                    };
                });

                const categoryAxis = {
                    stacked: stacked,
                    gridLines: { display: false, color: C.grid, zeroLineColor: C.axis, drawBorder: true },
                    ticks: { fontColor: C.textMuted, autoSkip: true, maxRotation: 0 },
                };
                const valueAxis = {
                    stacked: stacked,
                    gridLines: { color: C.grid, zeroLineColor: C.axis, drawBorder: false, lineWidth: 1 },
                    ticks: {
                        beginAtZero: true, fontColor: C.textMuted, precision: 0, maxTicksLimit: 6,
                        callback: (v) => tick(v, p.format),
                    },
                };

                charts[key] = new Chart(canvas.getContext('2d'), {
                    type: line ? 'line' : (horizontal ? 'horizontalBar' : 'bar'),
                    data: { labels: labels, datasets: datasets },
                    options: {
                        legend: multi
                            ? { display: true, position: 'bottom', labels: { boxWidth: 8, usePointStyle: true } }
                            : { display: false },
                        tooltips: {
                            mode: line || stacked ? 'index' : 'nearest',
                            intersect: false,
                            callbacks: {
                                label: (item, data) => (multi ? data.datasets[item.datasetIndex].label + ': ' : '') +
                                    fmt(horizontal ? item.xLabel : item.yLabel, p.format),
                            },
                        },
                        hover: { mode: line || stacked ? 'index' : 'nearest', intersect: false },
                        scales: horizontal
                            ? { xAxes: [valueAxis], yAxes: [categoryAxis] }
                            : { xAxes: [categoryAxis], yAxes: [valueAxis] },
                    },
                });
            };

            const renderTable = (body, key, p) => {
                const rows = p.rows || [];
                if (!rows.length) {
                    body.appendChild(emptyNode(p.empty || 'Nothing to show'));
                } else {
                    const wrap = node('div', 'dash-table-wrap');
                    const table = node('table', 'table table-sm mb-0 dash-table');
                    const head = table.createTHead().insertRow();
                    (p.columns || []).forEach((col) => {
                        const th = node('th', col.align === 'end' ? 'text-end' : null, col.label);
                        th.scope = 'col';
                        head.appendChild(th);
                    });
                    const tbody = table.createTBody();
                    rows.forEach((row, i) => {
                        const tr = tbody.insertRow();
                        row.forEach((value, c) => {
                            const col = (p.columns || [])[c] || {};
                            const td = tr.insertCell();
                            if (col.align === 'end') td.className = 'text-end';
                            const text = fmt(value, col.format || 'text');
                            const link = c === 0 && p.links ? p.links[i] : null;
                            if (link) {
                                const a = node('a', null, text);
                                a.href = link;
                                td.appendChild(a);
                            } else {
                                td.textContent = text;
                            }
                        });
                    });
                    wrap.appendChild(table);
                    body.appendChild(wrap);
                }
                if (p.footnote) body.appendChild(node('p', 'stat-context mb-0', p.footnote));
            };

            const renderers = { stat: renderStat, chart: renderChart, table: renderTable };

            const render = (key, payload) => {
                const card = cardFor(key);
                if (!card) return;

                destroyChart(key);
                payloads[key] = payload;

                const def = defs[key];
                const body = card.querySelector('[data-body]');
                const title = card.querySelector('[data-title]');
                body.innerHTML = '';

                title.textContent = def.label;
                card.querySelector('[data-sub]').textContent =
                    def.type === 'stat' ? '' : ((payload && payload.subtitle) || def.description);

                if (!payload || payload.error) {
                    renderError(body, key, payload && payload.error);
                    return;
                }

                if (payload.link) {
                    const a = node('a', null, def.label);
                    a.href = payload.link;
                    title.textContent = '';
                    title.appendChild(a);
                }

                (renderers[def.type] || renderError)(body, key, payload);
            };

            // ------------------------------------------------------------ data
            const load = (keys, refresh) => {
                keys = keys.filter((k) => defs[k]);
                if (!keys.length) return Promise.resolve();

                keys.forEach((k) => {
                    const card = cardFor(k);
                    if (card) {
                        destroyChart(k);
                        setLoading(card);
                    }
                });

                const params = new URLSearchParams();
                keys.forEach((k) => params.append('keys[]', k));
                if (refresh) params.append('refresh', '1');

                return fetch(config.urls.widgets + '?' + params.toString(), {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((r) => {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then((json) => {
                        keys.forEach((k) => render(k, json.data[k]));
                        els.generated.textContent = 'Figures cached up to 10 min · updated ' + json.generated_at;
                    })
                    .catch(() => {
                        keys.forEach((k) => render(k, { error: 'Could not reach the server.' }));
                        els.generated.textContent = 'Figures unavailable';
                    });
            };

            // ------------------------------------------------------------ save
            let saveTimer = null;
            let dirty = false;

            const setStatus = (state) => {
                const text = { pending: 'Unsaved…', saving: 'Saving…', saved: 'Saved', error: 'Not saved' }[state] || '';
                els.status.textContent = text;
                els.status.classList.toggle('is-error', state === 'error');
            };

            const request = (method, url, body) => fetch(url, {
                method: method,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': config.csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: body ? JSON.stringify(body) : undefined,
            }).then((r) => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });

            const currentLayout = () => grid.engine.nodes.map((n) => ({ key: n.id, x: n.x, y: n.y, w: n.w, h: n.h }));

            const save = () => {
                clearTimeout(saveTimer);
                // In the one-column phone view positions are not the real
                // 12-column layout, so they are never written back.
                if (!dirty || grid.getColumn() !== 12) return Promise.resolve();

                dirty = false;
                setStatus('saving');
                return request('PUT', config.urls.layout, { layout: currentLayout() })
                    .then(() => setStatus('saved'))
                    .catch(() => {
                        dirty = true;
                        setStatus('error');
                    });
            };

            const scheduleSave = () => {
                if (suspendSave) return;
                dirty = true;
                setStatus('pending');
                clearTimeout(saveTimer);
                saveTimer = setTimeout(save, 700);
            };

            grid.on('change', () => {
                if (editing) scheduleSave();
            });

            grid.on('resizestop', (event, el) => {
                const key = el && el.gridstackNode ? el.gridstackNode.id : null;
                if (key && charts[key]) charts[key].resize();
            });

            window.addEventListener('beforeunload', (event) => {
                if (dirty) {
                    save();
                    event.preventDefault();
                    event.returnValue = '';
                }
            });

            // ------------------------------------------------- add and remove
            const addWidget = (key) => {
                if (!defs[key]) return;

                if (onBoard(key)) {
                    focusWidget(key);
                    return;
                }

                const el = mount({ key: key }, true);
                if (!el) return;

                syncEmpty();
                syncPicker();
                scheduleSave();
                load([key]);
                focusWidget(key);
            };

            const removeWidget = (key) => {
                const card = cardFor(key);
                if (!card) return;

                destroyChart(key);
                delete payloads[key];
                grid.removeWidget(card.closest('.grid-stack-item'));
                syncEmpty();
                syncPicker();
                scheduleSave();
            };

            const focusWidget = (key) => {
                const card = cardFor(key);
                if (!card) return;
                card.classList.remove('is-new');
                void card.offsetWidth;
                card.classList.add('is-new');
                card.scrollIntoView({ behavior: 'smooth', block: 'center' });
            };

            const rebuild = (layout) => {
                suspendSave = true;
                Object.keys(charts).forEach(destroyChart);
                grid.removeAll();
                grid.batchUpdate();
                layout.forEach((item) => mount(item, false));
                grid.commit();
                suspendSave = false;
                syncEmpty();
                syncPicker();
                load(layout.map((item) => item.key));
            };

            // ---------------------------------------------------------- picker
            const buildPicker = () => {
                els.pickerList.innerHTML = '';

                config.picker.forEach((section) => {
                    const block = node('div', 'dash-picker-block');
                    block.appendChild(node('div', 'dash-picker-section', section.label));
                    const row = node('div', 'row g-2');

                    section.widgets.forEach((w) => {
                        const col = node('div', 'col-md-6 dash-picker-item');
                        col.dataset.search = (w.label + ' ' + w.description + ' ' + section.label).toLowerCase();

                        const btn = node('button', 'dash-option');
                        btn.type = 'button';
                        btn.dataset.key = w.key;

                        const icon = node('span', 'dash-option-icon bg-' + w.color + '-subtle text-' + w.color);
                        icon.appendChild(node('i', w.icon));
                        icon.setAttribute('aria-hidden', 'true');

                        const text = node('span', 'dash-option-text');
                        const title = node('span', 'dash-option-title', w.label);
                        const added = node('span', 'dash-option-added');
                        added.innerHTML = '<i class="mdi mdi-check"></i> On dashboard';
                        title.appendChild(added);
                        text.appendChild(title);
                        text.appendChild(node('span', 'dash-option-desc', w.description));

                        btn.appendChild(icon);
                        btn.appendChild(text);
                        btn.addEventListener('click', () => {
                            hidePicker();
                            addWidget(w.key);
                        });

                        col.appendChild(btn);
                        row.appendChild(col);
                    });

                    block.appendChild(row);
                    els.pickerList.appendChild(block);
                });

                syncPicker();
            };

            const syncPicker = () => {
                els.pickerList.querySelectorAll('.dash-option').forEach((btn) => {
                    const added = onBoard(btn.dataset.key);
                    btn.classList.toggle('is-added', added);
                    btn.setAttribute('aria-label', btn.querySelector('.dash-option-title').firstChild.textContent +
                        (added ? ' (already on your dashboard, jump to it)' : ' (add to dashboard)'));
                });
            };

            const filterPicker = () => {
                const query = els.pickerSearch.value.trim().toLowerCase();
                let shown = 0;
                els.pickerList.querySelectorAll('.dash-picker-block').forEach((block) => {
                    let visible = 0;
                    block.querySelectorAll('.dash-picker-item').forEach((item) => {
                        const match = !query || item.dataset.search.indexOf(query) !== -1;
                        item.classList.toggle('d-none', !match);
                        if (match) visible++;
                    });
                    block.classList.toggle('d-none', visible === 0);
                    shown += visible;
                });
                els.pickerNone.classList.toggle('d-none', shown > 0);
            };

            const modal = () => (window.bootstrap && bootstrap.Modal ? bootstrap.Modal.getOrCreateInstance(els.picker) : null);

            const showPicker = () => {
                els.pickerSearch.value = '';
                filterPicker();
                syncPicker();
                const m = modal();
                if (m) m.show();
            };

            const hidePicker = () => {
                const m = modal();
                if (m) m.hide();
            };

            els.picker.addEventListener('shown.bs.modal', () => els.pickerSearch.focus());
            els.pickerSearch.addEventListener('input', filterPicker);

            // --------------------------------------------------------- actions
            const confirmReset = () => {
                const title = 'Reset your dashboard?';
                const text = 'Your cards go back to the default set for your role. Other users are not affected.';
                if (window.Swal) {
                    return Swal.fire({
                        title: title, text: text, icon: 'question', showCancelButton: true,
                        confirmButtonText: 'Reset', cancelButtonText: 'Cancel', reverseButtons: true,
                    }).then((result) => result.isConfirmed);
                }
                return Promise.resolve(window.confirm(title + '\n\n' + text));
            };

            const setEditing = (on) => {
                editing = on;
                root.classList.toggle('is-editing', on);
                grid.setStatic(!on);
                if (on) {
                    setStatus(dirty ? 'pending' : '');
                } else {
                    save();
                }
            };

            const actions = {
                refresh: () => load(boardKeys(), true),
                edit: () => setEditing(true),
                done: () => setEditing(false),
                add: () => {
                    if (!editing) setEditing(true);
                    showPicker();
                },
                reset: () => confirmReset().then((ok) => {
                    if (!ok) return;
                    clearTimeout(saveTimer);
                    dirty = false;
                    setStatus('saving');
                    request('DELETE', config.urls.reset)
                        .then((json) => {
                            rebuild(json.layout || []);
                            setStatus('saved');
                        })
                        .catch(() => setStatus('error'));
                }),
            };

            root.addEventListener('click', (event) => {
                const trigger = event.target.closest('[data-dash-action]');
                if (trigger && actions[trigger.dataset.dashAction]) {
                    actions[trigger.dataset.dashAction]();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && editing && !document.querySelector('.modal.show')) {
                    setEditing(false);
                }
            });

            // Theme switches at runtime (Uplon's light/dark toggle), so charts
            // are redrawn from the figures already on hand rather than refetched.
            new MutationObserver(() => {
                readTokens();
                Object.keys(payloads).forEach((key) => {
                    if (defs[key] && defs[key].type === 'chart') render(key, payloads[key]);
                });
            }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-bs-theme'] });

            // ------------------------------------------------------------ boot
            buildPicker();
            rebuild(config.layout || []);
        })();
    </script>
@endpush
