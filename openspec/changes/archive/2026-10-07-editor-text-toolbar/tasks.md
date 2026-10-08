# Tasks: editor-text-toolbar

- [x] **T1**: The transformations are pure functions, selection in and text plus selection out (`src/editor/markdownToolbar.js`)
  - `node --test tests/editor-text-toolbar.spec.mjs` (`check:editor-text-toolbar`): every button, an empty selection, a multi-line list, toggling off, the shortcuts
- [x] **T2**: The "Tekst" block's field shows the toolbar over the textarea and reads "Tekst" (`src/editor/MarkdownField.vue`, `PageGridEditor.vue`, `pageWidgetCatalogue.js`)
  - `node --test tests/editor-text-toolbar.spec.mjs`: the test id, the toolbar role and label, real buttons, no `window.prompt`
- [x] **T3**: Every string the toolbar shows has its Dutch translation (`l10n/nl.json`, regenerated `l10n/nl.js`)
  - `npm run check:l10n-js`, and the Dutch strings asserted in `tests/editor-text-toolbar.spec.mjs`
- [x] **T4**: The toolbar follows the WAI-ARIA toolbar pattern: one Tab stop with a roving tabindex, Left/Right wrap, Home/End
  - `node --test tests/editor-text-toolbar.spec.mjs`: `toolbarIndexFor`, `rovingTabindexes` and the component wiring
