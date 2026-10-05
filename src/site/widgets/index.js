/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * THE NL DESIGN SYSTEM WIDGETS, AS TWO READABLE HALVES
 * (site-nlds-widget-palette design D3).
 *
 * `loaders` is what the SITE needs: a key to a dynamic import, so each widget
 * and its CSS package arrive in their own chunk and the site entry carries
 * none of them (REQ-SNW-011). It lives in `loaders.js`, because that is the
 * one half the site entry may hold, and is re-exported here so a reader finds
 * both halves in one place.
 *
 * `metas` is what the EDITOR needs: the label, the group, the editable fields
 * and the default size, as plain data with no imports. Today the designer
 * introspects a component's `props` to find its fields, and five components
 * are imported eagerly into the editor bundle just to be introspected. That
 * does not scale to forty widgets, and it answers the wrong question anyway:
 * a prop list is what a component accepts, not what an author should be asked.
 *
 * So a widget is a directory with two files:
 *
 *     src/site/widgets/<key>/<Name>.vue   the component, importing its own CSS
 *     src/site/widgets/<key>/meta.js      `{ key, group, label, nlds,
 *                                           synonyms, fields, defaultSize,
 *                                           scope }`, and no imports
 *
 * and both halves are registered here, in one place, so a widget cannot exist
 * for the site without being describable to the editor or the other way round.
 *
 * 🔑 A KEY MUST NOT COLLIDE WITH THE SHARED DASHBOARD REGISTRY. That registry
 * already has `table`, `cases` and more, and a widget renders at a public
 * origin if and only if it is in `PUBLIC_WIDGETS` (ADR-084 §5). A new widget
 * that reused a dashboard key would quietly take over what that key renders
 * publicly, which is why every key here is prefixed `nl` and why
 * `tests/widget-registry.spec.mjs` fails on a collision.
 */

import { loaders } from './loaders.js'
import { metaOf as accordionMeta } from './nlAccordion/meta.js'
import { metaOf as actionGroupMeta } from './nlActionGroup/meta.js'
import { metaOf as alertMeta } from './nlAlert/meta.js'
import { metaOf as bannerMeta } from './nlBanner/meta.js'
import { metaOf as buttonLinkMeta } from './nlButtonLink/meta.js'
import { metaOf as codeBlockMeta } from './nlCodeBlock/meta.js'
import { metaOf as descriptionListMeta } from './nlDescriptionList/meta.js'
import { metaOf as dialogMeta } from './nlDialog/meta.js'
import { metaOf as drawerMeta } from './nlDrawer/meta.js'
import { metaOf as eventListMeta } from './nlEventList/meta.js'
import { metaOf as headingMeta } from './nlHeading/meta.js'
import { metaOf as imageMeta } from './nlImage/meta.js'
import { metaOf as languageNavMeta } from './nlLanguageNav/meta.js'
import { metaOf as linkMeta } from './nlLink/meta.js'
import { metaOf as linkListMeta } from './nlLinkList/meta.js'
import { metaOf as listMeta } from './nlList/meta.js'
import { metaOf as newsArticleMeta } from './nlNewsArticle/meta.js'
import { metaOf as newsListMeta } from './nlNewsList/meta.js'
import { metaOf as noteMeta } from './nlNote/meta.js'
import { metaOf as paragraphMeta } from './nlParagraph/meta.js'
import { metaOf as progressBarMeta } from './nlProgressBar/meta.js'
import { metaOf as progressCircleMeta } from './nlProgressCircle/meta.js'
import { metaOf as quickTasksMeta } from './nlQuickTasks/meta.js'
import { metaOf as quoteMeta } from './nlQuote/meta.js'
import { metaOf as separatorMeta } from './nlSeparator/meta.js'
import { metaOf as signInMeta } from './nlSignIn/meta.js'
import { metaOf as tableMeta } from './nlTable/meta.js'
import { metaOf as tabsMeta } from './nlTabs/meta.js'
import { metaOf as taskNavMeta } from './nlTaskNav/meta.js'
import { metaOf as toggletipMeta } from './nlToggletip/meta.js'
import { metaOf as videoMeta } from './nlVideo/meta.js'
import { metaOf as youTubeMeta } from './nlYouTube/meta.js'

export { loaders } from './loaders.js'

/**
 * One widget's description, as the editor reads it.
 *
 * @typedef {object} SiteWidgetMeta
 * @property {string} key The widget key, unique and not a dashboard key.
 * @property {('content'|'nav'|'forms'|'feedback'|'mijn'|'layout')} group The palette group.
 * @property {string} label The Dutch label an editor reads.
 * @property {string} nlds The component's name on nldesignsystem.nl.
 * @property {Array<string>} synonyms Other words an editor may search for.
 * @property {Array<{name: string, kind: string, label: string}>} fields What the author may edit.
 * @property {{gridWidth: number, gridHeight: number}} defaultSize Its first geometry.
 * @property {('public'|'signedIn')} scope Whether it needs a signed-in visitor.
 */

