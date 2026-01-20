document.addEventListener("DOMContentLoaded", function () {
    const botInput = document.getElementById("bot-input");
    const addButton = document.getElementById("add-bot");
    const botBody = document.getElementById("llm-bot-body");
    const hiddenBots = document.getElementById("bots-hidden");

    const sitemapCheckboxes = document.querySelectorAll('input[name="llm_toolkit_selected_sitemaps[]"]');
    const promptBlocks = document.querySelectorAll('.prompt-block');
    const promptCheckboxes = document.querySelectorAll(".llm-prompt-checkbox");
    const promptLabels = document.querySelectorAll(".prompt-label");

    const chevronUp = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-up" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M7.646 4.646a.5.5 0 0 1 .708 0l6 6a.5.5 0 0 1-.708.708L8 5.707l-5.646 5.647a.5.5 0 0 1-.708-.708z"/>
</svg>`;
    const chevronDown = `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-chevron-down" viewBox="0 0 16 16">
  <path fill-rule="evenodd" d="M1.646 4.646a.5.5 0 0 1 .708 0L8 10.293l5.646-5.647a.5.5 0 0 1 .708.708l-6 6a.5.5 0 0 1-.708 0l-6-6a.5.5 0 0 1 0-.708"/>
</svg>`;

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
        const label = document.querySelector(`.prompt-label[data-prompt="${targetId}"]`);
        const chevronSpan = label.querySelector(".chevron");

        promptInput.dataset.originalCustomPrompt = promptInput.value;

        promptInput.addEventListener("input", function() {
            this.dataset.originalCustomPrompt = this.value;
        });

        checkbox.addEventListener("change", function() {
            if (this.checked) {
                promptInput.value = promptInput.dataset.originalCustomPrompt;
                promptInput.style.display = 'block';
                chevronSpan.innerHTML = chevronDown;
            } else {
                promptInput.value = '';
                promptInput.style.display = 'none';
                chevronSpan.innerHTML = chevronUp;
            }
        });
    });

    promptLabels.forEach(label => {
        const targetId = label.dataset.prompt;
        const promptInput = document.getElementById(targetId);
        const checkbox = document.querySelector(`.llm-prompt-checkbox[data-target="${targetId}"]`);
        const chevronSpan = label.querySelector(".chevron");

        const checkboxChecked = checkbox.checked;
        let promptState = localStorage.getItem("llm-toolkit-toggle-hidden-prompt");

        if (checkboxChecked) {
            if (promptState === "true") {
                promptInput.style.display = "none";
                chevronSpan.innerHTML = chevronUp;
            } else {
                promptInput.style.display = "block";
                chevronSpan.innerHTML = chevronDown;
            }
        }

        label.addEventListener("click", function() {
            const promptInputHidden = window.getComputedStyle(promptInput).display === 'none';

            if (checkboxChecked) {
                if (promptInputHidden) {
                    promptInput.style.display = "block";
                    localStorage.setItem("llm-toolkit-toggle-hidden-prompt", "false");
                    chevronSpan.innerHTML = chevronDown;
                } else {
                    promptInput.style.display = "none";
                    localStorage.setItem("llm-toolkit-toggle-hidden-prompt", "true");
                    chevronSpan.innerHTML = chevronUp;
                }
            }
        });
    });
});