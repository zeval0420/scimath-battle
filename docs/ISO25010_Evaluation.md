# IRC QUEST: ISO/IEC 25010 Evaluation Instrument

Objective 1-5 Likert rubric for the eight product-quality characteristics of ISO/IEC 25010.

## How to use this instrument

1. Run the tests in each section against a **test copy** (own database, seeded event with at least 3 teams, 30 questions, 2 scorers).
2. Compute each metric from the measured result. Do not score by impression.
3. Each characteristic's score is the **lowest level whose every criterion is met** (a level is only reached if all its bullets hold). This removes evaluator judgment.
4. Apply the **caps** (hard ceilings). A cap overrides the computed score.
5. Record evidence (log, screenshot, query, tool output) for every score. A score with no evidence counts as 1.

Tools: browser DevTools, Lighthouse, WAVE/axe, sqlmap, OWASP ZAP, Apache Bench or k6, `php -l`, PHPStan, PHPUnit (optional), phpMyAdmin.

### Generic scale (what the numbers mean)

| Score | Label | Meaning |
|---|---|---|
| 5 | Excellent | All criteria met, no defects found, evidence on file |
| 4 | Good | Minor defects only; no effect on running an event |
| 3 | Acceptable | Works, but with defects that need a workaround |
| 2 | Poor | Frequent or serious defects; event is at risk |
| 1 | Unacceptable | Does not work, or a critical defect exists |

Severity used below: **Critical** = data loss, wrong ranking, unauthorized control/data access, or code execution. **Major** = a feature unusable or a workaround needed during the event. **Minor** = cosmetic or inconvenient only.

---

## 1. Functional Suitability

Sub-characteristics: completeness, correctness, appropriateness.
Test: execute every case in the functional test set (suggested minimum: 40 cases covering create event, upload zip/images, configure points/timers, add/import teams, operator flow, display sync, score entry, tabulation, clincher, CSV/PDF export, archive/restore).

Metrics:
- **FC** (completeness) = cases for implemented README features / total README features
- **FP** (pass rate) = passed cases / executed cases
- **Score accuracy** = ranking and totals match an independently hand-computed answer key (use a fixed key of 10 teams x 30 questions, incl. ties and a clincher)

| Score | Criteria (all must hold) |
|---|---|
| 5 | FC = 100%; FP >= 98%; score accuracy 100% (0 mismatches); 0 Critical/Major open |
| 4 | FC >= 95%; FP >= 90%; score accuracy 100%; 0 Critical, <= 2 Major |
| 3 | FC >= 85%; FP >= 80%; score mismatches <= 1 and explained; 0 Critical |
| 2 | FC >= 70%; FP >= 60%; or any score mismatch with no explanation |
| 1 | FC < 70% or FP < 60%, or ranking is wrong in the answer-key run |

Cap: any wrong total or rank in the answer-key run caps this characteristic at 2.

---

## 2. Performance Efficiency

Sub-characteristics: time behaviour, resource utilization, capacity.
Test setup: seeded event with 100+ slides, 20 teams. Measure on the event hardware (laptop plus projector over LAN), not a developer machine.

Metrics (p95 over 50 runs unless stated):
- **T_state**: operator/display state poll response time
- **T_score**: scorer save-mark response time
- **T_sync**: delay between operator action and display change (screen recording, frame count)
- **T_load**: display first slide fully rendered
- **Load**: concurrent users (1 operator + 2 display + 5 scorers polling) sustained for 10 min; record error rate and CPU/RAM

| Score | Criteria (all must hold) |
|---|---|
| 5 | T_state and T_score <= 100 ms; T_sync <= 1 s; T_load <= 2 s; 0% errors under load; CPU < 50%, RAM < 60% |
| 4 | T_state and T_score <= 300 ms; T_sync <= 2 s; T_load <= 3 s; 0% errors; CPU < 70% |
| 3 | T_state and T_score <= 1 s; T_sync <= 3 s; T_load <= 5 s; errors <= 1% |
| 2 | T_state or T_score <= 3 s; T_sync <= 5 s; errors <= 5%; or CPU/RAM > 90% at any point |
| 1 | Any metric worse than level 2, or the app stalls or crashes under the load run |

---

## 3. Compatibility

