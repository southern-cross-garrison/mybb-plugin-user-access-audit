# MyBB Access Audit Plugin

Checks every forum member's groups against their 501st Legion record. Every active 501st
member belongs in a 501st members group, members of your unit belong in your unit's group
as well, and everybody else belongs in neither. The audit lists who is in the wrong place and fixes them
one at a time, after you have looked.

## Features

- **Every member checked.** Each member's Legion ID, from the profile field you choose, is
  looked up on the 501st Legion's API.
- **Only two groups touched.** The audit manages your unit members group and your 501st
  members group, and nothing else. Command staff, moderators, administrators and any other
  group a member is in are left exactly as they are.
- **One member at a time.** Each row has its own Fix button. There is deliberately no way to
  fix everybody at once: every change gives somebody access or takes it away.
- **A link to their record.** Each member's Legion ID links to their page on 501st.com, so
  you can check it before you press Fix.
- **Their posts a click away.** Each row shows how many posts the member has made, linked to
  a search of them, so you can see whether an account is really in use.
- **Shared Legion IDs flagged.** Two accounts on the same Legion ID are noted on each other's
  rows, and the table is sorted by Legion ID so they sit together.
- **Safe when the API is down.** A member the API could not answer for is shown as such, and
  cannot be changed until it does.

## Requirements

- MyBB 1.8 (tested on 1.8.41).
- PHP 7.4 or later, with the curl extension.
- A custom profile field your members put their Legion ID in.

## Installation

1. Upload the contents of the release zip to your forum's root directory. The zip's folders
   (`inc/`, `admin/`, `jscripts/`) line up with your forum's own.
2. In the Admin CP, go to Configuration -> Plugins and click **Install & Activate** beside
   Access Audit.
3. Go to Users & Groups -> Access Audit -> Settings and choose your unit, the Legion ID
   profile field and the two groups.

## Upgrading

Upload the new release over the old one, then deactivate and activate the plugin in
Configuration -> Plugins.

## Uninstalling

Deactivating removes the page from the Admin CP and keeps the settings. Uninstalling removes
the settings and the plugin's table too. Members' groups are never changed by either.

## Settings

Under Users & Groups -> Access Audit -> Settings:

| Setting                 | What it does                                                                                           |
| ----------------------- | ------------------------------------------------------------------------------------------------------ |
| 501st Legion API        | Where records are fetched from. Starts as `https://api.501st.com`.                                     |
| Unit                    | Your garrison or outpost, from the 501st's own list of units.                                          |
| Legion ID profile field | The custom profile field that holds members' Legion IDs.                                               |
| Unit members group      | Where active members of your unit belong.                                                              |
| 501st members group     | Where every active 501st member belongs, your unit's own included. Must not be the unit members group. |

They also appear under Configuration -> Settings -> Access Audit, but as plain boxes; use
the plugin's own tab, which offers the lists.

## The rules

A Legion ID is read as its digits, so "TK-12345", "TK 12345" and "12345" are all 12345.

| The member                                           | Belongs in              |
| ---------------------------------------------------- | ----------------------- |
| Has no Legion ID (or none with a number in it)       | Neither group           |
| Is not Active on the 501st's records - Reserve, say  | Neither group           |
| Has no public record                                 | Neither group           |
| Is Active in another unit (an outpost is a unit too) | The 501st members group |
| Is Active in your unit                               | Both groups             |

"No public record" is what the API says both for a member who has made their record private
and for an ID that does not exist, and in practice it is mostly members who have left or
retired. An active member with a private record will be flagged for removal; they can make
their record public on 501st.com, or you can leave their row alone.

## Shared Legion IDs

The 501st's record belongs to the Legion ID, not to the forum account, so the API cannot
tell the real member from somebody who has typed their ID into a second account. When more
than one account has the same Legion ID - however it was typed - each of their rows says
which other accounts have it, and they are listed even if their groups are right. Banned
accounts count, since an ID shared with one is how a ban gets dodged.

The audit still says what the rules say for each account, so a second account on an active
member's ID will be flagged to join the group. Check which account is really theirs before
pressing Fix on either.

The table is sorted by Legion ID, then username, with members who have none last, so
accounts sharing an ID are next to each other.

## Banned members

Members of a banned group are skipped: MyBB puts their groups back as they were when a ban
is lifted, so anything changed now would be undone then.

## How a fix changes groups

- Joining a group adds it to the member's additional groups.
- Leaving a group takes it out of their additional groups. If it is their primary group,
  which a member cannot be without, it is swapped for the group they now belong in, or for
  Registered when they belong in neither.
- If the group taken away was the one shown beside their name, MyBB goes back to showing
  their primary group.

The change is worked out again when you press Fix, from the member's groups as they are at
that moment, so it is never one the page showed before somebody else changed them. Every fix
is written to the administrator log.

## Who can use it

Administrators with "Can manage users?" under Admin Permissions -> Users & Groups.

## Checking against the 501st

Answers from the API are kept for 15 minutes, so fixing members one by one does not ask the
API about everybody again after each. **Check Again** forgets them. An answer the API could
not give is never kept, so it is asked for again next time.

## Database tables

| Table                          | Holds                                   |
| ------------------------------ | --------------------------------------- |
| `access_audit_lookups`         | The API's recent answers, by Legion ID  |
| `access_audit_schema_upgrades` | Which schema upgrades the board has had |

## License

Apache License 2.0 - see LICENSE and NOTICE.
