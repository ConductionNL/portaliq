# Proposal: example-set-sanne-cases

## Why

The Zuiddrecht example set wrote two "Verlichting fietspad Lindelaan" Woo cases: this fragment's, the request the portal
resident Sanne de Vries made through Mijn Zuiddrecht, and dossiq's, with Linda Bakker as requester. The DqZaak boards
draw Sanne's (Ruben, 20 October 2026), and the contact page (DqContact) lists her cases. Dossiq's case carried the
contact moments, documents, tasks and advice the boards draw, and Sanne's cases named no requester, so her contact page
listed none.

## What changes

- Sanne's Woo case takes over what dossiq's carried: in behandeling (received 14 days before install), the request's
  facts, its location, handler and archive status, its four contact moments, seven documents (four assessed), the
  statutory term, two advice requests and four tasks. The documents' files move here, and they find dossiq's Woo
  document type by lookup.
- The case carries a portal reference (`externalReference` MZ-ZDR-2026-0412), which decidiq's concept decision looks
  it up by.
- Each of Sanne's cases names her contact record (dossiq `persoon-sanne`, a `brpPerson`) as requester.
