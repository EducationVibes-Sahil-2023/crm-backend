# Database Structure — Super Admin & Platform

Reference for the Nexus CRM + HRMS database. Generated from the live schema.

- **Engine:** MySQL / MariaDB (MySQLi driver)
- **Host:** `localhost:3306`
- **Admin / main database:** `project` (configured in `backend/.env` → `database.default.database`)
- **Per-client databases:** `tenant_<slug>` (one isolated DB per client workspace)
- **Migrations:** CodeIgniter 4, in `backend/app/Database/Migrations/` (45 tables)

### Provision / deploy (auto-create the whole structure)

One idempotent command creates the database (if missing), runs every migration, and
seeds the admin login — safe to re-run on any fresh server or in CI/CD:

```bash
cd backend
php spark setup            # create DB + migrate + seed admin
php spark setup --no-seed  # create DB + migrate only
```

Default admin login after seeding: `admin@nexus.com` / `admin123`.

Equivalent manual steps: `CREATE DATABASE project;` then `php spark migrate` then
`php spark db:seed UserSeeder`.

---

## 1. Multi-tenant architecture

The **Super Admin** (platform owner) operates on the main `project` database and
provisions an **isolated database per client**:

```
MySQL @ localhost
├── project                ← admin / platform DB (super admin works here)
│   ├── tenants            ← registry of all client workspaces
│   ├── settings           ← platform config (branding, plans, Gmail/SMTP…)
│   ├── users              ← login accounts
│   └── … all module tables (the default workspace)
│
├── tenant_acme            ← client "acme" — full schema, isolated data
├── tenant_globex          ← client "globex" — full schema, isolated data
└── tenant_<slug>          ← one per provisioned client
```

**Request routing:** the JWT carries an optional `tenant` claim. The
[`JwtAuth`](../app/Filters/JwtAuth.php) filter points the default DB connection at
that client's database **before any query runs**, so each request reads/writes the
correct isolated database. No `tenant` claim → the `dashboard` DB.

**Provisioning** ([`Tenants::provision`](../app/Controllers/Api/Tenants.php)):
`CREATE DATABASE tenant_<slug>` → copies the full table structure from `project`
→ seeds an Administrator login → writes a row in `tenants`.

---

## 2. Super-Admin / platform tables

These live in the `project` DB and drive the admin console.

### `tenants` — client workspace registry
Maps each client to its isolated database, plan and status.

| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned | **PK** |
| `company` | varchar(191) | client company name |
| `slug` | varchar(64) | URL/db slug |
| `db_name` | varchar(80) | **UNIQUE** — `tenant_<slug>` |
| `admin_email` | varchar(191) | client admin login |
| `admin_name` | varchar(120) | |
| `plan` | varchar(40) | free / starter / pro / enterprise |
| `region` | varchar(80) | |
| `active` | tinyint(1) | 1 = active |
| `status` | varchar(20) | Active / Trial / Suspended |
| `storage_gb` | int unsigned | allocated storage |
| `created_at`, `updated_at` | datetime | |

API: [`Api\Tenants`](../app/Controllers/Api/Tenants.php) — `GET /api/tenants`,
`POST /api/tenants/provision`, `POST /api/tenants/update`,
`POST /api/tenants/impersonate`, `POST /api/tenants/drop`.

### `settings` — platform configuration (JSON key/value)
Secrets and config kept in the DB instead of `.env`/files.

| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned | **PK** |
| `setting_key` | varchar(64) | **UNIQUE** |
| `setting_value` | text | JSON |
| `updated_at` | datetime | |

Keys in use:
- `platform.config` — branding, landing content, plans, **per-plan permissions**, reviews, payments, Google, automation.
- `platform.demos` — demos booked from the landing page.
- Gmail OAuth app + SMTP relay credentials.

API: [`Api\Platform`](../app/Controllers/Api/Platform.php) — `GET /api/platform` (public read),
`POST /api/platform` (super-admin), `GET/POST /api/platform/demos`, `POST /api/platform/demos/book` (public).

### `app_store` — generic per-workspace JSON store
The replacement for front-end `localStorage`. Each module persists its blob here.

| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned | **PK** |
| `store_key` | varchar(120) | **UNIQUE** — e.g. `nexus_intake_leads` |
| `data` | longtext | JSON |
| `updated_at` | datetime | |

API: [`Api\Store`](../app/Controllers/Api/Store.php) — `GET /api/store` (hydrate all),
`GET/PUT/DELETE /api/store/<key>`.

### `users` — login accounts (auth + 2FA)

| Column | Type | Notes |
|---|---|---|
| `id` | int unsigned | **PK** |
| `name` | varchar(120) | |
| `username` | varchar(100) | **UNIQUE** |
| `email` | varchar(191) | **UNIQUE** |
| `role` | varchar(60) | Administrator / Member / … |
| `active` | tinyint(1) | drives real-time logout |
| `password` | varchar(255) | bcrypt hash |
| `twofa_enabled`, `twofa_secret` | tinyint / varchar(64) | TOTP 2FA |
| `phone`, `department`, `designation`, `avatar`, `api_token` | | |
| `created_at`, `updated_at` | datetime | |

API: [`Api\Auth`](../app/Controllers/Api/Auth.php), [`Api\Users`](../app/Controllers/Api/Users.php).

### `migrations` — CodeIgniter migration log (framework-managed).

