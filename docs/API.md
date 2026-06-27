# API Reference — Nexus CRM + HRMS backend (CodeIgniter 4)

REST API consumed by the Next.js frontend. JSON in / JSON out, CORS enabled.

- **Base URL:** `http://localhost:8080/api` (from `backend/.env` → `app.baseURL`)
- **Database:** MySQL/MariaDB `project` (MySQLi driver) — see [DATABASE.md](DATABASE.md)
- **Auth:** Bearer JWT in the `Authorization` header. Obtain one from `POST /auth/login`.
- **Routes source of truth:** [`app/Config/Routes.php`](../app/Config/Routes.php);
  protection list: [`app/Config/Filters.php`](../app/Config/Filters.php).

## Authentication

```http
POST /api/auth/login
Content-Type: application/json

{ "email": "admin@nexus.com", "password": "admin123" }
```
```json
{ "token": "<jwt>", "user": { "id": 1, "name": "Administrator", "email": "admin@nexus.com", "role": "Member", "active": true } }
```

Send the token on every protected call:
```http
Authorization: Bearer <jwt>
```
Missing/invalid token on a protected route → `401 Unauthorized`. The JWT may carry a
`tenant` claim; when present, [`JwtAuth`](../app/Filters/JwtAuth.php) repoints the DB
connection at that client's `tenant_<slug>` database before any query runs.

## Health

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/api/health` | public | `{ "status":"ok", "database":"up" }` — DB connectivity probe |

## Endpoints

`✓` = requires Bearer JWT. Resource routes (`resource`) expose the standard set:
`GET` (list), `GET /{id}` (show), `POST` (create), `PUT/PATCH /{id}` (update),
`DELETE /{id}` (delete).

### Auth & accounts
| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/auth/login` | public | email+password → JWT (or 2FA challenge) |
| GET | `/api/auth/me` | ✓ | current user from token |
| POST | `/api/auth/logout` | ✓ | invalidate session |
| POST | `/api/auth/2fa/verify` | public | complete a 2FA login (uses challenge) |
| POST | `/api/auth/2fa/setup` · `/enable` · `/disable` | ✓ | TOTP 2FA management |
| resource | `/api/users` | ✓ | login accounts (admin) |
| POST | `/api/users/{id}/activate` · `/deactivate` · `/reset-2fa` | ✓ | account actions |

### CRM
| Method | Path | Auth | Purpose |
|---|---|---|---|
| resource | `/api/leads` | ✓ | leads (normalised CRM domain) |
| resource | `/api/directory` | ✓ | team directory (per-tenant) |

### Work, assets, inventory
| Method | Path | Auth | Purpose |
|---|---|---|---|
| resource | `/api/tasks` | ✓ | tasks |
| resource | `/api/assets` | ✓ | asset tracking + costing |
| POST | `/api/assets/{id}/submit` · `/verify` · `/reject` · `/reopen` · `/comments` | ✓ | asset workflow |
| resource | `/api/inventory` | ✓ | stock items |
| POST | `/api/inventory/{id}/adjust` · `/assign` | ✓ | stock movement / assignment |
| POST | `/api/inventory/assignments/{id}/return` | ✓ | return assigned units |

### Media library
| Method | Path | Auth | Purpose |
|---|---|---|---|
| resource | `/api/media/folders` | ✓ | nested folders |
| resource | `/api/media/files` | ✓ | uploaded files |

### Generic JSON store (replaces frontend localStorage)
| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/api/store` | ✓ | hydrate all keys |
| GET/PUT/DELETE | `/api/store/{key}` | ✓ | one key's JSON blob |

### Platform / super-admin (multi-tenant)
| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/super-admin/token` | console creds | mint a super-admin JWT |
| GET | `/api/platform` | public | branding / plans / config (landing reads this) |
| POST | `/api/platform` | guarded | save platform config |
| GET/POST | `/api/platform/demos` | mixed | demos list / save |
| POST | `/api/platform/demos/book` | public | book a demo from the landing page |
| GET | `/api/tenants` | ✓ | client workspace registry |
| POST | `/api/tenants/provision` | ✓ | `CREATE DATABASE tenant_<slug>` + seed admin |
| POST | `/api/tenants/update` · `/impersonate` · `/drop` | ✓ | tenant management |

### Integrations
| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/api/ai/chat` | ✓ | server-side relay to Claude (keeps API key secret) |
| GET | `/api/gmail/callback` | public | Google OAuth redirect (no bearer) |
| GET | `/api/gmail/status` · `/config` · `/auth-url` · `/messages` · `/message/{id}` · `/calendar` | ✓ | Gmail read + calendar |
| POST | `/api/gmail/config` · `/send` · `/calendar` · `/disconnect` | ✓ | Gmail config / send / events |
| GET | `/api/smtp/config` | ✓ | SMTP relay config |
| POST | `/api/smtp/config` · `/test` · `/send` | ✓ | SMTP config / test / send |

## Conventions

- **Errors:** non-2xx with `{ "error": "<message>" }` (or CI4 validation payloads on 400).
- **Timestamps:** `created_at` / `updated_at` as `Y-m-d H:i:s`.
- **IDs:** integer auto-increment primary keys.
- **CORS:** preflight `OPTIONS api/*` is handled and skipped by the auth filter
  ([`Cors.php`](../app/Config/Cors.php)).

---
_Keep this in sync with [`app/Config/Routes.php`](../app/Config/Routes.php) when routes change._
