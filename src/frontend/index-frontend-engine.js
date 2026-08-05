/* =====================================================================
   Blomstra Index Frontend — Engine (JS)
   Shared, generic renderer for ALL Blomstra index widgets.
   Loaded once, site-wide. Scans the page for containers with
   [data-biw-slug] and boots one independent instance per container —
   no document-level IDs, no shared globals between instances, safe
   for multiple indices (or multiple copies of the same index) on
   one page.

   Each container supplies its own config via data-* attributes.
   See CII's shortcode PHP for the attributes this reads.
   ===================================================================== */
(function () {
    'use strict';

    function boot() {
        var containers = document.querySelectorAll('.biw[data-biw-slug]:not([data-biw-initialized])');
        containers.forEach(function (el) {
            el.setAttribute('data-biw-initialized', '1');
            try {
                new BlomstraIndexWidget(el);
            } catch (e) {
                el.innerHTML = '<div class="biw-error">Widget failed to initialize: ' + (e && e.message ? e.message : e) + '</div>';
                if (window.console) console.error('BlomstraIndexWidget init error', e);
            }
        });
    }

    function BlomstraIndexWidget(root) {
        var slug = root.getAttribute('data-biw-slug');
        var endpoint = root.getAttribute('data-biw-endpoint');
        var namesEndpoint = root.getAttribute('data-biw-names-endpoint') || '/wp-json/blomstra/v1/country-names';
        var historyEndpoint = root.getAttribute('data-biw-history-endpoint') || ('/wp-json/blomstra/v1/index-history/' + slug);
        var scoreKey = root.getAttribute('data-biw-score-key') || 'composite_score';
        var coverageKey = root.getAttribute('data-biw-coverage-key') || 'coverage_type';
        var pillars = [];
        try { pillars = JSON.parse(root.getAttribute('data-biw-pillars') || '[]'); } catch (e) { pillars = []; }
        var bandThresholds = (root.getAttribute('data-biw-band-thresholds') || '25,50,75').split(',').map(Number);
        var bandLabels = (root.getAttribute('data-biw-band-labels') || 'Low,Medium,High,Extreme').split(',');
        var bandClasses = ['biw-badge-low', 'biw-badge-medium', 'biw-badge-high', 'biw-badge-extreme'];
        var scoreLabel = root.getAttribute('data-biw-score-label') || 'Composite Score';
        var bandSelectLabel = root.getAttribute('data-biw-band-select-label') || 'All Levels';

        var watchlistKey = 'biw_watchlist_' + slug;
        var watchlist = [];
        try { watchlist = JSON.parse(localStorage.getItem(watchlistKey) || '[]'); } catch (e) { watchlist = []; }

        var state = {
            all: [],
            filtered: [],
            names: {},
            history: {},
            indexMeta: null,
            view: 'table',
            sortKey: scoreKey,
            sortAsc: false,
            showWatchlistOnly: false
        };

        // ---- render shell -------------------------------------------------
        root.innerHTML = buildShell();
        var q = function (sel) { return root.querySelector(sel); };
        var qa = function (sel) { return root.querySelectorAll(sel); };

        var modal = q('.biw-modal');

        bindControls();
        loadData();

        // ---- shell markup ---------------------------------------------------
        function buildShell() {
            var title = root.getAttribute('data-biw-title') || '';
            var subtitle = root.getAttribute('data-biw-subtitle') || '';
            var eyebrow = root.getAttribute('data-biw-eyebrow') || 'Strategic Intelligence';
            var methodology = root.getAttribute('data-biw-methodology') || '';

            var pillarBadges = pillars.map(function (p) {
                return '<div class="biw-pillar-badge"><div class="biw-pillar-dot" style="background:' + esc(p.color || '#60a5fa') + '"></div>' + esc(p.label) + '</div>';
            }).join('');

            var sortOptions = '<option value="' + esc(scoreKey) + '">Sort by: ' + esc(scoreLabel) + '</option>' +
                pillars.map(function (p) {
                    return '<option value="' + esc(p.key) + '">Sort by: ' + esc(p.label) + '</option>';
                }).join('') +
                '<option value="__coverage__">Sort by: Coverage (Full first)</option>';

            return '' +
                '<div class="biw-header">' +
                '  <span class="biw-eyebrow">' + esc(eyebrow) + '</span>' +
                '  <h1>' + esc(title) + '</h1>' +
                '  <div class="biw-sub">' + esc(subtitle) + '</div>' +
                '  <div class="biw-meta biw-last-updated">Loading data…</div>' +
                '</div>' +
                '<div class="biw-watchlist-panel"><h3>Your Watchlist</h3><div class="biw-watchlist-items"></div></div>' +
                '<div class="biw-pillars">' + pillarBadges + '</div>' +
                '<div class="biw-controls">' +
                '  <div class="biw-controls-row biw-controls-row-1">' +
                '    <input type="text" class="biw-search" placeholder="Search countries…">' +
                '    <select class="biw-select biw-band-filter">' +
                '      <option value="all">' + esc(bandSelectLabel) + '</option>' +
                '      <option value="0">' + esc(bandLabels[0]) + '</option>' +
                '      <option value="1">' + esc(bandLabels[1]) + '</option>' +
                '      <option value="2">' + esc(bandLabels[2]) + '</option>' +
                '      <option value="3">' + esc(bandLabels[3]) + '</option>' +
                '    </select>' +
                '    <div class="biw-btn-group">' +
                '      <button class="biw-btn-view active" data-view="table">Table</button>' +
                '      <button class="biw-btn-view" data-view="grid">Cards</button>' +
                '    </div>' +
                '    <select class="biw-select biw-sort-select" style="min-width:190px;">' + sortOptions + '</select>' +
                '    <button class="biw-btn-sort" title="Toggle sort direction">↓</button>' +
                '    <button class="biw-watchlist-toggle"><span>★</span> Watchlist</button>' +
                '  </div>' +
                '  <div class="biw-controls-row biw-controls-row-2">' +
                '    <div class="biw-share-group">' +
                '      <button class="biw-btn-share" data-share="x" title="Share on X">𝕏</button>' +
                '      <button class="biw-btn-share" data-share="linkedin" title="Share on LinkedIn">in</button>' +
                '      <button class="biw-btn-share" data-share="copy" title="Copy link"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></button>' +
                '    </div>' +
                '    <button class="biw-btn-print">Print Report</button>' +
                '  </div>' +
                '</div>' +
                '<div class="biw-table-wrap">' +
                '  <table class="biw-table"><thead><tr class="biw-head-row"></tr></thead><tbody class="biw-tbody"></tbody></table>' +
                '</div>' +
                '<div class="biw-grid" style="display:none;"></div>' +
                '<div class="biw-no-results" style="display:none;">No countries found matching your filters.</div>' +
                (methodology ? '<div class="biw-methodology">' + methodology + '</div>' : '') +
                '<div class="biw-excluded" style="display:none;">' +
                '  <button class="biw-excluded-toggle" type="button"></button>' +
                '  <div class="biw-excluded-body" style="display:none;"><table class="biw-excluded-table"><thead><tr><th>Country</th><th>Reason</th></tr></thead><tbody></tbody></table></div>' +
                '</div>' +
                '<div class="biw-modal">' +
                '  <div class="biw-modal-content">' +
                '    <div class="biw-modal-header"><h2 class="biw-modal-title"></h2><button class="biw-modal-close">×</button></div>' +
                '    <div class="biw-modal-score"></div>' +
                '    <div class="biw-modal-pillars"></div>' +
                '    <div class="biw-modal-grid"></div>' +
                '    <div class="biw-modal-actions"></div>' +
                '    <div class="biw-modal-analysis"><p class="biw-analysis-text"></p><div class="biw-modal-sources"></div></div>' +
                '  </div>' +
                '</div>';
        }

        // ---- data loading -----------------------------------------------
        function loadData() {
            q('.biw-tbody').innerHTML = '<tr><td colspan="' + (pillars.length + 5) + '" class="biw-loading">⏳ Loading data…</td></tr>';

            Promise.all([
                fetchJSON(endpoint + (endpoint.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now()),
                fetchJSON(namesEndpoint).catch(function () { return {}; }),
                fetchJSON(historyEndpoint).catch(function () { return {}; })
            ]).then(function (results) {
                var data = results[0];
                state.names = results[1] || {};
                state.history = results[2] || {};
                state.indexMeta = data;

                q('.biw-last-updated').textContent =
                    'Last updated: ' + (data.last_updated || '—') +
                    (data.version ? ' · v' + data.version : '') +
                    (data.total_countries ? ' · ' + data.total_countries + ' countries' : '');

                var countries = data.countries || {};
                state.all = Object.keys(countries).map(function (iso3) {
                    var row = countries[iso3];
                    row.iso3 = iso3;
                    row.name = state.names[iso3] || iso3;
                    return row;
                });

                render();
                renderWatchlistPanel();
                renderExcludedPanel(data.excluded_detail || null, data.excluded || 0);
            }).catch(function (err) {
                q('.biw-tbody').innerHTML = '<tr><td colspan="' + (pillars.length + 5) + '" class="biw-error">⚠ ' + esc(err.message || String(err)) + '</td></tr>';
            });
        }

        function fetchJSON(url) {
            return fetch(url).then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status + ' from ' + url);
                return r.json();
            });
        }

        // ---- filtering / sorting -----------------------------------------
        function band(score) {
            for (var i = 0; i < bandThresholds.length; i++) {
                if (score <= bandThresholds[i]) return i;
            }
            return bandThresholds.length;
        }

        function applyFilters() {
            var term = q('.biw-search').value.toLowerCase().trim();
            var bandFilter = q('.biw-band-filter').value;

            var filtered = state.all.filter(function (c) {
                return c.name.toLowerCase().indexOf(term) !== -1;
            });

            if (bandFilter !== 'all') {
                var bf = parseInt(bandFilter, 10);
                filtered = filtered.filter(function (c) { return band(c[scoreKey] || 0) === bf; });
            }

            if (state.showWatchlistOnly) {
                filtered = filtered.filter(function (c) { return watchlist.indexOf(c.iso3) !== -1; });
            }

            filtered.sort(function (a, b) {
                if (state.sortKey === '__coverage__') {
                    var ca = a[coverageKey] === 'partial' ? 1 : 0;
                    var cb = b[coverageKey] === 'partial' ? 1 : 0;
                    if (ca !== cb) { return state.sortAsc ? cb - ca : ca - cb; }
                    // tie-break by score within the same coverage group
                    return (b[scoreKey] || 0) - (a[scoreKey] || 0);
                }
                var va = a[state.sortKey], vb = b[state.sortKey];
                va = (va === null || va === undefined) ? -Infinity : va;
                vb = (vb === null || vb === undefined) ? -Infinity : vb;
                return state.sortAsc ? va - vb : vb - va;
            });

            state.filtered = filtered;
        }

        // ---- render -------------------------------------------------------
        function render() {
            applyFilters();
            renderHead();
            renderTable();
            renderGrid();
            q('.biw-no-results').style.display = state.filtered.length ? 'none' : 'block';
        }

        function renderHead() {
            var cells = '<th style="width:36px;text-align:center;">★</th>' +
                '<th style="width:70px;text-align:center;">Rank</th>' +
                '<th style="width:60px;text-align:center;">Δ</th>' +
                '<th>Country</th>' +
                '<th style="width:110px;text-align:center;">' + esc(scoreLabel) + '</th>' +
                pillars.map(function (p) {
                    return '<th style="width:120px;text-align:center;">' + esc(p.label) + '</th>';
                }).join('') +
                '<th style="width:80px;"></th>';
            q('.biw-head-row').innerHTML = cells;
        }

        function badgeHtml(score, coverage) {
            var b = band(score);
            var cov = coverage === 'partial'
                ? '<span class="biw-coverage biw-coverage-partial" title="Partial Index — one pillar estimated; see methodology">PARTIAL</span>'
                : (coverage ? '<span class="biw-coverage biw-coverage-full">FULL</span>' : '');
            return '<span class="biw-badge-cell"><span class="biw-badge ' + bandClasses[b] + '">' + fmtNum(score) + '</span>' + cov + '</span>';
        }

        function rankHtml(c) {
            var rd = c.rank_display;
            if (rd && rd.is_definitive) {
                return '<span>' + rd.string_format + '</span>';
            }
            if (rd) {
                return '<span class="biw-rank-partial" title="80% range #' + rd.range_80_low + '–#' + rd.range_80_high +
                    ' · theoretical #' + rd.theoretical_low + '–#' + rd.theoretical_high + '">' + rd.string_format + '</span>';
            }
            return c.rank !== undefined && c.rank !== null ? '#' + c.rank : '—';
        }

        function deltaInfo(c) {
            var hist = state.history[c.iso3];
            var currentBest = (c.rank_display && c.rank_display.best_estimate !== undefined && c.rank_display.best_estimate !== null)
                ? c.rank_display.best_estimate
                : (c.rank !== undefined && c.rank !== null ? c.rank : null);

            if (!hist || hist.length < 2 || currentBest === null) {
                return { type: 'new' };
            }

            // Last entry is the current period (already written this month); compare against
            // the most recent PRIOR period instead.
            var prevEntry = hist[hist.length - 2];
            var prevBest = null;
            var prevDefinitive = false;
            if (prevEntry.pillars && prevEntry.pillars.rank_display && prevEntry.pillars.rank_display.best_estimate !== undefined && prevEntry.pillars.rank_display.best_estimate !== null) {
                prevBest = prevEntry.pillars.rank_display.best_estimate;
                prevDefinitive = !!prevEntry.pillars.rank_display.is_definitive;
            } else if (prevEntry.rank !== null && prevEntry.rank !== undefined) {
                prevBest = prevEntry.rank;
                prevDefinitive = prevEntry.coverage_type === 'full';
            }
            if (prevBest === null) {
                return { type: 'new' };
            }

            var currentDefinitive = !!(c.rank_display && c.rank_display.is_definitive);
            var approx = !currentDefinitive || !prevDefinitive;
            var delta = prevBest - currentBest; // positive = climbed toward #1 ("moved up")

            if (delta === 0) { return { type: 'flat', approx: approx }; }
            return { type: delta > 0 ? 'up' : 'down', value: Math.abs(delta), approx: approx };
        }

        function deltaHtml(c) {
            var info = deltaInfo(c);
            if (info.type === 'new') {
                return '<span class="biw-delta biw-delta-new" title="Not enough history yet — needs a second monthly snapshot">NEW</span>';
            }
            if (info.type === 'flat') {
                var flatTitle = info.approx ? 'No change since last snapshot (approximate — based on a projected rank)' : 'No change since last snapshot';
                return '<span class="biw-delta biw-delta-flat" title="' + esc(flatTitle) + '">—</span>';
            }
            var arrow = info.type === 'up' ? '↑' : '↓';
            var cls = info.type === 'up' ? 'biw-delta-up' : 'biw-delta-down';
            var mark = info.approx ? '~' : '';
            var title = info.approx
                ? 'Change since last snapshot (approximate — based on a projected rank for at least one period)'
                : 'Change since last snapshot';
            return '<span class="biw-delta ' + cls + '" title="' + esc(title) + '">' + arrow + mark + info.value + '</span>';
        }

        function pillarCellHtml(c, p) {
            var v = c[p.key];
            if (v === null || v === undefined) {
                return '<span style="color:var(--biw-slate-dim);font-family:var(--biw-mono);font-size:0.7rem;" title="' + esc(p.missingNote || 'No data for this pillar') + '">—</span>';
            }
            return '<div class="biw-bar-cell">' +
                '<div class="biw-bar-track"><div class="biw-bar-fill" style="width:' + v + '%;background:' + esc(p.color || '#60a5fa') + '"></div></div>' +
                '<span>' + fmtNum(v) + '</span>' +
                '</div>';
        }

        function renderTable() {
            var body = q('.biw-tbody');
            body.innerHTML = state.filtered.map(function (c, i) {
                var starred = watchlist.indexOf(c.iso3) !== -1;
                return '<tr data-idx="' + i + '">' +
                    '<td style="text-align:center;"><button class="biw-star-btn ' + (starred ? 'active' : '') + '" data-action="star" data-idx="' + i + '">★</button></td>' +
                    '<td class="biw-rank">' + rankHtml(c) + '</td>' +
                    '<td class="biw-num">' + deltaHtml(c) + '</td>' +
                    '<td class="biw-country">' + esc(c.name) + '</td>' +
                    '<td class="biw-num">' + badgeHtml(c[scoreKey], c[coverageKey]) + '</td>' +
                    pillars.map(function (p) { return '<td class="biw-num">' + pillarCellHtml(c, p) + '</td>'; }).join('') +
                    '<td class="biw-action"><button class="biw-btn-detail" data-action="detail" data-idx="' + i + '">Details</button></td>' +
                    '</tr>';
            }).join('');
        }

        function renderGrid() {
            var grid = q('.biw-grid');
            grid.innerHTML = state.filtered.map(function (c, i) {
                var starred = watchlist.indexOf(c.iso3) !== -1;
                var b = band(c[scoreKey] || 0);
                return '<div class="biw-grid-card" data-idx="' + i + '">' +
                    '<button class="biw-grid-star ' + (starred ? 'active' : '') + '" data-action="star" data-idx="' + i + '">★</button>' +
                    '<div class="biw-grid-header">' +
                    '<div class="biw-grid-country">' + esc(c.name) + ' <span class="biw-grid-rank">' + rankHtml(c) + '</span> ' + deltaHtml(c) + '</div>' +
                    '<span class="biw-grid-badge ' + bandClasses[b] + '">' + fmtNum(c[scoreKey]) + '</span>' +
                    '</div>' +
                    '<div class="biw-grid-metrics">' +
                    pillars.map(function (p) {
                        var v = c[p.key];
                        return '<div class="biw-grid-metric"><div class="biw-metric-label">' + esc(p.label) + '</div><div class="biw-metric-value">' + (v === null || v === undefined ? '—' : fmtNum(v)) + '</div></div>';
                    }).join('') +
                    '</div></div>';
            }).join('');
        }

        function renderWatchlistPanel() {
            var panel = q('.biw-watchlist-panel');
            var items = q('.biw-watchlist-items');
            var toggle = q('.biw-watchlist-toggle');

            if (!watchlist.length) {
                items.innerHTML = '<span class="biw-watchlist-empty">No countries saved yet. Click the star on any row to add it here.</span>';
                toggle.classList.remove('active');
                state.showWatchlistOnly = false;
                panel.classList.remove('active');
                return;
            }

            toggle.classList.add('active');
            items.innerHTML = watchlist.map(function (iso3) {
                var name = state.names[iso3] || iso3;
                return '<span class="biw-watchlist-chip" data-iso3="' + esc(iso3) + '">' + esc(name) +
                    ' <span class="biw-chip-remove" data-iso3="' + esc(iso3) + '">×</span></span>';
            }).join('');
        }

        function renderExcludedPanel(excludedDetail, excludedCount) {
            var panel = q('.biw-excluded');
            if (!excludedDetail || !excludedCount) { panel.style.display = 'none'; return; }

            panel.style.display = 'block';
            var toggle = q('.biw-excluded-toggle');
            toggle.textContent = excludedCount + ' countries excluded — missing or stale data ▾';

            var body = q('.biw-excluded-table tbody');
            body.innerHTML = Object.keys(excludedDetail).map(function (iso3) {
                var d = excludedDetail[iso3];
                var name = state.names[iso3] || iso3;
                var reason = (d && typeof d === 'object') ? (d.reason || JSON.stringify(d)) : String(d);
                return '<tr><td>' + esc(name) + '</td><td>' + esc(reason) + '</td></tr>';
            }).join('');
        }

        function toggleWatchlist(iso3) {
            var idx = watchlist.indexOf(iso3);
            if (idx === -1) { watchlist.push(iso3); } else { watchlist.splice(idx, 1); }
            try { localStorage.setItem(watchlistKey, JSON.stringify(watchlist)); } catch (e) { /* ignore quota errors */ }
            render();
            renderWatchlistPanel();
        }

        // ---- modal ----------------------------------------------------------
        function openModal(c) {
            if (!c) return;
            var b = band(c[scoreKey] || 0);
            var starred = watchlist.indexOf(c.iso3) !== -1;

            q('.biw-modal-title').innerHTML = esc(c.name) + ' (' + (c.rank_display ? c.rank_display.string_format : (c.rank ? '#' + c.rank : '')) + ') ' + deltaHtml(c);
            var bandVarName = ['low', 'medium', 'high', 'extreme'][b];
            q('.biw-modal-score').innerHTML =
                '<div class="biw-score-big" style="color:var(--biw-' + bandVarName + ')">' + fmtNum(c[scoreKey]) + '</div>' +
                '<div class="biw-score-label">' + bandLabels[b] + ' · ' + (c[coverageKey] === 'partial' ? 'Partial Index' : 'Full Index') + '</div>';

            q('.biw-modal-pillars').innerHTML = pillars.map(function (p) {
                var v = c[p.key];
                var pct = (v === null || v === undefined) ? 0 : v;
                return '<div class="biw-pillar-row">' +
                    '<div class="biw-pillar-name">' + esc(p.label) + '</div>' +
                    '<div class="biw-pillar-track"><div class="biw-pillar-fill" style="width:' + pct + '%;background:' + esc(p.color || '#60a5fa') + '"></div></div>' +
                    '<div class="biw-pillar-value">' + (v === null || v === undefined ? '—' : fmtNum(v)) + '</div>' +
                    '</div>';
            }).join('');

            var extraItems = pillars.map(function (p) {
                if (!p.raw_key) return '';
                var rv = c[p.raw_key];
                return '<div class="biw-modal-item"><div class="biw-mlabel">' + esc(p.label) + ' (raw)</div><div class="biw-mvalue">' + (rv === null || rv === undefined ? '—' : rv) + '</div></div>';
            }).join('');
            q('.biw-modal-grid').innerHTML = extraItems;

            q('.biw-modal-actions').innerHTML =
                '<button class="biw-modal-btn biw-modal-btn-primary" data-action="modal-star">' + (starred ? '★ Remove from Watchlist' : '★ Add to Watchlist') + '</button>' +
                '<button class="biw-modal-btn biw-modal-btn-secondary" data-action="modal-close">Close</button>';

            var missing = (c.pillars_missing || []).length ? ('Missing data: ' + c.pillars_missing.join(', ') + '. ') : '';
            q('.biw-analysis-text').textContent = missing + (c[coverageKey] === 'partial' ? 'Rank shown is a projected range, not a definitive placement — see methodology for how partial ranks are derived.' : 'All pillars have real data for this country; rank is definitive.');

            var sources = [];
            if (c.hhi_source) sources.push('HHI: ' + c.hhi_source);
            if (c.maritime_source) sources.push('Maritime: ' + c.maritime_source);
            q('.biw-modal-sources').textContent = (sources.length ? sources.join(' · ') + ' · ' : '') + 'Updated ' + (c.last_updated || '');

            modal.setAttribute('data-iso3', c.iso3);
            modal.classList.add('active');
        }

        function closeModal() { modal.classList.remove('active'); }

        // ---- events -----------------------------------------------------
        function bindControls() {
            q('.biw-search').addEventListener('input', render);
            q('.biw-band-filter').addEventListener('change', render);

            q('.biw-btn-print').addEventListener('click', function () { window.print(); });

            qa('.biw-btn-share').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var kind = this.getAttribute('data-share');
                    var pageUrl = window.location.href;
                    var title = root.getAttribute('data-biw-title') || document.title;
                    if (kind === 'x') {
                        window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(title) + '&url=' + encodeURIComponent(pageUrl), '_blank', 'noopener,width=550,height=420');
                    } else if (kind === 'linkedin') {
                        window.open('https://www.linkedin.com/sharing/share-offsite/?url=' + encodeURIComponent(pageUrl), '_blank', 'noopener,width=550,height=420');
                    } else if (kind === 'copy') {
                        var doneIcon = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>';
                        var originalHTML = this.innerHTML;
                        var self = this;
                        var fallback = function () {
                            var ta = document.createElement('textarea');
                            ta.value = pageUrl; ta.style.position = 'fixed'; ta.style.opacity = '0';
                            document.body.appendChild(ta); ta.select();
                            try { document.execCommand('copy'); } catch (e) { /* ignore */ }
                            document.body.removeChild(ta);
                        };
                        var showCopied = function () {
                            self.innerHTML = doneIcon;
                            self.classList.add('biw-btn-share-success');
                            setTimeout(function () { self.innerHTML = originalHTML; self.classList.remove('biw-btn-share-success'); }, 1500);
                        };
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(pageUrl).then(showCopied, function () { fallback(); showCopied(); });
                        } else {
                            fallback(); showCopied();
                        }
                    }
                });
            });

            q('.biw-sort-select').addEventListener('change', function () {
                state.sortKey = this.value;
                state.sortAsc = false;
                q('.biw-btn-sort').textContent = '↓';
                render();
            });
            q('.biw-btn-sort').addEventListener('click', function () {
                state.sortAsc = !state.sortAsc;
                this.textContent = state.sortAsc ? '↑' : '↓';
                render();
            });

            qa('.biw-btn-view').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    state.view = this.getAttribute('data-view');
                    qa('.biw-btn-view').forEach(function (b) { b.classList.remove('active'); });
                    this.classList.add('active');
                    q('.biw-table-wrap').style.display = state.view === 'table' ? 'block' : 'none';
                    q('.biw-grid').style.display = state.view === 'grid' ? 'grid' : 'none';
                });
            });

            q('.biw-watchlist-toggle').addEventListener('click', function () {
                state.showWatchlistOnly = !state.showWatchlistOnly;
                this.classList.toggle('active', state.showWatchlistOnly);
                q('.biw-watchlist-panel').classList.toggle('active', state.showWatchlistOnly);
                render();
            });

            q('.biw-watchlist-items').addEventListener('click', function (e) {
                var t = e.target.closest('[data-iso3]');
                if (!t) return;
                if (e.target.classList.contains('biw-chip-remove')) {
                    toggleWatchlist(t.getAttribute('data-iso3'));
                } else {
                    var c = state.all.find(function (d) { return d.iso3 === t.getAttribute('data-iso3'); });
                    if (c) openModal(c);
                }
            });

            root.addEventListener('click', function (e) {
                var actionEl = e.target.closest('[data-action]');
                if (actionEl) {
                    var action = actionEl.getAttribute('data-action');
                    if (action === 'modal-close') { closeModal(); return; }
                    if (action === 'modal-star') {
                        var openIso3 = modal.getAttribute('data-iso3');
                        var c = state.all.find(function (d) { return d.iso3 === openIso3; });
                        if (c) { toggleWatchlist(c.iso3); openModal(c); }
                        return;
                    }
                    var idx = parseInt(actionEl.getAttribute('data-idx'), 10);
                    var row = state.filtered[idx];
                    if (!row) return;
                    if (action === 'star') { toggleWatchlist(row.iso3); }
                    if (action === 'detail') { openModal(row); }
                    return;
                }
                var tr = e.target.closest('tr[data-idx]');
                if (tr) { openModal(state.filtered[parseInt(tr.getAttribute('data-idx'), 10)]); return; }
                var card = e.target.closest('.biw-grid-card[data-idx]');
                if (card) { openModal(state.filtered[parseInt(card.getAttribute('data-idx'), 10)]); }
            });

            modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
            q('.biw-modal-close').addEventListener('click', closeModal);

            q('.biw-excluded-toggle').addEventListener('click', function () {
                var body = q('.biw-excluded-body');
                var isOpen = body.style.display !== 'none';
                body.style.display = isOpen ? 'none' : 'block';
                var arrow = isOpen ? ' ▾' : ' ▴';
                this.textContent = this.textContent.replace(/[▾▴]$/, '').trim() + arrow;
            });
        }

        // ---- helpers -----------------------------------------------------
        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"']/g, function (m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m];
            });
        }
        function fmtNum(n) {
            if (n === null || n === undefined) return '—';
            return (Math.round(n * 10) / 10).toString();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    // Re-scan if content loads later (page builders / AJAX-loaded shortcodes).
    window.BlomstraIndexFrontendRescan = boot;
})();