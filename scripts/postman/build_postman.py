"""Generates the Postman collection + local environment for the legacy API compatibility tests.

Run:  python scripts/postman/build_postman.py   (writes docs/postman/*.json)

The requests are taken from the REAL consumer contract (https://medicalcard.euro-assist.com/providers.php):
  GET /api/arabic-providers?fields=city      governorate dropdown (reads item.city)
  GET /api/arabic-providers?fields=type      provider-type dropdown (reads item.type)
  GET /api/arabic-providers?type=&city=&search=   results (reads id,name,type,address,city,district,
                                                  phone,package,map_url,discount_pct,logo_path|logo_url)
plus the routes declared by the V1 source (routes/api.php). No credentials or tokens are included.
"""
import json
import pathlib
import uuid

ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / "docs" / "postman"

ROW_AR = ["id", "name", "type", "address", "city", "district", "discount_pct", "hours", "phone", "email", "website",
          "map_url", "logo_path", "logo_url", "specialization", "package", "created_at", "updated_at", "latitude", "longitude"]
ROW_EN = ROW_AR[:ROW_AR.index("package") + 1] + ["is_translated"] + ROW_AR[ROW_AR.index("package") + 1:]
FORBIDDEN = ["created_by", "updated_by", "revision", "migration_batch", "password", "remember_token", "photo_path", "merged_into", "storage/app"]

HELP = """
const json = () => { try { return pm.response.json(); } catch (e) { return undefined; } };
const hasKeys = (o, keys) => keys.every(k => Object.prototype.hasOwnProperty.call(o, k));
const sameKeys = (o, keys) => JSON.stringify(Object.keys(o)) === JSON.stringify(keys);
const forbidden = %s;
pm.test('No private/administrative field is exposed', () => {
  const body = pm.response.text();
  forbidden.forEach(f => pm.expect(body).to.not.include(f));
});
""" % json.dumps(FORBIDDEN)


def req(name, path, tests, *, method="GET", query=None, headers=None, follow=True, desc=""):
    query = query or []
    url = "{{baseUrl}}/" + path.lstrip("/")
    item = {
        "name": name,
        "event": [{"listen": "test", "script": {"type": "text/javascript", "exec": (HELP + tests).split("\n")}}],
        "request": {
            "method": method,
            "header": [{"key": "Accept", "value": "application/json"}] + (headers or []),
            "url": {
                "raw": url + ("?" + "&".join(f"{k}={v}" for k, v in query) if query else ""),
                "host": ["{{baseUrl}}"],
                "path": [p for p in path.strip("/").split("/") if p],
                "query": [{"key": k, "value": v} for k, v in query],
            },
            "description": desc,
        },
    }
    if not follow:
        item["request"]["protocolProfileBehavior"] = {"followRedirects": False}
        item["protocolProfileBehavior"] = {"followRedirects": False}
    return item


def folder(name, items, desc=""):
    return {"name": name, "description": desc, "item": items}


def status(code):
    return f"pm.test('Status is {code}', () => pm.response.to.have.status({code}));\n"


def headers_ok():
    return ("pm.test('JSON content type', () => pm.expect(pm.response.headers.get('Content-Type')).to.include('application/json'));\n"
            "pm.test('CORS: readable cross-origin by the public website', () => pm.expect(pm.response.headers.get('Access-Control-Allow-Origin')).to.eql('*'));\n")