/**
 * The same widgets as data, for the editor.
 *
 * @type {Record<string, SiteWidgetMeta>}
 */
export const metas = {
	nlHeading: headingMeta,
	nlParagraph: paragraphMeta,
	nlLink: linkMeta,
	nlLinkList: linkListMeta,
	nlList: listMeta,
	nlQuote: quoteMeta,
	nlButtonLink: buttonLinkMeta,
	nlActionGroup: actionGroupMeta,
	nlDescriptionList: descriptionListMeta,
	nlImage: imageMeta,
	nlTable: tableMeta,
	nlSeparator: separatorMeta,
	nlCodeBlock: codeBlockMeta,
	nlAccordion: accordionMeta,
	nlVideo: videoMeta,
	nlYouTube: youTubeMeta,
	nlAlert: alertMeta,
	nlNote: noteMeta,
	nlBanner: bannerMeta,
	nlDialog: dialogMeta,
	nlDrawer: drawerMeta,
	nlProgressBar: progressBarMeta,
	nlProgressCircle: progressCircleMeta,
	nlToggletip: toggletipMeta,
	nlLanguageNav: languageNavMeta,
	nlSignIn: signInMeta,
	nlTaskNav: taskNavMeta,
	nlTabs: tabsMeta,
	nlQuickTasks: quickTasksMeta,
	nlNewsList: newsListMeta,
	nlNewsArticle: newsArticleMeta,
	nlEventList: eventListMeta,
}

/**
 * The groups the palette shows, in the order REQ-SNW-001 fixes, with the
 * heading an editor reads above each one.
 *
 * @type {Array<{group: string, label: string}>}
 */
export const WIDGET_GROUPS = [
	{ group: 'content', label: 'Inhoud' },
	{ group: 'nav', label: 'Navigatie' },
	{ group: 'forms', label: 'Formulieren' },
	{ group: 'feedback', label: 'Terugkoppeling' },
	{ group: 'mijn', label: 'Mijn omgeving' },
	{ group: 'layout', label: 'Opmaak' },
]

/**
 * Whether a value is a sound widget meta.
 *
 * Checked rather than trusted, because a meta is the only description the
 * editor has: a missing label would render a key to an author, and a missing
 * group would drop the widget out of every heading in the palette and so out
 * of the palette itself.
 *
 * @param {object} meta The candidate.
 * @param {string} key The key it is registered under.
 * @return {Array<string>} What is wrong with it, empty when nothing is.
 */
export function metaProblems(meta, key) {
	const problems = []
	if (!meta || typeof meta !== 'object') {
		return [`${key}: no meta`]
	}

	if (meta.key !== key) {
		problems.push(`${key}: meta.key is ${JSON.stringify(meta.key)}`)
	}

	if (!WIDGET_GROUPS.some((entry) => entry.group === meta.group)) {
		problems.push(
			`${key}: group ${JSON.stringify(meta.group)} is not one of the six`,
		)
	}

	for (const field of ['label', 'nlds']) {
		if (typeof meta[field] !== 'string' || meta[field].trim() === '') {
			problems.push(`${key}: ${field} is empty`)
		}
	}

	if (!Array.isArray(meta.synonyms)) {
		problems.push(`${key}: synonyms is not a list`)
	}

	if (!Array.isArray(meta.fields)) {
		problems.push(`${key}: fields is not a list`)
	}

	const size = meta.defaultSize
	if (
		!size
		|| !Number.isInteger(size.gridWidth)
		|| !Number.isInteger(size.gridHeight)
	) {
		problems.push(`${key}: defaultSize is not two whole numbers`)
	}

	if (meta.scope !== 'public' && meta.scope !== 'signedIn') {
		problems.push(
			`${key}: scope ${JSON.stringify(meta.scope)} is neither public nor signedIn`,
		)
	}

	return problems
}

/**
 * Every meta's problems, so one test can report them all at once.
 *
 * @return {Array<string>} The problems across the registry.
 */
export function registryProblems() {
	const problems = []
	for (const key of Object.keys(metas)) {
		problems.push(...metaProblems(metas[key], key))
	}

	for (const key of Object.keys(loaders)) {
		if (!Object.hasOwn(metas, key)) {
			problems.push(
				`${key}: a loader with no meta, so the editor cannot offer it`,
			)
		}
	}

	for (const key of Object.keys(metas)) {
		if (!Object.hasOwn(loaders, key)) {
			problems.push(
				`${key}: a meta with no loader, so the site cannot render it`,
			)
		}
	}

	return problems
}
