#!/usr/bin/env node
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// newsletter-title-translation.spec.mjs: a newsletter's own title shows in the
// reader's language under the same AI notice a news item has, with the button
// that shows the original title (decision D24, newsletter-title-translation).
// Runs against the site's Vue port (site-reaches-portal-parity REQ-SRP-033).
//
// Usage:
//   node --test tests/newsletter-title-translation.spec.mjs

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { test } from 'node:test'
import { fileURLToPath } from 'node:url'
import { t } from './support/page-instance.mjs'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const NewsletterArchive = await loadSfc(
	'src/site/components/inbox/NewsletterArchive.vue',
)

const NEWSLETTER_TRANSLATION = {
	targetLanguage: 'ar',
	text: 'نشرة سبتمبر',
	title: 'نشرة سبتمبر',
	translatedByAi: true,
	sourceLanguage: 'nl',
	originalRef: 'portaliq:newsletter:nl1',
}

test("a translated newsletter title shows in the reader's language with the notice and the original one click away", async () => {
	const archive = [
		{
			id: 'nl1',
			title: 'Nieuwsbrief september',
			items: [],
			translation: NEWSLETTER_TRANSLATION,
		},
	]
	const html = await renderComponent(NewsletterArchive, {
		archive,
		t,
		locale: 'en',
	})
	assert.match(
		html,
		/<h3 class="utrecht-heading-3 pq-newsletter__title" lang="ar">نشرة سبتمبر<\/h3>/,
	)
	assert.equal(
		(html.match(/pq-ai-notice"/g) || []).length,
		1,
		'one notice for the title',
	)
	assert.match(html, /Translated by AI from Dutch/)
	assert.match(html, /aria-controls="portaliq-original-newsletter-nl1"/)
	assert.match(html, /Show the original text/)
	assert.match(
		html,
		/<blockquote id="portaliq-original-newsletter-nl1" class="pq-translated__original" lang="nl" hidden>(<!--\[-->)?Nieuwsbrief september(<!--\]-->)?<\/blockquote>/,
	)
})

test('a newsletter without a labelled translation keeps its title as written and shows no notice', async () => {
	for (const translation of [
		undefined,
		{ ...NEWSLETTER_TRANSLATION, translatedByAi: false },
	]) {
		const archive = [
			{ id: 'nl1', title: 'Nieuwsbrief september', items: [], translation },
		]
		const html = await renderComponent(NewsletterArchive, {
			archive,
			t,
			locale: 'en',
		})
		assert.match(
			html,
			/<h3 class="utrecht-heading-3 pq-newsletter__title">Nieuwsbrief september<\/h3>/,
		)
		assert.doesNotMatch(html, /pq-ai-notice/)
	}
})

test('the check runs with the specs', () => {
	const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'))
	assert.match(pkg.scripts['check:specs'], /check:newsletter-title-translation/)
	assert.equal(
		pkg.scripts['check:newsletter-title-translation'],
		'node --test tests/newsletter-title-translation.spec.mjs',
	)
})
