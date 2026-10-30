# Proposal: example-set-lindelaan-case-files

## Why

DqZaakDocumenten lists the seven documents of the Lindelaan Woo request on the case, each with its Woo verdict ("4 van 7 beoordeeld"). The case declared four files, one of them the request itself rather than a document under review. On :8080 it carried none, because its files were declared after the case was first installed.

## What changes

- `woo-fietspad` declares as its files exactly the files of its seven information objects: advice, mail, council proposal, complaints list, internal note, quote and map.
- On :8080 they arrive on the next install through openregister `fix/example-set-files-on-kept-objects`, which attaches files a kept object gained.
