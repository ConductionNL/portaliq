<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A bulleted or numbered list (design D1 rows 99 and 63), or numbered steps
	with a title and a line each (site-school-blocks, `display: steps`).

	The steps are still an `ol`: the number a sighted visitor sees large is
	the list's own number to a screen reader, so nothing is said twice.
-->
<template>
	<ol
		v-if="steps"
		class="utrecht-ordered-list nl-list-steps"
		data-testid="nl-list">
		<li
			v-for="(item, index) in safeItems"
			:key="`${index}-${item.title}`"
			class="utrecht-ordered-list__item nl-list-steps__item">
			<span class="nl-list-steps__number" aria-hidden="true">{{
				index + 1
			}}</span>
			<span class="nl-list-steps__text">
				<strong class="nl-list-steps__title">{{ item.title }}</strong>
				<span v-if="item.text" class="nl-list-steps__line">{{
					item.text
				}}</span>
			</span>
		</li>
	</ol>
	<component
		:is="ordered ? 'ol' : 'ul'"
		v-else
		:class="ordered ? 'utrecht-ordered-list' : 'utrecht-unordered-list'"
		data-testid="nl-list">
		<li
			v-for="(item, index) in safeItems"
			:key="`${index}-${item.title}`"
			:class="
				ordered
					? 'utrecht-ordered-list__item'
					: 'utrecht-unordered-list__item'
			">
			<template v-if="item.text">
				<strong>{{ item.title }}</strong> {{ item.text }}
			</template>
			<template v-else>
				{{ item.title }}
			</template>
		</li>
	</component>
</template>

<script>
import { listLines } from './lines.js'

import '@utrecht/unordered-list-css/dist/index.css'
import '@utrecht/ordered-list-css/dist/index.css'

export default {
	name: 'NlList',

	props: {
		/** The lines: strings, or `{title, text}`. */
		items: { type: Array, default: () => [] },
		/** Numbered instead of bulleted. */
		ordered: { type: Boolean, default: false },
		/** `list`, or `steps` for large numbers with a title and a line. */
		display: { type: String, default: 'list' },
	},

	computed: {
		/**
		 * @return {Array<{title: string, text: string}>} The lines that have text.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeItems() {
			return listLines(this.items)
		},

		/**
		 * @return {boolean} Whether the lines are drawn as steps.
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-a-list-may-show-numbered-steps-with-a-title-and-a-line
		 */
		steps() {
			return this.display === 'steps'
		},
	},
}
</script>

<style scoped>
.nl-list-steps {
	margin: 0;
	padding: 0;
	list-style: none;
	border-block-start: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.nl-list-steps__item {
	display: flex;
	gap: 1.5rem;
	margin: 0;
	padding-block: 1.375rem;
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.nl-list-steps__number {
	flex: 0 0 2.5rem;
	color: var(--nldesign-color-primary, var(--utrecht-link-color, currentcolor));
	font-family: var(--nldesign-component-heading-font-family, inherit);
	font-size: 2.25rem;
	font-weight: 700;
	line-height: 1;
}

.nl-list-steps__text {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.nl-list-steps__title {
	font-size: 1.25rem;
}

.nl-list-steps__line {
	color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, var(--utrecht-document-color, CanvasText))
	);
}
</style>
