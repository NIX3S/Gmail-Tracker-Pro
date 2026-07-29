const DEFAULT_API_BASE = "https://statistics.ct.ws";

/**
 * Lit une réponse fetch en JSON. Si le serveur renvoie autre chose que du JSON
 * (page d'erreur 404/500 de l'hébergeur, erreur PHP non catchée, etc.), on renvoie
 * un message d'erreur clair avec le code HTTP au lieu de planter sur
 * "Unexpected token '<' is not valid JSON".
 */
async function parseJsonResponse(response) {
    const text = await response.text();
    try {
        return JSON.parse(text);
    } catch (e) {
        const preview = text.replace(/\s+/g, " ").slice(0, 150);
        return {
            error: `Le serveur a répondu avec le code ${response.status} au lieu de JSON. ` +
                `Vérifie l'URL configurée dans les options de l'extension, que le fichier PHP ` +
                `est bien en ligne à cette adresse, et consulte les logs serveur. ` +
                `Début de la réponse reçue : "${preview}"`,
        };
    }
}

function getConfig(callback) {
    chrome.storage.sync.get(["apiToken", "apiBaseUrl"], (result) => {
        callback({
            token: result.apiToken || null,
            // Si rien n'est configuré dans les options, on retombe sur la valeur par défaut —
            // mais le mieux est de la renseigner explicitement dans les options de l'extension
            // pour qu'elle corresponde exactement à APP_BASE_URL de config.php côté serveur.
            apiBase: (result.apiBaseUrl || DEFAULT_API_BASE).replace(/\/+$/, ""),
        });
    });
}

function originPatternFor(url) {
    try {
        const u = new URL(url);
        return `${u.protocol}//${u.host}/*`;
    } catch (e) {
        return null;
    }
}

/**
 * Un service worker ne peut pas afficher de popup de permission (ça nécessite un geste
 * utilisateur direct sur une page d'extension). On vérifie juste ici que la permission
 * a déjà été accordée — sinon on renvoie un message clair au lieu de laisser Chrome
 * bloquer silencieusement la requête avec une erreur CORS.
 */
function hasHostPermission(apiBase) {
    return new Promise((resolve) => {
        const pattern = originPatternFor(apiBase);
        if (!pattern) {
            resolve(false);
            return;
        }
        chrome.permissions.contains({ origins: [pattern] }, resolve);
    });
}

async function withPermissionCheck(apiBase, onMissing, onReady) {
    const granted = await hasHostPermission(apiBase);
    if (!granted) {
        onMissing({
            error: `L'extension n'a pas la permission d'accéder à ${apiBase}. ` +
                `Ouvre les options de l'extension, vérifie l'URL puis clique sur "Enregistrer" ` +
                `pour accorder la permission (une popup Chrome apparaîtra).`,
        });
        return;
    }
    onReady();
}

chrome.runtime.onMessage.addListener((request, sender, sendResponse) => {
    if (request.action === "getTrackedEmails") {
        chrome.storage.local.get(null, (data) => {
            // On ne garde que les entrées "email" locales (cache léger côté extension)
            sendResponse(data);
        });
        return true;
    }

    if (request.action === "registerEmail") {
        getConfig(({ token, apiBase }) => {
            if (!token) {
                sendResponse({ error: "Aucun token API configuré. Ouvre les options de l'extension." });
                return;
            }
            withPermissionCheck(apiBase, sendResponse, () => {
                fetch(`${apiBase}/register_email.php`, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json",
                        "Authorization": `Bearer ${token}`,
                    },
                    body: JSON.stringify(request.payload),
                })
                    .then(parseJsonResponse)
                    .then((data) => sendResponse(data))
                    .catch((err) => sendResponse({ error: `Requête réseau échouée vers ${apiBase} : ${err}` }));
            });
        });
        return true;
    }

    if (request.action === "uploadDocument") {
        getConfig(({ token, apiBase }) => {
            if (!token) {
                sendResponse({ error: "Aucun token API configuré. Ouvre les options de l'extension." });
                return;
            }
            withPermissionCheck(apiBase, sendResponse, () => {
                try {
                    const byteChars = atob(request.fileBase64);
                    const byteNumbers = new Array(byteChars.length);
                    for (let i = 0; i < byteChars.length; i++) byteNumbers[i] = byteChars.charCodeAt(i);
                    const blob = new Blob([new Uint8Array(byteNumbers)], { type: request.mimeType });

                    const formData = new FormData();
                    formData.append("file", blob, request.fileName);
                    formData.append("subject", request.subject || "");
                    formData.append("recipient", request.recipient || "");
                    formData.append("category", request.category || "");

                    fetch(`${apiBase}/upload_document.php`, {
                        method: "POST",
                        headers: { "Authorization": `Bearer ${token}` },
                        body: formData,
                    })
                        .then(parseJsonResponse)
                        .then((data) => sendResponse(data))
                        .catch((err) => sendResponse({ error: `Requête réseau échouée vers ${apiBase} : ${err}` }));
                } catch (err) {
                    sendResponse({ error: String(err) });
                }
            });
        });
        return true;
    }
});
