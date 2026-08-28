/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The vocabulary the page designer offers, and the fields it edits.
 *
 * TWO CATALOGUES, ONE LIST. A portal page can carry any widget key the
 * manifest grid understands, but only the keys the PUBLIC renderer mounts will
 * actually appear on a published page — everything else degrades to an inert
 * placeholder there (see `src/site/components/WidgetGrid.vue`). Hiding the rest
 * would be a smaller list and a bigger lie: the app's own catalogue is real,
 * apps do place those widgets, and an author who cannot see them cannot tell
 * "not offered" from "not supported". So both are listed and the ones a public
 * page will not mount say so, in the entry itself.
 *
 * NEITHER LIST IS WRITTEN DOWN HERE. The public one is derived from the
 * renderer's own allow-list and the app one from the shared dashboard
 * registry, because a copy of either would drift the moment a widget was added
 * upstream — and it would drift silently, since a stale catalogue looks exactly
 * like a complete one.
 */

import {
	dashboardWidgetRegistry,
	registerBuiltinDashboardWidgets,
} from '@conduction/nextcloud-vue'
import FederatedSearchBlock from '../site/components/FederatedSearchBlock.vue'
import PublicationDetailBlock from '../site/components/PublicationDetailBlock.vue'
import {
	publicWidgetFor,
	publicWidgetKeys,
} from '../site/components/WidgetGrid.vue'

/**
 * FORCE THE SHARED CATALOGUE TO EXIST BEFORE IT IS READ.
 *
 * The library's widgets self-register at module load, and the barrel imports
 * the aggregator that pulls them in. Importing only `dashboardWidgetRegistry`
 * from that barrel gives a bundler every reason to keep the aggregator out —
 * and the failure is silent in the worst possible way: an EMPTY registry reads
 * exactly like a catalogue with nothing in it, so the palette showed eleven
 * public blocks and zero app widgets and looked entirely plausible.
 *
 * `registerBuiltinDashboardWidgets` is the library's own no-op for this: the
 * call does nothing, the IMPORT is the point.
 */
registerBuiltinDashboardWidgets()

/**
 * Human labels for the public blocks, in the language the portal is authored
 * in. A key with no entry here falls back to the key itself rather than to
 * nothing: an unlabelled but placeable widget beats a widget that is missing.
 *
 * @type {Record<string, string>}
 */
const PUBLIC_LABELS = {
	markdown: 'Tekst (markdown)',
	hero: 'Hero',
	search: 'Zoekbalk',
	section: 'Sectie',
	cardGrid: 'Kaartenraster',
	card: 'Kaart',
	emptyState: 'Lege staat',
	glossary: 'Begrippenlijst',
	contributions: 'Bijdragen',
	federatedSearch: 'Federatief zoeken',
	publicationDetail: 'Publicatiedetail',
}

/**
 * Sensible first geometry per key, on the shared 12-column grid.
 *
 * A band paints edge to edge on the site, so it is placed full width here too:
 * dropping a hero into four columns produces a layout the public page will not
 * reproduce, which makes the designer lie about its own result.
 *
 * @type {Record<string, {gridWidth: number, gridHeight: number}>}
 */
const DEFAULT_SIZES = {
	hero: { gridWidth: 12, gridHeight: 5 },
	section: { gridWidth: 12, gridHeight: 4 },
	search: { gridWidth: 12, gridHeight: 3 },
	cardGrid: { gridWidth: 12, gridHeight: 5 },
	glossary: { gridWidth: 12, gridHeight: 5 },
	federatedSearch: { gridWidth: 12, gridHeight: 6 },
	publicationDetail: { gridWidth: 12, gridHeight: 6 },
	contributions: { gridWidth: 12, gridHeight: 4 },
	card: { gridWidth: 4, gridHeight: 3 },
	emptyState: { gridWidth: 6, gridHeight: 3 },
	markdown: { gridWidth: 6, gridHeight: 4 },
}

/**
 * Props a page must NOT author, because the host supplies them.
 *
 * The glossary's terms, the contributed surfaces and the publication a detail
 * block shows are DATA — fetched by the renderer over the public contract, or
 * taken from the route. Offering them as fields would let an author pin a
 * detail page to one publication regardless of its URL, and would let them
 * type a glossary that the real one immediately overwrites.
 *
 * @type {Record<string, Array<string>>}
 */
const HOST_SUPPLIED = {
	glossary: ['terms'],
	contributions: ['contributions'],
	publicationDetail: ['subjectId'],
}

/**
 * Stored-prop overrides for keys whose renderer prop is not the stored one.
 *
 * `markdown` is the only case: the page stores `props.markdown` and the block
 * receives it as `source`, a mapping `WidgetGrid.propsFor()` performs. Deriving
 * this field from the component would therefore offer a `source` field that no
 * renderer ever reads back.
 *
 * @type {Record<string, Array<object>>}
 */
const FIELD_OVERRIDES = {
	markdown: [
		{
			name: 'markdown',
			kind: 'text',
			label: 'Markdown',
		},
	],
}

