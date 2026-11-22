# Legacy staff accounts: findings, mapping and migration (2026-11-22)

Read-only inspection of the frozen snapshot (`euro_assist_legacy_audit_20261121`, SELECT-only account) and of the legacy
source code. No hash, password or token is reproduced anywhere in this document or in the tooling output.

## Findings

| Item | Frozen snapshot |
|---|---|
| Accounts in `users` | **1** |
| Identity | `admin@euro-assist.com` (name length 5), created 2026-07-05, e-mail never verified |
| Legacy role value | `super_admin` (column default `admin`; the legacy code never checks roles — every account is a full administrator) |
| Password storage | bcrypt `$2y$`, cost 12, 60 characters (Laravel default hasher; no `config/hashing.php` override) |
| Remember token / sessions / API tokens / reset tokens | none / 0 / 0 / 0 |
| Account creation | not by the legacy seeder (which creates `admin@example.com`); origin of the live password unknown |

Why it was missing from V2: the release dataset deliberately excluded every account and every password hash (King's
earlier instruction: no historical credentials in the package). That also removed the legitimate identity. ADR-016
corrects this: identities are preserved; whether the old password is preserved is a separate, explicit decision.

**Freshness:** the snapshot shows one account. The live system may have more today; the fresh-data sync
(`deploy/it-package/FRESH_DATA_SYNC.md`) must re-run `legacy:import-users` and report the new count.

## Mapping (legacy → V2)

| Legacy role | V2 role | Reason |
|---|---|---|
| `super_admin` | Admin | full administrator in the legacy app |
| `admin` | Admin | same |
| `editor` | Editor | not present in the snapshot; mapped for a fresh sync |
| `viewer` | Viewer | not present in the snapshot |
| anything else | **held** (not imported, reported) | no guessing |

| Legacy account | V2 result |
|---|---|
| #1 `admin@euro-assist.com`, `super_admin` | Admin, same e-mail and name, active; no duplicate account is created |

Conflicts and rules: an e-mail that already exists in V2 is never overwritten or merged (reported as skipped); invalid
e-mails are held; e-mails are compared lower-cased and trimmed (the snapshot has no case/space issues); legacy
remember tokens are never copied; the import is idempotent and local-only (ADR-014 guards).

## Password compatibility (verified, not assumed)

Checked against the real hash without knowing or printing it (`LegacySource::accounts(true)` → booleans only):
algorithm bcrypt, cost 12, V2 `BCRYPT_ROUNDS` 12, `Hash::isHashed` true, `verifyConfiguration` true, `needsRehash` false,
and the `User` model's `hashed` cast keeps the value unchanged. **Technically compatible**: the existing password would
work in V2 unchanged. The login itself was proven with a synthetic bcrypt-12 account (`tests/Feature/Legacy/UserImportTest.php`);
the real password was never tried. Caveat: if production sets `BCRYPT_ROUNDS` below 12, preserved hashes are refused (held).

## Security assessment of preserving the hash

* The hash has existed in uncontrolled copies: the legacy archive (`Providers.rar`, including the app's `.env`) and
  `Providers.sql` were distributed for this project. AGENTS.md requires treating such secrets as compromised. A bcrypt-12
  hash resists offline guessing only if the password is strong; its strength is unknown.
* The account is the only administrator and has never verified its e-mail.

**Recommendation: `activation` policy (default).** Preserve the identity and the Admin role; do not carry the hash.
At cutover the account holder (or IT with King's written authorization) sets a new password with
`php artisan users:set-password admin@euro-assist.com` (hidden prompt, typed twice, production confirmation, ends all
sessions/tokens, audit event `user.password_set_by_operator`), or uses "Forgot password" once SMTP is configured.
`preserve-hash` remains available as an explicit owner decision (`legacy:import-users --passwords=preserve-hash`).
Note: the hidden prompt needs an interactive console (RDP/SSH); piping input into it is refused by design.

## Rehearsal on isolated databases (2026-11-22)

`euro_assist_v2_usermig_test` = release SQL restored into a new empty database; then `legacy:import-users --dry-run`
(nothing written) → `legacy:import-users` (1 created, Admin, activation required) → re-run (0 created, 1 skipped) →
SQL check: same e-mail, name and verification date as legacy, role `admin`, active, hash **not** equal to legacy,
remember token empty, audit metadata without secrets → activation with a throwaway password (same operations as
`users:set-password`) → HTTP: wrong password refused, new password signs in, Admin pages 200, account listed in Users.

## Release impact

A revised release is required to carry the identity: the data SQL would include one `users` row for
`admin@euro-assist.com` with an unusable random password (activation policy) — or the legacy hash (preserve-hash) — and
the ZIP would include `users:set-password` and updated guides. The current `2.0.0-rc.1` ZIP is unchanged until King decides.
