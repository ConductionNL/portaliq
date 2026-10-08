#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// example-site.spec.mjs: every shipped example site (lib/Settings/sites/*.json)
// held against what it is imported into and rendered by:
//
//   - the `portal`, `menu`, `page` and `newsItem` schemas of the register this
//     app ships. OpenRegister does not keep a key a schema does not declare,
//     and says nothing, so an unknown key here is content that never arrives;
//   - the widgets the public site renders and the props each one takes;
//   - the pages its own links point at.
//
// The install proves the same on a live instance (ExampleSiteInstaller reads
// back what it wrote). This check is the half that runs without one.
//
// Usage:
//   node --test tests/example-site.spec.mjs
//
// @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md

import assert from 'node:assert/strict'
import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { test } from 'node:test'

const root = new URL('../', import.meta.url)
const read = (path) => readFileSync(new URL(path, root), 'utf8')

const register = JSON.parse(read('lib/Settings/portaliq_register.json'))
const schemas = register.components.schemas
const siteFiles = readdirSync(new URL('lib/Settings/sites/', root)).filter((f) =>
	f.endsWith('.json'),
)
const sites = siteFiles.map((file) => ({
	file,
	site: JSON.parse(read(`lib/Settings/sites/${file}`)),
}))

/**
 * Hold a value against a schema node and collect what does not fit.
 *
 * Strict where OpenRegister is silent: a key the schema does not declare is
 * an error, unless the node says `additionalProperties: true`.
 *
 * @param {*} value The declared value.
 * @param {object} schema The schema node.
 * @param {string} path Where we are, for the message.
 * @param {string[]} problems Collected problems.
 */
function hold(value, schema, path, problems) {
	if (!schema || typeof schema !== 'object') {
		return
	}
	const type = schema.type
	if (type === 'object') {
		if (value === null || typeof value !== 'object' || Array.isArray(value)) {
			problems.push(`${path}: expected an object`)
			return
		}
		for (const key of schema.required || []) {
			if (!(key in value)) {
				problems.push(`${path}.${key}: required and missing`)
			}
		}
		if (!schema.properties) {
			return
		}
		for (const [key, child] of Object.entries(value)) {
			if (key in schema.properties) {
				hold(child, schema.properties[key], `${path}.${key}`, problems)
			} else if (schema.additionalProperties !== true) {
				problems.push(`${path}.${key}: the schema does not declare this key`)
			}
		}
		return
	}
	if (type === 'array') {
		if (!Array.isArray(value)) {
			problems.push(`${path}: expected a list`)
			return
		}
		value.forEach((item, index) =>
			hold(item, schema.items, `${path}[${index}]`, problems),
		)
		return
	}
	const fits = {
		string: typeof value === 'string',
		integer: Number.isInteger(value),
		number: typeof value === 'number',
		boolean: typeof value === 'boolean',
	}
	if (type in fits && !fits[type]) {
		problems.push(`${path}: expected ${type}, got ${JSON.stringify(value)}`)
		return
	}
	if (schema.enum && !schema.enum.includes(value)) {
		problems.push(
			`${path}: ${JSON.stringify(value)} is not one of ${schema.enum}`,
		)
	}
	if (
		schema.maxLength
		&& typeof value === 'string'
		&& value.length > schema.maxLength
	) {
		problems.push(`${path}: longer than ${schema.maxLength}`)
	}
	if (schema.format === 'date-time' && Number.isNaN(Date.parse(value))) {
		problems.push(`${path}: not a date and time`)
	}
}

/**
 * The prop names a Vue component declares, read from its source.
 *
 * @param {string} path The component, from the repository root.
 * @return {string[]} The prop names.
 */
