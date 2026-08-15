<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!-- eslint-disable vue/no-v-html -->
	<!--
		The rule is correct in general and suppressed deliberately here: the
		HTML comes from `cnRenderMarkdown`, the shared library helper, which
		sanitises. Suppressing it is only defensible because the claim is
		TESTED rather than asserted — tests/e2e/site-security.spec.ts (S9)
		renders a page carrying a script tag, a `javascript:` href and an
		`onerror` attribute and asserts on the RENDERED DOM that none survives,
		while the surrounding prose does. That test has been observed failing
		with the sanitiser bypassed.
	-->
	<div class="pq-markdown" v-html="html" />
	<!-- eslint-enable vue/no-v-html -->
</template>

<script>
import { cnRenderMarkdown } from '@conduction/nextcloud-vue'

/**
 * Renders markdown through the SHARED library helper.
 *
 * `cnRenderMarkdown` is the same path `CnWikiPage` uses, so a page rendered
 * here and the same page rendered by the library agree. A second markdown
 * renderer in this app would be one more thing to keep in step, and the two
 * would diverge quietly — a heading that renders differently is not something
 * anyone reports as a bug.
 *
 * SANITISATION. This is a PUBLIC origin, so markdown is untrusted input in a
 * way it is not behind a Nextcloud login. The e2e suite asserts on the
 * RENDERED DOM that a script tag and a `javascript:` href do not survive —
 * asserting that a sanitiser is configured proves only that it is configured.
 */
export default {
	name: 'MarkdownBlock',

	props: {
		/** Markdown source. */
		source: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * @return {string} The rendered HTML.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-markdown-must-not-execute-at-a-public-origin
		 */
		html() {
			if (!this.source) {
				return ''
			}
			return cnRenderMarkdown(this.source)
		},
	},
}
</script>

<style scoped>
.pq-markdown :deep(h1),
.pq-markdown :deep(h2),
.pq-markdown :deep(h3) {
	color: var(--pq-heading-color, #1a1a1a);
	line-height: 1.25;
	margin: 0 0 0.5em;
}

.pq-markdown :deep(p) {
	margin: 0 0 1em;
	line-height: 1.6;
}

.pq-markdown :deep(a) {
	color: var(--pq-link-color, #004488);
}

.pq-markdown :deep(pre) {
	background: var(--pq-code-bg, #f3f3f3);
	padding: 0.75rem 1rem;
	border-radius: 4px;
	overflow-x: auto;
}

.pq-markdown :deep(table) {
	border-collapse: collapse;
	width: 100%;
	max-width: 100%;
	display: block;
	overflow-x: auto;
}

.pq-markdown :deep(th),
.pq-markdown :deep(td) {
	border: 1px solid var(--pq-border-color, #d0d0d0);
	padding: 0.4rem 0.6rem;
	text-align: left;
}
</style>
