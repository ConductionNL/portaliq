<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Hulp bij deze pagina" (help-texts-and-form-help): a closed disclosure under
	a page's heading holding the page's help text. With no text it renders
	nothing at all.
-->
<template>
	<details v-if="text" class="pq-page-help" data-testid="page-help">
		<summary class="pq-page-help__summary">{{ label }}</summary>
		<MarkdownBlock :source="text" />
	</details>
</template>

<script>
import MarkdownBlock from './MarkdownBlock.vue'
import strings from '../lib/helpStrings.js'
import { pageLocale } from '../pages/inbox/translate.js'

/**
 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-page-and-each-part-of-mijn-omgeving-can-carry-a-help-text-req-htf-002
 */
export default {
	name: 'PageHelp',

	components: { MarkdownBlock },

	props: {
		/** The help text (markdown); empty shows nothing. */
		text: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {string} The summary line.
		 * @spec openspec/changes/help-texts-and-form-help/specs/site-help-texts/spec.md#requirement-a-page-and-each-part-of-mijn-omgeving-can-carry-a-help-text-req-htf-002
		 */
		label() {
			return (strings[pageLocale()] || strings.nl).page
		},
	},
}
</script>

<style scoped>
.pq-page-help {
	margin-block: 0.75rem;
}

.pq-page-help__summary {
	cursor: pointer;
	font-weight: 700;
}
</style>
