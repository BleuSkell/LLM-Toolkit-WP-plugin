document.addEventListener("DOMContentLoaded", function () {
    const botInput = document.getElementById("bot-input");
    const addButton = document.getElementById("add-bot");
    const botBody = document.getElementById("llm-bot-body");
    const hiddenBots = document.getElementById("bots-hidden");

    const sitemapCheckboxes = document.querySelectorAll('input[name="llm_toolkit_selected_sitemaps[]"]');
    const promptBlocks = document.querySelectorAll('.prompt-block');
    const promptCheckboxes = document.querySelectorAll(".llm-prompt-checkbox");

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

    sitemapCheckboxes.forEach(cb => {
        cb.addEventListener("change", function() {
            const sitemap = this.value;
            const block = document.querySelector(`.prompt-block[data-sitemap="${sitemap}"]`);

            if (this.checked) {
                block.style.display = '';
            } else {
                block.style.display = 'none';
            }
        });
    });

    promptCheckboxes.forEach(checkbox => {
        const targetId = checkbox.dataset.target;
        const promptInput = document.getElementById(targetId);

        promptInput.dataset.originalCustomPrompt = promptInput.value;

        promptInput.addEventListener("input", function() {
            this.dataset.originalCustomPrompt = this.value;
        });

        checkbox.addEventListener("change", function() {
            if (this.checked) {
                promptInput.value = promptInput.dataset.originalCustomPrompt;
                promptInput.style.display = 'block';
            } else {
                promptInput.value = '';
                promptInput.style.display = 'none';
            }
        });
    });

    const promptLabels = document.querySelectorAll(".prompt-label");

    promptLabels.forEach(label => {
        const targetId = label.dataset.prompt;
        const promptInput = document.getElementById(targetId);
        const checkbox = document.querySelector(`.llm-prompt-checkbox[data-target="${targetId}"]`);

        const checkboxChecked = checkbox.checked;
        let promptState = localStorage.getItem("llm-toolkit-toggle-hidden-prompt");

        if (checkboxChecked) {
            if (promptState === "true") {
                promptInput.style.display = "none";
            } else {
                promptInput.style.display = "block";
            }
        }

        label.addEventListener("click", function() {
            const promptInputHidden = window.getComputedStyle(promptInput).display === 'none';

            if (checkboxChecked) {
                if (promptInputHidden) {
                    promptInput.style.display = "block";
                    localStorage.setItem("llm-toolkit-toggle-hidden-prompt", "false");
                } else {
                    promptInput.style.display = "none";
                    localStorage.setItem("llm-toolkit-toggle-hidden-prompt", "true");
                }
            }
        });
    });
});