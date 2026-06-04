document.addEventListener("DOMContentLoaded", function () {
    const list = document.getElementById("llm-bot-requests");
    const listContainer = document.getElementById("llm-bot-requests-container");
    const botChart = document.getElementById("llm-bot-chart");

    let autoScroll = true;

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

    function fetchAiCount() {
        fetch(ajaxurl + '?action=llm_toolkit_bot_counts')
            .then(response => response.json())
            .then(response => {const daily = response.daily;
                const monthly = response.monthly;

                const uas = [...new Set([
                    ...daily.map(r => r.UA),
                    ...monthly.map(r => r.UA)
                ])];

                const dailyLabels   = [...new Set(daily.map(r => r.day))].sort();
                const monthlyLabels = [...new Set(monthly.map(r => r.month))].sort();
                const labels        = [...monthlyLabels, ...dailyLabels];

                const colors = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f'];
                const datasets = uas.map((ua, i) => {
                    const data = labels.map(label => {
                        const dailyRow = daily.find(r => r.UA === ua && r.day === label);
                        if (dailyRow) return dailyRow.hits;

                        const monthlyRow = monthly.find(r => r.UA === ua && r.month === label);
                        if (monthlyRow) return monthlyRow.total_hits;

                        return 0;
                    });

                    return {
                        label: ua,
                        data,
                        backgroundColor: colors[i % colors.length],
                    };
                });

                const ctx = botChart.getContext('2d');
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels,
                        datasets
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {position: 'top'},
                            title: {
                                display: true,
                                text: 'Bot visists - daily (last 14 days_ + monthly (older than 14 days)'
                            }
                        },
                        scales: {
                            x: {stacked: false},
                            y: {beginAtZero: true, title: {display: true, text: 'Hits'}}
                        }
                    }
                });
            });
    }

    setInterval(fetchStatus, 10000);
    fetchStatus();
    fetchAiCount();
});