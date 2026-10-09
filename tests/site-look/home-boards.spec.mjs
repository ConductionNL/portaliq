#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// home-boards.spec.mjs: the school home pages and footer read like their
// boards. Only the address of a footer contact line is a link; a hero action
// may be an underlined text link or carry a chevron; the month of a date
// tile follows the set's case; the hero aside draws one frame
// (site-home-follows-the-school-boards).
//
// Usage:
//   node --test tests/site-look/home-boards.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { heroActions } from '../../src/site/lib/blockProps.js'
import { contactLineParts } from '../../src/site/lib/footerLine.js'
import { renderSfc as render } from '../support/render-sfc.mjs'

const LIBRARY = {
	'@conduction/nextcloud-vue': "export const cnRenderMarkdown = () => ''\n",
	'@conduction/nextcloud-vue/public': [
		"import { h } from 'vue'",
		"export const CnSiteIcon = { props: ['name', 'size'], render() { return h('svg') } }",
		"export const CnSiteSection = { props: ['variant', 'backgroundImage'], render() { return h('section', { class: 'ac-hero' }, this.$slots.default?.()) } }",
		"export const CnSiteSearch = { render() { return h('form') } }",
		'export const siteBlockIsBand = () => false',
		'export const siteBlockRegistry = {}',
	].join('\n'),
}
/**
 * A component rendered, without Vue's markers.
 *
 * @param {string} file The component.
 * @param {object} props Its props.
 * @param {object} stubs Bare imports.
 * @return {Promise<string>}
 */
async function renderSfc(file, props, stubs) {
	return (await render(file, props, stubs)).replace(/<!--[\s\S]*?-->/g, '')
}

const css = readFileSync(
	new URL('../../css/site-theme.css', import.meta.url),
	'utf8',
)
	.replace(/\/\*[\s\S]*?\*\//g, '')
	.replace(/\s+/g, ' ')

test('only the address of a contact line is a link', async () => {
	assert.deepEqual(
		contactLineParts({ text: 'E-mail: [e-mailadres]', href: 'mailto:a@b.nl' }),
		{ before: 'E-mail: ', linked: '[e-mailadres]' },
	)
	assert.deepEqual(contactLineParts({ text: 'E-mail', href: 'mailto:a@b.nl' }), {
		before: '',
		linked: 'E-mail',
	})
	assert.deepEqual(contactLineParts({ text: 'Wilgenlaan 12, Zuiddrecht' }), {
		before: 'Wilgenlaan 12, Zuiddrecht',
		linked: '',
	})

	const html = await renderSfc(
		'src/site/components/FooterColumns.vue',
		{
			title: 'De Wilgenboom',
			menus: [],
			footer: {
				contact: {
					title: 'Contact',
					lines: [
						{ text: 'Telefoon: [telefoonnummer]' },
						{
							text: 'E-mail: [e-mailadres]',
							href: 'mailto:info@example.org',
						},
					],
				},
			},
		},
		LIBRARY,
	)
	assert.match(
		html,
		/<p>\s*E-mail: <a href="mailto:info@example.org">\[e-mailadres\]<\/a><\/p>/,
	)
	assert.match(html, /<p>\s*Telefoon: \[telefoonnummer\]\s*<\/p>/)
})

test('a hero action may be an underlined text link, and a button may carry a chevron', async () => {
	assert.deepEqual(
		heroActions([
			{ label: 'Kom kennismaken', href: '/aanmelden', chevron: true },
			{
				label: 'Lees wat er speelt op school',
				href: '/zoeken',
				style: 'link',
			},
		]),
		[
			{ label: 'Kom kennismaken', href: '/aanmelden', chevron: true },
			{
				label: 'Lees wat er speelt op school',
				href: '/zoeken',
				style: 'link',
			},
		],
	)
	assert.deepEqual(heroActions([{ label: 'A', href: '/a', style: 'big' }]), [
		{ label: 'A', href: '/a' },
	])

	const html = await renderSfc(
		'src/site/components/HeroBlock.vue',
		{
			title: 'Kijk ver vooruit.',
			actions: [
				{ label: 'Kom kennismaken', href: '/aanmelden', chevron: true },
				{
					label: 'Lees wat er speelt op school',
					href: '/zoeken',
					style: 'link',
				},
			],
		},
		LIBRARY,
	)
	assert.match(
		html,
		/<a class="pq-hero__action" href="\/aanmelden" data-testid="hero-action">Kom kennismaken<svg class="pq-hero__action-chevron"/,
	)
	assert.match(
		html,
		/<a class="pq-hero__action pq-hero__action--link" href="\/zoeken" data-testid="hero-action">Lees wat er speelt op school<\/a>/,
	)
	assert.match(
		css,
		/\.pq-site \.ac-hero \.pq-hero__action\.pq-hero__action--link \{[^}]*background-color: transparent;[^}]*text-decoration: underline;/,
	)
})

test('the month of a date tile follows the set, and the hero aside draws one frame', () => {
	assert.match(
		css,
		/\.pq-site \.pq-date-tile \.pq-date-tile__month \{ text-transform: var\(--thematiq-date-month-text-transform, none\); \}/,
	)
	assert.match(
		css,
		/\.pq-site \.pq-hero__aside-card \.nl-event-list--framed \{[^}]*border: 0;[^}]*box-shadow: none;/,
	)
})
