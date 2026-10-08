# Tasks: public-faq-and-product-finder

- [ ] **T01**: `lib/Settings/portaliq_register.json`: schemas `portalFaq` and `portalFinder` per design.md "Data"; register bump; import and grep for `PARTIAL IMPORT` (REQ-FPF-001, REQ-FPF-002)
- [ ] **T02**: Public content read serves published `portalFaq` and `portalFinder` rows of the portal, drafts never; PHPUnit (REQ-FPF-001)
- [ ] **T03**: `src/site/widgets/faqList/` (meta.js, FaqList.vue) on the NL DS Accordion; strings in Dutch and English (REQ-FPF-001)
- [ ] **T04**: `src/site/widgets/productFinder/` per the Productzoeker board; in-browser evaluation, skip rule, chips, reset; no network call with answers (REQ-FPF-002, REQ-FPF-003)
- [ ] **T05**: Admin manifest pages for FAQ and finders (index and detail) in `src/manifest.json`
- [ ] **T06**: Zuiddrecht example site: FAQ entries on Parkeren en verkeer and Parkeervergunning bewoners, `/veelgestelde-vragen`, a parking finder with five questions and twelve product pages
- [ ] **T07**: node tests `tests/faq-list.spec.mjs` and `tests/product-finder.spec.mjs` (narrowing, skip, change of answer, no request)
- [ ] **T08**: Live check against the Onderwerp, ProductPagina and Productzoeker boards; screenshots in the build PR
