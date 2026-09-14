// Principal dashboard charts. Every dataset is a value the backend
// already computed and injected (RISK_DATA, TERM_TRENDS, SECTION_RISK_DATA,
// COMPONENT_PERFORMANCE) — nothing is derived or re-calculated here.
// Colours are the design system's semantic tokens (tailwind.config.js):
// success #16A34A, warning #F59E0B, danger #DC2626, brand #1F6B2A.
document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;

    const COLOR = { success: '#16A34A', warning: '#F59E0B', danger: '#DC2626', brand: '#1F6B2A', accent: '#3FAE4D', info: '#2563EB', grid: '#EEF1F5', muted: '#667085' };
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.color = COLOR.muted;

    // Chart 1 — Risk distribution (doughnut)
    const donutEl = document.getElementById('riskDonutChart');
    if (donutEl && typeof RISK_DATA !== 'undefined') {
        new Chart(donutEl, {
            type: 'doughnut',
            data: {
                labels: ['Low Risk', 'Moderate Risk', 'High Risk'],
                datasets: [{
                    data: [RISK_DATA.low, RISK_DATA.moderate, RISK_DATA.high],
                    backgroundColor: [COLOR.success, COLOR.warning, COLOR.danger],
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverOffset: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 14, usePointStyle: true, pointStyle: 'circle' } }
                }
            }
        });
    }

    // Chart 2 — Performance trend (line)
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
    const barEl = document.getElementById('sectionRiskChart');
    if (barEl && typeof SECTION_RISK_DATA !== 'undefined') {
        new Chart(barEl, {
            type: 'bar',
            data: {
                labels: SECTION_RISK_DATA.map(s => s.section),
                datasets: [
                    { label: 'Moderate', data: SECTION_RISK_DATA.map(s => s.moderate), backgroundColor: COLOR.warning, borderRadius: 4, maxBarThickness: 18 },
                    { label: 'High',     data: SECTION_RISK_DATA.map(s => s.high),     backgroundColor: COLOR.danger,  borderRadius: 4, maxBarThickness: 18 }
                ]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 14, usePointStyle: true, pointStyle: 'circle' } }
                },
                scales: {
                    x: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 }, precision: 0 }, grid: { color: COLOR.grid } },
                    y: { ticks: { font: { size: 12 } }, grid: { display: false } }
                }
            }
        });
    }

    // Chart 4 — Component performance (bar), one bar per assessment component
    const compEl = document.getElementById('componentPerformanceChart');
    if (compEl && typeof COMPONENT_PERFORMANCE !== 'undefined' && COMPONENT_PERFORMANCE.length) {
        new Chart(compEl, {
            type: 'bar',
            data: {
                labels: COMPONENT_PERFORMANCE.map(c => c.label),
                datasets: [{
                    label: 'Average %',
                    data: COMPONENT_PERFORMANCE.map(c => c.average),
                    backgroundColor: COMPONENT_PERFORMANCE.map(c => c.average >= 75 ? COLOR.success : (c.average >= 70 ? COLOR.warning : COLOR.danger)),
                    borderRadius: 6,
                    maxBarThickness: 44,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => `${ctx.parsed.y.toFixed(1)}% (target 75%)` } }
                },
                scales: {
                    y: { min: 0, max: 100, ticks: { stepSize: 25, font: { size: 11 }, callback: v => v + '%' }, grid: { color: COLOR.grid } },
                    x: { ticks: { font: { size: 12 } }, grid: { display: false } }
                }
            }
        });
    }
});
