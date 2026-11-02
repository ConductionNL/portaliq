# Design: personal-data-left-untranslated

## Where

No board draws this, because it changes no pixel. The values sit on boards that exist: the greeting on **MijnOverzicht**, the name in the account menu (**Kop**, **MijnMenu**), the acting-for bar, the details on **MijnGegevens** and **MijnAccount**, the case number on **Zaak** (canvas `5NkFW28vZUUij43xzxHg5a`).

| Component | Values marked |
|---|---|
| `src/site/components/mijn/GreetingBlock.vue`, `MijnHome.vue` | the resident's name |
| `src/site/components/BrandHeader.vue` account label | the resident's name |
| `src/site/components/mijn/ActingForBar.vue`, `e/ActingForSwitcher.vue` | the represented party's name |
| `src/site/components/e/AddressList.vue`, the account and details pages | address, e-mail, phone |
| `src/site/components/e/CaseField.vue`, `mijn/CaseCard.vue` | case number and field values typed as identifier, licence plate or address |

## How

`src/site/components/NoTranslate.vue` renders `<span translate="no">` around its slot (or around a `value` prop). Labels and sentences around the value are not wrapped. A field rendered by `CaseField` is wrapped when its schema format is `email`, `uri`, `telephone`, or when the contribution marks it `personal: true`.

## Test

A node test renders the greeting, the account menu, the acting-for bar, the details page and a case card with fixed data and asserts every personal value sits inside an element with `translate="no"` and no label does.
