# MyBB Access Audit plugin

Audits forum members' group access against the 501st Legion's API. Each member's Legion ID
is looked up, and the Admin CP lists who should be in the unit members group, who should be
in the 501st members group, and who should be in neither, with a Fix button for each. See
[PLUGIN.md](PLUGIN.md) for what the plugin does; this file covers the development
environment and the test suite.

## Quick start

Requires Docker and pnpm 12+, which fetches the Node version the project pins.

```bash
pnpm install
pnpm exec playwright install chromium
./scripts/bootstrap.sh     # ~2 minutes on a cold start
pnpm test
```

`bootstrap.sh` downloads the pinned MyBB release into `test-forum/`, starts the containers,
runs MyBB's installer non-interactively, deploys the plugin, provisions the fixtures, and
takes a database snapshot the suite resets to.

When it finishes:

|            |                                                                                |
| ---------- | ------------------------------------------------------------------------------ |
| Forum      | <http://localhost:8100>                                                        |
| Admin CP   | <http://localhost:8100/admin/> - `admin` / `adminpass123`                      |
| Database   | `localhost:3327` - `mybb` / `mybbpassword`                                     |
| phpMyAdmin | `docker compose --profile tools up -d phpmyadmin` then <http://localhost:8101> |

The ports are deliberately not the events plugin's (8080/3307/8081) or the reactions
plugin's (8090/3317/8091), so the environments can run side by side. They come from `.env`
(copy `.env.example`) if they clash with something else.

The plugin has no front end, so unlike its siblings the test forum runs MyBB's own theme,
not the garrison's.

## Development loop

```bash
./scripts/deploy.sh                  # copy plugin/ into test-forum/
pnpm test                            # whole suite
pnpm test audit                      # one spec
pnpm test:ui                         # interactive
```

`plugin/` is the source of truth. Nothing is edited directly inside `test-forum/`.

Changing anything in `access_audit_install()` / `access_audit_activate()` or in
`scripts/provision.php` needs the baseline snapshot refreshed. Restore first, so the
snapshot does not pick up whatever the last test run left behind:

```bash
./scripts/db-restore.sh
./scripts/deploy.sh
docker compose exec -T web php /dev/stdin < scripts/provision.php
./scripts/db-snapshot.sh
```

If the environment gets into a strange state, `./scripts/bootstrap.sh --fresh` rebuilds it
from nothing.

## PHP versions

The plugin supports PHP 7.4 (what the garrison's host runs) through the latest 8.x. The web
container runs 7.4 unless `PHP_VERSION` says otherwise, in `.env` or on the command line:

```bash
PHP_VERSION=8.5 ./scripts/bootstrap.sh
pnpm test
```

Each version builds its own image (`mybb-access-audit-web:php<version>`). Run the suite on
8.x before calling a change done: on 8 an undefined variable or index is a logged warning,
and every test fails on a logged warning.

## How the suite works

`tests/e2e/` drives a real browser against a real MyBB install.

**The 501st API is a stand-in.** Global setup copies
[tests/stubs/legion-api.php](tests/stubs/legion-api.php) into the forum root and
provisioning points the plugin at it, so the suite never reaches the real API. A test says
what it answers with `stubLegionApi()` and `stubUnits()` (`tests/helpers/legion-api.ts`);
a Legion ID nobody stubbed answers 503, the API being down. Every request is logged, so a
test can check what was asked.

**Isolation.** The suite runs single-worker against one MyBB instance. Global setup
restores the provisioned database snapshot and caches a login for each administrator.
Before each test, every member's groups and Legion ID and the plugin's settings are put
back as provisioned, the plugin's kept answers and the administrator log are emptied, and
the API stand-in goes back to its stock list of units and no members.

**Warnings fail tests.** The board logs PHP warnings and SQL errors rather than showing
them, and every test fails if anything was logged while it ran (`tests/helpers/fixtures.ts`).

**Fixtures.** `scripts/provision.php` creates three groups (Garrison Members and 501st
Members, which the audit manages, and Command Staff, which it must leave alone), a Legion
ID profile field, and these members, and writes `test-forum/access-audit-fixtures.json`
with their ids:

| User                   | Legion ID | Groups                          | Usual stubbed record |
| ---------------------- | --------- | ------------------------------- | -------------------- |
| `admin`                | -         | Administrators (super admin)    |                      |
| `staffadmin`           | -         | Administrators (not super)      |                      |
| `unitmember`           | TK-10001  | Garrison Members                | Active, this unit    |
| `legionmember`         | TK-10002  | 501st Members                   | Active, another unit |
| `commander`            | TK-10003  | Command Staff, Garrison Members | Active, this unit    |
| `guestmember`          | -         | -                               |                      |
| `trooper1`, `trooper2` | TK-2000x  | -                               | Classified Record    |
| `banned`               | TK-30001  | Banned, Garrison Members        | Active, this unit    |

`stubFixtureMembers()` stubs the usual records, which the provisioned groups all match.
All users but `admin` share the password `testpass123`.

## What is covered

| Spec           | Covers                                                                                                                                                                                           |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `audit`        | Every rule, Legion ID formats, the order, shared Legion IDs, the profile link, groups left alone, primary and display groups, the API down or answering nonsense, kept answers, fixing, escaping |
| `settings`     | The units from the API, profile fields and groups, saving, validation, the API down, changing the API's address                                                                                  |
| `permissions`  | "Can manage users?" opening and closing the page and the Fix action                                                                                                                              |
| `plugin-setup` | What install creates, the menu entry, deactivating and reactivating, uninstall leaving nothing and members' groups alone, the shipped license copies                                             |

## Continuous integration

`.github/workflows/e2e.yml` runs the suite on every push and pull request, once on PHP 7.4
and once on 8.5, after linting every PHP file in `plugin/` and `scripts/`.

Once both versions pass, the _Package the plugin_ job uploads the contents of `plugin/` as
the run's `mybb-plugin-user-access-audit-<sha>` artifact - a zip that expands straight over
a forum root.

### Releasing

Bump `"version"` in `plugin/inc/plugins/access_audit.php`, commit, then tag that commit to
match:

```bash
git tag v1.0.0
git push origin v1.0.0
```

Once both PHP versions pass, the _Publish the release_ job attaches
`mybb-plugin-user-access-audit-<tag>.zip` to a GitHub Release. It refuses if the tag and
the plugin's version disagree.

## License

Apache License 2.0 - see [LICENSE](LICENSE) and [NOTICE](NOTICE).
