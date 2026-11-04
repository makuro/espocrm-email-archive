# EspoCRM Email Archive

An EspoCRM extension that moves old emails into a read-only archive, so the `email`, `note`
and relationship tables of long-running instances stay small and fast.

Discussion: https://forum.espocrm.com/forum/feature-requests/128304-proposal-record-archiving-data-retention-willing-to-implement

[![CI](https://github.com/makuro/espocrm-email-archive/actions/workflows/ci.yml/badge.svg)](https://github.com/makuro/espocrm-email-archive/actions/workflows/ci.yml)

Status: 0.1.0, tested on EspoCRM 10.0.10 with MariaDB 11.4, MySQL 8.4 and PostgreSQL 17.

## What it does

- **Archive**: a scheduled job (or console command) copies old emails into an `ArchivedEmail`
  record (subject, addresses, body, parent, account, attachments and a JSON snapshot of the
  remaining data), verifies the copy, then deletes the original email together with its stream
  notes, notifications and relationship rows.
- **Read-only UI**: list, detail and full-text search for archived emails, plus an
  "Archived Emails" panel on Account, Contact, Lead, Opportunity and Case.
- **Retention**: optionally deletes archived emails permanently after a configurable period.

It deliberately does **not** restore emails. Archiving is one-way; the snapshot exists to keep
information, not to rebuild the original record (see the forum thread for why).

## Install

```bash
make package   # build/email-archive-<version>.zip
```

Upload the zip under *Administration > Extensions*. Installation creates the table, writes the
default settings and adds the panels to the detail layouts (existing customizations are kept).

Then, under *Administration > Email Archive*:

1. Leave the mode on **Dry run** and create a scheduled job "Archive Emails" (*Administration >
   Scheduled Jobs*), or run `bin/command archive-emails` to see how many emails qualify.
2. Switch to **Copy only** and check some archived emails.
3. Switch to **Move** once you trust the result.
4. After the first large move, run `OPTIMIZE TABLE email, note, email_email_address, email_user,
   entity_team;` (MySQL/MariaDB). InnoDB does not return space of deleted rows otherwise.

Users need *Archived Emails: read* in their role. Admins see everything.

Upgrading: upload the newer package; data, settings, the scheduled job and panel positions are
kept. Uninstalling deactivates the scheduled job and removes the panels, but keeps the
`archived_email` table, its attachments and the settings, so a reinstall continues where it
left off. Drop the table yourself if you want the archive gone.

### Settings

| Setting | Default | Meaning |
|---|---|---|
| Mode | Dry run | Dry run / Copy only / Move |
| Archive emails older than | 730 days | by sent date (creation date if never sent) |
| Skip emails of active records | on | e.g. cases not closed, opportunities not won/lost |
| Batch size / Max per run | 100 / 5000 | per scheduled run |
| Delete archived emails after | 0 (never) | retention, by sent date |

### Console

```bash
bin/command archive-emails                       # dry run, always, whatever the configured mode is
bin/command archive-emails --mode=copyOnly --limit=1000
bin/command archive-emails --mode=move --older-than-days=1095 --batch-size=500
bin/command archive-emails --purge                # retention only
bin/command archive-emails --setup                # default settings + panels (run by the installer)
```

## Design decisions

- **Only emails.** Their relationships are a fixed, known list (see `EmailPurger`). A generic
  archive for any entity type would have to walk all relationships through metadata.
- **Files are never copied or moved.** Archive attachments are attachment records that share
  the original's file (`sourceId`), created with core's `getCopiedAttachment()`. Originals are
  removed through the repository, so core's `RemoveFile` hook keeps a file while anything
  still references it (forwarded copies, the archive). Inline images in the body are rewritten
  to the archive copies.
- **Access follows current permissions**, not a snapshot: a user sees an archived email if
  they can read its parent or account right now. Lists are filtered in SQL (`ViaParent`
  access control filter), details and attachment downloads through the access checker. Archived
  emails without parent or account are visible to admins only. Nobody can create or edit
  archived emails through the API, only admins can delete them.
- **Safe by default.** Dry run is the default mode and the console command never moves without
  `--mode`. Each email is copied, verified (subject, body hash, attachment count) and only then
  deleted, each step in its own transaction. Re-running resumes an interrupted run. A lock
  file prevents overlapping runs.
- **What is left alone**: action history, webhook and email queue items referencing the email
  (logs that expire on their own). Tasks created from the email and replies to it are kept;
  only their reference to the archived email is cleared.

## Measurements

Generated data, 100k emails over 8 years, 51k of them older than two years (MacBook, Podman VM):

| | before | after move | after OPTIMIZE |
|---|---|---|---|
| `email` table | 1168 MB, 100k rows | 1163 MB, 49k rows | 580 MB |
| `note` table | 338 MB, 237k rows | 336 MB, 186k rows | 244 MB |
| `archived_email` table | – | 805 MB, 51k rows | 822 MB |
| Email text search (API) | 1200 ms | 252 ms | 243 ms |
| Archive text search (API) | – | – | 230 ms (word in 93 % of emails) |
| Account stream / panels / lists | ~180 ms | ~180 ms | ~180 ms |

Moving 51k emails took 289 s with verification. Total database size does **not** shrink by
archiving (the archive keeps a full-text index); it shrinks with retention. The gain is in the
live tables everyone works with every day.

## Known limits

- No GDPR "delete everything about this person" for the archive yet.
- Tested with generated data only; real mail imports (group folders, IMAP accounts, calendar
  invitations, mass email) have not been run through it yet.
- Only one archive run per server at a time (lock file); several web servers sharing one
  database are not coordinated.
- Group email folders, email accounts and inbound email assignments are kept only in the
  snapshot.

## Development

| Path | Purpose |
|---|---|
| `extension/src` | The extension (`manifest.json`, `files/`, `scripts/`) |
| `compose.yaml` | EspoCRM 10, MariaDB 11.4, daemon (Podman, rootless); the extension is bind-mounted |
| `dev/compose` | Overrides for MySQL and PostgreSQL, plain instance for the lifecycle test |
| `dev/seed/DevSeed` | Dev-only module: `dev-seed` (test data) and `dev-archive-check` (integration check) |
| `dev/measure` | `measure.sh` (table sizes, timings), `acl-check.sh` (API access as restricted user) |
| `dev/ci` | `functional.sh`, `lifecycle.sh` and `make-env.sh`, used by CI and locally |

```bash
podman machine start   # macOS only
dev/ci/make-env.sh     # .env with random passwords
make reset             # fresh instance on http://localhost:8080 (user: admin)
make seed              # ~100k emails, 30k cases, 237k notes, ~500-750 MB attachments (SCALE=2 for double)
make measure
make check             # needs archived emails; creates the restricted test user on first run
make package
```

Every target takes `DB=mysql` or `DB=postgres` (default `mariadb`); each database runs as its own
compose project with its own volumes, all on port 8080, so run one at a time.

The seeder writes rows directly with multi-row SQL (no hooks), mirroring what Espo creates when
importing mail. The containers run with `userns_mode: keep-id:uid=33,gid=33`, so bind-mounted
sources stay owned by the host user while Espo sees them as `www-data`.

### Tests

| Command | What it proves |
|---|---|
| `make ci DB=…` | On a fresh instance: seed, setup, dry run (writes nothing), copy only (keeps originals), move (all candidates, no failures), then `dev-archive-check` and `acl-check.sh` |
| `dev-archive-check` | Move and retention on fixtures created through the ORM: original, notes, notifications and relationship rows are gone; tasks and replies keep existing; attachment files shared with other records survive; inline images point to the archive copy |
| `acl-check.sh` | A restricted user sees exactly the archived emails of records they can read, in lists, search, details and downloads; nobody can create or edit |
| `make lifecycle` | On a plain instance (port 8091): install the previous version, use it, upgrade (data, settings, job and panels kept), uninstall (job deactivated, panels removed, app works, data kept), reinstall |

CI runs `make ci` for MariaDB, MySQL and PostgreSQL, plus `make lifecycle` and the package build,
on every push.

### Release

Set the version in `extension/src/manifest.json`, commit, then tag it:

```bash
git tag v0.1.0 && git push origin v0.1.0
```

The release workflow runs the full CI and attaches the package to a GitHub release.

## License

AGPL-3.0, like EspoCRM.
