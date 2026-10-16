---
title: Subject pages, featured subjects and live counts
sidebar_label: Subject pages and counts
---

# Subject pages, featured subjects and live counts

A subject (a theme) has its own page with an image, a description and its publications. The home page can feature subjects and show how much the portal publishes. All of it comes from the publication catalogue (opencatalogi) and is read as an anonymous visitor would read it.

Without opencatalogi the widgets say that the publication catalogue is not installed, and a subject page reads as not found.

## Lock a filter on the search block

`FederatedSearchBlock` takes `lockedFilters`, a map of facet field to value, for example `{"themes": "th-parkeren"}`. The block sends it on every request, shows it as a chip without a remove control, does not offer the field as a facet, and does not write it to the address, so a shared link cannot unlock it. Without `lockedFilters` the block behaves as before.

## The subject page

Add the widget **Pagina van een onderwerp** (`nlSubjectLanding`) to the page the subject addresses hang under, normally `/onderwerp`. The page `/onderwerp/{slug}` then:

- reads the subject from `GET /index.php/apps/opencatalogi/api/themes/{slug}`,
- shows its image with the alternative text, its title and its description,
- lists its publications with the search block locked to the subject.

A subject that does not exist, or is not public, shows a not-found message with a link back. The example site Zuiddrecht has this page.

## Featured subjects

The widget **Uitgelichte onderwerpen** (`nlFeaturedSubjects`) lists `GET /api/themes?featured=true`, in `featuredOrder` and then by title, at most the number the author sets (six by default). Each subject shows its image, title, summary and number of publications and links to its page. The widget keeps only rows whose `featured` is true, so an opencatalogi that ignores the filter shows nothing rather than every subject.

## Live counts

The widget **Wat we publiceren, in aantallen** (`nlPortalCounts`) shows totals per category, per subject or per year, as the author chooses. It makes one search request with `_limit=0` and the facet, with `credentials: 'omit'`, so a signed-in officer sees the counts the public sees, not their drafts. Each count links to the search page with that filter set. A bucket that was not answered, or answered zero, is left out and never shown as zero.

The count per year asks for a `date_histogram` facet on `publicationDate`. That facet has not been checked against a running opencatalogi.

## Widget keys

The registry keeps every key prefixed with `nl`, so the widgets are `nlFeaturedSubjects`, `nlPortalCounts` and `nlSubjectLanding`.
