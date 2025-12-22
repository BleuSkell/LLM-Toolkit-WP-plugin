document.addEventListener("DOMContentLoaded", function () {
    const botInput = document.getElementById("bot-input");
    const addButton = document.getElementById("add-bot");
    const botBody = document.getElementById("llm-bot-body");
    const hiddenBots = document.getElementById("bots-hidden");

    // Load existing bots
    const bots = Array.from(botBody.querySelectorAll("tr"))
        .map(tr => tr.querySelector("td").textContent.trim());

    function updateHidden() {
        hiddenBots.value = bots.join("|||");
    }
    updateHidden();

    addButton.addEventListener("click", function () {
        const name = botInput.value.trim();
        if (!name) return;

        bots.push(name);
        updateHidden();

        const row = document.createElement("tr");
        row.classList.add("bot-row");
        row.innerHTML = `
                    <td>${name}</td>
                    <td>
                        <button type="button" class="button button-small button-link-delete remove-bot">
                            <span class="dashicons dashicons-trash"></span> Remove
                        </button>
                    </td>
                `;
        botBody.appendChild(row);

        botInput.value = "";
    });

    botBody.addEventListener("click", function (e) {
        if (!e.target.closest(".remove-bot")) return;

        const row = e.target.closest("tr");
        const name = row.querySelector("td").textContent.trim();

        const index = bots.indexOf(name);
        if (index !== -1) bots.splice(index, 1);

        row.remove();
        updateHidden();
    });
});

// JS function called by admin toolbar button
function ajaxGenerateLLM (currentPageUrl) {

    if (!confirm("Generate LLMS HTML of this page. IMPORTANT!: Existing file will be overwritten!")) return;

    jQuery.post(
        llmToolkit.ajaxHandlerUrl,
        {
            action: 'llm_toolkit_generate_single_html', // call function to generate html
            url: currentPageUrl,
        },
        function(response) {
            if (response.success) {
                window.alert("Successfully generated LLM HTML for this page");
            } else {
                window.alert("Failed to generate LLM HTML for this page, check logs");
            }
        }
    ).fail(function() {
        window.alert("An unexpected error occurred while generating the LLM HTML");
    });
}