Sub-characteristics: co-existence, interoperability.
Test matrix: Chrome, Edge, Firefox (latest) on Windows; Chrome on Android; Safari on iOS (scorer only); projector at 1920x1080 and 1280x720; PHP 8.1 and latest 8.x; MariaDB 10.4 and MySQL 8; Excel/Google Sheets opening the CSV; PDF opened in a standard viewer.

Metric: **CM** = matrix cells where all five pages render and function / total cells.

| Score | Criteria (all must hold) |
|---|---|
| 5 | CM = 100%; CSV and PDF open without edits in Excel, Sheets and a PDF viewer; no conflict with other apps on the same XAMPP |
| 4 | CM >= 90%; export opens with at most cosmetic issues |
| 3 | CM >= 75%; the operator, display and scorer pages work on Chrome and Edge |
| 2 | CM >= 50%; or exports need manual repair |
| 1 | CM < 50%, or fails on the primary event browser |

---

## 4. Usability

Sub-characteristics: learnability, operability, error protection, UI aesthetics, accessibility.
Test: 5 or more representative users (operator, scorer, organizer; none who built the system), task-based, observed. Tasks: create an event, upload slides, run 5 questions, record scores, export.

Metrics:
- **TSR** = tasks completed without help / tasks attempted
- **TTL** = median time to complete the first scoring task on first use
- **SUS** = System Usability Scale score (0-100)
- **A11y** = axe/WAVE critical + serious violations per page, and Lighthouse accessibility score
- **Error protection** = destructive actions (delete scores, reset, archive, reclassify) that ask for confirmation or are blocked / total destructive actions

| Score | Criteria (all must hold) |
|---|---|
| 5 | TSR >= 95%; SUS >= 85; Lighthouse a11y >= 95 and 0 critical/serious violations; error protection 100%; keyboard-only path for the scorer page |
| 4 | TSR >= 85%; SUS >= 75; Lighthouse a11y >= 90; 0 critical violations; error protection >= 90% |
| 3 | TSR >= 70%; SUS >= 68; Lighthouse a11y >= 80; error protection >= 75% |
| 2 | TSR >= 50%; SUS >= 50; error protection >= 50% |
| 1 | TSR < 50% or SUS < 50, or any destructive action with no protection that caused data loss in testing |

---

## 5. Reliability

Sub-characteristics: maturity, availability, fault tolerance, recoverability.
Tests:
- **Soak**: a simulated full event (3 h, all pages open) with no restart.
- **Fault injection**: refresh operator/display mid-question; kill network on a scorer for 30 s then restore; stop and restart MariaDB; submit two scorers' marks for the same team/question at the same moment; double-click save; upload a corrupt zip; upload a file larger than `upload_max_filesize`.
- **Recovery**: restore the DB from a backup and confirm scores and screen state.

Metrics:
- **Failures per 3 h soak** (crash, blank screen, desync, lost entry)
- **Data integrity** = score rows correct after all fault tests / total (check the `uq_score_entries_question_contestant` constraint holds, no duplicates, no lost writes)
- **RTO** = time to recover to a working screen after a refresh or DB restart

| Score | Criteria (all must hold) |
|---|---|
| 5 | 0 failures in soak; data integrity 100%; display resumes the correct state after refresh in <= 3 s; RTO <= 1 min; invalid uploads fail with a clear message and no partial data |
| 4 | <= 1 failure, self-recovered; data integrity 100%; RTO <= 5 min |
| 3 | <= 2 failures; data integrity 100%; RTO <= 15 min with documented steps |
| 2 | >= 3 failures, or any lost or duplicated score entry that is detectable and fixable by hand |
| 1 | Any silent score loss, DB corruption, or a failure needing a reinstall |

Cap: any lost or wrong score caps this characteristic at 2.

---

## 6. Security

Sub-characteristics: confidentiality, integrity, non-repudiation, accountability, authenticity.
Reference frames: OWASP Top 10 (2021), OWASP ASVS Level 1.

Scoring rule: run every test in the table below. Each result is **Pass**, **Fail-Minor**, **Fail-Major** or **Fail-Critical**. Then apply the rubric.

### 6.1 Security test set

