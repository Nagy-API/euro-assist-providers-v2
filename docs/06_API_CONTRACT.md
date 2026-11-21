# API inventory and compatibility plan

**Reading order:** initial route-only notes are retained as history. The dated source contract below supersedes their verification column; it verifies source implementation, not running endpoint behavior.

## Known V1 route families from `providers/routes/api.php`

- `POST /api/login`, authenticated `POST /api/logout`.
- `GET /api/providers/distribution/stats`, `/api/providers/distinct/names`, `/api/providers/all-latlng`, `/api/providers/search`.
- `Route::apiResource('providers', ...)` (REST index/show/store/update/destroy **declared**; controller implementation is not yet verified for all methods).
- `GET /api/arabic-providers/distinct/names`, `/api/arabic-providers/all-latlng`, `/api/arabic-providers/search`.
- `Route::apiResource('arabic-providers', ...)` (declared; implementation requires audit).
- `GET /api/top-providers`.

## V2 strategy

- Introduce `/api/v2` with documented OpenAPI schema and consistent JSON/error response conventions.
- Keep public read endpoints deliberate, rate-limited and with only approved fields; protect all write endpoints with explicit auth + policies.
- Keep canonical identifiers stable for V2; maintain dedicated legacy IDs where needed for external consumers.
- Snapshot sample V1 responses in **sanitized synthetic fixtures**, not raw private records.
- Record consumer owner, current endpoint, requested response shape, sorting/pagination, auth expectations, migration instructions and status per endpoint.
- Use contract tests to verify any compatibility layer; don't announce parity before tests.

## Compatibility inventory (to complete in P1/P5)

| Endpoint family | Read/write | V1 behavior verified? | V2 test |
|---|---|---|---|
| Providers search | Read | Routes only | TODO |
| Provider distribution | Read | Routes only | TODO |
| Provider coordinates | Read | Routes only | TODO |
| Arabic provider search | Read | Routes only | TODO |
| Provider CRUD | Write | Not fully verified | TODO |
| Top providers | Read/admin write | Route inventory | TODO |
| JWT login/logout | Auth | Basic route inventory | TODO |

## 2026-11-21 — Implemented V1 source contracts

**Verified static only.** Source root is the frozen hosting export, not V2. Route definitions: `providers/routes/api.php`; handlers: `providers/app/Http/Controllers/Api/{ProviderController,ArabicProviderController,TopProviderController,AuthController}.php`. No HTTP request, legacy application bootstrap or production connection was made. Status codes below are explicitly returned by source, or framework behavior marked inferred; deployment caches, serialization at runtime and API consumers remain unknown. No production response/record is included.

