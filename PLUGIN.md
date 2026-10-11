# MyBB Access Audit Plugin

Checks every forum member's groups against their 501st Legion record. Every Active or
Reserve 501st member belongs in a 501st members group; your unit's own Active members belong
in your unit members group as well, and its Reserve members in your reserve members group;
your unit's Retired members belong in your retired members group and nothing else;
Active members of a squad you choose belong in that squad's group; everybody else belongs in
none of them. The audit lists who is in the wrong place and fixes
them one at a time, after you have looked.

## Features

- **Every member checked.** Each member's Legion ID, from the profile field you choose, is
  looked up on the 501st Legion's API.
- **Missing Legion IDs found.** A member who has not put their Legion ID on their profile is
  looked up by their email address, and Fix saves the Legion ID found along with their
  groups.
- **Only its own groups touched.** The audit manages your unit members, reserve members,
  retired members and 501st members groups, and a group for each squad you choose, and nothing else. Command staff, moderators, administrators and any other
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
   profile field and the four groups, and any squads you want audited.

## Upgrading

Upload the new release over the old one, then deactivate and activate the plugin in
Configuration -> Plugins.

## Uninstalling

Deactivating removes the page from the Admin CP and keeps the settings. Uninstalling removes
the settings and the plugin's table too. Members' groups are never changed by either.

## Settings

Under Users & Groups -> Access Audit -> Settings:

| Setting                 | What it does                                                                  |
| ----------------------- | ----------------------------------------------------------------------------- |
| 501st Legion API        | Where records are fetched from. Starts as `https://api.501st.com`.            |
| Unit                    | Your garrison or outpost, from the 501st's own list of units.                 |
| Legion ID profile field | The custom profile field that holds members' Legion IDs.                      |
| Unit members group      | Where Active members of your unit belong.                                     |
| Reserve members group   | Where Reserve members of your unit belong.                                    |
| Retired members group   | Where Retired members of your unit belong, and in none of the others.         |
| 501st members group     | Where every Active or Reserve 501st member belongs, your unit's own included. |

The four groups must all be different groups.

### Squads

Below those, the **Squads** table lists your unit's squads, one per row, each with the group its
Active members belong in. Squads are optional and come on top of the rules above. Choose a
squad's group to audit it, and set it back to Choose... to stop auditing it.

Each squad's group must be a group of its own, not another squad's and not one of the four
groups above. Only the squads of the unit saved above are listed. The 501st's list of squads does not
say which unit each belongs to, so the plugin looks up each squad's officers and goes by the
unit on their records. A squad with no officer on a public record cannot be placed, and is
not listed. If you change the unit, saving lists the new unit's squads, along with the old
unit's that still have a group; set those back to Choose... to save the change. A unit with no
squads on the 501st's records has no Squads table at all.

While the API is down, the squads already chosen can still be kept, moved to another group or
taken off, but new ones cannot be added.

They also appear under Configuration -> Settings -> Access Audit, but as plain boxes; use
the plugin's own tab, which offers the lists.

## The rules

A Legion ID is read as its digits, so "TK-12345", "TK 12345" and "12345" are all 12345.

| The member                                                      | Belongs in                                   |
| --------------------------------------------------------------- | -------------------------------------------- |
| Has no Legion ID (or none with a number in it)                  | None of the groups                           |
| Has no public record                                            | None of the groups                           |
| Is Active or Reserve in another unit (an outpost is a unit too) | The 501st members group                      |
| Is Active in your unit                                          | The unit members and 501st members groups    |
| Is Reserve in your unit                                         | The reserve members and 501st members groups |
| Is Retired in your unit                                         | The retired members group only               |
| Has any other status on the 501st's records                     | None of the groups                           |

A Retired member of another unit belongs in none of the groups.

On top of that, a member who is Active in one of the squads you chose belongs in that squad's
group, whichever unit the squad is in. Nobody else belongs in it: a member who has left the
squad, gone to the reserve or is no longer a 501st member is taken out of it. A squad you
have not chosen means nothing to the audit, and neither does its group.

A member with no Legion ID on their profile is looked up by email address first (see below),
and a Legion ID found that way is the one the rules above go by.

"No public record" is what the API says both for a member who has made their record private
and for an ID that does not exist, and in practice it is mostly members who have left or
retired. An active member with a private record will be flagged for removal; they can make
their record public on 501st.com, or you can leave their row alone. The same goes for a retired
member: only a public record says they are Retired, so one with a private record will be
flagged for removal from the retired members group.

## Legion IDs found by email address

A member with no Legion ID on their profile - or none with a number in it - is looked up on
the API by their forum email address, which finds the approved 501st member with that
address as their primary or secondary one. If one is found:

- their row says "Found by email address" with the Legion ID, linked to its record on
  501st.com, and the audit judges them by that record as if they had typed it in;
- the change offered starts with "Set Legion ID to 12345", followed by whatever their groups
  need for that record - which may be nothing else;
- pressing Fix saves the number to their Legion ID profile field, then changes their groups.

A Legion ID a member has typed in is never replaced, even if their email address belongs to
somebody else's. Banned members are not looked up. If the API does not answer for a member's
email address, they are shown as not checked and cannot be changed, just as when it does not
answer for a Legion ID; an address no approved member has is an answer, and means no Legion
ID.

A Legion ID found by email counts towards shared Legion IDs like any other, so an account
whose address matches an ID another account has typed in is flagged on both rows.

Members' email addresses are sent to the 501st Legion API for this, and only for members
with no Legion ID.

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

A Legion ID found by email is saved first, and only if their Legion ID field is still as it
was when the audit worked it out; if somebody has changed it in the meantime, nothing is
changed.

- Joining a group adds it to the member's additional groups.
- Leaving a group takes it out of their additional groups. If it is their primary group,
  which a member cannot be without, it is swapped for the group they now belong in - the
  reserve members group for a member of your unit gone to the reserve, the retired members
  group for one who has retired, the unit members group for one back from the reserve, the 501st members group for one gone to another unit - or for
  Registered when they belong in none. A squad's group is never the one made primary in
  its place.
- If the group taken away was the one shown beside their name, MyBB goes back to showing
  their primary group.

The change is worked out again when you press Fix, from the member's groups as they are at
that moment, so it is never one the page showed before somebody else changed them. Every fix
is written to the administrator log.

## Who can use it

Administrators with "Can manage users?" under Admin Permissions -> Users & Groups.

## Checking against the 501st

Answers from the API are kept for 15 minutes, so fixing members one by one does not ask the
API about everybody again after each - email address lookups included. **Check Again**
forgets them. An answer the API could not give is never kept, so it is asked for again next
time.

## Database tables

| Table                        | Holds                                                                                                  |
| ---------------------------- | ------------------------------------------------------------------------------------------------------ |
| `access_audit_lookups`       | The API's recent answers, by Legion ID                                                                 |
| `access_audit_email_lookups` | The API's recent answers to email address lookups, by a hash of the address - never the address itself |

Upgrading to the release with squads forgets the answers kept so far, so the first audit
afterwards asks the API about everybody again.
| `access_audit_schema_upgrades` | Which schema upgrades the board has had |

## License

Apache License 2.0 - see LICENSE and NOTICE.