| ID | Test | How | Pass condition |
|---|---|---|---|
| **SQLi-1** | Classic injection in every numeric id (`event`, `id`, `question_id`, `contestant_id`) | Send `1'`, `1 OR 1=1`, `1; DROP TABLE x`, `1 UNION SELECT NULL,NULL--` to each `$_GET`/`$_POST` parameter of every endpoint | No SQL error text, no extra rows, no changed data; response identical to an invalid id |
| **SQLi-2** | Injection in every JSON `payload` field | Fix the structure, replace each value with `' OR '1'='1`, `"; SELECT SLEEP(5)-- -`, `\\`, a 10,000-char string. Cover all `action` values in `operator/api.php` and `configure/api.php` | No delay of 5 s, no error leak, values stored literally or rejected |
| **SQLi-3** | Injection in login | `admin' --`, `' OR 1=1 --` as username and password | Login fails with the generic message |
| **SQLi-4** | Second-order injection | Save `x'); DROP TABLE scimath_events;--` as event name, team name, team code, school name, subtitle; then trigger every read path (display, scorer, export CSV/PDF, archive) | Stored and displayed literally; nothing executes |
| **SQLi-5** | Blind / time-based | `sqlmap -u "<url>?event=1" --cookie="PHPSESSID=..." --level=5 --risk=3 --batch`; repeat for each POST endpoint with `--data` | sqlmap reports "not injectable" for every parameter |
| **SQLi-6** | Dynamic identifiers | Review every query that builds SQL with `$table`, `$col`, `ORDER BY`, `LIMIT` or `IN (...)` (e.g. `configure/api.php` asset and classify helpers, `admin/event_create.php`) | Every interpolated fragment comes from a hardcoded whitelist or is cast to `(int)`; none comes from the request |
| **SQLi-7** | Static scan | `grep -rn "query(\|\"SELECT\|\"INSERT\|\"UPDATE\|\"DELETE"` plus PHPStan/Psalm taint analysis | No query concatenates request data; the DB user has only the needed privileges (no `FILE`, `DROP`, `GRANT`) |
| **SQLi-8** | CSV / file import | Teams import and `schools_template.csv` with `=cmd|' /C calc'!A0`, quotes, `'; DROP`, very long fields | Parsed as data via prepared statements; formula cells neutralised on export |
| AUTH-1 | Unauthenticated access to every page | Open each `.php` with no session | Redirect to login (pages) or 401 (JSON); **only** the display page and its read-only state may be public, and it must be a deliberate choice |
| AUTH-2 | Unauthenticated **write** actions | Call every `action` in `operator/api.php`, `configure/api.php`, `scorer/api.php` with no cookie (curl) | All write actions return 401 |
| AUTH-3 | Brute force | 100 wrong passwords in 1 minute | Lock-out or delay after <= 10 attempts; attempts logged |
| AUTH-4 | Credential storage | Inspect the source and repo history | Password is hashed (bcrypt/argon2); no plaintext; hash is not committed to a public repo; default password is changed |
| AUTH-5 | Session handling | Check cookie flags; fixation; logout; idle timeout | `HttpOnly`, `SameSite`, `Secure` on HTTPS; id regenerated on login; logout destroys the session; idle expiry |
| AUTH-6 | Role separation | Does a scorer account exist apart from the organizer? Can a scorer call configure/operator actions? | Least privilege enforced server-side |
| AUTHZ-1 | IDOR across events | Change `event` / `id` in each request to another event's id | Records of other events are not readable or changeable (every query is scoped by `event_id`) |
| CSRF-1 | Cross-site request forgery | Build an external HTML form posting to a state-changing action while logged in | Rejected (token or origin check); `SameSite` alone is Fail-Minor |
| XSS-1 | Stored XSS | `<script>alert(1)</script>`, `"><img src=x onerror=alert(1)>` in every text field, CSV import, file name | Output escaped on every page (check `innerHTML` use in the `.js` files) |
| XSS-2 | Reflected XSS and open redirect | Payloads in `next`, `error`, query strings | Escaped; `next` restricted to local paths |
| UP-1 | Malicious upload | Upload `shell.php`, `shell.php.png`, `a.png` containing PHP, `.htaccess`, SVG with script, zip with `../../x.php` (zip-slip), zip bomb, polyglot | Rejected or neutralised; nothing executable is stored under `uploads/`; names regenerated server-side; MIME and content checked for audio as well as images |
| UP-2 | Execution in uploads | Request an uploaded file directly | Served as static content only; PHP disabled in `uploads/` (`.htaccess`/server config) |
| PATH-1 | Path traversal | `..%2f`, absolute paths in any file or path parameter | Rejected |
| CONF-1 | Information exposure | Trigger errors; browse `/config`, `/assets/*.sql`, `.git`, `graphify-out`, backups | No stack traces, no DB credentials, no directory listing, internal files not web-reachable; `display_errors` off |
| CONF-2 | Transport and headers | Scan with ZAP or securityheaders.com | HTTPS in production; `X-Content-Type-Options`, `X-Frame-Options`/CSP `frame-ancestors`, a CSP |
| CONF-3 | Secrets in source control | `git log -p` search for passwords/keys | None committed |
| INT-1 | Score integrity and audit | Edit/delete a score as a scorer; check for a record of who changed what and when | Changes are traceable (the adjustments table or log); no silent overwrite |
| DOS-1 | Resource abuse | Oversized payload, many requests per second to the state endpoint | Bounded; the server stays up |

