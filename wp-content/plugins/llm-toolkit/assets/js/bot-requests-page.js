document.addEventListener("DOMContentLoaded", function () {
    const list          = document.getElementById("llm-bot-requests");
    const listContainer = document.getElementById("llm-bot-requests-container");

    let autoScroll      = true;

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

    setInterval(fetchStatus, 10000);
    fetchStatus();
});