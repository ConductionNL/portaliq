---
title: Sorting public search by relevance
sidebar_label: Search by relevance
---

# Sorting public search by relevance

A visitor who searches with a term sees the best matches first.
The public search block offers "Meest relevant" whenever there is a term, and starts a new search on it.
Without a term the option is not offered; there is nothing to be relevant to.

## What ranks

Portaliq does not rank anything itself.
It asks OpenRegister for `_order[_relevance]=DESC` with `_fuzzy=true`.
OpenRegister scores each publication by how closely its title matches the term, using the PostgreSQL trigram extension `pg_trgm`.
Summaries and the text inside documents do not count toward the score yet; that waits on OpenRegister.

Every search with a term sends `_fuzzy=true`, so a misspelt title is still found.

## When ranking is not available

OpenRegister skips the relevance order when `pg_trgm` is not installed.
The block checks every relevance answer for a score on the first result.
When there is none, it removes "Meest relevant" for the rest of the visit, searches again in the default order and says "Sorteren op relevantie kan hier niet." once.
The visitor never sees a sort option that changes nothing.

Results from other catalogues arrive without a comparable score.
The block shows them in the order the endpoint returns and does not re-sort them.

## For screen reader users

A result with a score carries a hidden description, "Overeenkomst: 88 procent", on its link.
A sighted visitor sees the order, not a number.
