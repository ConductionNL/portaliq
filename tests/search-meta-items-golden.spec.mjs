// The chips under a search result, pinned before `metaItems` is split.
import { test } from 'node:test'
import { golden } from './support/golden.mjs'
import { loadSfc } from './support/render-sfc.mjs'

const block = await loadSfc('src/site/components/FederatedSearchBlock.vue', { '@conduction/nextcloud-vue/public': 'export const CnSiteSearch = {}' })
const pinned = golden('search-meta-items')
const labels = { typeLabel: '', publicationsLabel: 'publicaties', partOfLabel: 'Onderdeel van', sourceLabel: 'Bron:' }
const meta = (result, extra = {}) => block.methods.metaItems.call({ ...labels, ...extra }, result)

const CASES = {
	empty: [{}],
	'date-type-source': [{ date: '1 mei 2026', type: 'Besluit', directory: 'Gemeente X' }],
	'block-type': [{ kind: 'publication' }, { typeLabel: 'Woo-verzoek' }],
	'own-type-wins': [{ type: 'Advies' }, { typeLabel: 'Woo-verzoek' }],
	'subject-with-count': [{ kind: 'subject', publicationCount: 4 }],
	'subject-without-count': [{ kind: 'subject', publicationCount: 0 }],
	'count-on-other-kind': [{ kind: 'publication', publicationCount: 4 }],
	'document-of-publication': [{ kind: 'document', publication: 'Raadsbesluit' }],
	'document-alone': [{ kind: 'document', publication: '' }],
	'publication-on-other-kind': [{ kind: 'subject', publication: 'X' }],
	everything: [{ date: 'd', type: 't', kind: 'document', publication: 'p', publicationCount: 2, directory: 'dir' }],
}

for (const [name, [result, extra]] of Object.entries(CASES)) {
	test(`meta items: ${name}`, () => pinned.check(name, meta(result, extra)))
}

test('save the golden', () => pinned.save())