---

## 3. Business-domain tables

All use: `id` int unsigned auto-increment **PK**, `created_at` / `updated_at`
datetime, `decimal(12,2)` for money, JSON in `longtext`/`text` columns, and a
`deleted` tinyint flag for soft-deletes where present.

### CRM — Leads (normalised, live API)
| Table | Key columns | Backed by |
|---|---|---|
| `leads` | name, company, phone, email, status*, source, type, assigned_to*, follow_up_date, custom (JSON), deleted* | [`Api\Leads`](../app/Controllers/Api/Leads.php) — `resource leads` |
| `lead_notes` | lead_id*, text, created_by | |
| `lead_reminders` | lead_id*, title, due, done | |
| `lead_activities` | lead_id*, kind, text | |
| `lead_calls` | lead_id*, direction, duration_sec, outcome | |
| `lead_transfers` | lead_id*, from_owner, to_owner, reason | |
| `visitor_requests` | lead_id, name, purpose, status, scheduled | |
| `lead_custom_fields` | field_key, label, type, options (JSON) | |

`*` = indexed. `lead_id` references `leads.id`.

### HRMS
| Table | Key columns |
|---|---|
| `employees` | employee_code, name, email, department*, designation, shift, salary, status*, meta (JSON) |
| `attendance` | employee_id*, date*, check_in, check_out, status, worked_mins, lat/lng, selfie_url |
| `leaves` | employee_id*, type, from_date, to_date, days, status, approver |
| `holidays` | name, date, type, region |
| `shifts` | name, start_time, end_time, grace_mins |

### Finance
| Table | Key columns |
|---|---|
| `invoices` | number, customer, lead_id, subtotal, tax, total, paid, status* |
| `invoice_items` | invoice_id*, description, qty, rate, amount |
| `quotations` | number, customer, lead_id, total, valid_until, status |
| `quotation_items` | quotation_id*, description, qty, rate, amount |
| `payments` | invoice_id*, customer, amount, method, reference, paid_at |
| `expenses` | title, category, vendor_id, amount, spent_on, status, receipt_url |
| `vendors` | name, email, phone, gstin, address, category |

### Support & Communications
| Table | Key columns |
|---|---|
| `support_tickets` | number, subject, requester, category, priority, status*, assigned_to |
| `ticket_comments` | ticket_id*, author, body, internal |
| `announcements` | title, body, audience, pinned, author |
| `activity_log` | category*, action, actor, meta |

### Config / lookups
`lead_statuses` (name, color), `lead_sources`, `departments`, `designations`,
`locations` (name, city, state), `ticket_categories`, `ticket_priorities` (weight).
Each: `name`, `sort_order`, `active`.

### Assets & Inventory (existing)
| Table | Key columns |
|---|---|
| `assets` | tag, name, category, purchase_cost, warranty_*, status*, verified_by |
| `asset_events` | asset_id*, type, actor, role, message |
| `inventory_items` | sku, name, quantity, reorder_level, unit_price, supplier |
| `inventory_movements` | item_id*, type, qty, balance_after, reason |
| `inventory_assignments` | item_id*, assignee_name, qty, qty_returned, status |

API: [`Api\Assets`](../app/Controllers/Api/Assets.php), [`Api\Inventory`](../app/Controllers/Api/Inventory.php).

### Team directory & Media (existing)
| Table | Key columns | API |
|---|---|---|
| `directory` | name, email, designation, department, role, status, employee_id | [`Api\Directory`](../app/Controllers/Api/Directory.php) |
| `media_folders` | name, parent_id* (nested) | [`Api\MediaFolders`](../app/Controllers/Api/MediaFolders.php) |
| `media_files` | name, folder_id*, mime, size, path | [`Api\MediaFiles`](../app/Controllers/Api/MediaFiles.php) |
| `tasks` | title, is_done | [`Api\Tasks`](../app/Controllers/Api/Tasks.php) |

---

## 4. Conventions

- **Primary keys:** `id` INT UNSIGNED AUTO_INCREMENT.
- **Timestamps:** `created_at` / `updated_at` DATETIME (CI4 `useTimestamps`).
- **Money:** `DECIMAL(12,2)`.
- **Relations:** child tables carry a `<parent>_id` column, indexed (e.g. `lead_id`, `invoice_id`, `employee_id`). Enforced in application code (no DB-level FKs, to keep the per-tenant schema-copy provisioning simple).
- **Soft delete:** `deleted` TINYINT(1) where supported (e.g. `leads`).
- **Flexible/extra data:** JSON stored in `longtext`/`text` (`custom`, `meta`, `options`, `app_store.data`, `settings.setting_value`).
- **Indexes:** added on frequently-filtered columns (`status`, `assigned_to`, `category`, `date`, parent ids).

## 5. Authentication & scope

- All `/api/*` data routes are protected by the `auth` (JwtAuth) filter — a valid Bearer JWT is required (see [`Config/Filters.php`](../app/Config/Filters.php)).
- Super-admin-only actions (provisioning, platform config writes) additionally check for `role: super-admin` or an `Administrator` user inside the controller.
- The super-admin console mints its JWT via `POST /api/super-admin/token` using the credentials in `backend/.env` (`superadmin.email` / `superadmin.password`).

---
_Regenerate the table list anytime:_ `php spark db:table --show` _or_ `SHOW TABLES FROM project;`
