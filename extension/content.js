/**
 * Gmail Tracker Pro — content script
 *
 * Changements majeurs vs la version précédente :
 *  - Suppression totale de addFakeAttachmentsfunc() : on n'injecte plus de fausses pièces
 *    jointes avec une icône/nom inventés pointant vers un fichier qui n'existe pas.
 *  - Suppression totale de la substitution silencieuse de PDF (téléchargement du vrai fichier,
 *    ajout d'un lien invisible plein-page, suppression et remplacement automatique).
 *  - Le mode "document tracké" est maintenant un choix explicite de l'utilisateur, avec un
 *    fichier qu'il upload lui-même, un lien clairement identifié inséré dans le corps du mail,
 *    et rien n'est fait à son insu.
 *  - Les liens de tracking sont signés côté SERVEUR (l'extension ne connaît pas le secret),
 *    ce qui empêche click.php de servir de redirecteur ouvert.
 *  - Gestion propre de "Extension context invalidated" (survient quand l'extension est
 *    rechargée alors qu'un onglet Gmail est resté ouvert avec l'ancien script) : on arrête
 *    proprement au lieu de spammer la console d'erreurs non catchées.
 *  - Champ de catégorie optionnel (ex: "Prospection", "Email important") pour retrouver
 *    plus vite les emails trackés dans le dashboard, avec mémorisation des catégories
 *    déjà utilisées (autocomplete via <datalist>).
 */

const SEND_BUTTON_SELECTOR = ".T-I.J-J5-Ji.aoO.T-I-atl.L3";
const RECENT_CATEGORIES_KEY = "recentCategories";
const MAX_RECENT_CATEGORIES = 20;

let contextInvalidatedWarned = false;

/** Détecte si le contexte de l'extension est toujours valide (recharge/mise à jour de l'extension). */
function isExtensionContextValid() {
    try {
        return !!(chrome && chrome.runtime && chrome.runtime.id);
    } catch (e) {
        return false;
    }
}

function warnContextInvalidatedOnce() {
    if (contextInvalidatedWarned) return;
    contextInvalidatedWarned = true;
    clearInterval(trackingButtonInterval);
    clearInterval(trackedDocButtonInterval);
    observer.disconnect();
    console.warn(
        "[Gmail Tracker Pro] L'extension a été mise à jour ou rechargée. " +
        "Recharge cette page Gmail (F5) pour continuer à utiliser le tracking."
    );
}

/**
 * Wrapper autour de chrome.runtime.sendMessage qui n'explose jamais en erreur non catchée :
 * si le contexte de l'extension a été invalidé, on prévient l'utilisateur une seule fois
 * et on arrête les boucles au lieu de continuer à échouer silencieusement.
 */
function safeSendMessage(payload, callback) {
    if (!isExtensionContextValid()) {
        warnContextInvalidatedOnce();
        alert("L'extension a été mise à jour ou rechargée. Recharge cette page Gmail (touche F5) puis réessaie.");
        return;
    }
    try {
        chrome.runtime.sendMessage(payload, (response) => {
            if (chrome.runtime.lastError) {
                const msg = chrome.runtime.lastError.message || "";
                if (msg.includes("context invalidated") || msg.includes("Extension context")) {
                    warnContextInvalidatedOnce();
                    alert("L'extension a été mise à jour ou rechargée. Recharge cette page Gmail (touche F5) puis réessaie.");
                    return;
                }
                callback({ error: msg });
                return;
            }
            callback(response);
        });
    } catch (e) {
        if (String(e).includes("context invalidated") || String(e).includes("Extension context")) {
            warnContextInvalidatedOnce();
            alert("L'extension a été mise à jour ou rechargée. Recharge cette page Gmail (touche F5) puis réessaie.");
            return;
        }
        callback({ error: String(e) });
    }
}

let observer = new MutationObserver(() => {
    if (!isExtensionContextValid()) {
        warnContextInvalidatedOnce();
        return;
    }
    if (document.querySelector(SEND_BUTTON_SELECTOR)) {
        addTrackingButton();
        addTrackedDocumentButton();
        addCategoryInput();
    }
});
observer.observe(document.body, { childList: true, subtree: true });