def list_tests(row_keys, lang):
    return status(200) + headers_ok() + f"""
const rows = json();
pm.test('Raw array (V1 shape: no envelope unless paginated)', () => pm.expect(rows).to.be.an('array'));
pm.test('Rows are never empty for the full list', () => pm.expect(rows.length).to.be.above(0));
pm.test('Every row has exactly the V1 field set and order', () => rows.slice(0, 50).forEach(r => pm.expect(sameKeys(r, {json.dumps(row_keys)})).to.be.true));
pm.test('id is a positive integer, name is a non-empty string', () => rows.slice(0, 50).forEach(r => {{ pm.expect(Number.isInteger(r.id) && r.id > 0).to.be.true; pm.expect(r.name).to.be.a('string').and.not.empty; }}));
pm.test('Coordinates are numbers or null', () => rows.forEach(r => {{ pm.expect(r.latitude === null || typeof r.latitude === 'number').to.be.true; pm.expect(r.longitude === null || typeof r.longitude === 'number').to.be.true; }}));
pm.test('Website/map links are http(s) or null', () => rows.forEach(r => ['website', 'map_url'].forEach(k => pm.expect(r[k] === null || /^https?:\\/\\//i.test(r[k]) || /^[^:\\s\\/]+\\.[^:\\s\\/]+/.test(r[k])).to.be.true)));
""" + (f"""pm.test('Arabic rows contain Arabic text', () => pm.expect(rows.some(r => /[\\u0600-\\u06FF]/.test(r.name))).to.be.true);
""" if lang == "ar" else """pm.test('English rows contain Latin text', () => pm.expect(rows.some(r => /[A-Za-z]/.test(r.name))).to.be.true);
pm.test('is_translated is 0 or 1', () => rows.forEach(r => pm.expect([0, 1]).to.include(r.is_translated)));
""")


def dropdown_tests(field, lang):
    return status(200) + headers_ok() + f"""
const rows = json();
pm.test('Array of single-field objects, exactly what the page reads (item.{field})', () => {{
  pm.expect(rows).to.be.an('array').and.not.empty;
  rows.forEach(r => pm.expect(Object.keys(r)).to.eql(['{field}']));
}});
pm.test('Values are non-empty, trimmed and unique', () => {{
  const v = rows.map(r => r.{field});
  v.forEach(x => pm.expect(x).to.be.a('string').and.not.empty.and.equal(x.trim()));
  pm.expect(new Set(v).size).to.eql(v.length);
}});
""" + (f"""pm.test('Arabic labels', () => pm.expect(rows.some(r => /[\\u0600-\\u06FF]/.test(r.{field}))).to.be.true);
""" if lang == "ar" else "") + f"""
pm.collectionVariables.set('{ 'arabic' if lang == 'ar' else 'english' }{field.capitalize()}', rows[{0 if field == 'type' else 1 if lang == 'ar' else 0}].{field});
"""


def page_tests(row_keys):
    return status(200) + headers_ok() + f"""
const b = json();
pm.test('Paginated envelope: data/meta/links', () => pm.expect(Object.keys(b)).to.eql(['data', 'meta', 'links']));
pm.test('meta has the V1 keys', () => pm.expect(Object.keys(b.meta)).to.eql(['current_page', 'last_page', 'per_page', 'total', 'from', 'to']));
pm.test('links has self/next/prev', () => pm.expect(Object.keys(b.links)).to.eql(['self', 'next', 'prev']));
pm.test('per_page honoured', () => pm.expect(b.data.length).to.be.at.most(b.meta.per_page));
pm.test('Rows keep the V1 shape', () => b.data.forEach(r => pm.expect(sameKeys(r, {json.dumps(row_keys)})).to.be.true));
"""


