<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Facts about a record as a description list in the Utrecht data list
	look: a <dl>, each fact a key and its value, on --utrecht-* tokens so the
	theme bridge colours it. Values are text.
-->
<template>
	<dl
		class="utrecht-data-list pq-description-list"
		data-testid="mijn-description-list">
		<div
			v-for="item in items"
			:key="item.key"
			class="utrecht-data-list__item pq-description-list__item"
			:data-testid="itemTestid || undefined">
			<dt class="utrecht-data-list__item-key pq-description-list__key">
				{{ item.label }}
			</dt>
			<dd
				v-if="item.slot"
				class="utrecht-data-list__item-value pq-description-list__value">
				<slot :name="`value-${item.key}`" :item="item" />
			</dd>
			<dd v-else class="utrecht-data-list__item-value pq-description-list__value">
				{{ item.value }}
			</dd>
		</div>
	</dl>
</template>

<script>
/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export default {
	name: 'DescriptionList',

	props: {
		/** The facts: `{key, label, value}`. */
		items: { type: Array, required: true },
		/** A test id for each fact, or ''. */
		itemTestid: { type: String, default: '' },
	},
}
</script>

<style scoped>
.pq-description-list {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	margin: 0;
}

.pq-description-list__item {
	display: grid;
	grid-template-columns: minmax(8rem, 1fr) minmax(0, 2fr);
	gap: var(--utrecht-space-inline-md, 1rem);
	padding-block: var(--utrecht-space-block-sm, 0.5rem);
	border-block-end: 1px solid var(--utrecht-color-grey-90, #e6e6e6);
}

@media (width < 36em) {
	.pq-description-list__item {
		grid-template-columns: minmax(0, 1fr);
		gap: 0.125rem;
	}
}

.pq-description-list__key {
	font-weight: var(--utrecht-data-list-item-key-font-weight, bold);
	color: var(
		--utrecht-data-list-item-key-color,
		var(--utrecht-document-color, inherit)
	);
}

.pq-description-list__value {
	margin: 0;
	color: var(
		--utrecht-data-list-item-value-color,
		var(--utrecht-document-color, inherit)
	);
}
</style>
