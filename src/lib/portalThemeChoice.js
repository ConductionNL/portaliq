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
 * The widget's actions over an injected transport.
 *
 * @param {object} deps The collaborators.
 * @param {Function} deps.get url => Promise<{data}>
 * @param {Function} deps.put (url, body) => Promise<{data}>
 * @param {Function} deps.url (path, params) => string, path relative to the app
 * @return {object}
 */
export function createPortalThemeChoice({ get, put, url }) {
	return {
		/**
		 * The portal's theme and the sets it can adopt.
		 *
		 * @param {string} slug The portal slug.
		 * @return {Promise<object>}
		 */
		async load(slug) {
			if (!slug) {
				return {
					state: 'error',
					current: '',
					currentResolves: false,
					sets: [],
				}
			}
			try {
				const { data } = await get(
					url('/api/portals/{slug}/theme', { slug }),
				)
				return {
					state: 'ready',
					current: String(data?.current || ''),
					currentResolves: data?.currentResolves === true,
					sets: Array.isArray(data?.sets) ? data.sets : [],
				}
			} catch {
				return {
					state: 'error',
					current: '',
					currentResolves: false,
					sets: [],
				}
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
				const data = error?.response?.data || {}
				if (data.error === 'contrast') {
					return { outcome: 'contrast', verdict: data.verdict || {} }
				}
				if (data.error === 'unknown_theme') {
					return { outcome: 'unknown' }
				}
				return { outcome: 'failed' }
			}
		},
	}
}
