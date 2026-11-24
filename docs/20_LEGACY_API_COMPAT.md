# Legacy API compatibility (ADR-021)

**Status:** implemented and tested locally; not deployed. Backed by the current V2 database. Read-only.

## The real consumer contract (evidence, 2026-11-24)

Source: the public page `https://medicalcard.euro-assist.com/providers.php` (HTML fetched read-only) and the frozen V1 route file `providers/routes/api.php`.

| Consumer call | Purpose | Fields read |
|---|---|---|
| `GET /api/arabic-providers?fields=city` | "governorate" dropdown | `item.city` |
| `GET /api/arabic-providers?fields=type` | provider-type dropdown | `item.type` |
| `GET /api/arabic-providers?type=&city=&search=&governorate=&district=` | results | `id, name, type, address, city, district, phone, package, map_url, discount_pct, logo_path or logo_url` |

Notes: the page groups rows by name into branches client-side, caches responses 24 h in `localStorage`, and fills the "governorate" dropdown from **`city`** (V1 stored governorate names, and a few city names, in `city`). V1 itself had **no** `fields` parameter: it ignored it and returned every full row. The dropdown projection is therefore a compatible optimisation (the page only reads one key). No mobile-app source or traffic was available; mobile compatibility is limited to the V1 routes/shape below (**unverified against a real app**).

## Endpoints (all `GET`, public, no credentials, base `/api`)

| Path | V1 behaviour kept |
|---|---|
| `providers`, `arabic-providers` | raw array; filters `type, city, district` (LIKE substring, wildcards literal), `search` (name/address/phone), `searchName` (exact, whitespace-normalised); `page`, `per_page`, `paginate=true` switch to `{data,meta,links}`; `fields=city|district|type` projection (new, additive) |
| `…/{id}` | single row; id space = the id the old system issued (`legacy_id_maps`) |
| `…/search?query=` (or `q`) | required (422 `{message:"Validation error.",errors:{query:[…]}}`), broad search, always paginated |
| `…/distinct/names` | `{names:[…]}` |
| `…/all-latlng` | `{data:[{name,type,latitude,longitude}]}` |
| `providers/distribution/stats` | `{distribution:[{type,display_name,english_count,arabic_count,max_count,color,icon}],total_types,total_providers}` (English path only, as V1) |
| `top-providers` | `{success:true,data:[{id,name_english,name_arabic,type_en,type_ar,logo_url,created_at,updated_at}]}` |
| `legacy-media/providers/{id}/photo`, `legacy-media/type-icons/{typeId}`, `legacy-media/featured/{id}/logo` | public images referenced by `logo_path` / `logo_url`, served from the private disk by numeric id only, active records only |

Row fields (English adds `is_translated` after `package`): `id, name, type, address, city, district, discount_pct, hours, phone, email, website, map_url, logo_path, logo_url, specialization, package, [is_translated,] created_at, updated_at, latitude, longitude`.

## Deliberate differences from V1

* `providers` = English translation rows, `arabic-providers` = Arabic rows of the **canonical** V2 provider (after the verified merges a branch with both languages appears once in each). Only active, non-deleted providers appear.
* `id`: legacy id when mapped; `1 000 000 + V2 id` for providers created in V2 (never collides with an old id).
* The `governorate` parameter is **ignored**. V1 queried a column that never existed (it would have failed). No governorate is derived from city/district: that would be a speculative geographic classification.
* Not restored: `POST /api/login`, `/api/logout` (the V1 JWT integration was inconsistent/broken) and the declared-but-unimplemented store/update/destroy routes. No write verb is registered; `/api/v2` remains token-protected.
* `email` is **not published** (owner decision 2026-11-25): the key stays in every row so the schema is unchanged, but its value is always `null`, and e-mail is excluded from the search columns. `LEGACY_API_EXPOSE_EMAIL=true` re-enables it, only with written approval.
* Unsafe `website`/`map_url` values (`javascript:`, `data:`) are never returned. No user id, revision, batch id, storage path or inactive record is exposed. `per_page` is capped at 1000 (V1 unbounded). Errors use the JSON envelope without internals.
* Rate limit 120 requests/minute per IP (`LEGACY_API_RATE_LIMIT`); kill switch `LEGACY_API_ENABLED=false` (404 everywhere); responses `Cache-Control: public, max-age=60`; CORS `*` for `/api/*` (the website calls it cross-origin).

## Finding about production (read-only probes, 2026-11-24)

`providers.euro-assist.com/api/arabic-providers?fields=city` **already answered** with a trimmed projection, `/api/top-providers` answered with `legacy-media` logo URLs, while `/api/providers/distribution/stats`, `/api/providers/1`, `/api/arabic-providers/distinct/names`, `/api/providers/all-latlng` and `/up` answered 404, `/login` answered 200 with an empty body and `favicon.ico` had length 0. None of those `legacy-media` routes or that projection exist in rc.5 or in this repository's history. **The code running in production is therefore not the delivered rc.5 package** (someone added or replaced routes). This update replaces the application files with a verifiable release; `ops:check` compares the running files to the release manifest.

## Tests

`tests/Feature/Api/LegacyApiCompatTest.php` (14 tests): dropdown round-trip AR/EN, row shape and order, filters, pagination envelope, 422 body, ids through `legacy_id_maps` and the offset, statistics, top providers, write verbs/login absent, unsafe URLs, CORS, kill switch, rate limit, `/api/v2` still 401. Postman: `docs/postman/` (73 requests; regenerate with `python scripts/postman/build_postman.py`).
