#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// site-pixel-match.spec.mjs: the drawn options the Zuiddrecht boards ask for
// (site-matches-the-zuiddrecht-boards), and the proof that a placement which
// asks for none of them renders exactly as it did before: every changed block
// is rendered with a school page's or the shipped demo's props and held to the
// HTML it rendered on origin/development (tests/fixtures/site-pixel-baseline.json).
//
// Usage:
//   node --test tests/site-pixel-match.spec.mjs
//
// @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { heroPopularLinks } from '../src/site/lib/blockProps.js'
import { ownBand, runsFor } from '../src/site/lib/gridPlacement.js'
import { menuLabelFor } from '../src/site/lib/shellData.js'
import { iconPathOf } from '../src/site/widgets/nlQuickTasks/iconPath.js'
import { loadSfc, renderSfc } from './support/render-sfc.mjs'
import { CASES, normalise } from './support/site-pixel-cases.mjs'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')
const baseline = JSON.parse(read('tests/fixtures/site-pixel-baseline.json'))
const site = JSON.parse(read('lib/Settings/sites/zuiddrecht.json'))
const home = site.pages.find((page) => page.route === '/')
const afval = site.pages.find((page) => page.route === '/afval')

test('a placement that names none of the new options renders as it did before', async () => {
	for (const entry of CASES) {
		assert.ok(entry.name in baseline, `${entry.name} has a baseline`)
		const html = normalise(
			await renderSfc(entry.file, entry.props, entry.stubs || {}),
		)
		assert.equal(html, baseline[entry.name], entry.name)
	}
})

