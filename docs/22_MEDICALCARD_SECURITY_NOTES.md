# MedicalCard.euro-assist.com archive — static security notes (2026-11-24)

**Scope:** `MedicalCard.euro-assist.com.zip` (232 entries, 26 MB) was inspected **statically** (read as text; nothing was executed, no network call was made to any address found in it). The website was **not modified** and is **not part of the rc.6 update**. This document is separate from the application release.

## 1. CRITICAL — injected backdoor / remote-controlled content in `index.php` (line 1)

`index.php` starts with a 2,123-byte single line of obfuscated PHP before the real page code (SHA-256 of the file `4fc224a2ebaed583fb6a41cc0c5e731134a6c550f4a021934f06cecc7baa84c7`). It is **not part of the site's own code** (every other PHP file starts with a plain `<?php` line). What it does, decoded by reading it:

* Builds a URL from a ROT13 string: `uggc://jjj.wnxxryyl.pbz/kynetr.cuc` = **`http://www.jakkelly.com/xlarge.php`**, appends `?merdo=<this site's host name>` and downloads it on **every page view** (cURL with `CURLOPT_SSL_VERIFYPEER=false`, user agent `RaBot`, 10 s timeout; `file_get_contents` fallback).
* Treats the answer as ROT13-encoded JSON and **echoes remote-supplied HTML into the home page** (`type` = `link`/`anti`, `{LINK_CODE_REPLACE}` replaced by remote `<a href>` links), i.e. the remote server can place arbitrary HTML/JavaScript and spam links on the page for all visitors.
* `type = redirect` + a `Googlebot`-style User-Agent match → `301` redirect to a remote-chosen URL (search-engine **cloaking**).
* Hidden probe: requesting `/?merdo=1` prints `<!--SYSADMIN-->` and exits, which lets the operator find infected sites.
* Obfuscated random variable names, no relation to the site: a classic SEO-spam / link-injection implant.

**Evidence it is a compromise of the live copy, not the project source:** the two backup archives inside the package (`medcialCard.com.zip`, `MedicalCard13-8-2026.zip`) contain `index.php` files **without** this code (no `merdo`, `str_rot13`, `RaBot`, `eval`, `base64_decode` in any of their PHP/HTML/JS). `providers.php`, `top-providers.php`, `home-care.php`, `services.php`, `send_mail.php` and the includes are clean of these patterns.

**Recommended actions for IT (not done by us):** treat the hosting subscription as compromised. (1) Replace `index.php` with a reviewed clean copy (diff against the dated backup first). (2) Search **every** site on the subscription for `merdo`, `str_rot13`, `RaBot`, `jakkelly` and unknown files/recent modifications. (3) Check web/FTP/Plesk logs for requests containing `?merdo` and for the time the file changed. (4) Rotate FTP/SSH, Plesk, database and **SMTP** credentials (see 2). (5) Block outbound connections to the domain above from the server. (6) Review Plesk users, scheduled tasks and installed extensions. Do **not** browse to or fetch that domain from a normal workstation.

Impact on the Providers system: none found — the implant calls out to a third-party server, not to `providers.euro-assist.com`. It is, however, a reason to suspect the shared hosting account that also hosts the Providers site: the unexplained production differences reported for the Providers application (extra routes, role value, empty login response) should be examined with that possibility in mind.

## 2. HIGH — `send_mail.php`: hard-coded SMTP credentials and an open mail endpoint

* A working SMTP user name and password are written in the source (not reproduced here). Anyone who can read the file or either backup ZIP can use the mailbox. **Rotate it** and keep it outside the web root.
* The endpoint sends `Access-Control-Allow-Origin: *`, accepts POST from any origin and the code shows only an e-mail format check: no CAPTCHA, token or rate limit was found, so it can be abused as a spam relay.

## 3. MEDIUM — backups and server details inside the web root

`medcialCard.com.zip`, `MedicalCard13-8-2026.zip`, `images/Euro Assist Medical Network.zip` and `assets/presentation.pdf` sit in the site folder; if the folder is the web root, the ZIPs (which contain `send_mail.php` with the credentials) are downloadable. `.user.ini` discloses server paths. Remove the archives from the web root.

## 4. MEDIUM — unescaped rendering of API data (`providers.php`, `top-providers.php`)

Branch/provider names and URLs are inserted with `innerHTML` templates (`${item.name}`, `${provider.logo_url}`) and into inline `onclick="showAllBranches('${providerName}', …)"` without escaping. A provider name containing an apostrophe or markup breaks the card and could inject script. The API output is controlled by the Providers admins and the V2 API already filters unsafe `website`/`map_url`, but the page should escape everything it renders. (V1 had the same pattern, finding L-10.)

## 5. INFORMATION — client-side cache hides the API fix for up to 24 hours

`providers.php` stores every API answer (including an empty one) in `localStorage` for 24 hours under `medical_network_cache_*`. Visitors who loaded the page while the dropdowns were empty may keep seeing them until the cache expires or they hard-refresh (the page clears it on Ctrl+F5). Tell the website owner; no change is needed in the API.

## 6. Other observations

* PHPMailer 6.10.0 (no known issue noted here). `home-care.php`/`services.php` make no API calls. `index.php` defines the `?fields=type` endpoints in its config but never calls them (the home cards are static).
* Third-party assets come from `cdn.jsdelivr.net`, `cdnjs.cloudflare.com`, Google Fonts, `upload.wikimedia.org`; no unexpected domains other than the one in section 1.
