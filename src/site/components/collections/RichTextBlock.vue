<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-richtext" data-testid="collections-richtext">
		<template v-for="line in lines" :key="line.index">
			<h2 v-if="line.level === 1" class="utrecht-heading-2">
				{{ line.text }}
			</h2>
			<h3 v-else-if="line.level === 2" class="utrecht-heading-3">
				{{ line.text }}
			</h3>
			<h4 v-else-if="line.level === 3" class="utrecht-heading-4">
				{{ line.text }}
			</h4>
			<p v-else class="utrecht-paragraph">
				{{ line.text }}
			</p>
		</template>
	</div>
</template>

<script>
import { richTextLines } from './richText.js'

/**
 * A `richText` block of a contribution page (slice b, b5): headings and
 * paragraphs as text, never as markup.
 *
 * WHY NOT MarkdownBlock.vue. That block renders through `cnRenderMarkdown`,
 * which hands marked's HTML to DOMPurify: a script is removed, but safe raw
 * HTML in the source (`<b>`, `<img>`) still renders. A contribution's
 * markdown comes from a leaf app's manifest, and REQ-SRP-018 says a manifest
 * never puts markup on the page. Every line here is a text node, so there is
 * nothing to sanitise.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-rich-text-must-stay-text-only-req-srp-018
 */
export default {
	name: 'RichTextBlock',

	props: {
		/** The block's markdown. */
		markdown: { type: String, default: '' },
	},

	computed: {
		lines() {
			return richTextLines(this.markdown)
		},
	},
}
</script>
