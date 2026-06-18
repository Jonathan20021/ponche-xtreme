/* =====================================================================
   Evallish BPO — Unified Chart.js navy theme  (interactive)
   Sets global Chart.js defaults (font, palette, grid, tooltip, motion)
   so every chart in the app is on-brand. Theme-aware via CSS variables.
   Exposes window.EvallishCharts helpers (palette, gradient, fade).
   Loaded right after chart.js in header.php / header_agent.php.
   ===================================================================== */
(function () {
    function cssVar(name, fallback) {
        try {
            var v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
            return v || fallback;
        } catch (e) { return fallback; }
    }

    function hexToRgba(hex, a) {
        if (!hex) return 'rgba(38,75,139,' + (a == null ? 0.15 : a) + ')';
        hex = hex.trim();
        if (hex.indexOf('rgb') === 0) return hex;
        var c = hex.replace('#', '');
        if (c.length === 3) c = c.split('').map(function (x) { return x + x; }).join('');
        var n = parseInt(c, 16);
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + (a == null ? 0.15 : a) + ')';
    }

    function build() {
        if (!window.Chart) return false;
        var C = window.Chart;

        var brand   = cssVar('--brand', '#264b8b');
        var bright  = cssVar('--brand-bright', '#3a5da0');
        var strong  = cssVar('--brand-strong', '#1f3f76');
        var text    = cssVar('--text', '#14223e');
        var muted   = cssVar('--text-muted', '#586a87');
        var border  = cssVar('--border', '#e3e8f1');
        var success = cssVar('--success', '#16895c');
        var warning = cssVar('--warning', '#b07614');
        var danger  = cssVar('--danger', '#cf3a35');
        var navy900 = cssVar('--navy-900', '#152849');

        // Navy-led categorical palette (+ semantic anchors at the tail)
        var palette = [brand, bright, '#6f8bbd', '#9db1d2', strong, success, warning, danger, '#4a6aa6', '#c3d0e6'];

        C.defaults.font.family = "'Inter', 'Segoe UI', system-ui, sans-serif";
        C.defaults.font.size = 12;
        C.defaults.color = muted;
        C.defaults.borderColor = border;
        C.defaults.responsive = true;
        C.defaults.maintainAspectRatio = false;
        // Professional hover: tooltip + highlight trigger anywhere along the x-index,
        // not only when the cursor is exactly over a (often invisible) point.
        C.defaults.interaction = { mode: 'index', intersect: false, axis: 'x' };
        C.defaults.hover = { mode: 'index', intersect: false, axis: 'x' };
        if (C.defaults.animation !== false) {
            C.defaults.animation = { duration: 700, easing: 'easeOutQuart' };
        }

        var p = C.defaults.plugins;
        if (p) {
            if (p.legend && p.legend.labels) {
                p.legend.labels.color = text;
                p.legend.labels.usePointStyle = true;
                p.legend.labels.pointStyle = 'circle';
                p.legend.labels.boxWidth = 8;
                p.legend.labels.boxHeight = 8;
                p.legend.labels.padding = 16;
                p.legend.labels.font = { size: 12, weight: '500' };
            }
            if (p.tooltip) {
                var tt = p.tooltip;
                tt.backgroundColor = navy900;
                tt.titleColor = '#ffffff';
                tt.bodyColor = '#dbe4f5';
                tt.borderColor = 'rgba(255,255,255,0.10)';
                tt.borderWidth = 1;
                tt.padding = 12;
                tt.cornerRadius = 10;
                tt.boxPadding = 6;
                tt.usePointStyle = true;
                tt.titleFont = { size: 13, weight: '700' };
                tt.bodyFont = { size: 12.5 };
                tt.displayColors = true;
                tt.mode = 'index';
                tt.intersect = false;
                tt.caretSize = 6;
                tt.cornerRadius = 10;
                tt.titleMarginBottom = 6;
            }
        }

        // Grid lines (v3/v4 tolerant)
        try {
            if (C.defaults.scale && C.defaults.scale.grid) {
                C.defaults.scale.grid.color = hexToRgba(border, 0.9);
                C.defaults.scale.grid.borderColor = border;
                C.defaults.scale.grid.tickColor = border;
            }
            if (C.defaults.scales) {
                ['linear', 'category', 'radialLinear', 'logarithmic', 'time'].forEach(function (k) {
                    if (C.defaults.scales[k]) {
                        C.defaults.scales[k].grid = C.defaults.scales[k].grid || {};
                        C.defaults.scales[k].grid.color = (k === 'category') ? 'transparent' : hexToRgba(border, 0.9);
                        C.defaults.scales[k].grid.drawBorder = false;
                        C.defaults.scales[k].ticks = C.defaults.scales[k].ticks || {};
                        C.defaults.scales[k].ticks.color = muted;
                        if (C.defaults.scales[k].ticks.padding == null) C.defaults.scales[k].ticks.padding = 8;
                    }
                });
            }
        } catch (e) { /* noop */ }

        // Element defaults — rounded bars, smooth lines, clean points, ringed arcs
        var el = C.defaults.elements;
        if (el) {
            if (el.bar) { el.bar.borderRadius = 6; el.bar.borderSkipped = 'bottom'; }
            if (el.line) { el.line.tension = 0.4; el.line.borderWidth = 3; el.line.fill = false; el.line.borderCapStyle = 'round'; }
            if (el.point) { el.point.radius = 0; el.point.hoverRadius = 6; el.point.hitRadius = 12; el.point.hoverBorderWidth = 2; el.point.backgroundColor = brand; }
            if (el.arc) { el.arc.borderWidth = 2; el.arc.borderColor = cssVar('--surface', '#ffffff'); }
            if (el.point) { el.point.hoverRadius = 7; el.point.hoverBorderColor = '#ffffff'; }
        }

        // Crosshair guide line on hover (line charts) — a polished, professional touch
        if (C.register && !C._evCrosshair) {
            C._evCrosshair = true;
            C.register({
                id: 'evCrosshair',
                afterDraw: function (chart) {
                    if (!chart.config || chart.config.type !== 'line') return;
                    var tip = chart.tooltip;
                    if (!tip || typeof tip.getActiveElements !== 'function') return;
                    var act = tip.getActiveElements();
                    if (!act.length || !chart.scales || !chart.scales.y) return;
                    var x = act[0].element.x, y = chart.scales.y, ctx = chart.ctx;
                    ctx.save();
                    ctx.beginPath();
                    ctx.moveTo(x, y.top);
                    ctx.lineTo(x, y.bottom);
                    ctx.lineWidth = 1;
                    ctx.setLineDash([4, 4]);
                    ctx.strokeStyle = 'rgba(38,75,139,0.30)';
                    ctx.stroke();
                    ctx.restore();
                }
            });
        }

        window.EvallishCharts = {
            palette: palette,
            brand: brand, bright: bright, strong: strong,
            success: success, warning: warning, danger: danger,
            color: function (i) { return palette[i % palette.length]; },
            colors: function (n) { var a = []; for (var i = 0; i < n; i++) a.push(palette[i % palette.length]); return a; },
            fade: hexToRgba,
            // vertical gradient fill for area/line/bar — pass a canvas 2d ctx
            gradient: function (ctx, height, from, to) {
                if (!ctx || !ctx.createLinearGradient) return from || bright;
                var g = ctx.createLinearGradient(0, 0, 0, height || 240);
                g.addColorStop(0, from || hexToRgba(bright, 0.35));
                g.addColorStop(1, to || hexToRgba(brand, 0.02));
                return g;
            }
        };
        return true;
    }

    if (!build()) {
        var tries = 0;
        var iv = setInterval(function () {
            if (build() || ++tries > 60) clearInterval(iv);
        }, 80);
    }

    // Re-apply palette/colors after a server-side theme switch (page reload covers it,
    // but expose a manual hook for live toggles).
    window.EvallishChartsRefresh = build;
})();
