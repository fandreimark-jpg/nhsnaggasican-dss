document.addEventListener('DOMContentLoaded', function () {

    // Chart 1 — Donut
    const donutEl = document.getElementById('riskDonutChart');
    if (donutEl) {
        new Chart(donutEl, {
            type: 'doughnut',
            data: {
                labels: ['Low Risk', 'Moderate Risk', 'High Risk'],
                datasets: [{
                    data: [RISK_DATA.low, RISK_DATA.moderate, RISK_DATA.high],
                    backgroundColor: ['#22c55e', '#eab308', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 }, padding: 12 }
                    }
                }
            }
        });
    }

    // Chart 2 — Line
    const lineEl = document.getElementById('termTrendChart');
    if (lineEl) {
        new Chart(lineEl, {
            type: 'line',
            data: {
                labels: ['Term 1', 'Term 2', 'Term 3'],
                datasets: [{
                    label: 'Average Computed Grade',
                    data: TERM_TRENDS,
                    borderColor: '#3b82f6',
                    backgroundColor: 'rgba(59,130,246,0.1)',
                    borderWidth: 2,
                    pointBackgroundColor: '#3b82f6',
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
                    y: {
                        min: 60,
                        max: 100,
                        ticks: { stepSize: 10, font: { size: 11 } },
                        grid: { color: '#f3f4f6' }
                    },
                    x: {
                        ticks: { font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

    // Chart 3 — Bar
    const barEl = document.getElementById('sectionRiskChart');
    if (barEl) {
        new Chart(barEl, {
            type: 'bar',
            data: {
                labels: SECTION_RISK_DATA.map(s => 'Section ' + s.section),
                datasets: [
                    {
                        label: 'Moderate',
                        data: SECTION_RISK_DATA.map(s => s.moderate),
                        backgroundColor: '#eab308',
                        borderRadius: 4,
                    },
                    {
                        label: 'High',
                        data: SECTION_RISK_DATA.map(s => s.high),
                        backgroundColor: '#ef4444',
                        borderRadius: 4,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { font: { size: 11 }, padding: 12 }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { stepSize: 1, font: { size: 11 } },
                        grid: { color: '#f3f4f6' }
                    },
                    x: {
                        ticks: { font: { size: 11 } },
                        grid: { display: false }
                    }
                }
            }
        });
    }

});