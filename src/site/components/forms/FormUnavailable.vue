<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What a resident sees in place of a form that takes no submissions right
	now, drawn on the board FormulierNietBeschikbaar: maintenance, replaced,
	full, and the not-yet-open and closed texts
	(form-governance-availability-retention-and-routing REQ-FGV-001).
-->
<template>
	<div
		v-if="view"
		class="pq-form-unavailable"
		role="status"
		:data-situation="view.situation"
		data-testid="form-unavailable">
		<h2 class="utrecht-heading-2">
			{{ view.title }}
		</h2>
		<p
			v-for="(line, index) in view.lines"
			:key="index"
			class="utrecht-paragraph"
			data-testid="form-unavailable-line">
			{{ line }}
		</p>
		<a
			v-if="view.link"
			class="utrecht-link"
			:href="view.link.href"
			data-testid="form-unavailable-link">
			{{ view.link.label }}
		</a>
	</div>
</template>

<script>
import { pageLocale } from '../../pages/inbox/translate.js'
import { unavailableView } from './unavailable.js'

import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/form-governance-availability-retention-and-routing/tasks.md#t03
 */
export default {
	name: 'FormUnavailable',

	props: {
		/** The server's `unavailable`: `situation` and its details. */
		unavailable: { type: Object, default: null },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {object|null} The title, lines and link to show.
		 * @spec openspec/changes/form-governance-availability-retention-and-routing/tasks.md#t03
		 */
		view() {
			return unavailableView(this.unavailable, pageLocale(this.locale))
		},
	},
}
</script>

<style scoped>
.pq-form-unavailable {
	padding: var(--utrecht-space-block-md, 1rem) 0;
	color: var(--utrecht-document-color, var(--color-main-text));
}
</style>
