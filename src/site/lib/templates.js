/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * Named region templates — a whole portal shell as data.
 *
 * A TEMPLATE IS THE CONFORMANCE TEST FOR THE REGION MODEL. It is easy to build
 * a composition system that expresses the pages you had in mind while you built
 * it; the question is whether it expresses a real design somebody else made.
 * `conduction-docs` is that design, reproduced structurally from
 * docs.conduction.nl, and everything it cannot say is written down in
 * `openspec/changes/portal-page-composition/tasks.md` (task 6.3) rather than
 * patched over with bespoke CSS.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */

/**
 * The documentation-site shell: single-bar header, cobalt hero, card grid,
 * footer columns above a legal bar.
 *
 * Every value here is CONTENT, not styling. The cobalt, the type scale and the
 * band's geometry come from the `conduction-new` token set — a template that
 * carried its own colours would be a second theme system, and the portal's
 * theme would stop being the thing that decides how it looks.
 *
 * @type {object}
 */
export const CONDUCTION_DOCS = {
	header: [
		{
			widgetKey: 'brandHeader',
			props: {},
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: 2,
		},
	],

	hero: [
		{
			widgetKey: 'hero',
			props: {
				eyebrow: 'Documentatie',
				title: 'Alles wat u nodig heeft om te bouwen',
				subtitle:
					'Handleidingen, referenties en voorbeelden voor iedereen die met onze open source software werkt.',
				// LEVEL ONE, because the hero carries the page's heading when a
				// page has one — the renderer's own title `h1` steps aside for
				// exactly this case, and two would be worse than either.
				headingLevel: 1,
				titleIcon: 'arrow-right',
				actions: [
					{ label: 'Aan de slag', href: '/aan-de-slag', variant: 'primary' },
					{ label: 'Bekijk de API', href: '/api', variant: 'secondary' },
				],
			},
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: 6,
		},
	],

	main: [
		{
			widgetKey: 'cardGrid',
			props: {
				// A LEVEL TWO, under the hero's one. The editor warns on a
				// skipped level and this template must not be the thing that
				// trips it — a reference that fails its own guardrails teaches
				// the wrong lesson.
				headingLevel: 2,
				cards: [
					{
						icon: 'arrow-right',
						title: 'Aan de slag',
						description: 'Van installatie tot uw eerste werkende koppeling.',
						link: '/aan-de-slag',
						linkLabel: 'Aan de slag',
					},
					{
						icon: 'arrow-right',
						title: 'API-referentie',
						description: 'Elke endpoint, elk veld, met voorbeelden.',
						link: '/api',
						linkLabel: 'Naar de API',
					},
					{
						icon: 'arrow-right',
						title: 'Standaarden',
						description: 'Hoe wij ons verhouden tot de landelijke afspraken.',
						link: '/standaarden',
						linkLabel: 'Naar de standaarden',
					},
					{
						icon: 'external-link',
						title: 'Broncode',
						description: 'Alles wat wij maken is open source.',
						link: 'https://github.com/ConductionNL',
						linkLabel: 'Naar GitHub',
					},
				],
			},
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: 5,
		},
	],

	aside: [],

	footer: [
		{
			widgetKey: 'footerColumns',
			props: {},
			gridX: 0,
			gridY: 0,
			gridWidth: 12,
			gridHeight: 4,
		},
	],
}

/**
 * The templates a portal can adopt, by name.
 *
 * @type {Record<string, object>}
 */
export const TEMPLATES = {
	'conduction-docs': CONDUCTION_DOCS,
}

/**
 * One template's regions, or null.
 *
 * @param {string} name The template name.
 * @return {object|null} The regions.
 */
export function templateNamed(name) {
	return TEMPLATES[name] || null
}
