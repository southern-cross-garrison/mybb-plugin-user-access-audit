# MyBB Access Audit Plugin

Checks every forum member's groups against their 501st Legion record, lists the members in
the wrong groups, and fixes them one member at a time.

## Features

- **Every member checked.** Each member's Legion ID, from the profile field you choose, is
  looked up on the 501st Legion's API.
- **Missing Legion IDs found.** Members with no Legion ID on their profile are looked up by
  email address in the background, and any Legion ID found is saved to their profile.
- **Only its own groups touched.** The audit changes only your unit members, reserve
  members, retired members and 501st members groups, and each chosen squad's group. A
  member's other groups are left alone.
- **One member at a time.** Each row has its own Fix button. There is no bulk fix.
- **A link to their record.** Each member's Legion ID links to their page on 501st.com.
- **Their posts a click away.** Each row shows the member's post count, linked to a search
  of their posts.
- **Shared Legion IDs flagged.** Accounts with the same Legion ID are noted on each other's
  rows.
- **Safe when the API is down.** A member the API did not answer for is shown as unchecked
  and cannot be changed.

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
   profile field and the four groups, and any squads you want audited. Saving them starts
   looking up members with no Legion ID by email address (see below).

## Upgrading

Upload the new release over the old one, then deactivate and activate the plugin in
Configuration -> Plugins.

## Uninstalling

Deactivating removes the page from the Admin CP, turns the scheduled task off and keeps the
settings. Uninstalling removes the settings, the plugin's tables and its scheduled task too.
Neither changes members' groups or the Legion IDs saved to their profiles.

## Settings

Under Users & Groups -> Access Audit -> Settings:

| Setting                 | What it does                                                       |
| ----------------------- | ------------------------------------------------------------------ |
| 501st Legion API        | Where records are fetched from. Starts as `https://api.501st.com`. |
| Unit                    | Your garrison or outpost, from the 501st's list of units.          |
| Legion ID profile field | The custom profile field that holds members' Legion IDs.           |
| Unit members group      | For Active members of your unit.                                   |
| Reserve members group   | For Reserve members of your unit.                                  |
| Retired members group   | For Retired members of your unit.                                  |
| 501st members group     | For Active and Reserve members of any unit, your own included.     |

The four groups must all be different.

### Squads

The **Squads** table lists your unit's squads. Choose a squad's group to audit it, or leave
it at Choose... to not audit it. Each squad's group must be different from every other
squad's and from the four groups above.

A squad's unit is taken from its officers' 501st records, so a squad with no officer on a
public record is not listed. After changing the unit, the old unit's squads that still have
a group stay listed until you set them back to Choose.... A unit with no squads has no
Squads table.

While the API is down, squads already chosen can be kept, changed or removed, but new ones
cannot be added.

The settings also appear under Configuration -> Settings -> Access Audit as plain text
boxes; use the plugin's own tab instead.

## The rules

A Legion ID is read as its digits, so "TK-12345", "TK 12345" and "12345" are all 12345.

| The member                                                      | Belongs in                                   |
| --------------------------------------------------------------- | -------------------------------------------- |
| Has no Legion ID (or none with a number in it)                  | None of the groups                           |
| Has no public record                                            | None of the groups (but see below)           |
| Is Active or Reserve in another unit (an outpost is a unit too) | The 501st members group                      |
| Is Active in your unit                                          | The unit members and 501st members groups    |
| Is Reserve in your unit                                         | The reserve members and 501st members groups |
| Is Retired in your unit                                         | The retired members group only               |
| Has any other status on the 501st's records                     | None of the groups                           |

A Retired member of another unit belongs in none of the groups.

A member who is Active in a chosen squad, whichever unit it is in, also belongs in that
squad's group, and everybody else is removed from it. Squads that are not chosen, and their
groups, are ignored.

Members with no Legion ID on their profile are looked up by email address (see below), and
are not checked until then.

"No public record" is the API's answer both for a private record and for an ID that does
not exist. A member with a private record who is Active is flagged for removal; they can
make their record public on 501st.com, or you can leave their row alone. Retired members'
records are often private, and a private record cannot be told from a retired one, so a
member with no public record who is already in the retired members group is left in it
(and taken out of the others). Nobody is put in the retired members group without a public
Retired record.

## Legion IDs found by email address

A member with no Legion ID on their profile (or none with a number in it) is looked up by
their forum email address, which is matched against approved 501st members' primary and
secondary addresses. A Legion ID found is saved to their Legion ID profile field. No groups
are changed; the audit then checks them like any other member.

The lookups are made by a scheduled task, Access Audit: Legion IDs by email address, under
Tools & Maintenance -> Task Manager. It runs every five minutes and makes up to 50 lookups
every 15 minutes (the API allows 60) until everybody has been looked up - about three hours
for 600 members. Saving the settings starts it straight away. MyBB runs scheduled tasks on
forum page views, so on a quiet forum it runs less often.

Users & Groups -> Access Audit -> **Legion IDs** shows how many members are still to be
looked up and about how long that will take, how many had no match, the Legion IDs saved,
and when the task next runs. **Look Up a Batch Now** runs a batch straight away, from the
same allowance.

- A Legion ID a member typed is never replaced.
- A saved Legion ID that is later removed from the member's profile is not saved again.
- Banned members are not looked up.
- An address with no match is looked up again after a week.
- If the API does not answer, or turns lookups away, the member is looked up in a later
  batch.

Until a member has been looked up, the audit shows them as not looked up yet and does not
change their groups.

Email addresses are sent to the 501st Legion API only for members with no Legion ID.

## Shared Legion IDs

When more than one account has the same Legion ID, however it was typed, each of their rows
lists the others, even if their groups are correct. Banned accounts are included. The audit
applies the rules to each account separately, so check which account is really the member's
before pressing Fix on either.

The table is sorted by Legion ID, then username, with members who have none last.

## Banned members

Members of a banned group are skipped.

## How a fix changes groups

- Joining a group adds it to the member's additional groups.
- Leaving a group removes it from their additional groups. If it is their primary group, it
  is replaced with the group they now belong in - the reserve members group for a member of
  your unit gone to the reserve, the retired members group for one who has retired, the unit
  members group for one back from the reserve, the 501st members group for one gone to
  another unit - or with Registered if they belong in none. A squad's group is never made
  primary.
- If the removed group was their display group, MyBB shows their primary group instead.

The change is worked out again from the member's current groups when you press Fix. Every
fix is written to the administrator log.

## Who can use it

Administrators with "Can manage users?" under Admin Permissions -> Users & Groups.

## Checking against the 501st

Answers from the API are kept for 15 minutes. **Check Again** clears them, except the
answers to email address lookups. An answer the API could not give is never kept.

## Database tables

| Table                           | Holds                                                                                           |
| ------------------------------- | ----------------------------------------------------------------------------------------------- |
| `access_audit_lookups`          | The API's recent answers, by Legion ID                                                          |
| `access_audit_email_lookups`    | The API's answers to email address lookups, by a hash of the address - never the address itself |
| `access_audit_found_legion_ids` | The Legion IDs the email address lookups have saved to members' profiles                        |
| `access_audit_schema_upgrades`  | Which schema upgrades the board has had                                                         |

## License

Apache License 2.0 - see LICENSE and NOTICE.
