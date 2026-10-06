## Why

The Zuiddrecht example site installs with one command, but nobody has seen the area behind its
"Mijn Zuiddrecht" button: the site ships no resident and no cases, and signing in needs a DigiD
broker. The design shows a resident, Sanne de Vries, with her overview, her cases and one case
page. An administrator of a demo or test instance has to be able to get that with one command,
sign in as that resident without a broker, and take it all off again.

## What Changes

- **A resident declaration**, `lib/Settings/sites/residents/zuiddrecht.json`: the resident (a
  Nextcloud account id, a name, the audience and organisation of her portal account), the way in
  (sign-in mode `nextcloud` with its card), and the objects that fill her area: five dossiq cases
  in different states, the open question on one of them and four messages. Values the install
  fills in are written as `{{subject}}`, `{{lookup:name}}`, `{{object:key.field}}`, `{{date:n}}`
  and `{{datetime:n HH:MM}}`, so a case type is found by its identifier, a message quotes the
  number the register gave its case, and every date is counted from the day of the install.
- **`occ portaliq:example-resident:install <site>`** makes the Nextcloud account (password shown
  once, or from `OC_PASS` with `--password-from-env`), the portal account, adds the sign-in mode to
  the portal and writes the objects in order, each into the register and schema it names. It
  writes only what its record lacks, never takes over an account it did not make (`--user` picks
  another id), names every object it could not write with the reason, reads the instance back and
  exits 2 when anything is missing or a key was not kept.
- **`occ portaliq:example-resident:remove <site>`** deletes what the record names and nothing
  else, takes the mode off the portal when the install added it, and names what OpenRegister
  would not delete (a case is an archive record) so a new install uses it again.
- **The sign-in mode `nextcloud` is the demo door**, not the test sign-in: it needs a password,
  mints a session only for a Nextcloud account with an active portal account under the same id,
  and opens nothing for other accounts. The docs say what the test sign-in is and why it stays off.

## Depends on

- `example-site-zuiddrecht` (the site and its install record) and `site-resident-portal-design`
  in dossiq (the overview, case and message pages drawn from the contribution). No schema key is
  added, so the register version stays.

## Out of scope

Documents on a case (a file on the case object, an informatieobject and its join), a status
history dossiq's own transitions write (the seed writes the entered-at moments only), the
Berichtenbox and the exact case numbers of the design (the register numbers a case itself).
