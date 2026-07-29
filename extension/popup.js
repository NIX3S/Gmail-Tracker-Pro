document.addEventListener("DOMContentLoaded", () => {
    chrome.runtime.sendMessage({ action: "getTrackedEmails" }, (response) => {
        const emailList = document.getElementById("email-list");
        emailList.innerHTML = "";
        const entries = Object.entries(response || {});
        if (entries.length === 0) {
            emailList.innerHTML = "<li>Aucun email récent en cache local.</li>";
            return;
        }
        for (const [id, info] of entries) {
            const li = document.createElement("li");
            li.innerText = `${id.slice(0, 8)}… — ${info.status || "envoyé"}`;
            emailList.appendChild(li);
        }
    });

    document.getElementById("open-dashboard").addEventListener("click", () => {
        chrome.storage.sync.get(["apiBaseUrl"], (result) => {
            const base = (result.apiBaseUrl || "https://statistics.ct.ws").replace(/\/+$/, "");
            chrome.tabs.create({ url: `${base}/dashboard/login.php` });
        });
    });
});
