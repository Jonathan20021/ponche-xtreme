<?php
session_start();
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/authorization_functions.php';

ensurePermission('wfm_planning');

include __DIR__ . '/../header.php';
?>

<style>
    :root {
        --slc-ink: #e2e8f0;
        --slc-ink-muted: #94a3b8;
        --slc-ink-dim: #64748b;
        --slc-bg-1: #0b1220;
        --slc-bg-2: #0f172a;
        --slc-bg-3: #1e293b;
        --slc-cyan: #06b6d4;
        --slc-cyan-soft: rgba(6, 182, 212, 0.18);
        --slc-blue: #3b82f6;
        --slc-emerald: #10b981;
        --slc-amber: #f59e0b;
        --slc-rose: #f43f5e;
        --slc-violet: #8b5cf6;
        --slc-border: rgba(148, 163, 184, 0.14);
        --slc-border-strong: rgba(148, 163, 184, 0.28);
        --slc-shadow: 0 12px 40px rgba(2, 6, 23, 0.55);
    }

    .slc-scope { color: var(--slc-ink); }
    .slc-scope * { scroll-margin-top: 88px; }
    .slc-scope .tabular { font-variant-numeric: tabular-nums; }

    /* ============ HERO BANNER ============ */
    .slc-hero {
        position: relative;
        overflow: hidden;
        border-radius: 1.25rem;
        padding: 2rem;
        background:
            radial-gradient(1200px 400px at 90% -30%, rgba(6, 182, 212, 0.24), transparent 60%),
            radial-gradient(900px 500px at -10% 130%, rgba(139, 92, 246, 0.22), transparent 65%),
            linear-gradient(135deg, #0b1220 0%, #111c36 60%, #0b1220 100%);
        border: 1px solid rgba(56, 189, 248, 0.18);
        box-shadow: var(--slc-shadow);
    }
    .slc-hero::before {
        content: "";
        position: absolute;
        inset: -2px;
        background: linear-gradient(135deg, rgba(6, 182, 212, 0.6), transparent 35%, rgba(139, 92, 246, 0.5) 100%);
        filter: blur(22px);
        opacity: 0.25;
        z-index: 0;
        pointer-events: none;
    }
    .slc-hero > * { position: relative; z-index: 1; }
    .slc-hero-orb {
        width: 68px; height: 68px;
        border-radius: 20px;
        background: linear-gradient(135deg, #22d3ee, #3b82f6 60%, #8b5cf6);
        display: grid; place-items: center;
        color: #0b1220;
        font-size: 1.6rem;
        box-shadow: 0 12px 36px rgba(34, 211, 238, 0.35), inset 0 0 24px rgba(255, 255, 255, 0.1);
    }
    .slc-chip {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .35rem .7rem;
        border-radius: 9999px;
        font-size: .75rem; font-weight: 600;
        background: rgba(15, 23, 42, .55);
        border: 1px solid var(--slc-border-strong);
        color: var(--slc-ink-muted);
        backdrop-filter: blur(8px);
    }
    .slc-chip.accent { background: rgba(6, 182, 212, .12); color: #67e8f9; border-color: rgba(6, 182, 212, .4); }
    .slc-chip.violet { background: rgba(139, 92, 246, .12); color: #c4b5fd; border-color: rgba(139, 92, 246, .4); }
    .slc-chip.emerald { background: rgba(16, 185, 129, .12); color: #6ee7b7; border-color: rgba(16, 185, 129, .4); }

    /* ============ NAV PILLS ============ */
    .slc-nav {
        position: sticky;
        top: 64px;
        z-index: 20;
        display: flex;
        gap: .5rem;
        padding: .4rem;
        border-radius: 14px;
        background: rgba(11, 18, 32, .85);
        border: 1px solid var(--slc-border);
        backdrop-filter: blur(14px);
        overflow-x: auto;
    }
    .slc-nav a {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .45rem .85rem;
        border-radius: 10px;
        font-size: .85rem; font-weight: 600;
        color: var(--slc-ink-muted);
        white-space: nowrap;
        transition: all .2s ease;
    }
    .slc-nav a:hover { color: #e0f2fe; background: rgba(14, 165, 233, .08); }
    .slc-nav a.is-active { background: linear-gradient(135deg, rgba(6,182,212,.25), rgba(59,130,246,.25)); color: #e0f7ff; border: 1px solid rgba(6, 182, 212, .4); }

    /* ============ SECTION CARDS ============ */
    .slc-card {
        position: relative;
        border-radius: 1.1rem;
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.92) 0%, rgba(17, 24, 39, 0.85) 100%);
        border: 1px solid var(--slc-border);
        box-shadow: var(--slc-shadow);
        backdrop-filter: blur(16px);
    }
    .slc-card::after {
        content: "";
        position: absolute; inset: 0;
        border-radius: inherit;
        pointer-events: none;
        box-shadow: inset 0 1px 0 rgba(148, 163, 184, 0.08);
    }
    .slc-card-head {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.25rem .75rem;
        border-bottom: 1px solid var(--slc-border);
    }
    .slc-card-body { padding: 1.25rem; }
    .slc-section-title {
        display: flex; align-items: center; gap: .65rem;
        font-size: 1.05rem; font-weight: 700; color: #f8fafc;
    }
    .slc-section-title .icon-wrap {
        width: 34px; height: 34px; border-radius: 10px;
        display: grid; place-items: center;
        background: linear-gradient(135deg, rgba(6, 182, 212, .2), rgba(59, 130, 246, .2));
        border: 1px solid rgba(6, 182, 212, .35);
        color: #67e8f9;
        font-size: .9rem;
    }
    .slc-section-sub { color: var(--slc-ink-muted); font-size: .82rem; margin-top: .15rem; }

    /* ============ INPUTS ============ */
    .slc-field { position: relative; margin-bottom: 1.1rem; }
    .slc-field-label {
        display: flex; align-items: center; justify-content: space-between;
        font-size: .7rem; font-weight: 700;
        color: var(--slc-ink-muted);
        text-transform: uppercase;
        letter-spacing: .08em;
        margin-bottom: .4rem;
    }
    .slc-input-shell {
        position: relative;
        display: flex; align-items: stretch;
        border-radius: .7rem;
        background: rgba(11, 18, 32, .8);
        border: 1px solid var(--slc-border-strong);
        transition: all .2s ease;
        overflow: hidden;
    }
    .slc-input-shell:focus-within {
        border-color: rgba(6, 182, 212, .7);
        box-shadow: 0 0 0 4px rgba(6, 182, 212, .12);
    }
    .slc-input-shell .slc-input-icon {
        display: grid; place-items: center;
        width: 42px;
        color: #7dd3fc;
        background: rgba(6, 182, 212, .08);
        border-right: 1px solid var(--slc-border);
        font-size: .9rem;
    }
    .slc-input-shell input,
    .slc-input-shell select {
        flex: 1;
        background: transparent;
        border: 0;
        outline: 0;
        padding: .7rem .9rem;
        color: #f1f5f9;
        font-size: .95rem;
        font-variant-numeric: tabular-nums;
    }
    .slc-input-shell .slc-input-suffix {
        display: grid; place-items: center;
        padding: 0 .85rem;
        color: var(--slc-ink-muted);
        font-size: .75rem;
        font-weight: 600;
        border-left: 1px solid var(--slc-border);
    }

    .slc-tag {
        display: inline-flex; align-items: center; gap: .3rem;
        padding: .15rem .5rem;
        border-radius: 9999px;
        font-size: .65rem; font-weight: 700;
        background: rgba(59, 130, 246, .15);
        color: #93c5fd;
        border: 1px solid rgba(59, 130, 246, .3);
        text-transform: uppercase;
        letter-spacing: .05em;
    }

    /* SL preview chip */
    .slc-sl-preview {
        margin-top: .75rem;
        padding: .65rem .9rem;
        border-radius: .65rem;
        background: linear-gradient(135deg, rgba(6, 182, 212, .12), rgba(59, 130, 246, .08));
        border: 1px solid rgba(6, 182, 212, .25);
        color: #cffafe;
        font-size: .8rem;
        display: flex; align-items: center; gap: .5rem;
    }

    /* ============ BUTTONS ============ */
    .slc-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: .5rem;
        padding: .7rem 1rem;
        border-radius: .7rem;
        font-weight: 700;
        font-size: .9rem;
        transition: transform .15s ease, box-shadow .2s ease, background .2s ease;
        border: 1px solid transparent;
        cursor: pointer;
        white-space: nowrap;
    }
    .slc-btn:active { transform: translateY(1px); }
    .slc-btn-primary {
        background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%);
        color: #fff;
        box-shadow: 0 10px 24px rgba(6, 182, 212, .35);
    }
    .slc-btn-primary:hover { box-shadow: 0 14px 32px rgba(6, 182, 212, .5); transform: translateY(-1px); }
    .slc-btn-ghost {
        background: rgba(30, 41, 59, .6);
        color: #cbd5e1;
        border-color: var(--slc-border-strong);
    }
    .slc-btn-ghost:hover { background: rgba(30, 41, 59, .9); color: #f1f5f9; }
    .slc-btn-emerald {
        background: linear-gradient(135deg, #10b981, #059669);
        color: #ecfdf5;
        box-shadow: 0 10px 24px rgba(16, 185, 129, .3);
    }
    .slc-btn-emerald:hover { box-shadow: 0 14px 32px rgba(16, 185, 129, .45); transform: translateY(-1px); }
    .slc-btn-block { width: 100%; }

    /* ============ RESULT TILES + GAUGES ============ */
    .slc-tile {
        position: relative;
        padding: 1rem 1.1rem;
        border-radius: .9rem;
        background: linear-gradient(160deg, rgba(6, 182, 212, .08), rgba(59, 130, 246, .06));
        border: 1px solid rgba(6, 182, 212, .22);
        overflow: hidden;
    }
    .slc-tile::before {
        content: "";
        position: absolute; inset: 0;
        background: radial-gradient(400px 120px at 100% 0%, rgba(6, 182, 212, .22), transparent 60%);
        pointer-events: none;
    }
    .slc-tile-label {
        font-size: .7rem; font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .08em;
        color: #7dd3fc;
    }
    .slc-tile-value {
        font-size: 2.4rem;
        font-weight: 800;
        font-variant-numeric: tabular-nums;
        line-height: 1.05;
        background: linear-gradient(135deg, #a5f3fc, #60a5fa);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .slc-tile-hint { font-size: .72rem; color: var(--slc-ink-muted); margin-top: .25rem; }

    .slc-gauge { position: relative; width: 100%; max-width: 180px; margin: 0 auto; }
    .slc-gauge svg { width: 100%; height: auto; transform: rotate(-90deg); }
    .slc-gauge .gauge-track { stroke: rgba(148, 163, 184, .15); }
    .slc-gauge .gauge-fill {
        stroke-linecap: round;
        transition: stroke-dashoffset 1s cubic-bezier(.4,1.5,.5,1), stroke .3s ease;
        filter: drop-shadow(0 0 8px currentColor);
    }
    .slc-gauge .gauge-caption {
        position: absolute;
        inset: 0;
        display: grid; place-items: center;
        text-align: center;
    }
    .slc-gauge .gauge-number {
        font-size: 1.6rem; font-weight: 800;
        color: #f8fafc;
        font-variant-numeric: tabular-nums;
    }
    .slc-gauge .gauge-label {
        font-size: .7rem;
        text-transform: uppercase;
        letter-spacing: .1em;
        color: var(--slc-ink-muted);
        font-weight: 700;
    }
    .gauge-color-excellent { color: var(--slc-emerald); stroke: currentColor; }
    .gauge-color-good { color: var(--slc-blue); stroke: currentColor; }
    .gauge-color-warn { color: var(--slc-amber); stroke: currentColor; }
    .gauge-color-poor { color: var(--slc-rose); stroke: currentColor; }

    .slc-erlang-bar {
        height: 8px;
        background: rgba(148, 163, 184, .15);
        border-radius: 9999px;
        overflow: hidden;
        margin-top: .6rem;
    }
    .slc-erlang-bar > span {
        display: block;
        height: 100%;
        background: linear-gradient(90deg, #06b6d4, #8b5cf6);
        width: 0%;
        border-radius: 9999px;
        transition: width 1s ease;
    }

    /* ============ METRIC BADGES ============ */
    .metric-badge {
        display: inline-flex; align-items: center; gap: .3rem;
        padding: .35rem .75rem;
        border-radius: 9999px;
        font-size: .72rem;
        font-weight: 700;
        letter-spacing: .02em;
    }
    .metric-excellent { background: rgba(16, 185, 129, .14); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, .4); }
    .metric-good { background: rgba(59, 130, 246, .14); color: #93c5fd; border: 1px solid rgba(59, 130, 246, .4); }
    .metric-warning { background: rgba(245, 158, 11, .14); color: #fcd34d; border: 1px solid rgba(245, 158, 11, .4); }
    .metric-poor { background: rgba(244, 63, 94, .14); color: #fda4af; border: 1px solid rgba(244, 63, 94, .4); }

    /* ============ PRESETS ============ */
    .slc-preset {
        position: relative;
        padding: 1rem;
        border-radius: .9rem;
        background: linear-gradient(160deg, rgba(30, 41, 59, .6), rgba(15, 23, 42, .6));
        border: 1px solid var(--slc-border-strong);
        cursor: pointer;
        text-align: left;
        transition: all .25s ease;
        overflow: hidden;
    }
    .slc-preset::before {
        content: "";
        position: absolute;
        inset: 0;
        background: radial-gradient(200px 120px at 100% 0%, var(--preset-glow, rgba(6, 182, 212, .3)), transparent 70%);
        opacity: 0; transition: opacity .25s ease;
        pointer-events: none;
    }
    .slc-preset:hover { transform: translateY(-3px); border-color: var(--preset-border, rgba(6, 182, 212, .5)); }
    .slc-preset:hover::before { opacity: 1; }
    .slc-preset .preset-icon {
        width: 44px; height: 44px; border-radius: 12px;
        display: grid; place-items: center;
        font-size: 1.15rem;
        background: rgba(15, 23, 42, .8);
        color: var(--preset-color, #67e8f9);
        border: 1px solid var(--preset-border, rgba(6, 182, 212, .35));
        margin-bottom: .75rem;
    }

    /* ============ DROPZONE ============ */
    .slc-dropzone {
        position: relative;
        border-radius: 1rem;
        border: 2px dashed rgba(148, 163, 184, .35);
        background: linear-gradient(160deg, rgba(6, 182, 212, .04), rgba(59, 130, 246, .04));
        padding: 2rem;
        text-align: center;
        transition: all .2s ease;
    }
    .slc-dropzone:hover { border-color: rgba(6, 182, 212, .6); background: linear-gradient(160deg, rgba(6, 182, 212, .08), rgba(59, 130, 246, .08)); }
    .slc-dropzone.is-drag {
        border-color: var(--slc-emerald);
        background: linear-gradient(160deg, rgba(16, 185, 129, .12), rgba(6, 182, 212, .08));
        transform: scale(1.01);
    }
    .slc-dropzone .drop-orb {
        width: 64px; height: 64px;
        margin: 0 auto .75rem;
        border-radius: 20px;
        display: grid; place-items: center;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        color: #0b1220;
        font-size: 1.5rem;
        box-shadow: 0 10px 28px rgba(16, 185, 129, .35);
    }

    /* ============ TABLE ============ */
    .slc-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: .85rem;
    }
    .slc-table thead th {
        position: sticky; top: 0;
        background: rgba(11, 18, 32, .95);
        color: #cbd5e1;
        font-weight: 700;
        text-transform: uppercase;
        font-size: .68rem;
        letter-spacing: .06em;
        padding: .6rem .75rem;
        border-bottom: 1px solid var(--slc-border-strong);
    }
    .slc-table tbody tr { transition: background .15s ease; }
    .slc-table tbody tr:hover { background: rgba(6, 182, 212, .05); }
    .slc-table td {
        padding: .6rem .75rem;
        border-bottom: 1px solid rgba(30, 41, 59, .6);
        font-variant-numeric: tabular-nums;
    }

    /* ============ MISC ============ */
    .slc-kpi {
        position: relative;
        padding: 1rem;
        border-radius: .9rem;
        background: linear-gradient(160deg, rgba(15, 23, 42, .7), rgba(11, 18, 32, .7));
        border: 1px solid var(--slc-border);
        overflow: hidden;
    }
    .slc-kpi .kpi-icon {
        width: 32px; height: 32px; border-radius: 10px;
        display: grid; place-items: center;
        font-size: .85rem;
        margin-bottom: .45rem;
    }

    .slc-chip-info {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .3rem .65rem;
        border-radius: 9999px;
        font-size: .72rem; font-weight: 600;
        background: rgba(15, 23, 42, .55);
        border: 1px solid var(--slc-border-strong);
        color: #cbd5e1;
    }
    .slc-chip-info i { color: #7dd3fc; }

    /* toast */
    .slc-toast {
        position: fixed;
        top: 1rem; right: 1rem;
        z-index: 80;
        padding: .8rem 1.1rem;
        border-radius: .8rem;
        color: #fff;
        box-shadow: 0 14px 40px rgba(0, 0, 0, .45);
        display: flex; align-items: center; gap: .6rem;
        font-weight: 600;
        animation: slc-slide 0.35s ease-out;
        border: 1px solid rgba(255, 255, 255, .1);
    }
    .slc-toast.success { background: linear-gradient(135deg, #10b981, #059669); }
    .slc-toast.error { background: linear-gradient(135deg, #ef4444, #b91c1c); }
    .slc-toast.warning { background: linear-gradient(135deg, #f59e0b, #d97706); color: #0b1220; }
    .slc-toast.info { background: linear-gradient(135deg, #3b82f6, #1d4ed8); }

    @keyframes slc-slide {
        from { opacity: 0; transform: translateX(30px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .animate-slide-in { animation: slc-slide 0.45s cubic-bezier(.25,.8,.3,1); }

    /* code inline */
    .slc-scope code {
        padding: .1rem .4rem;
        border-radius: .3rem;
        background: rgba(6, 182, 212, .1);
        border: 1px solid rgba(6, 182, 212, .25);
        color: #a5f3fc;
        font-size: .78rem;
    }

    @media (max-width: 768px) {
        .slc-hero { padding: 1.4rem; }
        .slc-tile-value { font-size: 2rem; }
    }
</style>

<div class="slc-scope container mx-auto px-4 py-8 space-y-6">

    <!-- ======================== HERO BANNER ======================== -->
    <section class="slc-hero">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
            <div class="flex items-start gap-5">
                <div class="slc-hero-orb">
                    <i class="fas fa-calculator"></i>
                </div>
                <div>
                    <div class="flex flex-wrap items-center gap-2 mb-2">
                        <span class="slc-chip accent"><i class="fas fa-bolt"></i> Erlang C</span>
                        <span class="slc-chip violet"><i class="fas fa-layer-group"></i> WFM · Dimensionamiento</span>
                        <span class="slc-chip emerald"><i class="fas fa-file-excel"></i> Análisis masivo</span>
                    </div>
                    <h1 class="text-2xl md:text-3xl font-extrabold text-white leading-tight">
                        Calculadora de Nivel de Servicio
                    </h1>
                    <p class="text-slate-300/80 mt-1 text-sm md:text-base max-w-2xl">
                        Proyecta agentes y staff requeridos con precisión. Calcula por escenario o sube una plantilla Excel con múltiples intervalos y obtén el dimensionamiento de cada uno.
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 md:justify-end shrink-0">
                <a href="wfm_planning.php" class="slc-btn slc-btn-ghost">
                    <i class="fas fa-calendar-check"></i> WFM Planning
                </a>
                <button type="button" onclick="toggleHelp()" class="slc-btn slc-btn-ghost">
                    <i class="fas fa-question-circle"></i> Guía de uso
                </button>
            </div>
        </div>
    </section>

    <!-- ======================== NAV PILLS ======================== -->
    <nav class="slc-nav" id="slcNav" aria-label="Secciones">
        <a href="#section-calc" class="is-active"><i class="fas fa-sliders-h"></i> Calculadora</a>
        <a href="#section-presets"><i class="fas fa-bookmark"></i> Escenarios rápidos</a>
        <a href="#section-bulk"><i class="fas fa-file-excel"></i> Plantilla Excel</a>
    </nav>

    <!-- ======================== HELP PANEL ======================== -->
    <section id="helpSection" class="hidden slc-card">
        <div class="slc-card-head">
            <div>
                <div class="slc-section-title">
                    <span class="icon-wrap"><i class="fas fa-book-open"></i></span>
                    Guía de uso
                </div>
                <p class="slc-section-sub">Qué significa cada parámetro y qué interpretan los resultados.</p>
            </div>
            <button type="button" onclick="toggleHelp()" class="slc-btn slc-btn-ghost" aria-label="Cerrar guía">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="slc-card-body">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-sm">
                <div class="p-4 rounded-xl bg-slate-900/50 border border-slate-700/40">
                    <h4 class="font-semibold text-cyan-300 mb-3 flex items-center gap-2"><i class="fas fa-arrow-right-to-bracket"></i> Parámetros de entrada</h4>
                    <ul class="space-y-2 text-slate-300/90">
                        <li><strong class="text-white">Service Level Goal</strong> — meta de SL en % y segundos (ej: 80% en 20s).</li>
                        <li><strong class="text-white">Interval Length</strong> — duración del intervalo: 15, 30 o 60 min.</li>
                        <li><strong class="text-white">Calls</strong> — llamadas esperadas en el intervalo.</li>
                        <li><strong class="text-white">AHT</strong> — Average Handling Time en segundos.</li>
                        <li><strong class="text-white">Occupancy Target</strong> — ocupación objetivo (70–90%).</li>
                        <li><strong class="text-white">Shrinkage</strong> — tiempo no productivo (20–35%).</li>
                    </ul>
                </div>
                <div class="p-4 rounded-xl bg-slate-900/50 border border-slate-700/40">
                    <h4 class="font-semibold text-cyan-300 mb-3 flex items-center gap-2"><i class="fas fa-arrow-right-from-bracket"></i> Resultados</h4>
                    <ul class="space-y-2 text-slate-300/90">
                        <li><strong class="text-white">Required Agents</strong> — mínimo para cumplir el SL.</li>
                        <li><strong class="text-white">Required Staff</strong> — total incluyendo shrinkage.</li>
                        <li><strong class="text-white">Service Level</strong> — SL proyectado.</li>
                        <li><strong class="text-white">Occupancy</strong> — % de tiempo productivo.</li>
                        <li><strong class="text-white">Intensity (Erlangs)</strong> — carga de trabajo.</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- ======================== CALCULATOR SECTION ======================== -->
    <section id="section-calc" class="grid grid-cols-1 lg:grid-cols-5 gap-6">

        <!-- ========== INPUT PANEL ========== -->
        <div class="slc-card lg:col-span-2">
            <div class="slc-card-head">
                <div>
                    <div class="slc-section-title">
                        <span class="icon-wrap"><i class="fas fa-sliders-h"></i></span>
                        Parámetros
                    </div>
                    <p class="slc-section-sub">Valores del escenario a dimensionar.</p>
                </div>
                <span class="slc-chip"><i class="fas fa-shield-check"></i> Validado</span>
            </div>

            <form id="calculatorForm" class="slc-card-body">

                <!-- Service Level Goal -->
                <div class="slc-field">
                    <div class="slc-field-label">
                        <span><i class="fas fa-bullseye mr-1.5 text-cyan-400"></i> Service Level Goal</span>
                        <span class="slc-tag">Meta SL</span>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="slc-input-shell">
                            <div class="slc-input-icon"><i class="fas fa-percent"></i></div>
                            <input type="number" id="targetSl" name="targetSl"
                                   value="80" min="1" max="100" step="1" required
                                   oninput="updateSlPreview()">
                            <div class="slc-input-suffix">%</div>
                        </div>
                        <div class="slc-input-shell">
                            <div class="slc-input-icon"><i class="fas fa-stopwatch"></i></div>
                            <input type="number" id="targetAns" name="targetAns"
                                   value="20" min="1" max="300" step="1" required
                                   oninput="updateSlPreview()">
                            <div class="slc-input-suffix">seg</div>
                        </div>
                    </div>
                    <div class="slc-sl-preview">
                        <i class="fas fa-wand-magic-sparkles text-cyan-300"></i>
                        <span id="slPreviewText">Objetivo: contestar <strong id="slPreviewPct">80%</strong> de las llamadas en <strong id="slPreviewSec">20 s</strong></span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Interval Length -->
                    <div class="slc-field">
                        <div class="slc-field-label">
                            <span><i class="fas fa-clock mr-1.5 text-cyan-400"></i> Intervalo</span>
                            <span class="slc-tag">Duración</span>
                        </div>
                        <div class="slc-input-shell">
                            <div class="slc-input-icon"><i class="fas fa-hourglass-half"></i></div>
                            <select id="intervalMinutes" name="intervalMinutes">
                                <option value="15" selected>15 minutos</option>
                                <option value="30">30 minutos</option>
                                <option value="60">60 minutos</option>
                            </select>
                        </div>
                    </div>

                    <!-- Calls -->
                    <div class="slc-field">
                        <div class="slc-field-label">
                            <span><i class="fas fa-phone mr-1.5 text-cyan-400"></i> Llamadas</span>
                            <span class="slc-tag">Esperadas</span>
                        </div>
                        <div class="slc-input-shell">
                            <div class="slc-input-icon"><i class="fas fa-phone-volume"></i></div>
                            <input type="number" id="calls" name="calls" value="100" min="0" step="1" required>
                            <div class="slc-input-suffix">calls</div>
                        </div>
                    </div>
                </div>

                <!-- AHT -->
                <div class="slc-field">
                    <div class="slc-field-label">
                        <span><i class="fas fa-headset mr-1.5 text-cyan-400"></i> Average Handling Time</span>
                        <span class="slc-tag">AHT</span>
                    </div>
                    <div class="slc-input-shell">
                        <div class="slc-input-icon"><i class="fas fa-stopwatch-20"></i></div>
                        <input type="number" id="ahtSeconds" name="ahtSeconds" value="180" min="1" step="1" required>
                        <div class="slc-input-suffix">seg</div>
                    </div>
                </div>

                <!-- Advanced toggle -->
                <button type="button" onclick="toggleAdvanced()"
                    class="slc-btn slc-btn-ghost slc-btn-block">
                    <i id="advancedIcon" class="fas fa-chevron-right transition-transform"></i>
                    Configuración avanzada
                </button>

                <div id="advancedSettings" class="hidden mt-4 p-4 rounded-xl bg-slate-900/60 border border-slate-700/40 space-y-4">
                    <div class="slc-field mb-0">
                        <div class="slc-field-label">
                            <span><i class="fas fa-gauge-high mr-1.5 text-cyan-400"></i> Occupancy Target</span>
                            <span class="slc-tag">70–90%</span>
                        </div>
                        <div class="slc-input-shell">
                            <div class="slc-input-icon"><i class="fas fa-gauge"></i></div>
                            <input type="number" id="occupancyTarget" name="occupancyTarget"
                                   value="85" min="50" max="95" step="1" required>
                            <div class="slc-input-suffix">%</div>
                        </div>
                    </div>
                    <div class="slc-field mb-0">
                        <div class="slc-field-label">
                            <span><i class="fas fa-user-clock mr-1.5 text-cyan-400"></i> Shrinkage</span>
                            <span class="slc-tag">20–35%</span>
                        </div>
                        <div class="slc-input-shell">
                            <div class="slc-input-icon"><i class="fas fa-user-minus"></i></div>
                            <input type="number" id="shrinkage" name="shrinkage"
                                   value="30" min="0" max="50" step="1" required>
                            <div class="slc-input-suffix">%</div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-2 mt-5">
                    <button type="submit" class="slc-btn slc-btn-primary col-span-2">
                        <i class="fas fa-calculator"></i> Calcular
                    </button>
                    <button type="button" onclick="resetForm()" class="slc-btn slc-btn-ghost">
                        <i class="fas fa-rotate-left"></i> Reset
                    </button>
                </div>
            </form>
        </div>

        <!-- ========== RESULTS PANEL ========== -->
        <div class="slc-card lg:col-span-3">
            <div class="slc-card-head">
                <div>
                    <div class="slc-section-title">
                        <span class="icon-wrap"><i class="fas fa-chart-line"></i></span>
                        Resultados
                    </div>
                    <p class="slc-section-sub">Dimensionamiento proyectado basado en Erlang C.</p>
                </div>
                <div id="resultHeadBadge" class="hidden">
                    <span class="slc-chip emerald"><i class="fas fa-check-circle"></i> Cálculo completado</span>
                </div>
            </div>

            <div class="slc-card-body">

                <!-- Placeholder -->
                <div id="resultsContainer" class="text-center py-14">
                    <div class="mx-auto w-20 h-20 rounded-2xl bg-slate-800/60 border border-slate-700/60 grid place-items-center text-3xl text-slate-500 mb-4">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-300">Listo para calcular</h3>
                    <p class="text-slate-500 text-sm mt-1">Ingresa los parámetros y presiona <span class="text-cyan-300 font-semibold">Calcular</span>.</p>
                </div>

                <!-- Results -->
                <div id="calculationResults" class="hidden">

                    <!-- Hero tiles: Agents + Staff -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                        <div class="slc-tile">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="slc-tile-label"><i class="fas fa-users mr-1"></i> Agentes requeridos</div>
                                    <div class="slc-tile-value mt-1" id="resultAgents">-</div>
                                    <div class="slc-tile-hint">Mínimo para cumplir SL</div>
                                </div>
                                <span class="slc-chip accent"><i class="fas fa-seat"></i> Seats</span>
                            </div>
                        </div>
                        <div class="slc-tile" style="border-color: rgba(139,92,246,.3); background: linear-gradient(160deg, rgba(139,92,246,.08), rgba(59,130,246,.06));">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="slc-tile-label" style="color:#c4b5fd"><i class="fas fa-user-group mr-1"></i> Staff total</div>
                                    <div class="slc-tile-value mt-1" id="resultStaff" style="background: linear-gradient(135deg,#e9d5ff,#a78bfa); -webkit-background-clip: text; -webkit-text-fill-color: transparent;">-</div>
                                    <div class="slc-tile-hint">Incluye shrinkage</div>
                                </div>
                                <span class="slc-chip violet"><i class="fas fa-user-minus"></i> + Shrink</span>
                            </div>
                        </div>
                    </div>

                    <!-- Gauges -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                        <div class="p-4 rounded-xl bg-slate-900/50 border border-slate-700/50">
                            <div class="slc-gauge" id="gaugeSL">
                                <svg viewBox="0 0 120 120" aria-hidden="true">
                                    <circle class="gauge-track" cx="60" cy="60" r="52" fill="none" stroke-width="12"/>
                                    <circle class="gauge-fill" cx="60" cy="60" r="52" fill="none" stroke-width="12"
                                            stroke-dasharray="326.73" stroke-dashoffset="326.73"/>
                                </svg>
                                <div class="gauge-caption">
                                    <div>
                                        <div class="gauge-number" id="resultSl">-</div>
                                        <div class="gauge-label">Service Level</div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 text-center">
                                <span id="slBadge" class="metric-badge">-</span>
                            </div>
                        </div>

                        <div class="p-4 rounded-xl bg-slate-900/50 border border-slate-700/50">
                            <div class="slc-gauge" id="gaugeOcc">
                                <svg viewBox="0 0 120 120" aria-hidden="true">
                                    <circle class="gauge-track" cx="60" cy="60" r="52" fill="none" stroke-width="12"/>
                                    <circle class="gauge-fill" cx="60" cy="60" r="52" fill="none" stroke-width="12"
                                            stroke-dasharray="326.73" stroke-dashoffset="326.73"/>
                                </svg>
                                <div class="gauge-caption">
                                    <div>
                                        <div class="gauge-number" id="resultOccupancy">-</div>
                                        <div class="gauge-label">Occupancy</div>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 text-center">
                                <span id="occBadge" class="metric-badge">-</span>
                            </div>
                        </div>
                    </div>

                    <!-- Intensity bar -->
                    <div class="p-4 rounded-xl bg-slate-900/50 border border-slate-700/50 mb-4">
                        <div class="flex items-center justify-between mb-1">
                            <div class="text-xs font-bold uppercase tracking-wider text-slate-400">
                                <i class="fas fa-wave-square mr-1 text-cyan-400"></i> Intensity
                            </div>
                            <div class="text-sm font-bold tabular" id="resultErlangs">-</div>
                        </div>
                        <div class="slc-erlang-bar"><span id="erlangsBar"></span></div>
                        <div class="text-[11px] text-slate-500 mt-1">Carga total del intervalo en Erlangs (A = calls × AHT ÷ intervalo).</div>
                    </div>

                    <!-- Additional info chips -->
                    <div class="p-4 rounded-xl bg-slate-900/40 border border-slate-700/40">
                        <div class="flex flex-wrap gap-2">
                            <span class="slc-chip-info"><i class="fas fa-clock"></i> Intervalo: <span class="text-white ml-1" id="infoInterval">-</span></span>
                            <span class="slc-chip-info"><i class="fas fa-phone"></i> Llamadas: <span class="text-white ml-1" id="infoCalls">-</span></span>
                            <span class="slc-chip-info"><i class="fas fa-headset"></i> AHT: <span class="text-white ml-1" id="infoAht">-</span></span>
                            <span class="slc-chip-info"><i class="fas fa-bullseye"></i> Objetivo: <span class="text-white ml-1" id="infoTarget">-</span></span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button onclick="exportResults()" class="slc-btn slc-btn-emerald slc-btn-block">
                            <i class="fas fa-file-csv"></i> Exportar resultados
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ======================== PRESETS ======================== -->
    <section id="section-presets" class="slc-card">
        <div class="slc-card-head">
            <div>
                <div class="slc-section-title">
                    <span class="icon-wrap"><i class="fas fa-bookmark"></i></span>
                    Escenarios predefinidos
                </div>
                <p class="slc-section-sub">Carga rápidamente configuraciones típicas del contact center.</p>
            </div>
        </div>
        <div class="slc-card-body">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <button type="button" onclick="loadPreset('highVolume')" class="slc-preset"
                        style="--preset-color:#22d3ee; --preset-border:rgba(34,211,238,.5); --preset-glow:rgba(34,211,238,.3)">
                    <div class="preset-icon"><i class="fas fa-phone-volume"></i></div>
                    <div class="font-bold text-white mb-1">Alto volumen</div>
                    <div class="text-xs text-slate-400">200 calls · 15 min · AHT 180s</div>
                    <div class="text-[11px] text-cyan-300 mt-2 font-semibold">SL 80/20 · Occ 85%</div>
                </button>
                <button type="button" onclick="loadPreset('standard')" class="slc-preset"
                        style="--preset-color:#60a5fa; --preset-border:rgba(96,165,250,.5); --preset-glow:rgba(96,165,250,.3)">
                    <div class="preset-icon"><i class="fas fa-chart-bar"></i></div>
                    <div class="font-bold text-white mb-1">Estándar</div>
                    <div class="text-xs text-slate-400">100 calls · 30 min · AHT 240s</div>
                    <div class="text-[11px] text-blue-300 mt-2 font-semibold">SL 80/20 · Occ 85%</div>
                </button>
                <button type="button" onclick="loadPreset('lowVolume')" class="slc-preset"
                        style="--preset-color:#c4b5fd; --preset-border:rgba(196,181,253,.5); --preset-glow:rgba(139,92,246,.3)">
                    <div class="preset-icon"><i class="fas fa-chart-line"></i></div>
                    <div class="font-bold text-white mb-1">Bajo volumen</div>
                    <div class="text-xs text-slate-400">50 calls · 60 min · AHT 300s</div>
                    <div class="text-[11px] text-violet-300 mt-2 font-semibold">SL 80/20 · Occ 80%</div>
                </button>
                <button type="button" onclick="loadPreset('premium')" class="slc-preset"
                        style="--preset-color:#fbbf24; --preset-border:rgba(251,191,36,.5); --preset-glow:rgba(251,191,36,.3)">
                    <div class="preset-icon"><i class="fas fa-star"></i></div>
                    <div class="font-bold text-white mb-1">Premium</div>
                    <div class="text-xs text-slate-400">80 calls · 30 min · AHT 420s</div>
                    <div class="text-[11px] text-amber-300 mt-2 font-semibold">SL 90/15 · Occ 85%</div>
                </button>
            </div>
        </div>
    </section>

    <!-- ======================== BULK INTERVAL ANALYSIS ======================== -->
    <section id="section-bulk" class="slc-card">
        <div class="slc-card-head">
            <div>
                <div class="slc-section-title">
                    <span class="icon-wrap" style="background: linear-gradient(135deg, rgba(16,185,129,.2), rgba(6,182,212,.2)); color:#6ee7b7; border-color: rgba(16,185,129,.4);">
                        <i class="fas fa-file-excel"></i>
                    </span>
                    Análisis por intervalos
                </div>
                <p class="slc-section-sub">Descarga la plantilla, completa un intervalo por fila y súbela. Se calcula cada uno por separado.</p>
            </div>
            <div class="flex flex-wrap gap-2 shrink-0">
                <a href="../api/service_level_template.php?action=download" class="slc-btn slc-btn-emerald">
                    <i class="fas fa-download"></i> Plantilla
                </a>
            </div>
        </div>

        <div class="slc-card-body space-y-4">

            <!-- Dropzone -->
            <div id="bulkDropzone" class="slc-dropzone" role="button" tabindex="0"
                 onclick="document.getElementById('bulkFileInput').click()"
                 onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();document.getElementById('bulkFileInput').click();}">
                <div class="drop-orb"><i class="fas fa-cloud-arrow-up"></i></div>
                <h3 class="text-white font-bold text-lg">Suelta tu archivo aquí</h3>
                <p class="text-slate-400 text-sm mt-1">o haz clic para seleccionar · <code>.xlsx</code> <code>.xls</code> <code>.csv</code></p>
                <div class="mt-3 flex flex-wrap justify-center gap-2">
                    <span class="slc-chip"><i class="fas fa-list"></i> Usa la hoja <strong class="text-cyan-300 ml-1">Intervalos</strong></span>
                    <span class="slc-chip"><i class="fas fa-shield-check"></i> Hasta 500 filas</span>
                </div>
                <input type="file" id="bulkFileInput" accept=".xlsx,.xls,.csv" class="hidden">
            </div>

            <!-- Instructions -->
            <details class="group rounded-xl bg-slate-900/50 border border-slate-700/50 overflow-hidden">
                <summary class="cursor-pointer list-none p-4 flex items-center justify-between text-sm font-semibold text-cyan-300 hover:bg-slate-900/80 transition-colors">
                    <span class="flex items-center gap-2"><i class="fas fa-circle-info"></i> Instrucciones de uso</span>
                    <i class="fas fa-chevron-down transition-transform group-open:rotate-180"></i>
                </summary>
                <ol class="px-5 pb-4 text-xs text-slate-300 space-y-1.5 list-decimal ml-3">
                    <li>Presiona <strong class="text-emerald-300">Plantilla</strong> y se descarga <code>plantilla_service_level_calculator.xlsx</code>.</li>
                    <li>Abre la pestaña <strong class="text-cyan-300">Intervalos</strong>. No modifiques los encabezados.</li>
                    <li>Llena una fila por intervalo: etiqueta, llamadas, AHT (seg), duración (min), SL objetivo (%), tiempo respuesta (seg), ocupación (%) y shrinkage (%).</li>
                    <li>Guarda como <code>.xlsx</code> o <code>.csv</code>.</li>
                    <li>Suelta el archivo en el área de arriba o presiona para seleccionarlo.</li>
                    <li>El análisis es independiente del formulario superior y no modifica sus valores.</li>
                </ol>
            </details>

            <!-- Status -->
            <div id="bulkStatus" class="hidden"></div>

            <!-- Results -->
            <div id="bulkResultsSection" class="hidden space-y-4">

                <!-- KPI cards -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="slc-kpi">
                        <div class="kpi-icon" style="background: rgba(6,182,212,.15); color:#67e8f9; border:1px solid rgba(6,182,212,.35);"><i class="fas fa-list-check"></i></div>
                        <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">Procesados</div>
                        <div class="text-2xl font-extrabold text-cyan-300 tabular" id="bulkProcessed">0</div>
                    </div>
                    <div class="slc-kpi">
                        <div class="kpi-icon" style="background: rgba(16,185,129,.15); color:#6ee7b7; border:1px solid rgba(16,185,129,.35);"><i class="fas fa-users"></i></div>
                        <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">Total agentes</div>
                        <div class="text-2xl font-extrabold text-emerald-300 tabular" id="bulkTotalAgents">0</div>
                    </div>
                    <div class="slc-kpi">
                        <div class="kpi-icon" style="background: rgba(59,130,246,.15); color:#93c5fd; border:1px solid rgba(59,130,246,.35);"><i class="fas fa-user-group"></i></div>
                        <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">Total staff</div>
                        <div class="text-2xl font-extrabold text-blue-300 tabular" id="bulkTotalStaff">0</div>
                    </div>
                    <div class="slc-kpi">
                        <div class="kpi-icon" style="background: rgba(245,158,11,.15); color:#fcd34d; border:1px solid rgba(245,158,11,.35);"><i class="fas fa-bullseye"></i></div>
                        <div class="text-[11px] text-slate-400 uppercase tracking-wider font-bold">SL promedio</div>
                        <div class="text-2xl font-extrabold text-amber-300 tabular" id="bulkAvgSl">0%</div>
                    </div>
                </div>

                <!-- Results table -->
                <div class="rounded-xl border border-slate-700/50 overflow-hidden">
                    <div class="overflow-x-auto max-h-[500px]">
                        <table class="slc-table">
                            <thead>
                                <tr>
                                    <th class="text-left">Intervalo</th>
                                    <th class="text-right">Llamadas</th>
                                    <th class="text-right">AHT</th>
                                    <th class="text-right">Dur.</th>
                                    <th class="text-right">Erlangs</th>
                                    <th class="text-right">Agentes</th>
                                    <th class="text-right">Staff</th>
                                    <th class="text-right">SL</th>
                                    <th class="text-right">Occ.</th>
                                </tr>
                            </thead>
                            <tbody id="bulkResultsBody"></tbody>
                        </table>
                    </div>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button type="button" onclick="exportBulkResults()" class="slc-btn slc-btn-emerald">
                        <i class="fas fa-file-csv"></i> Exportar CSV
                    </button>
                    <button type="button" onclick="clearBulkResults()" class="slc-btn slc-btn-ghost">
                        <i class="fas fa-xmark"></i> Limpiar
                    </button>
                </div>

                <!-- Errors -->
                <div id="bulkErrorsSection" class="hidden p-4 rounded-xl bg-rose-900/20 border border-rose-700/40">
                    <h4 class="text-sm font-semibold text-rose-300 mb-2 flex items-center gap-2">
                        <i class="fas fa-triangle-exclamation"></i>
                        Filas con error
                    </h4>
                    <ul id="bulkErrorsList" class="text-xs text-rose-200 space-y-1 list-disc ml-5"></ul>
                </div>
            </div>
        </div>
    </section>

</div>

<script>
let lastCalculation = null;

// ====== Utilities ======================================================
function animateNumber(el, to, { duration = 900, suffix = '', decimals = 0 } = {}) {
    if (!el) return;
    const start = parseFloat(String(el.dataset._val ?? 0)) || 0;
    const end = Number(to) || 0;
    const startTime = performance.now();
    el.dataset._val = end;
    function tick(t) {
        const progress = Math.min(1, (t - startTime) / duration);
        const eased = 1 - Math.pow(1 - progress, 3);
        const value = start + (end - start) * eased;
        el.textContent = value.toFixed(decimals) + suffix;
        if (progress < 1) requestAnimationFrame(tick);
        else el.textContent = end.toFixed(decimals) + suffix;
    }
    requestAnimationFrame(tick);
}

// Gauge helpers — radius 52 → circumference ≈ 326.73
const GAUGE_CIRC = 2 * Math.PI * 52;
function setGauge(wrapId, percent) {
    const wrap = document.getElementById(wrapId);
    if (!wrap) return;
    const fill = wrap.querySelector('.gauge-fill');
    const p = Math.max(0, Math.min(100, Number(percent) || 0));
    const offset = GAUGE_CIRC * (1 - p / 100);
    fill.setAttribute('stroke-dasharray', GAUGE_CIRC.toFixed(2));
    fill.setAttribute('stroke-dashoffset', offset.toFixed(2));

    fill.classList.remove('gauge-color-excellent', 'gauge-color-good', 'gauge-color-warn', 'gauge-color-poor');
    if (p >= 90) fill.classList.add('gauge-color-excellent');
    else if (p >= 80) fill.classList.add('gauge-color-good');
    else if (p >= 70) fill.classList.add('gauge-color-warn');
    else fill.classList.add('gauge-color-poor');
}

function setOccupancyGauge(percent) {
    const wrap = document.getElementById('gaugeOcc');
    if (!wrap) return;
    const fill = wrap.querySelector('.gauge-fill');
    const p = Math.max(0, Math.min(100, Number(percent) || 0));
    const offset = GAUGE_CIRC * (1 - p / 100);
    fill.setAttribute('stroke-dasharray', GAUGE_CIRC.toFixed(2));
    fill.setAttribute('stroke-dashoffset', offset.toFixed(2));

    fill.classList.remove('gauge-color-excellent', 'gauge-color-good', 'gauge-color-warn', 'gauge-color-poor');
    if (p >= 80 && p <= 90) fill.classList.add('gauge-color-excellent');
    else if (p >= 70 && p < 95) fill.classList.add('gauge-color-good');
    else if (p >= 60 || p >= 95) fill.classList.add('gauge-color-warn');
    else fill.classList.add('gauge-color-poor');
}

// SL preview text update
function updateSlPreview() {
    const pct = document.getElementById('targetSl').value || '0';
    const sec = document.getElementById('targetAns').value || '0';
    const pctEl = document.getElementById('slPreviewPct');
    const secEl = document.getElementById('slPreviewSec');
    if (pctEl) pctEl.textContent = pct + '%';
    if (secEl) secEl.textContent = sec + ' s';
}

// Toggle advanced settings
function toggleAdvanced() {
    const settings = document.getElementById('advancedSettings');
    const icon = document.getElementById('advancedIcon');
    settings.classList.toggle('hidden');
    icon.style.transform = settings.classList.contains('hidden') ? '' : 'rotate(90deg)';
}

// Toggle help section
function toggleHelp() {
    const help = document.getElementById('helpSection');
    help.classList.toggle('hidden');
    if (!help.classList.contains('hidden')) {
        help.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

// Load preset configurations
function loadPreset(type) {
    const presets = {
        highVolume: {
            targetSl: 80,
            targetAns: 20,
            intervalMinutes: 15,
            calls: 200,
            ahtSeconds: 180,
            occupancyTarget: 85,
            shrinkage: 30
        },
        standard: {
            targetSl: 80,
            targetAns: 20,
            intervalMinutes: 30,
            calls: 100,
            ahtSeconds: 240,
            occupancyTarget: 85,
            shrinkage: 30
        },
        lowVolume: {
            targetSl: 80,
            targetAns: 20,
            intervalMinutes: 60,
            calls: 50,
            ahtSeconds: 300,
            occupancyTarget: 80,
            shrinkage: 30
        },
        premium: {
            targetSl: 90,
            targetAns: 15,
            intervalMinutes: 30,
            calls: 80,
            ahtSeconds: 420,
            occupancyTarget: 85,
            shrinkage: 30
        }
    };

    const preset = presets[type];
    if (!preset) return;

    // Populate form
    Object.keys(preset).forEach(key => {
        const input = document.getElementById(key);
        if (input) input.value = preset[key];
    });

    // Show success message
    showToast('Preset cargado: ' + type, 'success');
}

// Reset form
function resetForm() {
    document.getElementById('calculatorForm').reset();
    document.getElementById('resultsContainer').classList.remove('hidden');
    document.getElementById('calculationResults').classList.add('hidden');
    const headBadge = document.getElementById('resultHeadBadge');
    if (headBadge) headBadge.classList.add('hidden');
    updateSlPreview();
    showToast('Formulario reseteado', 'info');
}

// Form submission
document.getElementById('calculatorForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);
    const data = {
        action: 'calculate',
        targetSl: parseFloat(formData.get('targetSl')),
        targetAns: parseInt(formData.get('targetAns')),
        intervalMinutes: parseInt(formData.get('intervalMinutes')),
        calls: parseInt(formData.get('calls')),
        ahtSeconds: parseInt(formData.get('ahtSeconds')),
        occupancyTarget: parseFloat(formData.get('occupancyTarget')),
        shrinkage: parseFloat(formData.get('shrinkage'))
    };

    try {
        const response = await fetch('../api/service_level_calculator.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            displayResults(result.data, data);
            lastCalculation = { result: result.data, params: data };
            showToast('Cálculo completado exitosamente', 'success');
        } else {
            showToast('Error: ' + (result.error || 'Error desconocido'), 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showToast('Error al procesar el cálculo', 'error');
    }
});

// Display results
function displayResults(data, params) {
    // Hide placeholder, show results
    document.getElementById('resultsContainer').classList.add('hidden');
    const resultsEl = document.getElementById('calculationResults');
    resultsEl.classList.remove('hidden');
    const headBadge = document.getElementById('resultHeadBadge');
    if (headBadge) headBadge.classList.remove('hidden');

    // Big tile numbers with count-up animation
    animateNumber(document.getElementById('resultAgents'), data.required_agents);
    animateNumber(document.getElementById('resultStaff'), data.required_staff);

    // Gauges
    const slPercent = data.service_level * 100;
    const occPercent = data.occupancy * 100;

    setGauge('gaugeSL', slPercent);
    setOccupancyGauge(occPercent);

    animateNumber(document.getElementById('resultSl'), slPercent, { suffix: '%', decimals: 2 });
    animateNumber(document.getElementById('resultOccupancy'), occPercent, { suffix: '%', decimals: 2 });

    // Erlangs bar + number
    const erlNum = document.getElementById('resultErlangs');
    erlNum.textContent = Number(data.workload).toFixed(3);
    const erlBar = document.getElementById('erlangsBar');
    if (erlBar) {
        const barPct = Math.max(4, Math.min(100, (data.workload / Math.max(data.workload, data.required_agents || 1)) * 100));
        erlBar.style.width = barPct + '%';
    }

    // Additional info chips
    document.getElementById('infoInterval').textContent = params.intervalMinutes + ' min';
    document.getElementById('infoCalls').textContent = params.calls;
    document.getElementById('infoAht').textContent = params.ahtSeconds + ' seg';
    document.getElementById('infoTarget').textContent = params.targetSl + '% / ' + params.targetAns + 's';

    // Badges
    const slBadge = document.getElementById('slBadge');
    if (slPercent >= 90) { slBadge.textContent = '★ Excelente'; slBadge.className = 'metric-badge metric-excellent'; }
    else if (slPercent >= 80) { slBadge.textContent = 'Bueno'; slBadge.className = 'metric-badge metric-good'; }
    else if (slPercent >= 70) { slBadge.textContent = 'Aceptable'; slBadge.className = 'metric-badge metric-warning'; }
    else { slBadge.textContent = 'Bajo'; slBadge.className = 'metric-badge metric-poor'; }

    const occBadge = document.getElementById('occBadge');
    if (occPercent >= 80 && occPercent <= 90) { occBadge.textContent = 'Óptimo'; occBadge.className = 'metric-badge metric-excellent'; }
    else if (occPercent >= 70 && occPercent < 95) { occBadge.textContent = 'Bueno'; occBadge.className = 'metric-badge metric-good'; }
    else if (occPercent >= 95) { occBadge.textContent = 'Saturado'; occBadge.className = 'metric-badge metric-warning'; }
    else if (occPercent >= 60) { occBadge.textContent = 'Revisar'; occBadge.className = 'metric-badge metric-warning'; }
    else { occBadge.textContent = 'Bajo'; occBadge.className = 'metric-badge metric-poor'; }

    resultsEl.classList.remove('animate-slide-in');
    void resultsEl.offsetWidth;
    resultsEl.classList.add('animate-slide-in');
}

// Export results
function exportResults() {
    if (!lastCalculation) {
        showToast('No hay resultados para exportar', 'warning');
        return;
    }

    const { result, params } = lastCalculation;
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').split('T')[0];
    
    const csvContent = [
        ['Service Level Calculator Results'],
        ['Fecha', new Date().toLocaleString()],
        [],
        ['Parámetros de Entrada'],
        ['Service Level Goal', params.targetSl + '% en ' + params.targetAns + ' segundos'],
        ['Interval Length', params.intervalMinutes + ' minutos'],
        ['Calls', params.calls],
        ['Average Handling Time', params.ahtSeconds + ' segundos'],
        ['Occupancy Target', params.occupancyTarget + '%'],
        ['Shrinkage', params.shrinkage + '%'],
        [],
        ['Resultados'],
        ['Required Agents', result.required_agents],
        ['Required Staff', result.required_staff],
        ['Service Level', (result.service_level * 100).toFixed(2) + '%'],
        ['Occupancy', (result.occupancy * 100).toFixed(2) + '%'],
        ['Intensity (Erlangs)', result.workload.toFixed(3)]
    ].map(row => row.join(',')).join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'service_level_calculation_' + timestamp + '.csv';
    link.click();

    showToast('Resultados exportados', 'success');
}

// Toast notifications
function showToast(message, type = 'info') {
    const iconMap = {
        success: 'check-circle',
        error: 'exclamation-circle',
        warning: 'exclamation-triangle',
        info: 'info-circle'
    };
    const toast = document.createElement('div');
    toast.className = 'slc-toast ' + type;
    toast.innerHTML = `<i class="fas fa-${iconMap[type] || 'info-circle'}"></i><span>${message}</span>`;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(30px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

// ======================================================================
// Bulk interval analysis (Excel template upload)
// ======================================================================
let lastBulkResults = null;

async function handleBulkFile(file) {
    if (!file) return;

    const statusBox = document.getElementById('bulkStatus');
    statusBox.className = 'p-3 rounded-xl text-sm border border-blue-500/40 bg-blue-900/20 text-blue-200 flex items-center gap-2';
    statusBox.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Procesando <strong>' + file.name + '</strong>...</span>';
    statusBox.classList.remove('hidden');

    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('file', file);

    try {
        const response = await fetch('../api/service_level_template.php?action=upload', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();

        if (!result.success) {
            statusBox.className = 'p-3 rounded-xl text-sm border border-rose-500/40 bg-rose-900/20 text-rose-200 flex items-center gap-2';
            statusBox.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>' + (result.error || 'Error procesando archivo') + '</span>';
            showToast('Error: ' + (result.error || 'Error procesando archivo'), 'error');
        } else {
            lastBulkResults = result;
            renderBulkResults(result);
            const s = result.summary;
            let msg = 'Procesados ' + s.processed + ' intervalos correctamente.';
            if (s.errors > 0) msg += ' ' + s.errors + ' con error.';
            statusBox.className = 'p-3 rounded-xl text-sm border border-emerald-500/40 bg-emerald-900/20 text-emerald-200 flex items-center gap-2';
            statusBox.innerHTML = '<i class="fas fa-check-circle"></i><span>' + msg + '</span>';
            showToast(msg, 'success');
        }
    } catch (err) {
        console.error(err);
        statusBox.className = 'p-3 rounded-xl text-sm border border-rose-500/40 bg-rose-900/20 text-rose-200 flex items-center gap-2';
        statusBox.innerHTML = '<i class="fas fa-exclamation-circle"></i><span>Error de red al subir el archivo</span>';
        showToast('Error de red al subir el archivo', 'error');
    }
}

document.getElementById('bulkFileInput').addEventListener('change', async function(e) {
    const file = e.target.files[0];
    await handleBulkFile(file);
    e.target.value = '';
});

// Drag-and-drop
(function() {
    const zone = document.getElementById('bulkDropzone');
    if (!zone) return;
    ['dragenter', 'dragover'].forEach(evt => {
        zone.addEventListener(evt, e => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.add('is-drag');
        });
    });
    ['dragleave', 'drop'].forEach(evt => {
        zone.addEventListener(evt, e => {
            e.preventDefault();
            e.stopPropagation();
            zone.classList.remove('is-drag');
        });
    });
    zone.addEventListener('drop', async e => {
        const file = e.dataTransfer.files && e.dataTransfer.files[0];
        await handleBulkFile(file);
    });
    // Ignore drops outside zone
    ['dragover', 'drop'].forEach(evt => {
        window.addEventListener(evt, e => { if (!zone.contains(e.target)) e.preventDefault(); });
    });
})();

function slBadgeClass(slPercent) {
    if (slPercent >= 90) return 'metric-excellent';
    if (slPercent >= 80) return 'metric-good';
    if (slPercent >= 70) return 'metric-warning';
    return 'metric-poor';
}

function renderBulkResults(payload) {
    const section = document.getElementById('bulkResultsSection');
    section.classList.remove('hidden');

    const s = payload.summary;
    animateNumber(document.getElementById('bulkProcessed'), s.processed);
    animateNumber(document.getElementById('bulkTotalAgents'), s.total_agents);
    animateNumber(document.getElementById('bulkTotalStaff'), s.total_staff);
    animateNumber(document.getElementById('bulkAvgSl'), s.avg_sl * 100, { suffix: '%', decimals: 2 });

    const body = document.getElementById('bulkResultsBody');
    body.innerHTML = '';

    payload.results.forEach((r, idx) => {
        const slPct = r.service_level * 100;
        const occPct = r.occupancy * 100;
        const rowEl = document.createElement('tr');
        rowEl.innerHTML = `
            <td class="text-left text-slate-100 font-semibold">${escapeHtml(r.label)}</td>
            <td class="text-right text-slate-300">${r.params.calls}</td>
            <td class="text-right text-slate-300">${r.params.ahtSeconds}s</td>
            <td class="text-right text-slate-300">${r.params.intervalMinutes}m</td>
            <td class="text-right text-slate-300">${Number(r.workload).toFixed(2)}</td>
            <td class="text-right text-cyan-300 font-bold">${r.required_agents}</td>
            <td class="text-right text-violet-300 font-bold">${r.required_staff}</td>
            <td class="text-right">
                <span class="metric-badge ${slBadgeClass(slPct)}">${slPct.toFixed(2)}%</span>
            </td>
            <td class="text-right text-slate-300">${occPct.toFixed(2)}%</td>
        `;
        body.appendChild(rowEl);
    });

    const errorsSection = document.getElementById('bulkErrorsSection');
    const errorsList = document.getElementById('bulkErrorsList');
    errorsList.innerHTML = '';
    if (payload.errors && payload.errors.length) {
        payload.errors.forEach(err => {
            const li = document.createElement('li');
            li.textContent = 'Fila ' + err.line + ' (' + (err.label || 'sin etiqueta') + '): ' + err.error;
            errorsList.appendChild(li);
        });
        errorsSection.classList.remove('hidden');
    } else {
        errorsSection.classList.add('hidden');
    }

    section.classList.remove('animate-slide-in');
    void section.offsetWidth;
    section.classList.add('animate-slide-in');
}

function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, m => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[m]));
}

function clearBulkResults() {
    lastBulkResults = null;
    document.getElementById('bulkResultsSection').classList.add('hidden');
    document.getElementById('bulkStatus').classList.add('hidden');
    document.getElementById('bulkResultsBody').innerHTML = '';
    showToast('Resultados de intervalos limpiados', 'info');
}

function exportBulkResults() {
    if (!lastBulkResults || !lastBulkResults.results.length) {
        showToast('No hay resultados para exportar', 'warning');
        return;
    }
    const rows = [
        ['Intervalo', 'Llamadas', 'AHT (seg)', 'Duración (min)', 'SL Objetivo (%)', 'Tiempo Resp. (seg)',
         'Ocupación Obj. (%)', 'Shrinkage (%)', 'Erlangs', 'Agentes', 'Staff', 'SL Proyectado (%)', 'Ocupación Real (%)']
    ];
    lastBulkResults.results.forEach(r => {
        rows.push([
            r.label,
            r.params.calls,
            r.params.ahtSeconds,
            r.params.intervalMinutes,
            r.params.targetSl,
            r.params.targetAns,
            r.params.occupancyTarget,
            r.params.shrinkage,
            Number(r.workload).toFixed(3),
            r.required_agents,
            r.required_staff,
            (r.service_level * 100).toFixed(2),
            (r.occupancy * 100).toFixed(2)
        ]);
    });
    const csv = rows.map(r => r.map(v => {
        const s = String(v ?? '');
        return s.includes(',') || s.includes('"') ? '"' + s.replace(/"/g, '""') + '"' : s;
    }).join(',')).join('\n');

    const timestamp = new Date().toISOString().replace(/[:.]/g, '-').split('T')[0];
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'analisis_intervalos_' + timestamp + '.csv';
    link.click();
    showToast('Resultados exportados', 'success');
}

// Scroll-spy for nav pills
function initScrollSpy() {
    const nav = document.getElementById('slcNav');
    if (!nav) return;
    const links = Array.from(nav.querySelectorAll('a[href^="#"]'));
    const sections = links.map(a => document.querySelector(a.getAttribute('href'))).filter(Boolean);
    if (!sections.length) return;

    const setActive = (id) => {
        links.forEach(a => a.classList.toggle('is-active', a.getAttribute('href') === '#' + id));
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) setActive(entry.target.id);
        });
    }, { rootMargin: '-40% 0px -55% 0px', threshold: 0 });

    sections.forEach(sec => observer.observe(sec));

    // Smooth scroll on click
    links.forEach(a => a.addEventListener('click', (e) => {
        const targetId = a.getAttribute('href').slice(1);
        const target = document.getElementById(targetId);
        if (target) {
            e.preventDefault();
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            setActive(targetId);
        }
    }));
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateSlPreview();
    initScrollSpy();
    console.log('Service Level Calculator Ready');
});
</script>

<?php include __DIR__ . '/../footer.php'; ?>
