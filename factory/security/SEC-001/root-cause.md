# SEC-001 — root-cause analysis

Pipeline: `security`. Stage (a). Written before any fix exists; no fix is
proposed here.

---

## Vulnerability class

**Primary — CWE-798, use of hard-coded credentials, in a repository that is
public.** `backend-symfony/src/Application/Meta/PreprodCopyService.php:15`
declares `private const PREPROD_DSN` with `scambuster:postgres@` inline. The
class is not "a secret in code" in the abstract: the disclosure event already
happened, at commit `7e71739` ("ScamBuster initial public release"), which is
where both this literal and the second copy in `docker-compose.yml` first
appear. Nothing about a fix un-publishes it. The class is therefore *disclosed*
credential, not *disclosable* one, and that distinction decides what the fix has
to be worth doing at all.

**Secondary, and a separate class — CWE-532, insertion of sensitive information
into a log or diagnostic channel.** The same constant is concatenated into SQL
text at `:62` and `:95` (`FROM dblink('" . self::PREPROD_DSN . "', ...)`). It is
therefore not only in source; it is in query text at runtime, and query text
goes to places source does not: the executing database's error log, and
`pg_stat_activity.query`. This second class matters here because it survives the
obvious remedy — moving the literal to an environment variable removes it from
git and leaves a *live* credential travelling in SQL strings. Step (e) has to
search for both, and they have different search terms.

**What this is not: SQL injection.** The value interpolated into the statement
is a compile-time constant with no path from user input. Calling this injection
would be wrong on the facts and would send the variant search after
string-concatenated SQL generally, which is a different and much larger hunt.
Recorded explicitly so the class is not quietly widened later.

**Class boundary I could not close from the repository**: whether the credential
pair is also present outside the repository — a wiki, a screenshot, an issue
comment, a Docker image published from this tree. Everything below is scoped to
what is in the worktree and its history, and that scope is an assumption, not a
finding.

---

## Entry points

For a disclosed credential there is no untrusted-input path to enumerate — the
flaw is reached by *reading*, not by sending. Listing "no HTTP route" as if that
were reassuring would be the wrong shape of answer, so the entry points below
are split into three questions: where the credential can be read, where it is
used, and where it can be turned into a session.

### Where it can be read

| # | Location | Authentication required |
|---|---|---|
| 1 | `backend-symfony/src/Application/Meta/PreprodCopyService.php:15` | **None.** Public repository since `7e71739`; also in every clone, fork and mirror, and in history regardless of what HEAD says. |
| 2 | `docker-compose.yml:145` — `DATABASE_URL=postgresql://scambuster:postgres@postgres-preprod:5432/scambuster_preprod?...` | **None.** Same commit. |
| 3 | `.env.dist:49` — `POSTGRES_PASSWORD=postgres` | **None.** Not itself the leak (a template default, and `.gitleaks.toml` allowlists `.env.dist` deliberately) but it is what makes #1 and #2 *correct* on a stock clone. |

Entry point #2 is not in the SEC-001 register row, which cites only
`PreprodCopyService.php:15`. It is not a variant found by searching later — it is
a second instance of the reported finding that the report missed, and the fix
scope is wrong if it is treated as discovered afterwards.

Two detectors that should have seen #2 and did not, with the reason for each:

- **Semgrep** — the rule that found #1, `hardcoded-dsn-credentials`
  (`.semgrep/constitution.yml:157`), is scoped `languages: [php]` and
  `paths.include: backend-symfony/src/**` (`:158`, `:160-162`). A YAML file
  outside `backend-symfony/src` is outside the rule twice over. The rule did not
  fail; it was never pointed at the file.
- **Gitleaks** — `.gitleaks.toml` does not allowlist `docker-compose.yml`, so
  this is not an allowlist gap. It is a shape gap: the default ruleset matches
  high-entropy secret *shapes*, and `postgres` is a dictionary word. The register
  row already says this for #1; it is equally true for #2.

**What I did not check**: whether any other detector in the repository (CI
job, pre-commit hook, custom script) covers compose files for inline
credentials. I searched the two named above because the register names them.

### Where it is used

| # | Location | Authentication required |
|---|---|---|
| 4 | `backend-symfony/src/UI/Console/PreprodCopyConversationsCommand.php:14-17`, `#[AsCommand(name: 'preprod:copy-conversations')]` — the sole caller | **Shell access** in the backend container, or on a host with Docker access. |

The class has exactly two references in the codebase (grep `PreprodCopyService`
across `*.php`, `*.yml`, `*.yaml`, `*.sh`, `*.md`, `Makefile`): its own
declaration and this command's constructor. **No HTTP route, controller,
message handler, n8n workflow, Makefile target or documentation invokes it.** One
console command in this codebase does shell out to `bin/console`
(`EvalRunJudgeCommand.php:189`) with a fixed command name, so there is no
generic "run any console command" surface either.

### Where reading it becomes a login

| # | Location | Authentication required |
|---|---|---|
| 5 | `docker-compose.yml:29-30` — `postgres-preprod` publishes `"5433:5432"` with no bind address, so Docker binds `0.0.0.0` on the host and installs its own NAT rule | **None to reach the port.** Password authentication after that, using the password published at #1–#3. Precondition: the `preprod` profile is active (`docker-compose.yml:21`), i.e. `--profile preprod` or naming the service. |

This is the entry point that decides the severity, and it is the one the
register row does not mention. #1 without #5 is a bad habit in a file; #1 with
#5 is a credential and a listener that accepts it.

