document.addEventListener("DOMContentLoaded", function () {
    const list          = document.getElementById("llm-bot-requests");
    const listContainer = document.getElementById("llm-bot-requests-container");
    const botChart      = document.getElementById("llm-bot-chart");
    const viewPerMonth  = document.getElementById("view-per-month");
    const visitsTable   = document.getElementById("llm-bot-visits-table");

    let autoScroll      = true;
    let chartInstance   = null;

    const chartColors = {
        crawler: '#4e79a7',
        answer_engine: '#f28e2b',
        trad_search_crawler: '#e15759',
        ai_referrals: '#76b7b2'
    };

    listContainer.addEventListener("scroll", () => {
        const isAtBottom = listContainer.scrollHeight - listContainer.scrollTop - listContainer.clientHeight < 5;

        if (isAtBottom) {
            autoScroll = true;
        } else {
            autoScroll = false;
        }
    });

    function scrollToBottom() {
        listContainer.scrollTo({
            top: listContainer.scrollHeight,
            behavior: "smooth"
        });
    }

    function fetchStatus() {
        fetch(ajaxurl + '?action=llm_toolkit_get_bot_requests')
            .then(response => response.json())
            .then(messages => {
                list.innerHTML = "";

                messages.forEach(msg => {
                    if (msg.user_agent !== 'PHP') {
                        const li = document.createElement("li");

                        li.className = 'llm-log-request';

                        li.textContent = `[${msg.time}] ${msg.user_agent} → ${msg.request} (IP: ${msg.ip})`;
                        list.appendChild(li);
                    }
                });

                if (autoScroll == true) {
                    scrollToBottom();
                }
            });
    }

    function renderVisitsTable(daily, monthly, viewMonthly) {
        const records   = viewMonthly ? monthly : daily;
        const hitsKey   = viewMonthly ? 'total_htis' : 'hits';
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
        const rows      = viewMonthly ? monthly : daily;
        const cats      = [...new Set(rows.map(r => r.category))];
        const labels    = viewMonthly
            ? [...new Set(monthly.map(r => r.month))].sort()
            : [...new Set(daily.map(r => r.day))].sort();

        const datasets = cats.map(cat => {
            const data = labels.map(label => {
                const row = rows.find(r =>
                    r.category === cat && (viewMonthly ? r.month : r.day) === label
                );
                return row ? (viewMonthly ? row.total_htis : row.hits) : 0;
            });

            return {
                label: cat,
                data,
                backgroundColor: chartColors[cat] ?? '#aaaaaa',
            }
        })

        if (chartInstance) chartInstance.destroy();

        chartInstance = new Chart(botChart.getContext('2d'), {
            type: 'bar',
            data: {
                labels,
                datasets
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {position: 'top'},
                    title: {display: true, text: viewMonthly ? 'Past months' : 'Past 14 days'}
                },
                scales: {
                    x: {stacked: false},
                    y: {beginAtZero: true, title: {display: true, text: 'Hits'}}
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

    setInterval(fetchStatus, 10000);
    fetchStatus();
    fetchBotVisits();
});