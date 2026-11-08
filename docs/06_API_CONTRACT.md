# API inventory and compatibility plan

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
