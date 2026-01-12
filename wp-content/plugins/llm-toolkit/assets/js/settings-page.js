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
        const defaultPrompt = "You're the best HTML coder and always writes flawless HTML pages with no errors or faults. Turn this HTML into a clean, semantic, and correct version, optimized for reading by LLMs with only the relevant content as described below: \n" +
            "- Keep the original page structure. \n" +
            "- Don't add additional content. \n" +
            "- Remove scripts, styles, classes, icons, navigation,footers, sidebars, tracking, ads, pop-ups, cookie banners, and other irrelevant elements. \n" +
            "- Return only the HTML, without additional text or explanations and witout ```html. \n" +
            "- Keep only the following tags in the header: canoncial, hreflang, robots, alternate, title, description, site name, generator, article:modified. \n" +
            "- Return only visible, relevant page content for a reader. Remove all instructions, embed-fallbacks, Javascript prompts or template texts.";

        promptInput.dataset.originalCustomPrompt = promptInput.value;

        promptInput.addEventListener("input", function() {
            this.dataset.originalCustomPrompt = this.value;
        });

        checkbox.addEventListener("change", function() {
            if (this.checked) {
                if (promptInput.dataset.originalCustomPrompt === defaultPrompt) {
                    promptInput.dataset.originalCustomPrompt = '';
                }

                promptInput.value = promptInput.dataset.originalCustomPrompt;
                promptInput.removeAttribute('readonly');
                promptInput.style.color = '';
            } else {
                promptInput.value = defaultPrompt;
                promptInput.setAttribute('readonly', true);
                promptInput.style.color = 'gray';
            }
        });
    });
});