`providers/bootstrap/app.php` registers the API file using the default `/api` prefix. Only logout is inside `auth:api`; provider reads and resource declarations, Arabic reads and Top Providers are outside it. Controllers add no auth/policy checks. API group is not proof of rate limiting: old `App\Http\Kernel` and `RouteServiceProvider` contain throttle/60-per-minute declarations, while the configured bootstrap/provider list uses the newer builder and does not explicitly enable its API limiter. Effective runtime throttling is unverified; see [boot caveat](03_LEGACY_AUDIT.md#source-map-and-boot-caveat).

### Supported handlers versus resource declarations

| Route | Handler present | Static input / result | Auth in application source |
|---|---|---|---|
| POST `/api/login` | AuthController::login (line 11) | Body email/password; `JWTAuth::attempt`; explicit 401 `{error:"Invalid credentials"}` or success `{success:true,access_token,token_type:"bearer",expires_in}` (TTL seconds) | None; no explicit input validator/throttle in handler. Integration is inconsistent, below. |
| POST `/api/logout` | AuthController::logout (27) | `auth()->logout()`; `{message:"Successfully logged out"}` | `auth:api` route middleware; token invalidation is not established. |
| GET `/api/providers` | ProviderController::index (14) | Optional filters, raw array by default; optional pagination envelope below | None |
| GET `/api/providers/{provider}` | ProviderController::show (369) | `findOrFail(id)`; full model JSON, **without** injected latitude/longitude | None |
| GET `/api/arabic-providers` | ArabicProviderController::index (13) | Same shape/filter/pagination except no translation filter | None |
| GET `/api/arabic-providers/{arabic_provider}` | ArabicProviderController::show (89) | `findOrFail(id)`; full Arabic model JSON, no injected coordinates | None |
| GET `/api/providers/search` | ProviderController::search (381) | Required trimmed query/q; paginated envelope, broad search below | None |
| GET `/api/arabic-providers/search` | ArabicProviderController::search (101) | Same search, without translation filter | None |
| GET `/api/providers/distinct/names` | ProviderController::distinctNames (464) | `{names:[...]}` from distinct name pluck; no explicit sort/pagination | None |
| GET `/api/arabic-providers/distinct/names` | ArabicProviderController::distinctNames (180) | Same Arabic name shape | None |
| GET `/api/providers/all-latlng` | ProviderController::allLatLng (473) | `{data:[{name,type,latitude,longitude}]}` for all English rows; no ID/pagination | None |
| GET `/api/arabic-providers/all-latlng` | ArabicProviderController::allLatLng (189) | Same Arabic shape | None |
| GET `/api/providers/distribution/stats` | ProviderController::getDistributionStats (98) | Cross-locale normalized type estimates; exact shape below. No Arabic-prefixed equivalent declared. | None |
| GET `/api/top-providers` | TopProviderController::index (14) | `{success:true,data:[{id,name_english,name_arabic,type_en,type_ar,logo_url,created_at,updated_at}]}`; all rows, no filter/sort/page | None |
| POST `/api/providers`; PUT/PATCH `/api/providers/{provider}`; DELETE `/api/providers/{provider}` | **Absent:** store/update/destroy | Declared through apiResource only; neither controller nor base implements these methods. Do not treat these as working write APIs; framework failure response is runtime unknown. | No auth group on declarations |
| POST `/api/arabic-providers`; PUT/PATCH `/api/arabic-providers/{arabic_provider}`; DELETE `/api/arabic-providers/{arabic_provider}` | **Absent:** store/update/destroy | Same declaration/implementation gap. No API create/edit forms generated by apiResource. | No auth group on declarations |

Custom provider paths are declared before resource paths, avoiding search/stats/name path interpretation as IDs in the source route order. No OpenAPI definition, consumer registry, API contract tests, explicit V1 version prefix or approved public-field allowlist was found.

### List and search parameters

| Parameter | List index behavior | Search endpoint behavior |
|---|---|---|
| `type,city,governorate,district` | Each filled filter applies `LIKE '%value%'`; combined with AND | Same filters |
| `is_translated` (English only) | Filled value applies LIKE to translation field | Same |
| `search` | Grouped LIKE across name/address/phone | Not read by this handler |
| `searchName` | Collapse whitespace + trim input, compare to `TRIM(REPLACE(REPLACE(name,'  ',' '),'  ',' '))` with bound equality | Not read |
| `query`, fallback `q` | Not read | `query` takes precedence if present; trim string. Empty yields explicit HTTP 422 `{message:"Validation error.",errors:{query:["The query parameter is required."]}}`. |
| `page,per_page,paginate` | Any filled page or per_page, or boolean paginate true, switches raw array to paginated object | Always paginated; paginate is not inspected |
| `per_page` | Cast to int; <=0 ->25, default25; no maximum | Same |
| Sorting | No orderBy or supported sort parameter in API controllers | Same; stable ordering is not guaranteed |

Search OR fields: name, type, specialization, city, district, **governorate**, address, discount_pct, hours, phone, email, website, package. **Schema defect:** governorate is absent from both provider tables in frozen dump CREATE statements. The search handler always includes it even without that filter; index includes it only when filter is filled. Query failure against the dump schema is inferred and must be reproduced on isolated synthetic tables before declaring compatible search. LIKE wildcard characters are not escaped into literal substring semantics.

Paginated response structure returned by both index/search:

```text
{
  data: [model attributes + latitude + longitude],
  meta: {current_page,last_page,per_page,total,from,to},
  links: {self,next,prev}
}
```

No-pagination index returns just `[model attributes + latitude + longitude]`, **not** `{data:[...]}`. Page links use paginator `url()`; the controllers do not call `withQueryString`/`appends`, so filter retention in links must not be promised. Empty-result boundary types and malformed parameter errors are runtime unknown.

### Model serialization and coordinates

Provider fields from dump schema: `id,name,type,address,city,district,discount_pct,hours,phone,email,website,map_url,logo_path,specialization,package,is_translated,created_at,updated_at`; Arabic has the same names except is_translated. There is no provider `$hidden`/resource DTO or explicit response field whitelist. Raw attributes are exposed by model serialization; actual scalar/timestamp serialization is unverified. Both list/search add latitude/longitude only in memory; show does not. Neither provider model appends `lat_lng` automatically.

`logo_path` is accessor-transformed: trimmed absolute HTTP(S) retained, other paths lose leading slash, blank yields type/default image. TopProvider instead derives logo_url with `asset('uploads/logos/' . logo_path)` or null. Generated base URL depends on environment/server configuration; do not freeze a production hostname into tests.

Both `getLatLngAttribute` accessors parse numeric `q=lat,lng` or `@lat,lng,` segments and return float pairs or `[null,null]`. No geographic range validation, URL fetching or short-link resolution. Coordinate endpoints retain null pairs and do not omit rows, and provide no stable ID to disambiguate repeated names. All-latlng reads `Model::all()` into memory.

`show` invokes findOrFail **before** an explicit not-found JSON branch; that branch is unreachable on absent IDs. A framework 404 is inferred, but actual envelope/headers depend on exception handling/request negotiation. Do not claim the hand-coded provider-not-found message is the observed 404 contract.

### Distribution calculation

English controller gathers distinct nonnull/nonempty type strings from both locale tables, normalizes through a hardcoded alias map, sums exact-string English/Arabic counts into each normalized bucket, sets `max_count=max(english_count,arabic_count)`, and sorts descending by max_count (no tie-breaker). Unknown types retain their raw key and use display/color/icon fallbacks.

```text
{
  distribution: [{type,display_name,english_count,arabic_count,max_count,color,icon}],
  total_types: number of included buckets,
  total_providers: sum of max_count across buckets
}
```

This is a dashboard estimate, **not a deduplicated canonical-provider count** or proof of bilingual pairing. Excluded blank types, alias drift and branch duplication affect business interpretation; any changed V2 calculation requires owner approval and versioned tests.

### Auth integration risks and unresolved compatibility

Verified source inconsistencies: API auth imports the `Tymon\JWTAuth` facade; composer requires `php-open-source-saver/jwt-auth` and supplied autoload registers `PHPOpenSourceSaver\JWTAuth` (no supplied Tymon package path). User defines JWT methods but does not implement JWTSubject. Configured `api` guard uses driver `token`, while login attempts JWT and logout calls generic `auth()->logout`; dump users table lacks api_token. No corrective binding found in empty AppServiceProvider. These support a likely broken integration, **not a tested successful JWT contract**. Do not copy configuration/secrets or change V2 auth based on an assumed working V1 token.

Updated compatibility acceptance matrix:

| Family | What is verified now | Still required before parity claim |
|---|---|---|
| List/show | Implemented read source, dual raw/paginated shapes, differing coordinates | Synthetic successful/empty/not-found/invalid-query fixtures; approved field exposure/order/limits |
| Search | Implemented source plus dump-schema mismatch | Isolated reproduction and owner-approved treatment of missing governorate |
| Names/maps | Implemented source; no IDs for coordinates | Consumer needs, nullable-coordinate fixtures, large-result bounds |
| Distribution | Implemented max-per-type estimate and fields | Approved meaning, alias fixtures and compatibility choice |
| CRUD writes | Declared routes only, handlers absent | Decide supported V2 methods; test method/auth denials explicitly |
| Top Providers | Explicit read DTO source | Logo URL and optional fields fixtures; owner-approved canonical relationship if any |
| Login/logout | Handler declarations and integration inconsistencies | Resolve intended V2 auth contract; synthetic auth/expiry/logout/role/rate-limit tests |

All V2 compatibility tests remain TODO. Capture consumer owner, endpoint, payload/response field dependence, authentication, pagination/link/order assumptions and migration deadline before approving an adapter. Breaking changes remain versioned under `/api/v2`; the strategy above is a proposal, not delivered behavior. No unsupported legacy writes or security bugs should be preserved merely to claim parity.
