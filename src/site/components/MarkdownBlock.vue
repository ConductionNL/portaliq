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

			return this.withDesignSystemClasses(cnRenderMarkdown(this.source))
		},
	},

	methods: {
		/**
		 * Tag rendered markdown with the design system's typography classes.
		 *
		 * MARKDOWN EMITS BARE ELEMENTS. `## Onderwerpen` becomes `<h2>` with no
		 * class, and the NL Design System styles `\u002eutrecht-heading-2`, not `h2`.
		 * So the tokens were right, the stylesheet was loaded, and the heading
		 * still rendered in the document's fallback: measured against the
		 * reference, Roboto 24px rgb(0, 56, 101) where the design says Avenir
		 * 32px rgb(26, 26, 26). Nothing was missing except the class.
		 *
		 * Applied AFTER sanitisation, never before — this runs on the sanitiser's
		 * output and only ever ADDS a class attribute to elements that are
		 * already in the tree. It cannot reintroduce anything the sanitiser
		 * removed.
		 *
		 * A class is added only where one is absent, so authored HTML that
		 * already carries design-system classes is left alone.
		 *
		 * @param {string} html Sanitised HTML.
		 * @return {string} The same HTML, with typography classes applied.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
		 */
		withDesignSystemClasses(html) {
			const template = document.createElement('template')
			template.innerHTML = html

			const MAP = {
				H1: 'utrecht-heading-1',
				H2: 'utrecht-heading-2',
				H3: 'utrecht-heading-3',
				H4: 'utrecht-heading-4',
				H5: 'utrecht-heading-5',
				H6: 'utrecht-heading-6',
				P: 'utrecht-paragraph',
				A: 'utrecht-link',
				UL: 'utrecht-unordered-list',
				OL: 'utrecht-ordered-list',
				BLOCKQUOTE: 'utrecht-blockquote',
			}

			for (const [tag, className] of Object.entries(MAP)) {
				for (const el of template.content.querySelectorAll(
					tag.toLowerCase(),
				)) {
					if (el.className === '') {
						el.className = className
					}
				}
			}

			return template.innerHTML
		},
	},
}
</script>

<style scoped>
/*
 * NO HEADING OR LINK COLOUR HERE — the design system owns both now.
 *
 * These rules predate the markdown output carrying design-system classes. Once
 * it did, they became a second opinion on the same pixels, and a scoped style
 * wins: measured against the reference, the heading resolved
 * `--utrecht-heading-2-color` correctly to #1a1a1a and still rendered
 * rgb(0, 56, 101), because this rule overrode it after the fact. Font family,
 * size and weight all matched; only the colour was ours.
 *
 * Same shape as the `.pq-site :any-link` rule removed from App.vue, and the
 * same lesson: a blanket colour applied without reference to the surface it
 * lands on cannot be right for every surface.
 */
.pq-markdown :deep(h1),
.pq-markdown :deep(h2),
.pq-markdown :deep(h3) {
	line-height: 1.25;
	margin: 0 0 0.5em;
}

.pq-markdown :deep(p) {
	margin: 0 0 1em;
	line-height: 1.6;
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
	text-align: start;
}
</style>
