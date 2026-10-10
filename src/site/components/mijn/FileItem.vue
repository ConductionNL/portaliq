<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One document as a Den Haag file item: ONE control named after the
	document that downloads it, then a line with who added it, when, its type
	and its size ("Van de organisatie, 2 oktober 2026. PDF, 84 kB"). The
	download goes through the portal api, so the control is a button; the
	icon is decorative.
-->
<template>
	<li class="pq-file-item" data-testid="mijn-file-item">
		<button
			type="button"
			class="denhaag-file pq-file-item__control"
			:class="{ 'denhaag-file--loading': busy }"
			:aria-busy="busy ? 'true' : undefined"
			@click="$emit('open')">
			<span class="denhaag-file__left" aria-hidden="true">
				<svg
					class="denhaag-file__icon denhaag-icon"
					viewBox="0 0 24 24"
					focusable="false">
					<path
						d="M6 2h8l4 4v16H6z M14 2v4h4"
						fill="none"
						stroke="currentColor"
						stroke-width="1.5" />
				</svg>
			</span>
			<span class="denhaag-file__right">
				<span class="denhaag-file__label">
					<span class="pq-file-item__name">{{ name }}</span>
					<span v-if="line" class="pq-file-item__line">{{ line }}</span>
				</span>
			</span>
		</button>
		<DataBadge
			v-if="isNew"
			class="pq-file-item__badge"
			:text="newLabel"
			state="warning"
			data-testid="mijn-file-new" />
		<DataBadge
			v-if="status"
			class="pq-file-item__badge"
			:text="status"
			:state="statusState"
			data-testid="mijn-file-status" />
	</li>
</template>

<script>
import DataBadge from './DataBadge.vue'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-a-cases-documents-and-history-must-render-as-file-items-and-a-contact-timeline-req-smo-005
 */
export default {
	name: 'FileItem',

	components: { DataBadge },

	props: {
		/** The document's name, which is also the control's name. */
		name: { type: String, required: true },
		/** Who added it, when, its type and size; or ''. */
		line: { type: String, default: '' },
		/** Whether its download is under way. */
		busy: { type: Boolean, default: false },
		/** Whether the document is new to the resident (a "New" badge). */
		isNew: { type: Boolean, default: false },
		/** The words of the status pill ("Signed"); '' for none. */
		status: { type: String, default: '' },
		/** The pill's state: success, warning, error or neutral. */
		statusState: { type: String, default: 'neutral' },
		/** The words of the new badge, in the page language. */
		newLabel: { type: String, default: 'New' },
	},

	emits: ['open'],
}
</script>

<style>
/* The file item's look: the package's own CSS, nothing else of it. */
@import '@gemeente-denhaag/file/index.css';
</style>

<style scoped>
.pq-file-item {
	list-style: none;
}

.pq-file-item__badge {
	margin-inline-start: 0.5rem;
}

.pq-file-item__control {
	color: var(--utrecht-document-color, inherit);
	text-align: start;
}

.pq-file-item__control:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-file-item__name {
	display: block;
	color: var(--utrecht-link-color, currentcolor);
	text-decoration: underline;
}

.pq-file-item__line {
	display: block;
	font-size: 0.875em;
}

.denhaag-file__icon {
	inline-size: 1.5rem;
	block-size: 1.5rem;
}
</style>
