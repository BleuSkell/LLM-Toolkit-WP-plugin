document.addEventListener("DOMContentLoaded", function () {
    const list = document.getElementById("llm-status-list");
    const listContainer = document.getElementById("llm-status-list-container");
    const summaryElement = document.getElementById("llm-summary-text");

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
        fetch(ajaxurl + '?action=llm_toolkit_get_status')
            .then(r => r.json())
            .then(messages => {
                list.innerHTML = "";

                messages.forEach(msg => {
                    if (msg.type === 'bot_request_logs') return;

                    let color = "#000";
                    let label = "Info";

                    if (msg.type === 'cron')     { color = "#2271b1"; label = "Cron"; }
                    if (msg.type === 'success')  { color = "#049723"; label = "Success"; }
                    if (msg.type === 'error')    { color = "#c60000"; label = "Error"; }

                    const li = document.createElement("li");
                    li.className = "llm-log-item";

                    li.innerHTML = `
                                <span class="llm-badge" style="background:${color};">${label}</span>
                                <span class="llm-log-text">[${msg.time}] ${msg.message}</span>
                            `;

                    list.appendChild(li);
                });

                if (autoScroll == true) {
                    scrollToBottom();
                }
            });
    }

    // refresh every 3 seconds
    setInterval(fetchStatus, 3000);
    fetchStatus();

    function fetchSummary() {
        fetch(ajaxurl + '?action=llm_toolkit_get_summary')
            .then(response => response.json())
            .then(data => {
                summaryElement.innerHTML = `<strong>${data.generated_files}</strong> of <strong>${data.total_pages}</strong> pages currently have LLMS HTML.`;
            })
            .catch(err => console.log('LLM summary fetching error: ', err));
    }

    setInterval(fetchSummary, 3000);
    fetchSummary();
});