# Ada AI Patient Wellbeing Analytics — Phase 1

A portfolio-quality patient wellbeing analytics dashboard. Phase 1 connects a
static web dashboard to SQL Server through a small PHP API and runs an
existing stored procedure to find patients who may be due for a wellbeing
follow-up. All data is synthetic demonstration data.

**This is an educational portfolio project, not a medical diagnostic tool.**

---

## 1. Project overview

The dashboard lets you set three filters — minimum age, minimum number of
wellbeing indicators, and months since last checkup — and runs
`dbo.usp_FindWellbeingFollowUpCandidates` against `PatientWellbeingDB` to
find matching patients. Results are shown in a sortable, paginated table,
along with an honest summary (only values that can actually be calculated
from the data returned).

### Phase 1 capabilities

- Numeric filter form (minimum age, minimum indicators, months since
  checkup) with client- and server-side validation.
- Secure PHP API that calls the stored procedure with bound parameters
  only — no string-built SQL.
- Per-patient list of which of the six wellbeing indicators apply,
  calculated in PHP from the same six rules as the stored procedure.
- Days-since-checkup calculation, with `null` handled as "no record."
- Sortable (numeric/text/date-aware), paginated results table.
- Honest summary: total matching patients, average age, average
  indicators, and count with no recorded checkup.
- Clear loading, error, and empty states.

### Features intentionally deferred to Phase 2

- Natural-language question interpretation (the "Ask" field). The
  Phase 1 UI shows this as a **disabled preview only** — it does not
  call any API and does not pretend to work.
- Any weighted `RiskScore` or `RiskLevel`. The current stored procedure
  only returns `Indicators_Count` (an unweighted 0–6 count), and Phase 1
  never labels that as a "risk score."
- `api/interpret.php` exists only as a documented extension point that
  currently returns HTTP 501 Not Implemented — see section 10 below.

---

## 2. Required software

| Requirement | Notes |
|---|---|
| PHP | 8.1 or later (uses `declare(strict_types=1)`, typed properties-style code) |
| PHP extension | `pdo_sqlsrv` (recommended) — see section 3 for why |
| SQL Server driver | Microsoft ODBC Driver 17 or 18 for SQL Server (required by `pdo_sqlsrv` itself) |
| SQL Server | SQL Server 2016+ with `PatientWellbeingDB` created and reachable at `localhost` |
| SSMS | SQL Server Management Studio, to create/verify the database and stored procedure |
| Browser | Any modern browser (Chrome, Edge, Firefox) |

---

## 3. Database driver: `pdo_sqlsrv` vs. PDO ODBC — and why we chose `pdo_sqlsrv`

You asked us to review whether PDO ODBC with `Trusted_Connection` is
reliable for this setup. Both approaches can work, but for a Windows +
SQL Server + PHP local-development stack, **Microsoft's own `pdo_sqlsrv`
driver is the more reliable and better-documented choice**, because:

- It is built and maintained by Microsoft specifically for SQL Server,
  so SQL Server–specific behavior (parameter binding, output types,
  error codes, `TrustServerCertificate` handling for Driver 18's
  stricter TLS defaults) is handled directly rather than translated
  through the generic ODBC layer.
- Windows Authentication ("trusted connection") works simply by
  omitting a username/password from the DSN — no separate
  `Trusted_Connection=yes` flag to remember, and fewer DSN string
  quoting pitfalls than the ODBC braces-and-semicolons syntax.
- It has clearer, more specific error messages when something is
  misconfigured, which matters a lot when you are debugging a local
  setup for the first time.

**PDO ODBC is still fully supported** in this project as a fallback
(`config.php` has `DB_DRIVER = 'odbc'` as an option) in case your
environment cannot install `pdo_sqlsrv` (for example, a PHP build
without a prebuilt `pdo_sqlsrv` binary for your exact PHP
version/architecture/thread-safety combination). If you use the ODBC
path, make sure the driver name in `config.php`
(`DB_ODBC_DRIVER_NAME`) exactly matches what's registered on your
machine (see step 4 below).

---

## 4. Installing and enabling the PHP driver

### Option A (recommended): `pdo_sqlsrv`

1. Check your exact PHP version, architecture, and thread safety:
   ```powershell
   php -v
   php -i | findstr "Thread Safety"
   php -i | findstr "Architecture"
   ```
2. Download the matching driver ZIP from Microsoft's
   [Drivers for PHP for SQL Server](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server)
   page. Pick the `php8.x` folder matching your version, and the `nts`
   (non-thread-safe) or `ts` (thread-safe) + `x64`/`x86` build that
   matches step 1.
3. Copy `php_pdo_sqlsrv_8x_ts_x64.dll` (exact filename varies) into
   your PHP `ext/` directory.
4. In `php.ini`, add:
   ```ini
   extension=pdo_sqlsrv
   ```
5. Restart any running PHP server and confirm it loaded:
   ```powershell
   php -m | findstr sqlsrv
   ```
   You should see `pdo_sqlsrv` (and usually `sqlsrv`) listed.

### Option B: PDO ODBC

1. Confirm `pdo_odbc` is enabled:
   ```powershell
   php -m | findstr odbc
   ```
   If missing, add `extension=pdo_odbc` to `php.ini` and restart.
2. Install the
   [Microsoft ODBC Driver 17 or 18 for SQL Server](https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server)
   (MSI installer for Windows).
