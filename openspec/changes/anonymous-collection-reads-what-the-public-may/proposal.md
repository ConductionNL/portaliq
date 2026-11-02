# Proposal: anonymous-collection-reads-what-the-public-may

## Why

Seen in the Zuiddrecht go-live rehearsal R2: "Vergaderkalender" in Mijn Zuiddrecht read "Geen items", while decidiq holds six council meetings and the public council page lists them.

decidiq contributes the calendar as a collection over `publication-payload` with `anonymous: true`, which the contribution contract documents as "readable with no session". Portaliq never implemented that read: every collection read was scoped to the subject (`scopeField`, default `subjectRef`), and a council meeting belongs to no resident, so the read always came back empty. The same held for decidiq's live broadcasts and the motions open for advice.

## What changes

- A collection its app declares `anonymous: true` is read as the public may read it: OpenRegister's own read rules ON for the caller as it is, not scoped to the subject. A resident has no Nextcloud account, so the schema's `public` rule decides (a payload is public once its publication date has passed). This read can never show more than OpenRegister shows that caller.
- The declared `filter` narrows that read; a row naming another tenant is dropped; the `fields` whitelist shapes every row, as on every other read.

## Not changed

- Every other collection stays scoped to the subject.
- A read with no session still answers 401; the signed-out site uses the public records.
- Writes: nothing here writes.