def build():
    c = []

    c.append(folder("1. Website contract: providers.php (Arabic)", [
        req("Governorate dropdown  GET /api/arabic-providers?fields=city", "api/arabic-providers", dropdown_tests("city", "ar"),
            query=[("fields", "city")], desc="The page labels this dropdown 'governorate' but fills it from item.city. No geographic grouping is derived."),
        req("Type dropdown  GET /api/arabic-providers?fields=type", "api/arabic-providers", dropdown_tests("type", "ar"), query=[("fields", "type")]),
        req("Results: type + city (the dropdown values round-trip)", "api/arabic-providers",
            list_tests(ROW_AR, "ar") + """
pm.test('Every row matches the chosen type and city', () => json().forEach(r => { pm.expect(r.type).to.include(pm.collectionVariables.get('arabicType')); pm.expect(r.city).to.include(pm.collectionVariables.get('arabicCity')); }));
""", query=[("type", "{{arabicType}}"), ("city", "{{arabicCity}}")],
            desc="Run the two dropdown requests first (they set arabicType / arabicCity)."),
        req("Results: free-text search (name/address/phone) + type", "api/arabic-providers", list_tests(ROW_AR, "ar"),
            query=[("search", "{{searchTermAr}}")]),
        req("Results: no match returns an empty array (page shows 'no results')", "api/arabic-providers",
            status(200) + "pm.test('Empty array', () => pm.expect(json()).to.eql([]));\n", query=[("search", "zzzz-no-such-provider-zzzz")]),
        req("Branch details: GET /api/arabic-providers/{id}", "api/arabic-providers/{{providerIdAr}}",
            status(200) + """
const r = json();
pm.test('Single object with the branch fields the page reads', () => ['id', 'name', 'type', 'address', 'city', 'district', 'phone', 'package', 'map_url', 'discount_pct', 'logo_path'].forEach(k => pm.expect(r).to.have.property(k)));
pm.test('Same id as requested', () => pm.expect(String(r.id)).to.eql(pm.collectionVariables.get('providerIdAr')));
""", desc="providerIdAr is set by 'List all Arabic providers' in folder 3."),
    ], "Exactly the three calls the public page makes, in the order it makes them."))

    c.append(folder("2. Website contract: English", [
        req("Type dropdown (English)", "api/providers", dropdown_tests("type", "en"), query=[("fields", "type")]),
        req("City dropdown (English)", "api/providers", dropdown_tests("city", "en"), query=[("fields", "city")]),
        req("Results: type + city (English)", "api/providers", list_tests(ROW_EN, "en"),
            query=[("type", "{{englishType}}"), ("city", "{{englishCity}}")]),
    ]))

    c.append(folder("3. V1 routes: lists, search, details", [
        req("List all Arabic providers (also sets providerIdAr)", "api/arabic-providers", list_tests(ROW_AR, "ar") + "\npm.collectionVariables.set('providerIdAr', String(json()[0].id));\n"),
        req("List all English providers (also sets providerIdEn)", "api/providers", list_tests(ROW_EN, "en") + "\npm.collectionVariables.set('providerIdEn', String(json()[0].id));\n"),
        req("Filter: district (LIKE substring)", "api/providers", status(200) + "pm.test('Array', () => pm.expect(json()).to.be.an('array'));\n", query=[("district", "{{englishDistrict}}")]),
        req("searchName: exact name, whitespace-normalised", "api/providers", status(200) + """
pm.test('Only exact-name rows', () => json().forEach(r => pm.expect(r.name.replace(/\\s+/g, ' ').trim()).to.eql(pm.collectionVariables.get('exactNameEn'))));
""", query=[("searchName", "{{exactNameEn}}")]),
        req("Pagination: per_page=5 (Arabic)", "api/arabic-providers", page_tests(ROW_AR) + "pm.test('per_page=5', () => pm.expect(json().meta.per_page).to.eql(5));\n",
            query=[("per_page", "5")]),
        req("Pagination: page=2&per_page=5 (English)", "api/providers", page_tests(ROW_EN) + "pm.test('page 2', () => pm.expect(json().meta.current_page).to.eql(2));\n",
            query=[("page", "2"), ("per_page", "5")]),
        req("Pagination: paginate=true", "api/providers", page_tests(ROW_EN), query=[("paginate", "true")]),
        req("Pagination: oversized per_page is capped, not an error", "api/arabic-providers", status(200) + "pm.test('Capped at 1000', () => pm.expect(json().meta.per_page).to.be.at.most(1000));\n",
            query=[("per_page", "99999999")]),
        req("Search endpoint (English): query", "api/providers/search", page_tests(ROW_EN), query=[("query", "{{searchTermEn}}")]),
        req("Search endpoint (English): q alias + type filter", "api/providers/search", page_tests(ROW_EN), query=[("q", "{{searchTermEn}}")]),
        req("Search endpoint (Arabic)", "api/arabic-providers/search", page_tests(ROW_AR), query=[("query", "{{searchTermAr}}")]),
        req("Details (English)", "api/providers/{{providerIdEn}}", status(200) + "pm.test('Single object', () => pm.expect(json()).to.have.property('name'));\n"),
        req("Distinct names (English)", "api/providers/distinct/names", status(200) + """
pm.test("{names:[...]} unique strings", () => { const n = json().names; pm.expect(Object.keys(json())).to.eql(['names']); pm.expect(new Set(n).size).to.eql(n.length); });
"""),
        req("Distinct names (Arabic)", "api/arabic-providers/distinct/names", status(200) + "pm.test('names array', () => pm.expect(json().names).to.be.an('array').and.not.empty);\n"),
        req("All coordinates (English)", "api/providers/all-latlng", status(200) + """
pm.test('{data:[{name,type,latitude,longitude}]}', () => { pm.expect(Object.keys(json())).to.eql(['data']); json().data.forEach(r => pm.expect(Object.keys(r)).to.eql(['name', 'type', 'latitude', 'longitude'])); });
"""),
        req("All coordinates (Arabic)", "api/arabic-providers/all-latlng", status(200) + "pm.test('data array', () => pm.expect(json().data).to.be.an('array'));\n"),
        req("Distribution statistics (English path only, as in V1)", "api/providers/distribution/stats", status(200) + """
const d = json();
pm.test('Shape: distribution,total_types,total_providers', () => pm.expect(Object.keys(d)).to.eql(['distribution', 'total_types', 'total_providers']));
pm.test('Row shape', () => d.distribution.forEach(r => pm.expect(Object.keys(r)).to.eql(['type', 'display_name', 'english_count', 'arabic_count', 'max_count', 'color', 'icon'])));
pm.test('max_count = max(english, arabic); totals add up', () => { d.distribution.forEach(r => pm.expect(r.max_count).to.eql(Math.max(r.english_count, r.arabic_count))); pm.expect(d.total_types).to.eql(d.distribution.length); pm.expect(d.total_providers).to.eql(d.distribution.reduce((s, r) => s + r.max_count, 0)); });
pm.test('Sorted by max_count desc', () => d.distribution.forEach((r, i) => { if (i) pm.expect(d.distribution[i - 1].max_count).to.be.at.least(r.max_count); }));
"""),
        req("Featured / top providers", "api/top-providers", status(200) + headers_ok() + """
const b = json();
pm.test('{success:true,data:[...]}', () => { pm.expect(b.success).to.eql(true); pm.expect(Object.keys(b)).to.eql(['success', 'data']); });
pm.test('Row shape', () => b.data.forEach(r => pm.expect(Object.keys(r)).to.eql(['id', 'name_english', 'name_arabic', 'type_en', 'type_ar', 'logo_url', 'created_at', 'updated_at'])));
pm.test('Bilingual names', () => pm.expect(b.data.some(r => r.name_english && r.name_arabic)).to.be.true);
pm.test('logo_url is an absolute API URL or null (never a storage path)', () => b.data.forEach(r => pm.expect(r.logo_url === null || /^https?:\\/\\/.+\\/api\\/legacy-media\\/featured\\/\\d+\\/logo$/.test(r.logo_url)).to.be.true));
"""),
    ]))

    c.append(folder("4. Error handling", [
        req("Search without query -> 422 with the V1 body", "api/providers/search", status(422) + """
pm.test('Exact V1 validation body', () => pm.expect(json()).to.eql({ message: 'Validation error.', errors: { query: ['The query parameter is required.'] } }));
"""),
        req("Unknown provider id -> 404", "api/providers/999999", status(404) + "pm.test('JSON error, no stack trace', () => { pm.expect(json()).to.have.property('error'); pm.expect(pm.response.text()).to.not.match(/Stack trace|vendor\\/laravel|\\.php:/i); });\n"),
        req("Arabic id is not an English id space (Arabic id on English route)", "api/providers/999998", status(404)),
        req("Unknown path under /api -> 404 JSON", "api/not-a-route", status(404)),
        req("Unknown fields value is ignored (V1 ignored fields): full rows", "api/arabic-providers", status(200) + "pm.test('Full rows returned', () => pm.expect(json()[0]).to.have.property('name'));\n", query=[("fields", "password")]),
        req("governorate parameter is ignored (never mapped onto another field)", "api/arabic-providers", status(200) + "pm.test('Array', () => pm.expect(json()).to.be.an('array').and.not.empty);\n", query=[("governorate", "anything")]),
        req("Wildcard characters are literal (search=%)", "api/providers", status(200) + "pm.test('No rows (a literal percent sign matches nothing)', () => pm.expect(json()).to.eql([]));\n", query=[("search", "%25")]),
        req("Malformed input never causes a 500 (array parameters)", "api/providers", "pm.test('Not a server error', () => pm.expect(pm.response.code).to.be.below(500));\n",
            query=[("search[]", "x"), ("type[a]", "y"), ("per_page", "abc")]),
    ]))

    wr = []
    for verb in ("POST", "PUT", "PATCH", "DELETE"):
        for path in ("api/providers", "api/providers/1", "api/arabic-providers", "api/arabic-providers/1", "api/top-providers"):
            wr.append(req(f"{verb} /{path} is not served", path,
                          "pm.test('Not 2xx: no write verb exists (404/405)', () => pm.expect([404, 405]).to.include(pm.response.code));\n", method=verb))
    c.append(folder("5. Authorization & safety", [
        folder("5a. Writes and authentication are NOT restored", wr + [
            req("POST /api/login is not served (V1 JWT login was broken)", "api/login", "pm.test('404/405', () => pm.expect([404, 405]).to.include(pm.response.code));\n", method="POST"),
            req("POST /api/logout is not served", "api/logout", "pm.test('404/405', () => pm.expect([404, 405]).to.include(pm.response.code));\n", method="POST"),
        ]),
        folder("5b. /api/v2 stays private", [
            req("v2 providers without token -> 401", "api/v2/providers", status(401) + """
pm.test('Error envelope', () => pm.expect(json().error.code).to.eql('unauthenticated'));
"""),
            req("v2 featured without token -> 401", "api/v2/featured-providers", status(401)),
            req("v2 with a bad token -> 401", "api/v2/providers", status(401), headers=[{"key": "Authorization", "value": "Bearer 1|not-a-real-token"}]),
            req("v2 with YOUR token (optional; skipped when v2Token is empty)", "api/v2/providers",
                "if (!pm.environment.get('v2Token')) { pm.test.skip('no v2Token set'); } else { pm.test('200 with a valid read token', () => pm.response.to.have.status(200)); }\n",
                headers=[{"key": "Authorization", "value": "Bearer {{v2Token}}"}],
                desc="Create a LOCAL token with: php artisan api:token <email>. Never paste a production token."),
        ]),
        folder("5c. Administration is not reachable anonymously", [
            req("Dashboard redirects to login", "dashboard", status(302) + "pm.test('Location is /login', () => pm.expect(pm.response.headers.get('Location')).to.match(/\\/login$/));\n", follow=False),
            req("Users page redirects to login", "users", status(302), follow=False),
            req("Migration review is not public", "migration/review", "pm.test('302 to login or 404, never 200', () => pm.expect([302, 404]).to.include(pm.response.code));\n", follow=False),
            req("Forgot-password route no longer exists (ADR-020)", "forgot-password", status(404)),
            req("Reset-password route no longer exists", "reset-password/abc", status(404)),
            req("Login page has no forgot-password link", "login", status(200) + "pm.test('No recovery link', () => pm.expect(pm.response.text().toLowerCase()).to.not.include('forgot'));\n",
                headers=[{"key": "Accept", "value": "text/html"}]),
            req("Favicon is the brand icon (not empty)", "favicon.ico", status(200) + "pm.test('Non-empty icon', () => pm.expect(pm.response.stream.length).to.be.above(1000));\n"),
        ]),
        folder("5d. Response hygiene", [
            req("No private fields in the full English list", "api/providers", status(200)),
            req("Rate-limit headers present", "api/arabic-providers", status(200) + "pm.test('X-RateLimit-Limit', () => pm.expect(pm.response.headers.get('X-RateLimit-Limit')).to.not.be.null);\n", query=[("fields", "type")]),
        ]),
    ]))

    c.append(folder("6. Images", [
        req("Type icon (200 image or clean 404 when no icon is stored)", "api/legacy-media/type-icons/1",
            "pm.test('Image or 404 JSON', () => { if (pm.response.code === 200) { pm.expect(pm.response.headers.get('Content-Type')).to.match(/^image\\//); pm.expect(pm.response.headers.get('X-Content-Type-Options')).to.eql('nosniff'); } else { pm.expect(pm.response.code).to.eql(404); } });\n"),
        req("Unknown media id -> 404", "api/legacy-media/providers/99999999/photo", status(404)),
        req("Non-numeric media id is not routed", "api/legacy-media/type-icons/..%2F..%2F.env", "pm.test('404', () => pm.expect(pm.response.code).to.eql(404));\n"),
    ]))

    collection = {
        "info": {
            "_postman_id": str(uuid.uuid5(uuid.NAMESPACE_URL, "euro-assist-legacy-api-compat")),
            "name": "Euro Assist Providers - Legacy API compatibility (V2)",
            "description": "Contract tests for the public, read-only V1-compatible API. Based on the real consumer "
                           "(medicalcard.euro-assist.com/providers.php) and the V1 route file. Run folder 1 first, then the "
                           "Collection Runner for everything. No credentials are stored here.",
            "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        },
        "item": c,
        "variable": [
            {"key": "arabicType", "value": ""}, {"key": "arabicCity", "value": ""},
            {"key": "englishType", "value": ""}, {"key": "englishCity", "value": ""},
            {"key": "providerIdAr", "value": "1"}, {"key": "providerIdEn", "value": "1"},
        ],
    }
    env = {
        "id": str(uuid.uuid5(uuid.NAMESPACE_URL, "euro-assist-local-env")),
        "name": "Euro Assist - Local (127.0.0.1:8000)",
        "values": [
            {"key": "baseUrl", "value": "http://127.0.0.1:8000", "type": "default", "enabled": True},
            {"key": "searchTermAr", "value": "مستشفى", "type": "default", "enabled": True},
            {"key": "searchTermEn", "value": "hospital", "type": "default", "enabled": True},
            {"key": "englishDistrict", "value": "Nasr", "type": "default", "enabled": True},
            {"key": "exactNameEn", "value": "Al Borg", "type": "default", "enabled": True},
            {"key": "v2Token", "value": "", "type": "secret", "enabled": True},
        ],
        "_postman_variable_scope": "environment",
    }
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "EuroAssist_Legacy_API_Compat.postman_collection.json").write_text(json.dumps(collection, ensure_ascii=False, indent=2) + "\n", encoding="utf8")
    (OUT / "EuroAssist_Local.postman_environment.json").write_text(json.dumps(env, ensure_ascii=False, indent=2) + "\n", encoding="utf8")
    n = sum(1 for _ in _walk(c))
    print(f"{n} requests written to {OUT}")


def _walk(items):
    for i in items:
        if "item" in i:
            yield from _walk(i["item"])
        else:
            yield i


if __name__ == "__main__":
    build()
