/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Theme widget on a portal's page, without the widget
 * (nldesign-theme-integration 1.4, 3.1, 3.2).
 *
 * It reads the sets the portal can wear, each with its contrast verdict, and
 * saves a choice. A set that fails AA on a portal surface is saved only after
 * the administrator confirms, and a verdict that measured nothing reads "not
 * checked", never as a pass.
 *
 * WHY THIS MODULE IMPORTS NOTHING. The GET, the PUT and the URL generator are
 * handed in by `src/widgets/PortalTheme.vue`, so
 * `tests/portal-theme-choice.spec.mjs` runs it as a plain node script.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */

/**
 * What a verdict says, in one word.
 *
 * @param {object} verdict `{evaluated, measured, passes, findings}`.
 * @return {'passes'|'fails'|'unchecked'}
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */
export function verdictState(verdict) {
	if (
		!verdict
		|| verdict.evaluated !== true
		|| Number(verdict.measured || 0) === 0
	) {
		return 'unchecked'
	}
	return verdict.passes === true ? 'passes' : 'fails'
}

/**
 * Whether a listed set can be chosen. A set the theme app's validator refused
 * (a shared or uploaded house style with a declaration it does not allow) is
 * listed with its reason and cannot be picked.
 *
 * @param {object} set A listed set.
 * @return {boolean}
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
export function isSelectable(set) {
	return !set?.refusal
}

/**
 * A portal whose theme could not be read.
 *
 * @return {{state: string, current: string, currentResolves: boolean, sets: Array<object>}} The empty view.
 */
function noTheme() {
	return {
		state: 'error',
		current: '',
		currentResolves: false,
		sets: [],
	}
}

/**
 * A portal's theme and the sets it can adopt, as the widget reads them.
 *
 * @param {object|null} data The theme endpoint's answer.
 * @return {{state: string, current: string, currentResolves: boolean, sets: Array<object>}} The view.
 */
function themeView(data) {
	return {
		state: 'ready',
		current: String(data?.current || ''),
		currentResolves: data?.currentResolves === true,
		sets: Array.isArray(data?.sets) ? data.sets : [],
	}
}

/**
 * What a refused save says, by the server's error.
 */
const SAVE_REFUSALS = {
	contrast: (data) => ({ outcome: 'contrast', verdict: data.verdict || {} }),
	unknown_theme: () => ({ outcome: 'unknown' }),
	refused: (data) => ({
		outcome: 'refused',
		refusal: String(data.refusal || ''),
	}),
}

/**
 * The outcome of a save the server refused.
 *
 * @param {object} data The refusal's body.
 * @return {object} `contrast`, `unknown`, `refused` or `failed`.
 */
function saveRefusal(data) {
	if (Object.hasOwn(SAVE_REFUSALS, data.error)) {
		return SAVE_REFUSALS[data.error](data)
	}
	return { outcome: 'failed' }
}

/**
 * The widget's actions over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get url => Promise<{data}>
 * @param {Function} deps.put (url, body) => Promise<{data}>
 * @param {Function} deps.url (path, params) => string, path relative to the app
 * @return {object}
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */
export function createPortalThemeChoice({ get, put, url }) {
	return {
		/**
		 * The portal's theme and the sets it can adopt.
		 *
		 * @param {string} slug The portal slug.
		 * @return {Promise<object>}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		async load(slug) {
			if (!slug) {
				return noTheme()
			}
			try {
				const { data } = await get(
					url('/api/portals/{slug}/theme', { slug }),
				)
				return themeView(data)
			} catch {
				return noTheme()
			}
		},

		/**
		 * Save a theme. Answers `contrast` with the findings when the set fails
		 * AA and the administrator has not confirmed.
		 *
		 * @param {string} slug The portal slug.
		 * @param {string} theme The set id.
		 * @param {boolean} acceptFindings Whether the administrator confirmed.
		 * @return {Promise<object>}
		 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
		 */
		async save(slug, theme, acceptFindings = false) {
			try {
				const { data } = await put(
					url('/api/portals/{slug}/theme', { slug }),
					{
						theme,
						acceptFindings,
					},
				)
				return { outcome: 'saved', data }
			} catch (error) {
				return saveRefusal(error?.response?.data || {})
			}
		},
	}
}
