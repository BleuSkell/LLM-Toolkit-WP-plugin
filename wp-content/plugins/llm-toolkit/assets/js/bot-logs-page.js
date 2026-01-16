document.addEventListener("DOMContentLoaded", function () {
    const list = document.getElementById("llm-bot-logs");
    const listContainer = document.getElementById("llm-bot-logs-container");

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
        fetch(ajaxurl + '?action=llm_toolkit_get_bot_logs')
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