function propsOfVue(path) {
	const source = read(path)
	const start = source.indexOf('\tprops: {')
	assert.ok(start > 0, `${path} declares props`)
	const end = source.indexOf('\n\t},', start)
	return [...source.slice(start, end).matchAll(/^\t\t([A-Za-z]+): [{[A-Z]/gm)].map(
		(match) => match[1],
	)
}

// The widgets the public site renders: the blocks WidgetGrid names itself and
// the NL Design System widgets, each with the props it takes.
const widgetGrid = read('src/site/components/WidgetGrid.vue')
const publicMap = widgetGrid.slice(
	widgetGrid.indexOf('const PUBLIC_WIDGETS = {'),
	widgetGrid.indexOf('\n}\n', widgetGrid.indexOf('const PUBLIC_WIDGETS = {')),
)
const blockComponents = {
	hero: 'src/site/components/HeroBlock.vue',
	federatedSearch: 'src/site/components/FederatedSearchBlock.vue',
	publicationDetail: 'src/site/components/PublicationDetailBlock.vue',
}
const widgetProps = {}
for (const [key, path] of Object.entries(blockComponents)) {
	assert.match(
		publicMap,
		new RegExp(`\\n\\t${key}: `),
		`${key} is a public widget`,
	)
	widgetProps[key] = propsOfVue(path)
}
assert.match(
	publicMap,
	/\.\.\.nldsWidgets/,
	'the NL Design System widgets are public',
)
for (const dir of readdirSync(new URL('src/site/widgets/', root))) {
	if (existsSync(new URL(`src/site/widgets/${dir}/meta.js`, root))) {
		const { metaOf } = await import(
			new URL(`src/site/widgets/${dir}/meta.js`, root)
		)
		if (metaOf.scope === 'public') {
			widgetProps[metaOf.key] = metaOf.fields.map((field) => field.name)
		}
	}
}
const { default: taskIcons } = await import(
	new URL('src/site/widgets/nlQuickTasks/icons.js', root)
)

/**
 * Every address a declaration links to, with where it stands.
 *
 * @param {object} site The declaration.
 * @return {Array<{href: string, where: string}>} The links.
 */
function linksOf(site) {
	const links = []
	for (const menu of site.menus) {
		for (const item of menu.items || []) {
			links.push({
				href: item.link,
				where: `menu "${menu.title}" item "${item.name}"`,
			})
			for (const child of item.items || []) {
				links.push({
					href: child.link,
					where: `menu "${menu.title}" item "${child.name}"`,
				})
			}
		}
	}
	const footer = site.portal.footer || {}
	if (footer.cta) {
		links.push({ href: footer.cta.href, where: 'footer button' })
	}
	for (const line of footer.contact?.lines || []) {
		if (line.href) {
			links.push({ href: line.href, where: `footer contact "${line.text}"` })
		}
	}
	for (const key of ['socials', 'legalLinks', 'badges']) {
		for (const entry of footer[key] || []) {
			links.push({ href: entry.href, where: `footer ${key} "${entry.label}"` })
		}
	}
	if (site.portal.headerSearch) {
		links.push({
			href: site.portal.headerSearch.route || '/zoeken',
			where: 'header search',
		})
	}
	for (const page of site.pages) {
		for (const widget of page.body.widgets || []) {
			const props = widget.props || {}
			const where = `page ${page.route} widget ${widget.id}`
			for (const key of [
				'href',
				'moreHref',
				'articleRoute',
				'signInHref',
				'linkHref',
			]) {
				if (props[key]) {
					links.push({ href: props[key], where: `${where} ${key}` })
				}
			}
			for (const key of [
				'items',
				'links',
				'actions',
				'buttons',
				'popularLinks',
			]) {
				for (const entry of Array.isArray(props[key]) ? props[key] : []) {
					if (entry && entry.href) {
						links.push({
							href: entry.href,
							where: `${where} ${key} "${entry.label}"`,
						})
					}
				}
			}
			// The link columns' links (site-matches-the-zuiddrecht-boards).
			for (const column of Array.isArray(props.columns) ? props.columns : []) {
				for (const entry of Array.isArray(column?.links)
					? column.links
					: []) {
					if (entry && entry.href) {
						links.push({
							href: entry.href,
							where: `${where} column "${column.title}" "${entry.label}"`,
						})
					}
				}
			}
		}
	}
	return links
}

/**
 * Every string in a value, with its path.
 *
 * @param {*} value The value.
 * @param {string} path The path so far.
 * @return {Array<{text: string, path: string}>} The strings.
 */
function stringsOf(value, path = '') {
	if (typeof value === 'string') {
		return [{ text: value, path }]
	}
	if (value && typeof value === 'object') {
		return Object.entries(value).flatMap(([key, child]) =>
			stringsOf(child, `${path}.${key}`),
		)
	}
	return []
}

test('a site ships, and each file names itself', () => {
	assert.ok(sites.length > 0, 'at least one example site ships')
	for (const { file, site } of sites) {
		assert.equal(`${site.id}.json`, file, `${file} carries its own id`)
		assert.match(site.id, /^[a-z0-9][a-z0-9-]{0,39}$/)
		assert.ok(site.name && site.description, `${file} says what it is`)
	}
})

test('the Zuiddrecht site holds what the design shows', () => {
	const site = sites.find((entry) => entry.site.id === 'zuiddrecht').site
	assert.equal(site.portal.theme, 'zuiddrecht')
	assert.equal(site.portal.accountLabel, 'Mijn Zuiddrecht')
	assert.equal(site.portal.headerSearch.placeholder, 'Zoeken')
	// The main menu of the Kop board, in its order.
	const main = site.menus.find((menu) => menu.position === 0)
	assert.deepEqual(
		main.items.map((item) => item.name),
		[
			'Home',
			'Wonen en leven',
			'Afval',
			'Parkeren',
			'Ondernemen',
			'Woo-publicaties',
			'Bestuur en organisatie',
		],
	)
	// The footer of the Voet board: the two link columns and the bottom line.
	assert.deepEqual(
		site.menus.filter((menu) => menu.position === 1).map((menu) => menu.title),
		['Snel naar', 'Over deze website'],
	)
	assert.match(
		site.portal.footer.colophon,
		/^Gemeente Zuiddrecht is een voorbeeldgemeente\./,
	)
	// The home page of the Home board: the eight tasks under "Direct regelen".
	const home = site.pages.find((page) => page.route === '/')
	const tasks = home.body.widgets.find(
		(widget) => widget.widgetKey === 'nlQuickTasks',
	)
	assert.equal(tasks.props.heading, 'Direct regelen')
	assert.equal(tasks.props.items.length, 8)
	assert.equal(
		home.body.widgets.find((widget) => widget.widgetKey === 'hero').props.title,
		'Wat wilt u regelen?',
	)
	// Counts the installer's own test and the documentation name.
	assert.equal(site.menus.length, 3)
	assert.equal(site.pages.length, 33)
	assert.equal(site.news.length, 4)
})

for (const { file, site } of sites) {
	test(`${file}: every object fits the schema it is imported into`, () => {
		const problems = []
		hold(site.portal, schemas.portal, 'portal', problems)
		site.menus.forEach((menu, index) =>
			hold(
				{ ...menu, portal: site.portal.slug },
				schemas.menu,
				`menus[${index}]`,
				problems,
			),
		)
		site.pages.forEach((page) =>
			hold(
				{ ...page, portal: site.portal.slug },
				schemas.page,
				`page ${page.route}`,
				problems,
			),
		)
		site.news.forEach((item) =>
			hold(
				{ ...item, portal: site.portal.slug },
				schemas.newsItem,
				`news "${item.title}"`,
				problems,
			),
		)
		assert.deepEqual(problems, [])
	})

	test(`${file}: the portal is one a visitor can open`, () => {
		assert.equal(site.portal.status, 'published')
		assert.ok(
			site.portal.authentication.modes.includes('public'),
			'without `public` the content API serves a visitor nothing',
		)
		// An install hook has no business claiming a hostname.
		assert.deepEqual(site.portal.domains, [])
		assert.ok(
			site.news.every(
				(item) => item.public === true && item.status === 'published',
			),
			'a news item that is not public and published never shows on the site',
		)
	})

	test(`${file}: what tells objects apart is unique`, () => {
		const routes = site.pages.map((page) => page.route)
		assert.equal(new Set(routes).size, routes.length, 'two pages share a route')
		const menus = site.menus.map((menu) => `${menu.position} ${menu.title}`)
		assert.equal(
			new Set(menus).size,
			menus.length,
			'two menus share position and title',
		)
		const titles = site.news.map((item) => item.title)
		assert.equal(
			new Set(titles).size,
			titles.length,
			'two news items share a title',
		)
		for (const page of site.pages) {
			assert.match(
				page.route,
				/^\/(?!\/)[a-z0-9/-]*$/,
				`${page.route} is a plain path`,
			)
			const ids = page.body.widgets.map((widget) => widget.id)
			assert.equal(
				new Set(ids).size,
				ids.length,
				`${page.route}: two widgets share an id`,
			)
		}
	})

	test(`${file}: every widget is one the site renders, with props it takes`, () => {
		const problems = []
		for (const page of site.pages) {
			assert.equal(page.body.type, 'grid')
			const cells = new Set()
			for (const widget of page.body.widgets) {
				const where = `page ${page.route} widget ${widget.id}`
				const known = widgetProps[widget.widgetKey]
				if (!known) {
					problems.push(
						`${where}: ${widget.widgetKey} is not a public widget`,
					)
					continue
				}
				// The schema refuses an empty props object and a null one.
				if (!widget.props || Object.keys(widget.props).length === 0) {
					problems.push(`${where}: props must hold at least one key`)
				}
				for (const prop of Object.keys(widget.props || {})) {
					if (!known.includes(prop)) {
						problems.push(
							`${where}: ${widget.widgetKey} takes no prop "${prop}"`,
						)
					}
				}
				if (widget.gridX + widget.gridWidth > 12) {
					problems.push(`${where}: runs past the twelfth column`)
				}
				for (
					let x = widget.gridX;
					x < widget.gridX + widget.gridWidth;
					x++
				) {
					for (
						let y = widget.gridY;
						y < widget.gridY + widget.gridHeight;
						y++
					) {
						if (cells.has(`${x},${y}`)) {
							problems.push(
								`${where}: overlaps another widget at ${x},${y}`,
							)
						}
						cells.add(`${x},${y}`)
					}
				}
				if (widget.widgetKey === 'nlQuickTasks') {
					for (const item of widget.props.items) {
						if (item.icon && !Object.hasOwn(taskIcons, item.icon)) {
							problems.push(`${where}: no task icon "${item.icon}"`)
						}
					}
				}
			}
		}
		assert.deepEqual([...new Set(problems)], [])
	})

	test(`${file}: every link leads to a page of the site or the own area`, () => {
		const routes = new Set(site.pages.map((page) => page.route))
		const dead = linksOf(site)
			.filter(({ href }) => !/^(https?:\/\/|mailto:|tel:)/.test(href))
			.filter(
				({ href }) =>
					!routes.has(href)
					&& href !== '/mijn'
					&& !href.startsWith('/mijn/'),
			)
			.map(({ href, where }) => `${where} -> ${href}`)
		assert.deepEqual(dead, [])

		// A news list opens its items on a page that holds the article block.
		for (const page of site.pages) {
			for (const widget of page.body.widgets) {
				if (widget.widgetKey !== 'nlNewsList') {
					continue
				}
				const target = site.pages.find(
					(candidate) =>
						candidate.route === (widget.props.articleRoute || '/nieuws'),
				)
				assert.ok(
					target?.body.widgets.some(
						(w) => w.widgetKey === 'nlNewsArticle',
					),
					`page ${page.route}: the news list opens items on a page without the article block`,
				)
			}
		}
		// Every page can be reached: a page no link names is dead weight,
		// except the detail page a search result opens by id.
		const linked = new Set(linksOf(site).map(({ href }) => href))
		const orphans = site.pages
			.map((page) => page.route)
			.filter(
				(route) =>
					route !== '/' && route !== '/publicatie' && !linked.has(route),
			)
		assert.deepEqual(orphans, [])
	})

	test(`${file}: the text follows the house style`, () => {
		const all = stringsOf({
			portal: site.portal,
			menus: site.menus,
			pages: site.pages,
			news: site.news,
		})
		const dashes = all.filter(({ text }) => /[—–]|--/.test(text))
		assert.deepEqual(
			dashes.map(({ path }) => path),
			[],
			'no em-dash, en-dash or double hyphen',
		)
		const lorem = all.filter(({ text }) =>
			/lorem ipsum|voorbeeld titel|todo/i.test(text),
		)
		assert.deepEqual(
			lorem.map(({ path }) => path),
			[],
		)
		// Sentence case: a heading or label has one capital to start with,
		// and after that only names carry one.
		const names =
			/Zuiddrecht|DigiD|eHerkenning|Woo|ID-kaart|Lindelaan|Havenpark|Nextcloud|TenderNed|WCAG|AA|A tot Z|Mijn|PDF/g
		const titled = []
		for (const page of site.pages) {
			titled.push(page.title)
			for (const widget of page.body.widgets) {
				titled.push(
					widget.props.heading,
					widget.props.title,
					widget.props.label,
				)
				if (widget.widgetKey === 'nlHeading') {
					titled.push(widget.props.text)
				}
			}
		}
		for (const menu of site.menus) {
			titled.push(menu.title, ...menu.items.map((item) => item.name))
		}
		const titleCase = titled
			.filter(Boolean)
			.filter((text) => /\s[A-Z]/.test(text.replace(names, 'x')))
		assert.deepEqual(titleCase, [])
	})
}

test("a link in a link list or a button link is the site's own address", async () => {
	// The address a visitor has when the portal is served through Nextcloud.
	globalThis.window = {
		location: {
			href: 'http://localhost/index.php/apps/portaliq/site?portal=zuiddrecht&route=%2F',
		},
	}
	const { authoredLink, staysInSite } = await import(
		new URL('src/site/components/mijn/links.js', root)
	)
	const link = authoredLink('/afval')
	const address = new URL(link.href)
	assert.equal(address.pathname, '/index.php/apps/portaliq/site')
	assert.equal(address.searchParams.get('portal'), 'zuiddrecht')
	assert.equal(address.searchParams.get('route'), '/afval')
	assert.equal(link.route, '/afval')
	assert.equal(staysInSite({ button: 0 }, link), true)
	assert.equal(staysInSite({ button: 0, ctrlKey: true }, link), false)
	assert.equal(authoredLink('https://www.tenderned.nl').route, '')
	assert.equal(authoredLink('javascript:alert(1)'), null)
	delete globalThis.window

	// Both widgets use it, emit the route, and the group of buttons passes it on.
	for (const path of [
		'src/site/widgets/nlLinkList/NlLinkList.vue',
		'src/site/widgets/nlButtonLink/NlButtonLink.vue',
	]) {
		const source = read(path)
		assert.match(
			source,
			/import \{ authoredLink, staysInSite \} from '\.\.\/\.\.\/components\/mijn\/links\.js'/,
		)
		assert.match(source, /emits: \['navigate'\]/)
		assert.match(source, /this\.\$emit\('navigate', (this\.)?link\.route\)/)
		assert.doesNotMatch(source, /:href="(safeHref|link\.href \|\| )/)
	}
	assert.match(
		read('src/site/widgets/nlActionGroup/NlActionGroup.vue'),
		/@navigate="\$emit\('navigate', \$event\)"/,
	)
	// A list may name one page twice: the row's place is its key, not its address.
	assert.match(
		read('src/site/widgets/nlLinkList/NlLinkList.vue'),
		/v-for="\(link, index\) in safeLinks"\s+:key="index"/,
	)
})

test('the current menu item may sit in the line under the menu', () => {
	const css = read('css/site-theme.css')
	const start = css.indexOf('THE CURRENT ITEM IN THE LINE')
	assert.ok(start > 0, 'the rule is documented where it stands')
	const rule = css.slice(start, css.indexOf('}', css.indexOf('{', start)) + 1)
	// After the bar it moves, so it wins at equal specificity.
	assert.ok(start > css.indexOf("[aria-current='page']::after {"))
	assert.match(rule, /\[aria-current='page'\]::after \{/)
	// Without the tokens: no shift, four pixels, the accent.
	assert.match(
		rule,
		/--pq-nav-current-in-line: var\(--nldesign-website-nav-current-in-line, 0\)/,
	)
	assert.match(
		rule,
		/inset-block-end: calc\(\s*var\(--pq-nav-current-in-line\) \* -1 \* var\(--cn-brand-stripe-height, 0px\)\s*\)/,
	)
	assert.match(
		rule,
		/block-size: calc\(\s*4px \+ var\(--pq-nav-current-in-line\) \*\s*\(var\(--cn-brand-stripe-height, 4px\) - 4px\)\s*\)/,
	)
	assert.match(
		rule,
		/background-color: var\(\s*--nldesign-website-nav-current-color,\s*var\(--thematiq-accent-color, var\(--nldesign-color-primary\)\)\s*\)/,
	)
	// Token references only: this sheet carries no colour of its own.
	assert.doesNotMatch(
		rule.replace(/\/\*[\s\S]*?\*\//g, ''),
		/#[0-9a-f]{3,8}\b|rgb\(/i,
	)
})

test('the Zuiddrecht site offers its two ways in as the Inloggen board shows', () => {
	const site = sites.find((entry) => entry.site.id === 'zuiddrecht').site
	const auth = site.portal.authentication
	// A card for every way in the portal names, and for no other.
	assert.deepEqual(
		Object.keys(auth.modeLabels).sort(),
		auth.modes.filter((mode) => mode !== 'public').sort(),
	)
	assert.deepEqual(auth.modeLabels.digid, {
		title: 'Als inwoner',
		text: 'Voor uw eigen zaken, berichten en dossiers.',
		button: 'Inloggen met DigiD',
	})
	assert.equal(auth.modeLabels.eherkenning.title, 'Namens een bedrijf')
	assert.equal(auth.modeLabels.eherkenning.button, 'Inloggen met eHerkenning')
	assert.equal(auth.signInPage.title, 'Inloggen op Mijn Zuiddrecht')
	assert.match(auth.signInPage.intro, /^Kies hoe u inlogt\./)
	assert.match(auth.signInPage.notice.text, /^Geen DigiD\?/)
	// The shell serves a card only with a text; each has its three.
	for (const card of Object.values(auth.modeLabels)) {
		assert.ok(card.title && card.text && card.button)
	}
})

test('the own area carries the name the portal gives it', async () => {
	const { ownAreaLink, withAreaName } = await import(
		new URL('src/site/lib/residentMenu.js', root)
	)
	const { accountCrumbs } = await import(
		new URL('src/site/lib/accountArea.js', root)
	)
	const nl = (key) => ({ 'My area': 'Mijn omgeving', Home: 'Home' })[key] ?? key
	const named = withAreaName(nl, ' Mijn Zuiddrecht ')

	assert.equal(named('My area'), 'Mijn Zuiddrecht')
	assert.equal(named('Home'), 'Home', "every other string is the translator's")
	assert.deepEqual(
		accountCrumbs(null, named, (route) => route).map((crumb) => crumb.label),
		['Home', 'Mijn Zuiddrecht'],
	)
	assert.equal(
		ownAreaLink({ subjectRef: 'x' }, named, (route) => route).label,
		'Mijn Zuiddrecht',
	)

	// No name: the translator itself, so nothing changes for other portals.
	assert.equal(withAreaName(nl, ''), nl)
	assert.equal(withAreaName(nl, undefined), nl)
	assert.equal(withAreaName(nl, '   ')('My area'), 'Mijn omgeving')

	// The site's one translator is the named one.
	assert.match(
		read('src/site/App.vue'),
		/withAreaName\(\s*createTranslator\(this\.locale\),\s*this\.site\?\.accountLabel,?\s*\)/,
	)
})

test('a trail keeps its own room under the menu line', () => {
	const css = read('css/site-theme.css')
	const start = css.indexOf('A trail keeps the room the breadcrumb bar had')
	assert.ok(start > 0)
	const rule = css.slice(start, css.indexOf('}', css.indexOf('{', start)) + 1)
	// Only a bar that holds a trail: the home page keeps the motif's height.
	assert.match(
		rule,
		/\.ac-header__navigation-breadcrumb:not\(:has\(\.container:empty\)\) \{/,
	)
	// The motif's height plus the bar's own padding, each with a length to fall back on.
	assert.match(
		rule,
		/padding-block-start: calc\(\s*var\(--cn-brand-stripe-height, 0px\) \+\s*var\(\s*--utrecht-breadcrumb-nav-padding-block-start,\s*var\(--tilburg-space-block-mouse, 0px\)\s*\)\s*\)/,
	)
	// After the rule it adds to, so it wins at the same weight for a bar with a trail.
	assert.ok(start > css.indexOf('the breadcrumb starts under it. */'))
})

test('the Zuiddrecht site lays out its own area as the MijnMenu and MijnZaken boards draw it', () => {
	const site = sites.find((entry) => entry.site.id === 'zuiddrecht').site
	// The menu groups of the MijnMenu board, in order; Afspraken, Mijn
	// dossiers, Mijn zoekopdrachten, Mijn vragen and Mijn meldingen have no
	// page on this site, so they are not named (an item named here must exist).
	assert.deepEqual(
		site.portal.residentMenu.groups.map((group) => [group.title, group.items]),
		[
			['Mijn Zuiddrecht', ['overview', 'inbox']],
			['Zaken en taken', ['cases', 'tasks']],
			['Vragen en meldingen', ['portaliq:meldingen']],
			['Uw gegevens', ['details', 'account']],
		],
	)
	// Mijn zaken as rows (the MijnZaken board).
	assert.equal(site.portal.myCases.display, 'rows')
	// Both keys are in the portal schema, so OpenRegister keeps them.
	const portal = schemas.portal
	assert.ok(
		portal.properties.residentMenu.properties.groups,
		'residentMenu.groups is in the schema',
	)
	assert.deepEqual(portal.properties.myCases.properties.display.enum, [
		'cards',
		'rows',
	])
	assert.equal(portal.version, '0.16.0')
	assert.equal(register.info.version, '0.72.0')
})