test('a banner carries a lead and a link, and is a band only when asked', async () => {
	const html = await renderSfc('src/site/widgets/nlBanner/NlBanner.vue', {
		kind: 'notice',
		band: true,
		lead: 'Let op',
		text: 'Zaterdag open.',
		linkLabel: 'Bekijk de openingstijden',
		linkHref: '/contact',
	})
	assert.match(html, /nl-banner--notice/)
	assert.match(html, /<strong[^>]*class="nl-banner__lead"[^>]*>Let op<\/strong>/)
	assert.match(html, /data-testid="nl-banner-link"/)
	assert.match(html, /class="container utrecht-paragraph nl-banner__text/)
	assert.doesNotMatch(html, /nl-banner-close/)

	const unknownKind = await renderSfc('src/site/widgets/nlBanner/NlBanner.vue', {
		kind: 'shout',
		text: 'x',
		linkLabel: 'Ga',
		linkHref: 'javascript:alert(1)',
	})
	assert.match(unknownKind, /nl-banner--info/)
	assert.doesNotMatch(
		unknownKind,
		/nl-banner-link/,
		'an address the site cannot follow is no link',
	)

	assert.equal(ownBand('nlBanner', { props: { band: true } }), true)
	assert.equal(ownBand('nlBanner', { props: { closable: true } }), false)
	assert.equal(ownBand('nlLinkColumns', {}), true)
	assert.equal(ownBand('nlLinkList', {}), false)
	const runs = runsFor(
		[
			{ widgetKey: 'nlBanner', gridY: 0, props: { band: true } },
			{ widgetKey: 'nlParagraph', gridY: 1 },
			{ widgetKey: 'nlLinkColumns', gridY: 2 },
		],
		ownBand,
	)
	assert.deepEqual(
		runs.map((run) => run.band),
		[true, false, true],
	)
})

test('the hero draws plain with popular links, six at most and followable only', async () => {
	assert.deepEqual(
		heroPopularLinks([
			{ label: 'Paspoort', href: '/paspoort' },
			{ label: '', href: '/leeg' },
			{ label: 'Elders', href: 'javascript:x' },
			{ label: 'Web', href: 'https://example.org' },
			...Array.from({ length: 6 }, (_, i) => ({
				label: `L${i}`,
				href: `/l${i}`,
			})),
		]).map((link) => link.label),
		['Paspoort', 'Web', 'L0', 'L1', 'L2', 'L3'],
	)
	const source = read('src/site/components/HeroBlock.vue')
	assert.match(source, /'pq-hero--plain': plain/)
	assert.match(source, /data-testid="hero-popular"/)
	assert.match(
		source,
		/ac-card ac-card--blue ac-card--padding-lg/,
		'the card variant stays',
	)
})

test("the task tiles draw the author's own path, held to path data", () => {
	const board =
		'M7 3h10a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z'
	assert.equal(
		iconPathOf({ icon: 'card', iconPath: board }),
		board,
		'the own path wins',
	)
	assert.notEqual(
		iconPathOf({ icon: 'card', iconPath: '<svg onload=x>' }),
		'<svg onload=x>',
	)
	assert.equal(
		iconPathOf({ icon: 'card', iconPath: 'M1 1 <b>' }).includes('<'),
		false,
	)
	assert.equal(
		iconPathOf({ icon: 'card', iconPath: 'M'.repeat(401) }).length < 401,
		true,
	)
	assert.equal(iconPathOf({ icon: 'nothing' }), '')
	// Rendered, not grepped: the first version of this test read the classes
	// in the source while the props that switch them were never declared.
	const html = await renderSfc('src/site/widgets/nlQuickTasks/NlQuickTasks.vue', {
		heading: 'Direct regelen',
		iconStyle: 'plain',
		narrow: 'list',
		narrowHeading: 'Veel gezocht',
		narrowLimit: 2,
		items: [
			{ label: 'A', href: '/a', iconPath: board },
			{ label: 'B', href: '/b', icon: 'card' },
			{ label: 'C', href: '/c' },
		],
	})
	assert.match(html, /nl-quick-tasks--plain-icons/)
	assert.match(html, /nl-quick-tasks--narrow-list/)
	assert.match(html, /nl-quick-tasks__heading-narrow[^>]*>Veel gezocht</)
	assert.equal((html.match(/nl-quick-tasks__item--beyond-narrow/g) || []).length, 1, 'the third row is beyond the phone limit')
	assert.match(html, new RegExp(`<path d="${board.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"`))
})

test('a link list draws as a card or under an accent line, with an intro', async () => {
	const card = await renderSfc('src/site/widgets/nlLinkList/NlLinkList.vue', {
		heading: 'Openbare informatie',
		intro: 'Besluiten, rapporten.',
		display: 'card',
		links: [{ label: 'Zoeken', href: '/zoeken' }],
	})
	assert.match(card, /nl-link-list--card/)
	assert.ok(
		card.indexOf('Openbare informatie') < card.indexOf('Besluiten, rapporten.')
			&& card.indexOf('Besluiten, rapporten.') < card.indexOf('Zoeken'),
		'heading, intro, link, in that order',
	)
	const accent = await renderSfc('src/site/widgets/nlLinkList/NlLinkList.vue', {
		display: 'accent',
		links: [{ label: 'A', href: '/a' }],
	})
	assert.match(accent, /nl-link-list--accent/)
	const odd = await renderSfc('src/site/widgets/nlLinkList/NlLinkList.vue', {
		display: 'neon',
		links: [{ label: 'A', href: '/a' }],
	})
	assert.doesNotMatch(
		odd,
		/nl-link-list--/,
		'an unknown display is plain, with the markup plain has',
	)
})

test('link columns draw a heading over at most four columns in a container', async () => {
	const html = await renderSfc(
		'src/site/widgets/nlLinkColumns/NlLinkColumns.vue',
		{
			heading: 'Bestuur en organisatie',
			columns: [
				{
					title: 'Gemeenteraad',
					links: [{ label: 'Raadsleden', href: '/bestuur/raadsleden' }],
				},
				{ title: 'Leeg', links: [{ label: '', href: '/x' }] },
				{
					title: 'College',
					links: [{ label: 'B en W', href: '/bestuur/college' }],
				},
				{ title: 'Drie', links: [{ label: 'D', href: '/d' }] },
				{ title: 'Vier', links: [{ label: 'V', href: '/v' }] },
				{ title: 'Vijf', links: [{ label: 'X', href: '/xx' }] },
			],
		},
	)
	assert.match(html, /nl-link-columns--surface/)
	assert.match(html, /class="container nl-link-columns__inner"/)
	assert.equal((html.match(/data-testid="nl-link-column"/g) || []).length, 4)
	assert.doesNotMatch(html, /Leeg/, 'a column without a usable link is left out')
	assert.match(html, /aria-label="Gemeenteraad"/)
})

test('a lookup form is a GET form to a page of the site, fields held to a name and a label', async () => {
	const props = {
		fields: [
			{
				name: 'postcode',
				label: 'Postcode',
				value: '3311 AB',
				width: '10rem',
				autocomplete: 'postal-code',
			},
			{ name: 'huis nummer', label: 'Huisnummer', width: '12px' },
			{ name: '', label: 'Naamloos' },
			{ name: 'x', label: '' },
		],
		buttonLabel: 'Toon mijn afvalkalender',
		href: '/afval',
	}
	const html = await renderSfc(
		'src/site/widgets/nlLookupForm/NlLookupForm.vue',
		props,
	)
	assert.match(html, /<form[^>]*method="get"/)
	assert.match(html, /<label[^>]*for="nl-lookup-postcode"[^>]*>Postcode<\/label>/)
	assert.match(html, /<label[^>]*for="nl-lookup-huisnummer"/)
	assert.doesNotMatch(html, /Naamloos/)
	assert.doesNotMatch(html, /12px/, 'a width not in rem or ch is dropped')
	assert.match(html, /autocomplete="postal-code"/)

	const nowhere = await renderSfc(
		'src/site/widgets/nlLookupForm/NlLookupForm.vue',
		{
			...props,
			href: 'javascript:alert(1)',
		},
	)
	assert.doesNotMatch(
		nowhere,
		/<form/,
		'no form to an address the site cannot follow',
	)

	const component = await loadSfc('src/site/widgets/nlLookupForm/NlLookupForm.vue')
	const emitted = []
	const self = {
		safeFields: component.methods.safeFieldsOf(props.fields),
		values: { postcode: '3311 AB', huisnummer: '12' },
		target: { href: '/afval', route: '/afval' },
		$emit: (name, value) => emitted.push([name, value]),
	}
	let prevented = false
	component.methods.submit.call(self, {
		preventDefault: () => {
			prevented = true
		},
		defaultPrevented: false,
		button: 0,
	})
	assert.equal(prevented, true)
	assert.deepEqual(emitted, [
		['navigate', '/afval?postcode=3311+AB&huisnummer=12'],
	])
})

test('a lead paragraph, a boxed table without visible caption and a chevron button', async () => {
	const lead = await renderSfc('src/site/widgets/nlParagraph/NlParagraph.vue', {
		text: 'Intro',
		lead: true,
	})
	assert.match(lead, /utrecht-paragraph--lead/)
	const table = await renderSfc('src/site/widgets/nlTable/NlTable.vue', {
		caption: 'Containers',
		columns: ['A'],
		rows: [['1']],
		display: 'boxed',
		captionVisible: false,
	})
	assert.match(table, /nl-table--boxed/)
	assert.match(
		table,
		/<caption[^>]*nl-table__caption--hidden[^>]*>\s*Containers\s*<\/caption>/,
	)
	const button = await renderSfc(
		'src/site/widgets/nlButtonLink/NlButtonLink.vue',
		{
			label: 'Grof afval laten ophalen',
			href: '/mijn',
			icon: 'chevron',
		},
	)
	assert.match(button, /nl-button-link__chevron/)
	const news = read('src/site/widgets/nlNewsList/NlNewsList.vue')
	assert.match(news, /data-testid="nl-news-lead-placeholder"/)
})

test('a breadcrumb reads like the menu', () => {
	const menus = [
		{
			position: 0,
			items: [
				{ name: 'Afval', link: '/afval' },
				{
					name: 'Bestuur',
					link: '/bestuur',
					items: [{ name: 'Raad', link: '/bestuur/raad' }],
				},
			],
		},
		{ position: 1, items: [{ name: 'Afvalkalender (voet)', link: '/afval' }] },
	]
	assert.equal(
		menuLabelFor(menus, '/afval'),
		'Afval',
		'the header menu, not the footer',
	)
	assert.equal(menuLabelFor(menus, '/afval/'), 'Afval')
	assert.equal(menuLabelFor(menus, '/bestuur/raad'), 'Raad')
	assert.equal(menuLabelFor(menus, '/parkeren'), '')
	assert.match(read('src/site/App.vue'), /menuLabelFor\(this\.menus, route\)/)
})

test('the document language follows the portal', () => {
	const controller = read('lib/Controller/PortalPageController.php')
	assert.match(controller, /localeThePortalServes\(string \$locale\)/)
	assert.match(controller, /\$portal\['locales'\]/)
	assert.match(controller, /return \$declared\[0\];/)
})

test('the Zuiddrecht declaration follows the boards and keeps every route', () => {
	const widgets = home.body.widgets
	const keyOf = (id) => widgets.find((widget) => widget.id === id)
	assert.deepEqual(
		widgets.map((widget) => widget.widgetKey),
		[
			'nlBanner',
			'hero',
			'nlQuickTasks',
			'nlNewsList',
			'nlSignIn',
			'nlLinkList',
			'nlLinkColumns',
		],
	)
	const banner = keyOf('zd-home-01-nlbanner').props
	assert.equal(banner.kind, 'notice')
	assert.equal(banner.band, true)
	assert.equal(banner.lead, 'Let op')
	assert.equal(banner.linkLabel, 'Bekijk de openingstijden')
	const hero = keyOf('zd-home-02-hero').props
	assert.equal(hero.variant, 'plain')
	assert.deepEqual(
		hero.popularLinks.map((link) => link.label),
		['Paspoort verlengen', 'Grof afval', 'Verhuizing doorgeven', 'Uittreksel'],
	)
	const tasks = keyOf('zd-home-03-nlquicktasks').props
	assert.equal(tasks.columns, 3)
	assert.equal(tasks.iconStyle, 'plain')
	assert.equal(tasks.narrow, 'list')
	assert.equal(tasks.narrowHeading, 'Veel gezocht')
	assert.equal(tasks.narrowLimit, 6)
	for (const item of tasks.items) {
		assert.equal(
			iconPathOf(item),
			item.iconPath,
			`${item.label} draws the board's icon`,
		)
	}
	assert.equal(
		keyOf('zd-home-05-nlsignin').props.buttonLabel,
		'Inloggen met DigiD',
	)
	assert.equal(keyOf('zd-home-06-nllinklist').props.display, 'card')
	const columns = keyOf('zd-home-07-nllinkcolumns').props.columns
	assert.deepEqual(
		columns.map((column) => column.title),
		['Gemeenteraad', 'College', 'Organisatie'],
	)
	assert.equal(columns.flatMap((column) => column.links).length, 9)

	const content = afval.body.widgets
	assert.equal(
		content.find((widget) => widget.widgetKey === 'nlLookupForm').props.href,
		'/afval',
	)
	assert.equal(
		content.find((widget) => widget.widgetKey === 'nlTable').props.display,
		'boxed',
	)
	assert.equal(
		content.find((widget) => widget.widgetKey === 'nlButtonLink').props.icon,
		'chevron',
	)
	assert.equal(
		content.find((widget) => widget.widgetKey === 'nlLinkList').props.display,
		'accent',
	)

	// THE ROUTE RULE: nothing reachable before this change may become unreachable.
	// These are the 33 routes the declaration carried on origin/development.
	const before = [
		'/',
		'/afval',
		'/afval/afvalbrengstation',
		'/afval/container',
		'/afval/afvalstoffenheffing',
		'/wonen-en-leven',
		'/paspoort-en-id-kaart',
		'/verhuizing-doorgeven',
		'/melding-openbare-ruimte',
		'/afspraak-maken',
		'/belastingen',
		'/parkeren',
		'/ondernemen',
		'/bestuur',
		'/bestuur/vergaderingen',
		'/bestuur/raadsleden',
		'/bestuur/inspreken',
		'/bestuur/college',
		'/bestuur/collegebesluiten',
		'/bestuur/coalitieakkoord',
		'/bestuur/inkoop',
		'/werken-bij-zuiddrecht',
		'/klacht',
		'/contact',
		'/onderwerpen',
		'/toegankelijkheid',
		'/privacy',
		'/cookies',
		'/kwetsbaarheid-melden',
		'/nieuws',
		'/nieuwsoverzicht',
		'/zoeken',
		'/publicatie',
	]
	const routes = site.pages
		.filter((page) => page.status === 'published')
		.map((page) => page.route)
	for (const route of before) {
		assert.ok(routes.includes(route), `${route} is still a published page`)
	}
	// And the links the home page lost as link lists still point somewhere: every
	// column link is a page.
	for (const link of columns.flatMap((column) => column.links)) {
		assert.ok(routes.includes(link.href), `${link.href} is a page`)
	}
})

test('the site stylesheet reads the two optional tokens and draws the plain hero', () => {
	const css = read('css/site-theme.css')
	assert.match(
		css,
		/--nldesign-website-page-title-size,\s+var\(--utrecht-heading-2-font-size, revert\)/,
	)
	assert.match(
		css,
		/\.pq-site \.ac-hero\.pq-hero--plain \.ac-search-box__input\.utrecht-textbox/,
	)
	assert.match(css, /--nldesign-website-hero-decoration-opacity, 0\.1/)
})
