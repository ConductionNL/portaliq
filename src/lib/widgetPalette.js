/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE PALETTE AS DATA (site-nlds-widget-palette REQ-SNW-001).
 *
 * Grouping and searching are decisions about words, not about rendering, so
 * they live here and the dialog renders what they answer. That is also what
 * lets a node test say "typing zaak finds the case cards" without a browser.
 *
 * WHAT THE SEARCH SEARCHES, and why each one is in it:
 *
 * - the Dutch label, because that is what the author reads in the list;
 * - the synonyms, because an author types the word for the thing they want,
 *   not the name a design system gave it: "zaak" for the case cards,
 *   "koppeling" for a link;
 * - the component's NL Design System name, so somebody working from
 *   nldesignsystem.nl finds the widget by the name on that page;
 * - the key, because a developer reading a page's JSON searches for `nlLink`.
 *
 * All four are folded to lower case and matched on a substring: an author who
 * types half a word is looking for the same thing as one who types all of it.
 */

/**
 * The groups in the order REQ-SNW-001 fixes, with the heading above each.
 *
 * It is declared here as well as in the widget registry because the palette
 * shows a heading for a group even before a wave has registered a widget in
 * it, and because the entries that do NOT render on a public page come last,
 * under their own heading, which is not a widget group at all.
 *
 * @type {Array<{group: string, label: string}>}
 */
export const PALETTE_GROUPS = [
	{ group: 'content', label: 'Inhoud' },
	{ group: 'nav', label: 'Navigatie' },
	{ group: 'forms', label: 'Formulieren' },
	{ group: 'feedback', label: 'Terugkoppeling' },
	{ group: 'mijn', label: 'Mijn omgeving' },
	{ group: 'layout', label: 'Opmaak' },
]

/**
 * The group an entry with no group of its own falls into.
 *
 * Every widget that was here before this change has no meta yet, so it would
 * otherwise sit under no heading and therefore out of the palette. "Overig"
 * says what it is: a widget this app offers that the design system does not
 * describe.
 *
 * @type {{group: string, label: string}}
 */
export const OTHER_GROUP = { group: 'other', label: 'Overig' }

/**
 * The heading the entries a public page will not mount sit under.
 *
 * @type {{group: string, label: string}}
 */
export const NOT_PUBLIC_GROUP = {
	group: 'notPublic',
	label: 'Niet op een openbare pagina',
}

/**
 * Dutch labels for the widgets whose own name is English or technical.
 *
 * T2 names `siteNavigation` and `form`: an author reads "Site navigation" and
 * "form" today, which is the key dressed up rather than a label. The rest of
 * the old widgets keep what `widgetLabels.js` derives, and a new widget states
 * its label in its own meta.
 *
 * @type {Record<string, string>}
 */
export const DUTCH_LABELS = {
	siteNavigation: 'Menu van deze site',
	form: 'Formulier',
}

/**
 * The words one entry answers to.
 *
 * @param {object} entry A catalogue entry.
 * @return {Array<string>} The haystack, lower case.
 */
function haystack(entry) {
	return [
		entry.label,
		entry.key,
		entry.nlds || '',
		...(Array.isArray(entry.synonyms) ? entry.synonyms : []),
	]
		.filter((word) => typeof word === 'string' && word.trim() !== '')
		.map((word) => word.toLowerCase())
}

/**
 * Whether an entry answers to a query.
 *
 * @param {object} entry A catalogue entry.
 * @param {string} query What the author typed.
 * @return {boolean} True when it matches, and for an empty query always.
 */
export function matchesQuery(entry, query) {
	const needle = String(query || '')
		.trim()
		.toLowerCase()
	if (needle === '') {
		return true
	}

	return haystack(entry).some((word) => word.includes(needle))
}

/**
 * The label an author reads for an entry.
 *
 * @param {object} entry A catalogue entry.
 * @return {string} The label.
 */
export function paletteLabel(entry) {
	return DUTCH_LABELS[entry.key] || entry.label || entry.key
}

/**
 * The palette, grouped and filtered, in the order the groups are declared.
 *
 * A group with no matching entry is left out rather than shown empty: an
 * author searching for "zaak" should read the hits, not six headings with
 * nothing under five of them.
 *
 * @param {Array<object>} entries The catalogue (`widgetCatalogue()`).
 * @param {string} [query] What the author typed.
 * @return {Array<{group: string, label: string, entries: Array<object>}>} The groups.
 */
export function paletteGroups(entries, query = '') {
	const matching = (entries || [])
		.filter((entry) =>
			matchesQuery({ ...entry, label: paletteLabel(entry) }, query),
		)
		.map((entry) => ({ ...entry, label: paletteLabel(entry) }))

	const groups = []
	for (const { group, label } of [...PALETTE_GROUPS, OTHER_GROUP]) {
		const inGroup = matching.filter(
			(entry) =>
				entry.publicSafe && (entry.group || OTHER_GROUP.group) === group,
		)
		if (inGroup.length > 0) {
			groups.push({ group, label, entries: inGroup })
		}
	}

	// Last, and under their own heading: what a published page will not mount
	// (REQ-SNW-001). They stay marked entry by entry as they were.
	const notPublic = matching.filter((entry) => !entry.publicSafe)
	if (notPublic.length > 0) {
		groups.push({ ...NOT_PUBLIC_GROUP, entries: notPublic })
	}

	return groups
}

/**
 * How many entries a query finds, which is the number the palette announces.
 *
 * @param {Array<object>} entries The catalogue.
 * @param {string} [query] What the author typed.
 * @return {number} The hit count.
 */
export function paletteHitCount(entries, query = '') {
	return paletteGroups(entries, query).reduce(
		(total, group) => total + group.entries.length,
		0,
	)
}