### 6.2 Rubric

| Score | Criteria (all must hold) |
|---|---|
| 5 | **All** SQLi tests pass (0 injectable parameters in SQLi-1 to 8); 0 Fail-Critical and 0 Fail-Major across the test set; <= 2 Fail-Minor; AUTH-2 passes (no unauthenticated write); brute-force protection and CSRF protection in place; uploads cannot execute code; independent scan (ZAP) shows 0 High and 0 Medium |
| 4 | All SQLi tests pass; 0 Fail-Critical; <= 1 Fail-Major with a mitigation and a fix date; ZAP shows 0 High |
| 3 | All SQLi tests pass; 0 Fail-Critical; <= 3 Fail-Major (e.g. no CSRF token, no lock-out, hardcoded single account); ZAP shows 0 High |
| 2 | Any one Fail-Critical **other than** SQLi/RCE (e.g. unauthenticated write to scores or screen state, XSS on a shared screen), or >= 4 Fail-Major |
| 1 | **Any** confirmed SQL injection (data read, changed or time delay obtained), remote code execution via upload, authentication bypass, or credentials exposed publicly |

Caps (override the computed score):
- Any confirmed SQL injection: Security = **1**.
- Any unauthenticated action that changes scores, questions, settings or the live screen: Security <= **2**.
- Any executable file stored in `uploads/` and reachable by URL: Security = **1**.
- Credentials or secrets committed to a public repository: Security <= **2** until rotated.

### 6.3 Preliminary observations from a read of the code (to be verified by the tests above, not yet scored)

Positive:
- Queries go through a `prepare()`/bound-parameter helper (`config/api_helpers.php`) and the scorer, admin and clincher code uses `prepare()` throughout. Request ids are cast to `(int)`.
- Table/column names in `configure/api.php` come from a hardcoded `ASSETS` map, not the request.
- Login uses `password_verify`, a constant-time username compare, session id regeneration, `HttpOnly` and `SameSite=Lax`; `next` is restricted to local paths.
- Image uploads use an extension whitelist plus `getimagesize`; server-side generated file names; delete helper blocks `..`.

To check first:
- `operator/api.php` contains no `require_login()`, whereas `configure/api.php` and `scorer/api.php` do. If the state-changing actions (`goto_question`, `show`, `save_question_fields`, `save_clincher`, ...) really are callable without a session, that is **AUTH-2 Fail-Critical**. The display page needs to read state, but writes should require login.
- `admin/event_create.php` builds two `INSERT` statements with `$eventId` interpolated. It is an integer from `insert_id` so it is not injectable, but should be parameterised for consistency (SQLi-6/7).
- Audio uploads are checked by extension only (no content check) (UP-1).
- A single admin account with its hash in the source file (AUTH-4, AUTH-6); no login throttling (AUTH-3); no CSRF token (CSRF-1).
- 21 `innerHTML` uses in the JS files need review against XSS-1.
- `uploads/` should have PHP execution disabled (UP-2).

---

## 7. Maintainability

Sub-characteristics: modularity, reusability, analysability, modifiability, testability.
Metrics:
- **Lint/static**: `php -l` errors; PHPStan level reached (0-9) with 0 errors
- **Duplication** = duplicated lines % (e.g. phpcpd or jscpd)
- **Complexity**: highest cyclomatic complexity per function (phpmetrics or PHPLOC); largest file in lines
- **Doc coverage** = README steps that work when followed on a clean machine / total steps (a fresh install test; time it)
- **Test coverage** = automated tests passing; line coverage %
- **Change test**: time for a developer not on the project to add a new setting or round type (record minutes)

