<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The tabs over a list (mijn-lists-follow-the-boards): "Komend",
	"Afgerond", "Geannuleerd"; "Periode 1", "Heel het schooljaar". A tab list
	with one tab in the tab order; the arrow keys, Home and End move between
	the tabs and choose at once, so the list under it follows.
-->
<template>
	<div
		class="pq-list-tabs"
		role="tablist"
		:aria-label="label || undefined"
		data-testid="mijn-list-tabs">
		<button
			v-for="(tab, index) in tabs"
			:key="index"
			ref="tab"
			type="button"
			role="tab"
			class="pq-list-tabs__tab"
			:class="{ 'pq-list-tabs__tab--chosen': index === chosen }"
			:aria-selected="index === chosen ? 'true' : 'false'"
			:tabindex="index === chosen ? 0 : -1"
			data-testid="mijn-list-tab"
			@click="$emit('choose', index)"
			@keydown="onKey($event, index)">
			{{ tab.label }}
		</button>
	</div>
</template>

<script>
/**
 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
 */
export default {
	name: 'ListTabs',

	props: {
		/** The tabs: `{label, field?, values?}`. */
		tabs: { type: Array, required: true },
		/** The index of the chosen tab. */
		chosen: { type: Number, default: 0 },
		/** The tab list's name for assistive technology, '' for none. */
		label: { type: String, default: '' },
	},

	emits: ['choose'],

	methods: {
		/**
		 * Move between the tabs with the arrow keys, Home and End.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @param {number} index The tab the key was pressed on.
		 * @return {void}
		 * @spec openspec/changes/mijn-lists-follow-the-boards/specs/portal-contribution-contract/spec.md#requirement-a-list-may-show-its-rows-under-tabs
		 */
		onKey(event, index) {
			const last = this.tabs.length - 1
			const next = {
				ArrowRight: index === last ? 0 : index + 1,
				ArrowLeft: index === 0 ? last : index - 1,
				Home: 0,
				End: last,
			}[event.key]
			if (next === undefined) {
				return
			}
			event.preventDefault()
			this.$emit('choose', next)
			this.$nextTick(() => this.$refs.tab?.[next]?.focus())
		},
	},
}
</script>

<style scoped>
/* The boards' tabs: a line under the row, the chosen tab bold with the
   accent under it. */
.pq-list-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 24px;
	margin-block-end: 16px;
	border-block-end: 1px solid
		var(--nldesign-color-border, var(--utrecht-color-grey-90, currentcolor));
}

.pq-list-tabs__tab {
	margin: 0 0 -1px;
	padding: 10px 2px;
	border: 0;
	border-block-end: 3px solid transparent;
	background: none;
	color: var(--utrecht-link-color, LinkText);
	font: inherit;
	font-weight: 500;
	cursor: pointer;
}

.pq-list-tabs__tab--chosen {
	border-block-end-color: var(
		--thematiq-accent-color,
		var(--nldesign-color-accent, CanvasText)
	);
	color: var(--utrecht-document-color, CanvasText);
	font-weight: 700;
}

.pq-list-tabs__tab:focus-visible {
	outline: 2px solid var(--pq-focus-color, CanvasText);
	outline-offset: 2px;
}
</style>
