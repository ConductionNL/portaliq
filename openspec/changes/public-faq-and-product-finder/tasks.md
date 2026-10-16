# Tasks: public-faq-and-product-finder

- [x] **T01**: `lib/Settings/portaliq_register.json`: schemas `portalFaq` and `portalFinder` per design.md "Data"; register bump; import and grep for `PARTIAL IMPORT` (REQ-FPF-001, REQ-FPF-002)
- [x] **T02**: Public content read serves published `portalFaq` and `portalFinder` rows of the portal, drafts never; PHPUnit (REQ-FPF-001) (`CmsReader::faq()` and `finder()`, routes `/api/content/faq` and `/api/content/finder`; the schemas read as admin only, so the public read is the only way out and a draft is never served; cache dropped on a write)
- [x] **T03**: `src/site/widgets/faqList/` (meta.js, FaqList.vue) on the NL DS Accordion; strings in Dutch and English (REQ-FPF-001) (widget key `nlFaqList`)
- [x] **T04**: `src/site/widgets/productFinder/` per the Productzoeker board; in-browser evaluation, skip rule, chips, reset; no network call with answers (REQ-FPF-002, REQ-FPF-003) (widget key `nlProductFinder`; evaluation in `src/site/lib/finderPlan.js`)
- [x] **T05**: Admin manifest pages for FAQ and finders (index and detail) in `src/manifest.json` (index pages only, as the glossary has; a detail page is not built: not run)
- [x] **T06**: Zuiddrecht example site: FAQ entries on Parkeren en verkeer and Parkeervergunning bewoners, `/veelgestelde-vragen`, a parking finder with five questions and twelve product pages (pages `/veelgestelde-vragen`, `/parkeren/productzoeker` and the FAQ on `/parkeren` are in the example site; the FAQ entries, the finder row and the twelve product pages are not seeded: not run, ExampleSiteInstaller creates only menus, pages and news items)
- [x] **T07**: node tests `tests/faq-list.spec.mjs` and `tests/product-finder.spec.mjs` (narrowing, skip, change of answer, no request)
- [ ] **T08**: Live check against the Onderwerp, ProductPagina and Productzoeker boards; screenshots in the build PR — not run: needs a live instance and the Zuiddrecht boards
