# Working on this repo

## Layout

- `plugin/` is the source of truth for the plugin. Nothing is edited inside `test-forum/`.
  It mirrors a MyBB forum root, which is how MyBB expects a plugin package to be laid out:
  deploying to real hosting is copying the contents of `plugin/` over the forum's root
  directory, and `scripts/deploy.sh` does exactly that. Anything that must not be uploaded
  to a web root (docs, tooling) stays out of `plugin/` - the plugin's documentation is
  `PLUGIN.md` for that reason.
  - `plugin/inc/plugins/access_audit.php` - plugin metadata, hook registration,
    install/activate/deactivate/uninstall.
  - `plugin/inc/plugins/access_audit/inc/access_audit_functions.php` - the rules, the group
    plan and applying it.
  - `plugin/inc/plugins/access_audit/inc/access_audit_api.php` - the 501st API client and
    the table of kept answers.
  - `plugin/inc/plugins/access_audit/inc/access_audit_install.php` - schema, upgrades,
    settings, uninstall.
  - `plugin/inc/plugins/access_audit/upgrades/` - schema upgrades (see below).
  - `plugin/admin/modules/user/access_audit.php` - the Admin CP page, Users & Groups ->
    Access Audit, with its Audit and Settings tabs.
  - `plugin/inc/languages/english/admin/user_access_audit.lang.php` - its strings. MyBB
    loads it by name for the page, as `<module>_<action>`.
  - `plugin/jscripts/access_audit/access-audit-admin.css` - the page's CSS.
- `test-forum/` is a disposable MyBB tree (gitignored), built by `scripts/bootstrap.sh`.
- `tests/` is the Playwright end-to-end suite, with the 501st API stand-in in
  `tests/stubs/`; `scripts/` holds the environment tooling.

## Everyday commands

```bash
./scripts/bootstrap.sh          # bring the environment up (first run, or after a wipe)
./scripts/bootstrap.sh --fresh  # wipe test-forum/ and the database and rebuild
./scripts/deploy.sh             # push plugin/ changes into test-forum/
pnpm test                       # run the suite
pnpm test:ui                    # run it interactively
```

After changing anything under `plugin/`, run `./scripts/deploy.sh`. After changing
`access_audit_install()`/`access_audit_activate()` or `scripts/provision.php`, re-take the
baseline from a restored database, so the last test run's leftovers are not baked in:

```bash
./scripts/db-restore.sh && ./scripts/deploy.sh \
  && docker compose exec -T web php /dev/stdin < scripts/provision.php \
  && ./scripts/db-snapshot.sh
```

## Things worth knowing

- The plugin has to run on PHP 7.4 (the garrison's host) and the latest 8.x, and CI runs
  the suite on both. Write for 7.4 - no `str_contains()`, `match`, `?->`, named arguments
  or other 8.x additions - and for 8's strictness: every test fails on a logged warning.
  Run the suite on `PHP_VERSION=8.5` before calling a change done.
- The audit only ever changes the three configured groups (unit, reserve and 501st members). Anything else a member is in is
  none of its business, and `access_audit_plan()` is where that is kept true - every change
  to group handling goes through it and gets a test in `audit.spec.ts`.
- There is deliberately no "fix everybody" action. Each fix is a person gaining or losing
  access, and the page is built for looking at each one first. Do not add a bulk action.
- An API that is down, slow or answers nonsense is never a reason to change anybody's
  groups: such a member is `ACCESS_AUDIT_UNCHECKED` and has no plan. A "Classified Record"
  is an answer, and means not active.
- A fix is worked out again on the server from the member as they are then, never taken
  from the posted form, and `access_audit_apply()` only writes if their groups are still
  the ones it was worked out from.
- The page is guarded by MyBB's own `user-users` permission ("Can manage users?") rather
  than one of its own: a declared permission starts out "No" for every existing
  administrator.
- `insert_query()`/`update_query()` quote values but do not escape them. Every string from
  input, the API or board data goes through `$db->escape_string()`, and everything shown
  goes through `htmlspecialchars_uni()` - Legion IDs are whatever members typed, and names
  come from the API.
- In the Admin CP, `verify_post_check()` returns false rather than stopping the page, so
  act on its result.
- Schema changes are a new file in `upgrades/` (`0001_something.php`, returning a closure
  that takes `$db`), never an edit to the `CREATE TABLE` in
  `access_audit_install_database()` once released. Every step in an upgrade is guarded so
  it can run twice; a table an upgrade creates is added to `access_audit_plugin_tables()`
  so uninstall drops it.
- The suite holds a lock (`.devenv/suite.lock`) while it runs. Never delete it, and never
  run `db-restore.sh`, `db-snapshot.sh` or `provision.php` while a run holds it.
- A test that changes something the per-test reset does not cover puts it back in a
  `finally`, or with `withSettings()`.
- `plugin/inc/plugins/access_audit/LICENSE` and `NOTICE` are copies of the root ones,
  shipped with the plugin; `plugin-setup.spec.ts` fails if they drift.

## Code style

PHP follows the surrounding code: four-space indents, braces on their own lines,
`if(`/`foreach(` with no space, `array()` rather than `[]`, double-quoted SQL with
`TABLE_PREFIX`, the `IN_MYBB` guard at the top of every file, and a docblock on every
function. Comments say why, in sentences. TypeScript is formatted by Prettier
(`pnpm format`).
