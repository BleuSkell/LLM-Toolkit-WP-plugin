document.addEventListener("DOMContentLoaded", function () {
    const botChart      = document.getElementById("llm-bot-chart");
    const viewPerMonth  = document.getElementById("view-per-month");
    const visitsTable   = document.getElementById("llm-bot-visits-table");

    let chartInstance   = null;

    const chartColors = {
        crawler: '#4e79a7',
        answer_engine: '#f28e2b',
        trad_search_crawler: '#e15759',
        ai_referrals: '#76b7b2'
    };

    function getLast14Days() {
        const days = [];
        for (let i = 13; i >= 0; i--) {
            const d = new Date();
            d.setDate(d.getDate() - i);
            days.push(d.toISOString().split('T')[0]);
        }
        return days;
    }

    function getMonthRange(monthly) {
        const months = [];
        const oldest = monthly.length ? monthly[monthly.length - 1].month : null;
        if (!oldest) return months;

        const start  = new Date(oldest + '-01');
        const end    = new Date();
        end.setDate(end.getDate() - 14);

        while (start <= end) {
            months.push(start.toISOString().slice(0, 7));
            start.setMonth(start.getMonth() + 1);
        }
        return months;
    }

    function renderVisitsTable(daily, monthly, viewMonthly) {
        const records   = viewMonthly ? monthly : daily;
        const hitsKey   = viewMonthly ? 'total_hits' : 'hits';
        const dateKey   = viewMonthly ? 'month' : 'day';

        const rows = records.map(r => `
            <tr>
                <td>${r.category}</td>
                <td>${r.UA}</td>
                <td>${r.referral || '-'}</td>
                <td>${r[hitsKey]}</td>
                <td>${r[dateKey]}</td>
            </tr>
        `).join('');

        visitsTable.innerHTML = `
            <thead>
                <tr>
                    <th>Category</th>
                    <th>User-agent string</th>
                    <th>Referral</th>
                    <th>Hits</th>
                    <th>${viewMonthly ? 'Month' : 'Date'}</th>
                </tr>
            </thead>
            <tbody>
                ${rows}
            </tbody>
        `;
    }

    function renderChart(daily, monthly, viewMonthly) {
        const rows   = viewMonthly ? monthly : daily;
        const cats   = [...new Set(rows.map(r => r.category))];
        const labels = viewMonthly ? getMonthRange(monthly) : getLast14Days();

        const datasets = cats.map(cat => {
            const data = labels.map(label => {
                const dateKey = viewMonthly ? 'month' : 'day';
                const hitsKey = viewMonthly ? 'total_hits' : 'hits';

                return rows
                    .filter(r => r.category === cat && r[dateKey] === label)
                    .reduce((sum, r) => sum + Number(r[hitsKey]), 0);
            });

            return {
                label: cat,
                data,
                borderColor: chartColors[cat] ?? '#aaaaaa',
            };
        });

        if (chartInstance) chartInstance.destroy();

        chartInstance = new Chart(botChart.getContext('2d'), {
            type: 'line',
            data: { labels, datasets },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'top' },
                    title: { display: true, text: viewMonthly ? 'Bot visits in the past months' : 'Bot visits in the past 14 days' }
                },
                scales: {
                    x: { stacked: false },
                    y: { beginAtZero: true, title: { display: true, text: 'Hits' } }
                }
            }
        });

        renderVisitsTable(daily, monthly, viewMonthly);
    }

    function fetchBotVisits() {
        fetch(ajaxurl + '?action=llm_toolkit_bot_counts')
            .then(response => response.json())
            .then(response => {
                const daily = response.daily;
                const monthly = response.monthly;

                renderChart(daily, monthly, false);
                viewPerMonth.addEventListener('change', function() {
                    renderChart(daily, monthly, this.checked);
                });
            });
    }

    fetchBotVisits();
});