<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Steps an author lists on a page (site-nlds-widget-palette T9, design D1
	row 74): "Zo werkt het" as Den Haag process steps, each with its state in
	words for a screen reader and the current one marked `aria-current`. The
	steps are page content, so the widget is public and reads nothing.
-->
<template>
	<section
		class="nl-steps"
		:aria-labelledby="heading ? headingId : undefined"
		data-testid="nl-steps">
		<h2 v-if="heading" :id="headingId" class="utrecht-heading-2">
			{{ heading }}
		</h2>
		<ProcessSteps
			v-if="list.length > 0"
			:steps="list"
			:tr="tr"
			:locale="locale"
			:display="display === 'bars' ? 'bars' : 'list'" />
	</section>
</template>

<script>
import ProcessSteps from '../../components/mijn/ProcessSteps.vue'
import { mijnTranslator } from '../../components/mijn/rows.js'
import { authoredSteps } from '../ownData.js'

import '@utrecht/heading-2-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
 */
export default {
	name: 'NlSteps',

	components: { ProcessSteps },

	props: {
		/** The heading. */
		heading: { type: String, default: '' },
		/** The steps: `{label, state?, text?, date?}`, in order. */
		steps: { type: Array, default: () => [] },
		/** `list` (process steps) or `bars`. */
		display: { type: String, default: 'list' },
		/** The page language. */
		locale: { type: String, default: 'nl' },
	},

	computed: {
		/**
		 * @return {Array<object>} The steps with a label.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		list() {
			return authoredSteps(this.steps)
		},

		/**
		 * @return {(key: string) => string} The translator.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		tr() {
			return mijnTranslator(null, this.locale)
		},

		/**
		 * @return {string} An id for the heading.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		headingId() {
			return `nl-steps-${this.heading.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
		},
	},
}
</script>
