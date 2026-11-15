// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The placements a school page or the shipped demo portal made BEFORE
// site-matches-the-zuiddrecht-boards, one per changed block, with the props
// they name and nothing the change added. tests/site-pixel-match.spec.mjs
// renders each with the current block and holds it to the HTML the block
// rendered on origin/development before the change
// (tests/fixtures/site-pixel-baseline.json): the proof that a placement that
// asks for none of the new options renders exactly as it did.

/** The library's public entry imports `.vue` files node cannot load. */
const PUBLIC_STUB = `
export const CnSiteIcon = { props: ['name', 'size'], template: '<i />' }
export const CnSiteSearch = { props: ['label', 'labelVisible', 'placeholder', 'submitLabel', 'value', 'inputId'], template: '<form role="search"><label :for="inputId">{{ label }}</label><input :id="inputId" :value="value" /><button type="submit">{{ submitLabel }}</button></form>' }
export const CnSiteSection = { props: ['variant', 'backgroundImage'], template: '<section :class="\\'ac-section ac-\\' + variant"><div class="container"><slot /></div></section>' }
`

export const CASES = [
	{
		name: 'banner: a school warning',
		file: 'src/site/widgets/nlBanner/NlBanner.vue',
		props: {
			kind: 'warning',
			text: 'Vrijdag is de school dicht.',
			closable: true,
		},
	},
	{
		name: 'hero: the shipped demo search',
		file: 'src/site/components/HeroBlock.vue',
		props: {
			title: 'Zoek in publicaties',
			search: true,
			searchLabel: 'Zoekterm',
			searchSubmitLabel: 'Zoeken',
			headingVisible: true,
			actions: [{ label: 'Alle publicaties', href: '/zoeken' }],
		},
		stubs: { '@conduction/nextcloud-vue/public': PUBLIC_STUB },
	},
	{
		name: 'quick tasks: a school card',
		file: 'src/site/widgets/nlQuickTasks/NlQuickTasks.vue',
		props: {
			heading: 'Direct regelen',
			columns: 4,
			overlap: true,
			items: [
				{ label: 'Ziek melden', href: '/ziek-melden', icon: 'heartPlus' },
				{ label: 'Verlof aanvragen', href: '/verlof', icon: 'calendar' },
				{
					label: 'Schoolgids',
					href: 'https://example.org/gids',
					icon: 'book',
				},
			],
			moreLabel: 'Alles',
			moreHref: '/alles',
		},
	},
	{
		name: 'link list: a school aside',
		file: 'src/site/widgets/nlLinkList/NlLinkList.vue',
		props: {
			heading: 'Meer',
			links: [
				{ label: 'Rooster', href: '/rooster', description: 'Per klas' },
				{ label: 'Ouderportaal', href: 'https://example.org' },
			],
		},
	},
	{
		name: 'paragraph: plain',
		file: 'src/site/widgets/nlParagraph/NlParagraph.vue',
		props: { text: 'Een alinea.' },
	},
	{
		name: 'table: plain',
		file: 'src/site/widgets/nlTable/NlTable.vue',
		props: {
			caption: 'Lestijden',
			columns: ['Dag', 'Van', 'Tot'],
			rows: [['Ma', '8.30', '14.30']],
		},
	},
	{
		name: 'button link: primary',
		file: 'src/site/widgets/nlButtonLink/NlButtonLink.vue',
		props: { label: 'Aanmelden', href: '/aanmelden', kind: 'primary' },
	},
	{
		name: 'sign-in card: a school card',
		file: 'src/site/widgets/nlSignIn/NlSignIn.vue',
		props: {
			heading: 'Mijn school',
			display: 'card',
			points: ['Zie het rooster'],
			buttonLabel: 'Inloggen',
			ways: [
				{
					id: 'digid',
					label: 'DigiD',
					href: '/mijn/digid',
					route: '/mijn/digid',
				},
			],
		},
	},
]

/**
 * Rendered HTML without what changes between builds: the scoped-style hash
 * and the SSR comments that stand for an absent branch.
 *
 * @param {string} html The rendered HTML.
 * @return {string} The same, comparable.
 */
export function normalise(html) {
	return html
		.replace(/ data-v-[0-9a-f]+=""/g, '')
		.replace(/<!--(\[|\]|v-if)?-->/g, '')
		.trim()
}