3. Confirm the driver's exact registered name via **ODBC Data Source
   Administrator** (`odbcad32.exe`) → Drivers tab. It will read
   something like `ODBC Driver 18 for SQL Server`. Set
   `DB_ODBC_DRIVER_NAME` in `api/config.php` to match exactly.
4. In `api/config.php`, set `DB_DRIVER` to `'odbc'`.

---

## 5. Configure the SQL Server instance

Edit the configuration block at the top of `api/config.php`:

```php
define('DB_DRIVER', 'sqlsrv');           // 'sqlsrv' or 'odbc'
define('DB_SERVER', 'localhost');        // or 'localhost\SQLEXPRESS', etc.
define('DB_NAME', 'PatientWellbeingDB');
```

If your instance is a named instance (common with SQL Server Express),
set `DB_SERVER` to `localhost\INSTANCENAME`.

No username or password is required for Windows Authentication. If your
environment instead requires a SQL Server login, set the `PWD_DB_USER`
and `PWD_DB_PASS` environment variables before starting PHP — do not
hard-code credentials into `config.php` or any other source file.

---

## 6. Create or update the stored procedure

1. Open SSMS and connect to your local SQL Server instance.
2. Open `sql/usp_FindWellbeingFollowUpCandidates.sql`.
3. Confirm you are connected to `PatientWellbeingDB`, then execute the
   script. It uses `CREATE OR ALTER`, so it is safe to re-run.
4. Sanity-check it directly in SSMS:
   ```sql
   EXEC dbo.usp_FindWellbeingFollowUpCandidates
       @MinimumAge = 65,
       @MinimumIndicators = 2,
       @MonthsSinceCheckup = 12;
   ```

---

## 7. Running the project locally

**Do not open `dashboard.html` directly via `file://`.** The dashboard
calls `api/patients.php` using a relative path (`fetch('api/patients.php')`),
and browsers block or mishandle `fetch` requests made from `file://`
pages in most configurations (no proper origin, and PHP won't execute
at all without a server). You must serve the project through PHP's
built-in development server so `.php` files actually run.

From the **project root** (`patient-wellbeing-dashboard/`):

```powershell
php -S localhost:8000
```

Then open:

```
http://localhost:8000/welcome.html
```

or go straight to the dashboard:

```
http://localhost:8000/dashboard.html
```

---

## 8. Troubleshooting

**Database connection failure**
- Confirm SQL Server is running (`services.msc` → SQL Server
  (instance name) should be "Running").
- Confirm `DB_SERVER` in `config.php` matches your instance name
  exactly, including `\INSTANCENAME` if applicable.
- Confirm Windows Authentication is enabled on the SQL Server instance
  (SSMS → right-click server → Properties → Security).
- If PHP is running as a different Windows user than the one you use
  in SSMS, that user needs login access to `PatientWellbeingDB` too.

**Driver failure ("could not find driver" / class not found)**
- Run `php -m` and confirm `pdo_sqlsrv` (or `pdo_odbc`) is listed.
- Double-check the DLL filename matches your PHP version, architecture
  (x64/x86), and thread safety (TS/NTS) exactly — this is the most
  common cause of silent failures.
- Restart the PHP server after any `php.ini` change.

**HTTP 500 from `api/patients.php`**
- This means an exception was thrown and caught; the browser is shown
  a safe generic message on purpose. Check the PHP error log (location
  depends on your `php.ini` `error_log` setting, or the terminal
  running `php -S` if no log file is configured) for the real
  exception message logged by `error_log()`.

**Non-JSON response / "unexpected response from the server"**
- Usually means a PHP fatal error printed HTML/plain text before any
  JSON could be sent. Check the terminal running `php -S localhost:8000`
  for the PHP warning/error output.
- Confirm you're hitting the PHP server (`http://localhost:8000/...`)
  and not opening the file directly.

---

## 9. Testing the API directly

**curl:**
```bash
curl -X POST http://localhost:8000/api/patients.php \
  -H "Content-Type: application/json" \
  -d "{\"minimumAge\": 65, \"minimumIndicators\": 2, \"monthsSinceCheckup\": 12}"
```

**PowerShell:**
```powershell
Invoke-RestMethod -Uri "http://localhost:8000/api/patients.php" `
  -Method POST `
  -ContentType "application/json" `
  -Body '{"minimumAge": 65, "minimumIndicators": 2, "monthsSinceCheckup": 12}'
```

---

## 10. Phase 2 extension point

`api/interpret.php` is included as a placeholder only. It currently
returns HTTP 501 with a plain "not implemented" message and does **not**
call any LLM or generate any SQL. The planned Phase 2 design (documented
in comments inside that file) is:

```
Natural-language question
  → LLM interpretation constrained to an ALLOWLIST of known intents
  → mapped to validated, strongly-typed parameters
  → an approved stored procedure or query template (never free-form SQL)
  → executed with a read-only database login
  → validated results + a plain-language explanation grounded in them
```

---

## 11. A note on data

**Use only synthetic data with this project.** `PatientWellbeingDB`
should contain generated, non-real patient records. Do not load real
patient data into this database or this application.

---

## Folder structure

```
patient-wellbeing-dashboard/
├── dashboard.html
├── dashboard.css
├── dashboard.js
├── welcome.html
├── README.md
├── api/
│   ├── config.php        (DB configuration — no secrets committed)
│   ├── patients.php       (Phase 1 endpoint)
│   └── interpret.php      (Phase 2 placeholder — returns 501, not implemented)
└── sql/
    └── usp_FindWellbeingFollowUpCandidates.sql
```
