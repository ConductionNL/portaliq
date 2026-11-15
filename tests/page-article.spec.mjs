// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// A markdown page that names an article shows its title, hero image and body
// in place (marketing-articles-from-pipelinq).
//
// @spec openspec/changes/marketing-articles-from-pipelinq/specs/marketing-articles/spec.md#requirement-a-page-can-show-an-article

import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { loadSfc, renderComponent } from './support/render-sfc.mjs'

const PageArticleBlock = await loadSfc('src/site/components/PageArticleBlock.vue', {
	'@conduction/nextcloud-vue': "export const cnRenderMarkdown = (source) => '<div class=\"md\">' + source + '</div>'\n",
})

// MarkdownBlock tags the markup through DOMParser; node has none.
globalThis.DOMParser = class {
	parseFromString(html) {
		return { body: { innerHTML: html, querySelectorAll: () => [] } }
	}
}

const ARTICLE = {
	id: 'a1',
	title: 'OpenRegister 3.0 is uit',
	hero: { url: '/apps/pipelinq/img/marketing/openregister-3-0.svg', alt: 'OpenRegister 3.0 is uit' },
	markdown: '## Wat er nieuw is',
}

test('the page shows the article it names: title, hero image and body', async () => {
	const html = await renderComponent(PageArticleBlock, { article: ARTICLE })
	assert.match(html, /data-testid="page-article"/)
	assert.match(html, /<h2[^>]*>\s*OpenRegister 3\.0 is uit\s*<\/h2>/)
	assert.match(html, /<img[^>]*src="\/apps\/pipelinq\/img\/marketing\/openregister-3-0\.svg"[^>]*alt="OpenRegister 3\.0 is uit"/)
	assert.match(html, /## Wat er nieuw is/)
})

test('without a hero the article still shows, and without an article nothing does', async () => {
	const plain = await renderComponent(PageArticleBlock, { article: { ...ARTICLE, hero: null } })
	assert.doesNotMatch(plain, /page-article-hero/)
	assert.match(plain, /OpenRegister 3\.0 is uit/)
	assert.doesNotMatch(await renderComponent(PageArticleBlock, { article: null }), /page-article/)
})

test('the site places the article under the page\'s own markdown', () => {
	const app = readFileSync(new URL('../src/site/App.vue', import.meta.url), 'utf8')
	assert.match(app, /data-testid="page-markdown"[\s\S]{0,200}<PageArticleBlock\s+v-if="page\.body && page\.body\.article"\s+:article="page\.body\.article"/)
})
