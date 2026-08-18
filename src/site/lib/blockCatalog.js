/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * What each block IS, and what an author can set on it.
 *
 * ONE DECLARATION PER BLOCK, read by the editor's library, its inspector and
 * its layer tree (tasks 4.2, 4.3, 4.4). The alternative — a hand-written form
 * per block — is the version that goes stale: a block grows a prop, nobody
 * updates its form, and the prop becomes unreachable to every author while
 * looking supported in the code.
 *
 * IT LIVES BESIDE THE BLOCKS, not in the editor, for the same reason. A field
 * list in another bundle is a field list that drifts from the component it
 * describes; here the two are one directory apart and change together.
 *
 * The `description` is what the block DOES, not what it is called. "Melding
 * indienen" is a name; "a form a visitor can submit without an account" is
 * what someone choosing from a list actually needs.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

/**
 * Field types the inspector knows how to render.
 *
 * Deliberately few. Every type here is a control the inspector implements, so
 * adding one to a block without adding it here renders nothing — which is why
 * the editor reports an unknown type rather than skipping the field.
 *
 * @type {Array<string>}
 */
export const FIELD_TYPES = ['text', 'textarea', 'number', 'boolean', 'select', 'list']

/**
 * The catalogue, keyed by `widgetKey`.
 *
 * @type {Record<string, object>}
 */
export const BLOCK_CATALOG = {
	brandHeader: {
		label: 'Merkbalk',
		description: 'De kop van het portaal: logo, naam, navigatie en inloggen.',
		category: 'Structuur',
		regions: ['header'],
		fields: [
			{ name: 'signOutLabel', label: 'Tekst uitlogknop', type: 'text' },
			{ name: 'userMenuLabel', label: 'Naam accountmenu (voor schermlezers)', type: 'text' },
		],
	},

	footerColumns: {
		label: 'Voettekst',
		description: 'Linkkolommen met daaronder een balk met colofon en juridische links.',
		category: 'Structuur',
		regions: ['footer'],
		fields: [
			{ name: 'landmarkLabel', label: 'Naam voettekst (voor schermlezers)', type: 'text' },
		],
	},

	hero: {
		label: 'Hero',
		description: 'De brede band bovenaan met titel, introductie en maximaal twee knoppen.',
		category: 'Inhoud',
		regions: ['hero', 'main'],
		fields: [
			{ name: 'eyebrow', label: 'Bovenschrift', type: 'text' },
			{ name: 'title', label: 'Titel', type: 'text' },
			{ name: 'subtitle', label: 'Introductie', type: 'textarea' },
			{
				name: 'headingLevel',
				label: 'Kopniveau',
				type: 'select',
				options: [1, 2, 3],
				help: 'Eén pagina heeft één h1. Kies 2 als de titel niet de paginatitel is.',
			},
			{ name: 'search', label: 'Zoekveld tonen', type: 'boolean' },
			{ name: 'actions', label: 'Knoppen (maximaal twee)', type: 'list' },
		],
	},

	markdown: {
		label: 'Tekst',
		description: 'Vrije tekst met opmaak, koppen en links.',
		category: 'Inhoud',
		regions: ['main', 'aside'],
		fields: [{ name: 'markdown', label: 'Tekst', type: 'textarea' }],
	},

	cardGrid: {
		label: 'Kaarten',
		description: 'Een rij kaarten die elk naar een pagina of dienst verwijzen.',
		category: 'Inhoud',
		regions: ['main', 'aside'],
		fields: [
			{
				name: 'headingLevel',
				label: 'Kopniveau van de kaarten',
				type: 'select',
				options: [2, 3, 4],
				help: 'Sla geen niveau over: na een h1 hoort een h2.',
			},
			{ name: 'cards', label: 'Kaarten', type: 'list' },
		],
	},

	glossary: {
		label: 'Begrippenlijst',
		description: 'De begrippen van dit portaal, opgehaald uit de eigen inhoud.',
		category: 'Inhoud',
		regions: ['main'],
		fields: [{ name: 'title', label: 'Titel', type: 'text' }],
	},

	contributions: {
		label: 'Diensten',
		description: 'Wat andere apps op dit portaal aanbieden, automatisch bijgewerkt.',
		category: 'Inhoud',
		regions: ['main', 'aside'],
		fields: [
			{ name: 'title', label: 'Titel', type: 'text' },
			{ name: 'emptyLabel', label: 'Tekst als er niets is', type: 'text' },
		],
	},

	section: {
		label: 'Band',
		description: 'Een band over de volle breedte waar andere inhoud op staat.',
		category: 'Structuur',
		regions: ['main'],
		fields: [{ name: 'title', label: 'Titel', type: 'text' }],
	},

	search: {
		label: 'Zoeken',
		description: 'Een zoekveld dat de inhoud van dit portaal doorzoekt.',
		category: 'Inhoud',
		regions: ['main', 'aside'],
		fields: [
			{ name: 'label', label: 'Label', type: 'text' },
			{ name: 'placeholder', label: 'Voorbeeldtekst', type: 'text' },
		],
	},
}

/**
 * The catalogue entry for a block, or null.
 *
 * @param {string} key The block key.
 * @return {object|null} The entry.
 */
export function blockInfo(key) {
	return BLOCK_CATALOG[key] || null
}

/**
 * Blocks that may be placed in a region, grouped by category.
 *
 * A BLOCK DECLARES WHERE IT BELONGS. Offering a footer in the hero region
 * produces a page an author has to undo rather than one they meant, and the
 * region a block was built for is knowledge the block has and the editor does
 * not.
 *
 * @param {string} region The region key.
 * @return {Array<object>} `[{category, blocks: [...]}]`, categories sorted.
 */
export function blocksForRegion(region) {
	const byCategory = new Map()

	for (const [key, info] of Object.entries(BLOCK_CATALOG)) {
		if (info.regions.includes(region) === false) {
			continue
		}

		if (byCategory.has(info.category) === false) {
			byCategory.set(info.category, [])
		}

		byCategory.get(info.category).push({ key, ...info })
	}

	return [...byCategory.entries()]
		.sort(([a], [b]) => a.localeCompare(b))
		.map(([category, blocks]) => ({
			category,
			blocks: blocks.sort((a, b) => a.label.localeCompare(b.label)),
		}))
}

/**
 * Field definitions the inspector cannot render.
 *
 * REPORTED, NOT SKIPPED. A field with an unknown type would otherwise vanish
 * from the form, and the author would conclude the block does not support it —
 * the same class of silent failure as a widget that renders nothing.
 *
 * @return {Array<string>} `"blockKey.fieldName: type"` for each unknown type.
 */
export function unrenderableFields() {
	const unknown = []

	for (const [key, info] of Object.entries(BLOCK_CATALOG)) {
		for (const field of info.fields) {
			if (FIELD_TYPES.includes(field.type) === false) {
				unknown.push(`${key}.${field.name}: ${field.type}`)
			}
		}
	}

	return unknown
}