const trackingButtonInterval = setInterval(() => {
    if (!isExtensionContextValid()) {
        warnContextInvalidatedOnce();
        return;
    }
    addTrackingButton();
    addCategoryInput();
}, 3000);

const trackedDocButtonInterval = setInterval(() => {
    if (!isExtensionContextValid()) {
        warnContextInvalidatedOnce();
        return;
    }
    addTrackedDocumentButton();
}, 3000);

function findComposeRow() {
    let sendButton = document.querySelector(SEND_BUTTON_SELECTOR);
    if (!sendButton) return null;
    return sendButton.closest("tr.btC");
}

function addTrackingButton() {
    let row = findComposeRow();
    if (!row || document.getElementById("tracking-button")) return;

    let cell = document.createElement("td");
    let btn = document.createElement("button");
    btn.id = "tracking-button";
    btn.type = "button";
    btn.innerText = "Envoyer avec tracking";
    styleButton(btn, "#3d5a80");
    btn.onclick = sendWithTracking;

    cell.appendChild(btn);
    row.insertBefore(cell, row.cells[1]);
}

function addTrackedDocumentButton() {
    let row = findComposeRow();
    if (!row || document.getElementById("tracked-doc-button")) return;

    let cell = document.createElement("td");
    let btn = document.createElement("button");
    btn.id = "tracked-doc-button";
    btn.type = "button";
    btn.innerText = "Joindre un document tracké";
    styleButton(btn, "#c9821f");
    btn.onclick = attachTrackedDocument;

    cell.appendChild(btn);
    row.insertBefore(cell, row.cells[1]);
}

/** Petit champ texte optionnel pour catégoriser l'envoi (Prospection, Email important, ...) */
function addCategoryInput() {
    let row = findComposeRow();
    if (!row || document.getElementById("tracker-category-input")) return;

    if (!document.getElementById("tracker-category-list")) {
        const datalist = document.createElement("datalist");
        datalist.id = "tracker-category-list";
        document.body.appendChild(datalist);
        refreshCategoryDatalist();
    }

    let cell = document.createElement("td");
    let input = document.createElement("input");
    input.id = "tracker-category-input";
    input.type = "text";
    input.placeholder = "Catégorie (optionnel)";
    input.setAttribute("list", "tracker-category-list");
    input.style.padding = "6px 10px";
    input.style.border = "1px solid #d7dad5";
    input.style.borderRadius = "4px";
    input.style.marginRight = "6px";
    input.style.fontSize = "13px";
    input.style.width = "150px";

    cell.appendChild(input);
    row.insertBefore(cell, row.cells[1]);
}

function refreshCategoryDatalist() {
    const datalist = document.getElementById("tracker-category-list");
    if (!datalist) return;
    chrome.storage.local.get([RECENT_CATEGORIES_KEY], (result) => {
        const categories = result[RECENT_CATEGORIES_KEY] || [];
        datalist.innerHTML = "";
        categories.forEach((cat) => {
            const option = document.createElement("option");
            option.value = cat;
            datalist.appendChild(option);
        });
    });
}

function getCurrentCategory() {
    const input = document.getElementById("tracker-category-input");
    return input ? input.value.trim() : "";
}

/** Mémorise la catégorie utilisée pour l'autocomplete des prochains envois. */
function rememberCategory(category) {
    if (!category) return;
    chrome.storage.local.get([RECENT_CATEGORIES_KEY], (result) => {
        let categories = result[RECENT_CATEGORIES_KEY] || [];
        categories = categories.filter((c) => c.toLowerCase() !== category.toLowerCase());
        categories.unshift(category);
        categories = categories.slice(0, MAX_RECENT_CATEGORIES);
        chrome.storage.local.set({ [RECENT_CATEGORIES_KEY]: categories }, refreshCategoryDatalist);
    });
}

function styleButton(btn, bg) {
    btn.style.background = bg;
    btn.style.color = "white";
    btn.style.border = "none";
    btn.style.padding = "8px 12px";
    btn.style.cursor = "pointer";
    btn.style.borderRadius = "4px";
    btn.style.marginRight = "6px";
}

