<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Parts of a page side by side (design D1 row 93).

	THE WAI-ARIA TABS PATTERN, as MyCasesPage.vue already does it: a tablist of
	buttons, arrow keys between them, Home and End to the ends, and one panel
	visible at a time. Roving tabindex, so Tab moves out of the tablist rather
	than through every tab.
-->
<template>
	<div class="nl-tabs" data-testid="nl-tabs">
		<div class="nl-tabs__list" role="tablist" :aria-label="label">
			<button
				v-for="(tab, index) in safeTabs"
				:id="`${idBase}-tab-${index}`"
				:key="`${index}-${tab.title}`"
				:ref="`tab${index}`"
				type="button"
				class="nl-tabs__tab"
				:class="{ 'nl-tabs__tab--selected': index === selected }"
				role="tab"
				:aria-selected="index === selected ? 'true' : 'false'"
				:aria-controls="`${idBase}-panel-${index}`"
				:tabindex="index === selected ? 0 : -1"
				:data-testid="`nl-tabs-tab-${index}`"
				@click="select(index)"
				@keydown="onKey($event, index)">
				{{ tab.title }}
			</button>
		</div>
		<div
			v-for="(tab, index) in safeTabs"
			v-show="index === selected"
			:id="`${idBase}-panel-${index}`"
			:key="`panel-${index}`"
			class="nl-tabs__panel"
			role="tabpanel"
			:aria-labelledby="`${idBase}-tab-${index}`"
			:tabindex="0"
			:data-testid="`nl-tabs-panel-${index}`">
			<p class="utrecht-paragraph">{{ tab.text }}</p>
		</div>
	</div>
</template>

<script>
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlTabs',

	props: {
		/** The tabs: `{title, text}`. */
		tabs: { type: Array, default: () => [] },
		/** The name of the tablist, for a screen reader. */
		label: { type: String, default: 'Tabbladen' },
	},

	data() {
		return {
			/** Which tab is open. */
			selected: 0,
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The tabs that have a title.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeTabs() {
			return (this.tabs || [])
				.map((tab) => ({
					title: String(tab?.title ?? '').trim(),
					text: String(tab?.text ?? '').trim(),
				}))
				.filter((tab) => tab.title !== '')
		},

		/**
		 * A stable prefix for the aria wiring, so a page with two tab widgets
		 * does not point both panels at the same tab.
		 *
		 * @return {string} The prefix.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		idBase() {
			const first = this.safeTabs[0]?.title ?? 'tabs'
			return 'nl-tabs-' + first.toLowerCase().replace(/[^a-z0-9]+/g, '-')
		},
	},

	methods: {
		/**
		 * Open one tab.
		 *
		 * @param {number} index Which tab.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		select(index) {
			if (index >= 0 && index < this.safeTabs.length) {
				this.selected = index
			}
		},

		/**
		 * Arrow keys move between tabs, Home and End to the ends, and focus
		 * follows the selection: the WAI-ARIA pattern, which is what a screen
		 * reader user expects a tablist to do. Any other key is left to the
		 * browser, so Tab still leaves the tablist.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @param {number} index The tab it came from.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		onKey(event, index) {
			const last = this.safeTabs.length - 1
			const moves = {
				ArrowRight: index + 1 > last ? 0 : index + 1,
				ArrowLeft: index - 1 < 0 ? last : index - 1,
				Home: 0,
				End: last,
			}
			const next = moves[event.key]
			if (next === undefined) {
				return
			}

			event.preventDefault()
			this.select(next)
			this.$nextTick(() => {
				const button = this.$refs[`tab${next}`]
				;(Array.isArray(button) ? button[0] : button)?.focus?.()
			})
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY (design D5): the selected tab is marked by weight and by a
 * border in the document's own ink, never by colour alone.
 */
.nl-tabs__list {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-xs, 0.25rem);
	border-block-end: 1px solid var(--utrecht-color-grey-20, currentcolor);
}

.nl-tabs__tab {
	padding: var(--utrecht-space-block-xs, 0.25rem)
		var(--utrecht-space-inline-sm, 0.5rem);
	border: 0;
	border-block-end: 3px solid transparent;
	background-color: transparent;
	color: var(--utrecht-document-color, CanvasText);
	cursor: pointer;
	font: inherit;
}

.nl-tabs__tab--selected {
	border-block-end-color: var(--utrecht-document-color, CanvasText);
	font-weight: bold;
}

.nl-tabs__tab:focus-visible,
.nl-tabs__panel:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.nl-tabs__panel {
	padding-block: var(--utrecht-space-block-sm, 0.5rem);
}
</style>