### Where the credential goes at runtime

| # | Location | Authentication required |
|---|---|---|
| 6 | Query text built at `PreprodCopyService.php:61-62` and `:94-95`, executed against the **dev** database | Whatever is needed to read that database's server log or `pg_stat_activity` — for `pg_stat_activity.query` in full, superuser or `pg_read_all_stats`; the log file is a filesystem read inside the `postgres` container/volume. |
| 7 | `PreprodCopyConversationsCommand.php:39, 51, 63, 76` — each catch block prints `$e->getMessage()` to the console | Same as #4 (whoever ran the command sees the output; console output is also whatever captured it — CI log, terminal scrollback, screen recording). |

#6 is not a hypothetical path. `dblink` is created **nowhere** in this
repository — no migration, no init SQL, no compose command; the only mentions
are the two call sites and the command's own note telling the operator to
install it by hand (`PreprodCopyConversationsCommand.php:64`). On a stock stack
the statement therefore *fails*, and Postgres's default
`log_min_error_statement = error` writes the offending statement — the full SQL,
credential included — into the server log. The error path is the normal path
here, which inverts the usual "only on failure" caveat.

**#7 is asserted more weakly than #6, on purpose.** Whether DBAL 3.10.5
(`composer.lock:156-157`) puts the SQL text into
`DriverException::getMessage()` I could **not verify**: `backend-symfony/vendor/`
is not installed in this environment. DBAL 2 embedded the statement in the
message; DBAL 3 moved the query onto the exception object, and which of those
3.10.5 does is exactly the kind of detail worth being wrong about quietly.
Treat #7 as unconfirmed pending a check against installed sources. #6 does not
depend on it.

---

## Impact

**What an attacker gets, concretely.** Against a host running the stack with
the `preprod` profile and an unmodified `.env`:
`psql -h <host> -p 5433 -U scambuster -d scambuster_preprod`, password
`postgres`. That grants read **and write** on `scambuster_preprod`, whose
`conversation` and `message` tables are enumerated in the copy statements at
`PreprodCopyService.php:50-73` and `:83-106`: `body_text`, `body_html`,
`headers`, `subject`, `reply_to`, `ts_msg`. That is scam correspondence with its
mail headers — third-party content and the honeypot personas' own addresses.
The account is `scambuster`, the database owner, so there is no read-only
ceiling.

**Preconditions, stated honestly, all three of which must hold:**

1. The `preprod` profile is running. A plain `docker compose up` does not start
   it (`docker-compose.yml:21`) — the profile was added precisely to stop that.
2. Port 5433 on that host is reachable by the attacker. On a developer laptop
   behind NAT that is a small set; on anything with a routable address it is the
   internet, because Docker's published-port rules are evaluated before most
   host firewall configurations.
3. The operator kept `POSTGRES_PASSWORD=postgres` from `.env.dist:49`.

**What the maintainer's rotation did and did not do.** It killed the credential
on *their* preprod instance, and that is why this run does not handle a live
secret. It did not change the fact that `.env.dist` ships `postgres` as the
default and the source ships `postgres` as the expected value, so the pair is
still correct by construction on every fresh clone. Rotation removed one
instance of the credential; the repository still contains the recipe for
recreating it. Any severity claim that rests on "it is rotated" is measuring the
wrong instance.

**Second-order impact: the fix that removes the literal does not stop the
leak.** Move the DSN to an environment variable and the value still lands in SQL
text at `:62` and `:95` on every run — and now it is a *live* credential rather
than a dead one, going into the dev database's error log (#6) and the command's
console output (#7). The one control that could redact it does not:
`PiiMaskingProcessor` (`backend-symfony/src/Infrastructure/EventListener/Security/PiiMaskingProcessor.php`)
masks emails, IPv4, IBAN, wallet and card shapes, and its email pattern requires
a dotted TLD — `scambuster:postgres@postgres-preprod` has no dot after the `@`,
so nothing matches and nothing is masked. Stated here rather than saved for the
fix stage because it changes what "fixed" has to mean, and that is a root-cause
question.

**What I could not establish, and how each would move the severity:**

- **Whether `scambuster_preprod` ever held real victim correspondence** rather
  than synthetic scambaiting data. If it did, this is a personal-data exposure
  and not a hygiene finding. Not answerable from the repository.
- **Whether anyone other than the maintainer ever ran the `preprod` profile**,
  and on what kind of address. Precondition 2 is the whole severity and I have
  no evidence either way.
- **Whether the credential was reused anywhere else** — another host, another
  service, an operator's password manager. Credential reuse is the usual reason
  a throwaway password is not throwaway, and the repository cannot answer it.
- **Whether the public repository was cloned or forked before rotation.** Fork
  counts and clone traffic are outside the worktree; this is the difference
  between "published" and "read".

**Deliberate understatement of the direct impact, and the reason this is still
worth a pipeline run.** The direct exposure is a profile-gated container holding
test data behind a weak password on a port that is usually not reachable — low.
What is not low is that the repository is public and internally consistent about
it: `.env.dist:52-53` instructs that the password "MUST match POSTGRES_PASSWORD
above", `PreprodCopyService.php:15` shows what a DSN looks like in this codebase,
and the detector that would object is scoped to a directory that
`docker-compose.yml` is not in. The next DSN written here will be written the
same way, and that is the impact worth spending a run on. Overstating this as a
data breach would cost the argument on the next finding; calling it a lint issue
would skip a decision the maintainer should get to make.
