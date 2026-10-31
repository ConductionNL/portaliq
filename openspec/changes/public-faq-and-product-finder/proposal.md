---
kind: code
---

# Proposal: public-faq-and-product-finder

## Why

Two questions reach the municipality's phone line every day: "Do I need a permit for this?" and "Which permit fits me?". The public site cannot answer either in a structured way. An editor can fill an accordion by hand on each page (`src/site/widgets/nlAccordion/meta.js:14`), so the same answer lives on five pages and drifts. There is no way to walk a resident through a few yes or no questions to the permits that apply.

Open Inwoner has a FAQ page with answers per product and category (`src/open_inwoner/urls.py:135`) and a product finder plugin (`src/open_inwoner/cms/products/cms_plugins.py:107`). The Zuiddrecht boards **Onderwerp** and **ProductPagina** draw FAQs on a topic and a product page; **Productzoeker** draws the finder.

## What changes

- **One FAQ, shown where it applies.** A FAQ entry (question, answer, the pages it belongs to) is written once. A `faqList` block shows the entries of its page; the page `/veelgestelde-vragen` lists all of them grouped by topic. "Alle veelgestelde vragen" links there.
- **A product finder.** An editor builds a finder from yes or no questions. Each answer rules out products, which are the site's own product pages. The resident sees one question at a time with "Vraag 3 van 5", her earlier answers as chips, Ja and Nee, Vorige vraag, Opnieuw beginnen, and on the right "Nog 4 van de 12 producten passen bij uw antwoorden" with the ruled-out ones folded away.
- **No answers kept.** The finder runs in the browser and stores nothing: "Wij bewaren uw antwoorden niet."

## Rows covered

- `site-faq`, `site-product-finder` (decision 101), screens Onderwerp, ProductPagina and Productzoeker.

## Out of scope

- A product catalogue with prices and conditions as data. Product pages stay CMS pages; the PtProducten board is a separate change.
- The self-test (Zelfdiagnose) with result advice. `cmp-tsk-questionnaire` is decided-no.
