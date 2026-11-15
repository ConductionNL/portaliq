<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  ActionTitleCell: the submitted action in the Submissions table, by the
  title the resident read ("Melding openbare ruimte"), else by its declared
  id for a row written without one.

  The column widget `action-title` (src/App.vue cellWidgets, used by the
  Submissions page's `actionId` column in src/manifest.json).

  @spec openspec/specs/portaliq-leaf-integrations/spec.md#requirement-the-forms-leaf-attaches-nextcloud-forms-to-portal-submissions-on-a-submissions-surface-that-exists
  @visual exclude one line of text in a table cell; its wording is pinned by tests/submission-action-title.spec.mjs
-->
<template>
	<span data-testid="action-title-cell">{{ title }}</span>
</template>

<script>
import { actionTitleOf } from '../lib/actionTitle.js'

export default {
	name: 'ActionTitleCell',

	props: {
		/** The row's `actionId`. */
		value: {
			type: String,
			default: '',
		},

		/** The submission row. */
		row: {
			type: Object,
			default: null,
		},
	},

	computed: {
		/**
		 * The action's name.
		 *
		 * @return {string}
		 * @spec openspec/specs/portaliq-leaf-integrations/spec.md#requirement-the-forms-leaf-attaches-nextcloud-forms-to-portal-submissions-on-a-submissions-surface-that-exists
		 */
		title() {
			return actionTitleOf(this.row || { actionId: this.value })
		},
	},
}
</script>
