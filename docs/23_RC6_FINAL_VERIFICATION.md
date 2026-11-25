# rc.6 final verification (2026-11-24)

Environment used (temporary, deleted afterwards, nothing permanent created): the **exact final update ZIP applied by `apply-update.ps1` to a fresh extraction of the delivered rc.5 package**, served by PHP's built-in server on 127.0.0.1:8001, reading the **existing** rc.5 dataset database (`euro_assist_v2_release_rc3`: 2,192 providers / 1,402 live, 3 featured, 5 photos, 30 type icons; it is the data the rc.5 SQL was built from) with the rc.5 package's own media files. Session/cache/queue were array/sync, so the database received no writes. No database was replaced.

## Gate 1 — every request the MedicalCard website makes (source: `providers.php`, `top-providers.php`, `index.php`)

| Website call | Result on rc.6 |
|---|---|
| `GET /api/arabic-providers?fields=type` / `?fields=city` | 200, 30 types / 26 cities, `[{"type":…}]`/`[{"city":…}]`, unique, trimmed |
| `GET /api/providers?fields=type` / `?fields=city` | 200, 8 types / 31 cities |
| Filters `type`, `city`, `search`, combinations (AR and EN) | every dropdown option returns rows (30 + 26 AR, 8 + 31 EN); combinations behave |
| `GET /api/arabic-providers?searchName=<name>` / `providers?searchName=` (featured detail) | AR: Al Borg 291, Al Mokhtabar 406, Royal Lab 81 branches; EN: 292 / 409 / 81 |
| `GET /api/top-providers` | 200, `success:true`, 3 featured with `name_arabic/english`, `type_ar/en`, `logo_url` |
| Fields read by the page (`id,name,type,address,city,district,phone,package,map_url,discount_pct,logo_path,logo_url`) | present on all 1,104 AR / 1,088 EN rows; `email` always null |
| CORS | `Access-Control-Allow-Origin: *` on every API response (page is on another origin) |
| Images | see gate 2 |

`index.php` defines `?fields=type` endpoints but never calls them. 38 scripted checks: 38 passed, 0 failed.

## Gate 2 — images (rc.5 dataset and media)

* Root cause of the earlier `logo_url: null`: the **UAT** database was an older baseline without media references; the serializer was correct. With the rc.5 dataset: **all 38 referenced files exist** (3 featured logos, 5 photos, 30 type icons).
* Featured logos: `/api/legacy-media/featured/{1,2,3}/logo` → **200**, `image/png` 22,892 B, `image/jpeg` 8,626 B, `image/png` 7,738 B.
* Provider images: Arabic list = 33 distinct URLs for 1,104 rows, English = 10 for 1,088 rows (photo where one exists, otherwise the type icon): **every URL 200 + `image/*`, 0 broken, 0 rows without an image.**
* Nothing was invented or copied from elsewhere.

## Gate 3 — why English shows 8 types and Arabic 30 (and 7 on the UAT copy)

It is the **real data, not a defect.** Frozen legacy source (read-only): the English table has 11 spellings that map to **8** real types; the Arabic table has **30** distinct types: the same 8 plus **22 wellness/beauty/lifestyle categories that exist only as one Arabic row each** (e.g. home healthcare, dental, fertility, spa, skincare…), with no English counterpart. V2 keeps them as Arabic-only providers (`english_count = 0`, `arabic_count = 1`). The UAT copy showed 7 because its older baseline pre-dates the "Eye Center → Optics" mapping, so Optics had no English row there; rc.5 data gives 8. If those 22 categories should appear in English, the owner must supply English translations (not invented by us).

## Gate 4 — Postman collection executed (Newman, real run)

`docs/postman/EuroAssist_Legacy_API_Compat.postman_collection.json` + `EuroAssist_Local.postman_environment.json` (`baseUrl` overridden to the temporary server): **73 requests, 285 assertions, 0 failed** (plus 2 helper pre-request calls). The first runs found 15 defects **in the collection** (negated chai chain, an unrealistic type/city pair, wrong variable scope, JSON `Accept` on browser routes, a false positive on the word "password" in a 404 page); they were fixed and the whole collection re-run.

## Gate 5 — approved changes (automated tests, 613 PHPUnit tests, SQLite) + live smoke

Account creation with password+confirmation, admin reset (sessions/tokens revoked, flag), self-service change (other devices and API tokens ended), no forgot/invitation routes (404, also live), three roles only, legacy `super_admin` read as Administrator and normalized by an idempotent migration, last-administrator protection, favicon (valid ICO 5,110 B on every page, live 200), Advanced tools page with Migration Review out of the menu and route gating unchanged (not registered in production), export queue: a real `queue:work` completes an export, scheduler lock expires, stuck exports are flagged.

## Gate 6 — exact ZIP vs delivered rc.5

Fresh extraction of the delivered rc.5 (inner ZIP SHA-256 `87ed2307…2c19`) → `preflight.php` before: **247 files identical, 0 modified, 0 missing, 0 extra** → `apply-update.ps1` (48 files copied, 3 deleted, backup made) → `ops:check`: **"258 files identical to the release"**, migration page not registered in the intended environment, recovery routes absent; preflight afterwards lists the update as 26 modified / 3 missing / 14 extra relative to rc.5, as expected. The ZIP contains no `.sql` file.

## Gate 8 — GitHub checks

See the final report (checked on the pushed head).
