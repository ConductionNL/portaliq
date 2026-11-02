## Why

Board SkPubliek draws a software catalogue on the municipality website: anyone
searches which software suppliers offer to government, without signing in. The
decision (23 Oct) is that this is a portaliq page placed through the site's
configuration, with the content contributed by stackiq, so the public site stays
in one place and stackiq has no public page of its own (ADR-108, ADR-109).

Stackiq now declares the public record list `softwareCatalogue` in its portal
contribution (stackiq change `portal-public-software-catalogue`). The example
site has no page that shows it.

## What Changes

- The Zuiddrecht example site gets the page `/softwarecatalogus`, "Softwarecatalogus":
  an introduction and the public records block on stackiq's `softwareCatalogue`
  list, searchable, each application opening its record.
- The page carries `requiresApp: stackiq`, so an instance without stackiq installs
  the site without it.
- No code changes: the public records block and the contribution contract already
  read and render the list.

## Out of scope

- The "register your organisation" form on the board. It needs an intake form whose
  destination is stackiq's organisation schema with `registrationStatus: pending`
  fixed; a separate change.
- A result count and per-result description in the list view of the public records
  block; the block shows title and subtitle.
