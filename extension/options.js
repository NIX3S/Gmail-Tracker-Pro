function originPatternFor(url) {
    try {
        const u = new URL(url);
        return `${u.protocol}//${u.host}/*`;
    } catch (e) {
        return null;
    }
}

/** Demande à Chrome la permission d'accès réseau pour ce domaine précis (popup natif). */
function requestHostPermission(apiBaseUrl) {
    return new Promise((resolve) => {
        const pattern = originPatternFor(apiBaseUrl);
        if (!pattern) {
            resolve({ granted: false, error: "URL invalide." });
            return;
        }
        chrome.permissions.request({ origins: [pattern] }, (granted) => {
            if (chrome.runtime.lastError) {
                resolve({ granted: false, error: chrome.runtime.lastError.message });
                return;
            }
            resolve({ granted, error: granted ? null : "Permission refusée pour ce domaine." });
        });
    });
}

document.addEventListener("DOMContentLoaded", () => {
    chrome.storage.sync.get(["apiToken", "apiBaseUrl"], (result) => {
        if (result.apiToken) document.getElementById("token").value = result.apiToken;
        document.getElementById("apiBaseUrl").value = result.apiBaseUrl || "https://statistics.ct.ws";
    });

    document.getElementById("save").addEventListener("click", async () => {
        const saved = document.getElementById("saved");
        const token = document.getElementById("token").value.trim();
        const apiBaseUrl = document.getElementById("apiBaseUrl").value.trim().replace(/\/+$/, "");

        // On demande la permission d'accès réseau pour ce domaine AVANT de sauvegarder :
        // sans ça, Chrome bloquera les requêtes de background.js vers ce domaine (erreur CORS).
        const { granted, error } = await requestHostPermission(apiBaseUrl);
        if (!granted) {
            saved.className = "saved";
            saved.style.display = "block";
            saved.style.background = "#fbe9e7";
            saved.style.color = "#a13d2b";
            saved.textContent = `✗ ${error || "Permission refusée."} L'URL n'a pas été enregistrée.`;
            return;
        }

        chrome.storage.sync.set({ apiToken: token, apiBaseUrl }, () => {
            saved.className = "saved";
            saved.style.background = "#e7f0e9";
            saved.style.color = "#2f6b45";
            saved.style.display = "block";
            saved.textContent = "Enregistré ✓ (permission réseau accordée pour ce domaine)";
            setTimeout(() => (saved.style.display = "none"), 3000);
        });
    });

    document.getElementById("test").addEventListener("click", async () => {
        const resultEl = document.getElementById("testResult");
        const apiBaseUrl = document.getElementById("apiBaseUrl").value.trim().replace(/\/+$/, "");
        const token = document.getElementById("token").value.trim();

        resultEl.className = "test-result";
        resultEl.style.display = "block";
        resultEl.textContent = "Test en cours...";

        const pattern = originPatternFor(apiBaseUrl);
        const hasPermission = pattern && await new Promise((resolve) =>
            chrome.permissions.contains({ origins: [pattern] }, resolve)
        );

        if (!hasPermission) {
            resultEl.classList.add("test-fail");
            resultEl.textContent =
                `✗ L'extension n'a pas encore la permission d'accéder à ce domaine. ` +
                `Clique d'abord sur "Enregistrer" pour l'accorder (une popup Chrome va apparaître).`;
            return;
        }

        try {
            // Peu importe le code retourné (401, 400...) : ce qui compte c'est que la réponse
            // soit bien du JSON, preuve que le backend est déployé et exécute PHP correctement.
            const response = await fetch(`${apiBaseUrl}/register_email.php`, {
                method: "GET",
                headers: token ? { Authorization: `Bearer ${token}` } : {},
            });
            const text = await response.text();

            try {
                const json = JSON.parse(text);
                resultEl.classList.add("test-ok");
                resultEl.textContent =
                    `✓ Le serveur répond en JSON (code ${response.status}) : ${JSON.stringify(json)}`;
            } catch (parseErr) {
                resultEl.classList.add("test-fail");
                const preview = text.replace(/\s+/g, " ").slice(0, 200);
                resultEl.textContent =
                    `✗ Le serveur a répondu avec le code ${response.status} mais ce n'est pas du JSON. ` +
                    `Vérifie que "${apiBaseUrl}/register_email.php" existe bien sur ton hébergement. ` +
                    `Début de la réponse : "${preview}"`;
            }
        } catch (networkErr) {
            resultEl.classList.add("test-fail");
            resultEl.textContent = `✗ Impossible de contacter ${apiBaseUrl} : ${networkErr}`;
        }
    });
});