/** Ajoute pixel d'ouverture + réécrit les liens (comportement légitime, inchangé sur le fond) */
function sendWithTracking() {
    const emailBody = document.querySelector(".Am.Al.editable.LW-avf");
    if (!emailBody) {
        alert("Le corps de l'email n'a pas été trouvé.");
        return;
    }

    const subjectInput = document.querySelector(".aoT");
    const subject = subjectInput ? subjectInput.value : "";
    const recipient = getRecipient();
    const category = getCurrentCategory();
    const links = Array.from(emailBody.querySelectorAll("a")).map((a) => a.href);

    safeSendMessage(
        { action: "registerEmail", payload: { subject, recipient, category, links } },
        (response) => {
            if (!response || response.status !== "success") {
                alert("Erreur lors de l'enregistrement du tracking : " + (response?.error || response?.message || "inconnue"));
                return;
            }

            // Pixel d'ouverture
            const pixel = document.createElement("img");
            pixel.src = response.pixel_url;
            pixel.width = 1;
            pixel.height = 1;
            pixel.alt = "";
            emailBody.appendChild(pixel);

            // Remplace chaque lien par sa version signée par le serveur (si dispo)
            emailBody.querySelectorAll("a").forEach((link) => {
                const signed = response.signed_links?.[link.href];
                if (signed) link.href = signed;
            });

            rememberCategory(category);

            alert("Tracking ajouté. Envoi en cours...");
            const sendButton = document.querySelector(SEND_BUTTON_SELECTOR);
            if (sendButton) sendButton.click();
        }
    );
}

function getRecipient() {
    const el = document.evaluate(
        '//*[@class="oL aDm az9"]',
        document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null
    ).singleNodeValue;
    return el ? el.textContent.trim() : "";
}

/**
 * Mode "document tracké" : action 100% explicite de l'utilisateur.
 * Il choisit un fichier réel via un <input type="file">, on l'uploade sur le serveur,
 * et on colle un lien clairement identifié comme "document suivi" dans le corps du mail.
 * Rien n'est fait sur un fichier déjà joint par l'utilisateur, aucune substitution.
 */
function attachTrackedDocument() {
    const emailBody = document.querySelector(".Am.Al.editable.LW-avf");
    if (!emailBody) {
        alert("Le corps de l'email n'a pas été trouvé.");
        return;
    }

    const input = document.createElement("input");
    input.type = "file";
    input.accept = ".pdf,.png,.jpg,.jpeg,.docx,.xlsx";
    input.onchange = () => {
        const file = input.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = () => {
            const base64 = reader.result.split(",")[1];
            const subjectInput = document.querySelector(".aoT");
            const subject = subjectInput ? subjectInput.value : "";
            const recipient = getRecipient();
            const category = getCurrentCategory();

            safeSendMessage(
                {
                    action: "uploadDocument",
                    fileBase64: base64,
                    fileName: file.name,
                    mimeType: file.type,
                    subject,
                    recipient,
                    category,
                },
                (response) => {
                    if (!response || response.status !== "success") {
                        alert("Erreur upload : " + (response?.error || response?.message || "inconnue"));
                        return;
                    }
                    rememberCategory(category);
                    insertTrackedDocumentLink(emailBody, file.name, response.viewer_url);
                }
            );
        };
        reader.readAsDataURL(file);
    };
    input.click();
}

function insertTrackedDocumentLink(emailBody, fileName, viewerUrl) {
    const link = document.createElement("a");
    link.href = viewerUrl;
    link.target = "_blank";
    link.style.display = "inline-block";
    link.style.margin = "8px 0";
    link.style.padding = "8px 12px";
    link.style.border = "1px solid #d7dad5";
    link.style.borderRadius = "6px";
    link.style.textDecoration = "none";
    link.style.color = "#132a3a";
    // Le libellé indique clairement au destinataire ET à l'expéditeur qu'il s'agit
    // d'un document suivi — pas d'une pièce jointe native déguisée.
    link.innerText = `📄 Voir ${fileName} (document suivi)`;

    const wrapper = document.createElement("div");
    wrapper.appendChild(link);

    const lastElement = emailBody.lastElementChild;
    if (lastElement && lastElement.tagName.toLowerCase() === "br") {
        emailBody.insertBefore(wrapper, lastElement);
    } else {
        emailBody.appendChild(wrapper);
    }

    alert(`Lien de document tracké inséré pour "${fileName}". Vérifie le corps de l'email avant envoi.`);
}
