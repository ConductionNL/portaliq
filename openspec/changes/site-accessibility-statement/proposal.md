---
kind: code
depends_on: []
---

# Proposal: site-accessibility-statement

Woo capability programme, round 1, wave 1. Rows 6.7 and 15.2.

| row | text | our rating today |
| --- | --- | --- |
| 6.7 | The portal meets WCAG 2.2 AA, and says so in a statement | partial (production) |
| 15.2 | An accessibility statement is generated from the product's own state | no |

No Ruben decision governs these rows.

## Why

A Dutch public body publishes an accessibility statement (toegankelijkheidsverklaring) for each
website, in the national model, and registers it (Besluit digitale toegankelijkheid overheid; the
model and the register are at toegankelijkheidsverklaring.nl and digitoegankelijk.nl).

What portaliq does today, read on `development` at ca591037: `tests/e2e/site-accessibility.spec.ts`
and `tests/e2e/portal-accessibility.spec.ts` run axe-core with the wcag2a, wcag2aa, wcag21a,
wcag21aa and wcag22aa tags over the public site and the signed-in portal and fail on any serious or
critical violation. Those results exist only in a CI log. Nothing in portaliq produces a statement;
`grep` for toegankelijkheid or "accessibility statement" in `lib`, `src`, `templates` and the specs
finds nothing. The plan expected an opencatalogi seed page to carry over; opencatalogi on
`development` (35999c29) has none, so there is nothing to carry.

## What changes

1. **The product measures itself on this instance.** On the portal's admin, "Measure accessibility"
   opens the portal's own pages (home, search, a publication, the not-found page, and any page the
   administrator adds) in a frame in the administrator's browser, runs axe-core with the same tags
   the e2e suite uses, and stores one `accessibilityMeasurement` per run: the date, the axe version,
   the tags, the theme in use, and per page the violations by rule and impact. Measuring on the
   instance matters, because the theme decides contrast.
2. **A statement page generated from it.** Each portal gets a public page `/toegankelijkheid`, linked
   from the footer, in the national model's sections: the organisation and the site, the status, the
   evidence (the latest measurement and any audit), the known issues (from the measurement, in plain
   language per rule), and how to report a barrier. It is regenerated whenever a measurement is
   stored.
3. **The status is never claimed beyond the evidence.** Status A (fully compliant) and B (partly
   compliant) need an audit by an independent party. The administrator records an audit (party,
   date, report link, result) to claim A or B. Without one, the statement says the status is at most
   C ("eerste maatregelen genomen"), names the automated measurement as the product's own, and says
   that an automated check does not prove compliance.
4. **A link to the register entry.** The administrator records the register URL; the statement links
   to it.

## What does not change

- The e2e axe suite. It stays the gate in CI.
- The site's pages themselves.

## Dependencies

None. axe-core is already in `node_modules` for the e2e suite; the admin measurement loads it as a
lazy chunk, never in the site entry. When the administrator's browser cannot frame a page (a portal
on another domain with `frameAncestors` set), that page is reported as not measured, with the
reason, and does not count as passing.

## Wave and done

Wave 1. Done means merged on `development` with CI green. 6.7 and 15.2 then read `yes` (build), and
`production` only once a portaliq store release carries them. 6.7 measures that the portal says so in
a statement; it does not turn an automated check into a compliance claim.