| Score | Criteria (all must hold) |
|---|---|
| 5 | 0 lint errors; PHPStan level >= 6 with 0 errors; duplication < 3%; max function complexity <= 10; clean-machine install works from the README in <= 30 min with no help; automated tests exist with >= 60% coverage of scoring/clincher logic; change test <= 2 h |
| 4 | 0 lint errors; PHPStan level >= 4 with 0 errors; duplication < 5%; max complexity <= 15; README install works first time; tests cover the scoring logic; change test <= 4 h |
| 3 | 0 lint errors; PHPStan level >= 2; duplication < 10%; max complexity <= 25; README install works with <= 2 fixes; change test <= 1 day |
| 2 | Lint errors, or duplication < 20%, or complexity <= 40; install needs help; no tests |
| 1 | Duplication >= 20% or complexity > 40, or the change test cannot be completed |

---

## 8. Portability

Sub-characteristics: adaptability, installability, replaceability.
Test: install on three clean targets: Windows + XAMPP, Linux (Apache or Nginx + PHP-FPM), and a shared host or VPS. Use only the README.

Metrics:
- **IS** = installs that reach a working login with only README steps / installs attempted
- **Config effort** = number of files that must be edited and hard-coded paths found (`config/database.php` includes `../../scilab/helperFiles/db_connection.php`, a path outside the project; count such dependencies)
- **Data move** = export and import an event (DB + `uploads/`) between two installs and run it

| Score | Criteria (all must hold) |
|---|---|
| 5 | IS = 100% (3/3); <= 1 file to configure (DB credentials) and 0 hardcoded external paths; event data move works with no manual fixes; works with a configurable base URL/subfolder |
| 4 | IS >= 2/3; <= 2 files to configure; event data move works with documented steps |
| 3 | IS >= 2/3 with fixes outside the README; <= 4 files to configure |
| 2 | IS = 1/3; or data move needs code edits |
| 1 | IS = 0/3, or it works only on the original machine |

---

## Summary sheet

| # | Characteristic | Weight | Score (1-5) | Cap applied? | Evidence ref |
|---|---|---|---|---|---|
| 1 | Functional Suitability | 20% | | | |
| 2 | Performance Efficiency | 10% | | | |
| 3 | Compatibility | 5% | | | |
| 4 | Usability | 15% | | | |
| 5 | Reliability | 20% | | | |
| 6 | Security | 20% | | | |
| 7 | Maintainability | 5% | | | |
| 8 | Portability | 5% | | | |

Weights reflect a live-event scoring tool (correctness, uptime and security matter most). Change them only before testing, and state why.

**Overall = sum(weight x score).** Overall interpretation:

| Overall | Verdict |
|---|---|
| 4.50 - 5.00 | Excellent: release as is |
| 3.75 - 4.49 | Good: release; fix listed minor items |
| 3.00 - 3.74 | Acceptable: release only for low-stakes events, fix Major items first |
| 2.00 - 2.99 | Poor: do not use for an official event |
| 1.00 - 1.99 | Unacceptable |

Release gate regardless of the average: Functional Suitability, Reliability and Security must each be >= 3.

## Appendix: reproducible commands

```bash
# SQL injection (authenticated session cookie required for protected endpoints)
sqlmap -u "http://localhost/irc_quest/scorer/api.php?event=1" --cookie="PHPSESSID=<id>" --level=5 --risk=3 --batch
sqlmap -u "http://localhost/irc_quest/operator/api.php" --data="event=1&action=state" --level=5 --risk=3 --batch

# Unauthenticated write check (expect 401)
curl -i -X POST http://localhost/irc_quest/operator/api.php -d "event=1&action=goto_question&payload={\"id\":1}"
curl -i -X POST http://localhost/irc_quest/configure/api.php -d "event=1&action=save_settings&payload={}"

# Static checks
php -l <file>            # per file
vendor/bin/phpstan analyse --level=6 .
npx jscpd .

# Load
ab -n 2000 -c 20 "http://localhost/irc_quest/operator/api.php"   # needs POST body for a real test
```
