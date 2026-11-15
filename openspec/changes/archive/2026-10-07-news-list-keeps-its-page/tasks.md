# Tasks: news-list-keeps-its-page

- [x] **T1**: `recordListFetches` and `refreshList` replay a list's last fetch.
  - `node --test tests/news-list-refresh.spec.mjs` (real Pinia store)
- [x] **T2**: The News handlers refresh their list in place; `main.js` records the shared object store.
  - `tests/news-list-refresh.spec.mjs` ("the News handlers refresh the list, and reload the page only as a fallback")
  - Live: publish an item on page 2 of the News list and stay on page 2
