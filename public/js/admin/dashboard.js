// Principal dashboard charts. Every dataset is a value the backend
// already computed and injected (TERM_TRENDS, COMPONENT_PERFORMANCE) — nothing is derived or re-calculated here.
// Colours are the design system's semantic tokens (tailwind.config.js):
// success #16A34A, warning #F59E0B, danger #DC2626, brand #1F6B2A.
// Fragment on Refresh / Back / Forward: the browser restores the ROOT scroll
// position there instead of scrolling to the #fragment, but this layout scrolls
// inside <main>, so the page would reopen at the top. One scroll to the target,
// only for those navigation types (ordinary links/form submits use the native
// anchor jump). Chart canvases sit in fixed-height boxes, so nothing shifts after.
document.addEventListener('DOMContentLoaded', function () {
    const nav = performance.getEntriesByType('navigation')[0];
    const target = location.hash.length > 1 ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
    if (target && nav && (nav.type === 'reload' || nav.type === 'back_forward')) {
        target.scrollIntoView({ block: 'start' });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    const COLOR = { success: '#16A34A', warning: '#F59E0B', danger: '#DC2626', brand: '#1F6B2A', accent: '#3FAE4D', info: '#2563EB', grid: '#EEF1F5', muted: '#667085' };
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = COLOR.muted;

    // Chart 1 — Risk distribution (doughnut)
    const lineEl = document.getElementById('termTrendChart');
    if (lineEl && typeof TERM_TRENDS !== 'undefined') {
        new Chart(lineEl, {
            type: 'line',
            data: {
                labels: ['Term 1', 'Term 2', 'Term 3'],
                datasets: [{
                    label: 'Average Computed Grade',
                    data: TERM_TRENDS,
                    borderColor: COLOR.brand,
                    backgroundColor: 'rgba(31,107,42,0.10)',
                    borderWidth: 2,
                    pointBackgroundColor: COLOR.brand,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    tension: 0.3,
                    fill: true,
                    spanGaps: false,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.parsed.y !== null
                                ? `Computed: ${ctx.parsed.y.toFixed(2)}`
                                : 'No data yet'
                        }
                    }
                },
                scales: {
                    y: { min: 60, max: 100, ticks: { stepSize: 10, font: { size: 11 } }, grid: { color: COLOR.grid } },
                    x: { ticks: { font: { size: 11 } }, grid: { display: false } }
                }
            }
        });
    }

    // Chart 3 — Risk by section (horizontal bar)
    const compEl = document.getElementById('componentPerformanceChart');
    if (compEl && typeof COMPONENT_PERFORMANCE !== 'undefined' && COMPONENT_PERFORMANCE.some(c => c.average !== null)) {
        new Chart(compEl, {
            type: 'bar',
            data: {
                labels: COMPONENT_PERFORMANCE.map(c => c.label),
                datasets: [{
                    label: 'Average %',
                    data: COMPONENT_PERFORMANCE.map(c => c.average),
                    // null = no data: no bar (never drawn as 0). Two states only, against
                    // the system's 75% in-term reference — not a pass/fail mark.
                    backgroundColor: COMPONENT_PERFORMANCE.map(c => c.average === null ? COLOR.grid : (c.average >= 75 ? COLOR.success : COLOR.warning)),
                    borderRadius: 6,
                    maxBarThickness: 44,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => `${ctx.parsed.y.toFixed(1)}% average raw score (reference 75%)` } }
                },
                scales: {
                    y: { min: 0, max: 100, ticks: { stepSize: 25, font: { size: 11 }, callback: v => v + '%' }, grid: { color: COLOR.grid } },
                    x: { ticks: { font: { size: 12 } }, grid: { display: false } }
                }
            }
        });
    }
});