/**
 * Components the SITE loads lazily and this designer does not.
 *
 * A `defineAsyncComponent` wrapper carries no `props` until it resolves, so
 * introspecting the site's own reference yields nothing for these two. The
 * real component is imported here instead — the app bundle has no first-paint
 * budget to protect — which keeps the fields derived from the component rather
 * than from a copy of its prop list that would drift.
 *
 * @type {Record<string, object>}
 */
const LAZY_ON_THE_SITE = {
	federatedSearch: FederatedSearchBlock,
	publicationDetail: PublicationDetailBlock,
}

/**
 * Props every Vue component has and no author should be offered.
 *
 * @type {Array<string>}
 */
const NEVER_EDITABLE = ['key', 'ref', 'class', 'style']

/**
 * The component to read a key's editable fields from.
 *
 * @param {string} key The widget key.
 * @return {object|null} A component with declared props, or null.
 */
function introspectable(key) {
	const resolved = publicWidgetFor(key)
	if (resolved && resolved.props) {
		return resolved
	}

	return LAZY_ON_THE_SITE[key] || null
}

/**
 * The editing kind for one declared prop.
 *
 * @param {string} name The prop name.
 * @param {object} definition The prop definition.
 * @return {string} One of `text`, `string`, `number`, `boolean`, `json`.
 */
function kindFor(name, definition) {
	const type = definition?.type
	if (type === Boolean) {
		return 'boolean'
	}

	if (type === Number) {
		return 'number'
	}

	if (type === Array || type === Object) {
		return 'json'
	}

	// A long-form string gets a textarea. Judged by name because Vue's prop
	// declarations carry no such hint, and a one-line input for a paragraph is
	// the difference between a field an author uses and one they avoid.
	if (/description|subtitle|body|content|markdown|summary/i.test(name)) {
		return 'text'
	}

	return 'string'
}

/**
 * Humanise a camelCase prop or widget key for a label.
 *
 * @param {string} name The name.
 * @return {string} The label.
 */
function humanise(name) {
	const spaced = String(name)
		.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
		.replace(/[-_]+/g, ' ')
		.trim()

	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/**
 * The full catalogue the palette offers.
 *
 * Public entries come first: they are the ones that will actually render on a
 * published page, and an author scanning a list should meet those before the
 * ones they cannot use there.
 *
 * @return {Array<object>} Entries of `{key, label, publicSafe, reason}`.
 */
export function widgetCatalogue() {
	const entries = publicWidgetKeys().map((key) => ({
		key,
		label: PUBLIC_LABELS[key] || humanise(key),
		publicSafe: true,
		reason: '',
	}))

	const known = new Set(entries.map((entry) => entry.key))
	for (const key of Object.keys(dashboardWidgetRegistry)) {
		if (known.has(key)) {
			continue
		}

		entries.push({
			key,
			label: dashboardWidgetRegistry[key]?.displayName || humanise(key),
			publicSafe: false,
			reason:
				'Deze widget wordt niet getoond op een openbare pagina — bezoekers zien een lege plek.',
		})
	}

	return entries
}

/**
 * The editable fields for a widget key.
 *
 * @param {string} key The widget key.
 * @return {Array<object>} Entries of `{name, kind, label}`.
 */
export function fieldsFor(key) {
	if (FIELD_OVERRIDES[key]) {
		return FIELD_OVERRIDES[key]
	}

	const component = introspectable(key)
	if (!component || !component.props) {
		// An app widget, or a key this build does not know. It is still
		// placeable — the geometry is the page's, not the widget's — and its
		// configuration is edited as JSON rather than not at all.
		return []
	}

	const hidden = HOST_SUPPLIED[key] || []

	return Object.keys(component.props)
		.filter(
			(name) => !hidden.includes(name) && !NEVER_EDITABLE.includes(name),
		)
		.map((name) => ({
			name,
			kind: kindFor(name, component.props[name]),
			label: humanise(name),
		}))
}

/**
 * The first geometry for a newly placed widget.
 *
 * @param {string} key The widget key.
 * @return {{gridWidth: number, gridHeight: number}} The size.
 */
export function defaultSizeFor(key) {
	return DEFAULT_SIZES[key] || { gridWidth: 6, gridHeight: 4 }
}

/**
 * Whether a key renders on a published page.
 *
 * @param {string} key The widget key.
 * @return {boolean} True when the public renderer mounts it.
 */
export function isPublicWidget(key) {
	return publicWidgetKeys().includes(key)
}

/**
 * The component that previews a widget, or null when the public page would
 * show a placeholder for it.
 *
 * The designer previews with the SAME resolution the public renderer uses, so
 * a placeholder in the designer means a placeholder on the site. A preview
 * that rendered an app widget the site cannot mount would be a demonstration
 * of something that will not happen.
 *
 * @param {string} key The widget key.
 * @return {object|null} The component, or null.
 */
export function previewComponentFor(key) {
	return publicWidgetFor(key)
}
