<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	What the visitor still has to do, on a CMS page (site-nlds-widget-palette
	T9, design D1 row 94): the rows of one collection of a contributing app,
	or, when the placement names none, the visitor's open portal tasks. Both
	are read through the shell's subject-scoped api; signed out the widget
	shows a sign-in prompt and reads nothing (REQ-SNW-012).
-->
<template>
	<div class="nl-tasks" data-testid="nl-tasks">
		<TasksBlock
			v-if="signedIn"
			:block="taskBlock"
			:collection="ownCollection"
			:app="collection ? app : ''"
			v-bind="{ ...readProps, ...shellProps }"
			@navigate="$emit('navigate', $event)"
			@retry="load" />
		<SignInPrompt v-else v-bind="promptProps" />
	</div>
</template>

<script>
import SignInPrompt from '../../components/mijn/SignInPrompt.vue'
import TasksBlock from '../../components/mijn/TasksBlock.vue'
import { portalTaskRows } from '../ownData.js'
import ownRows from '../ownRowsMixin.js'

import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/**
 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
 */
export default {
	name: 'NlTasks',

	components: { SignInPrompt, TasksBlock },

	mixins: [ownRows],

	props: {
		/** The field that holds a row's deadline. */
		dueField: { type: String, default: '' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * The tasks block: the named collection's, or the portal tasks'
		 * deadline and title.
		 *
		 * @return {object} The block.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		taskBlock() {
			return this.collection
				? {
						collection: this.collection,
						dueField: this.dueField,
						limit: this.rowLimit,
						label: this.label,
					}
				: {
						collection: '',
						dueField: 'dueAt',
						titleFields: ['title'],
						limit: this.rowLimit,
						label: this.label,
					}
		},
	},

	methods: {
		/**
		 * Read the named collection, or the visitor's portal tasks when the
		 * placement names no collection.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-a-mijn-omgeving-widget-must-show-only-the-visitors-own-data-req-snw-012
		 */
		async load() {
			if (this.collection) {
				return ownRows.methods.load.call(this)
			}
			if (!this.signedIn) {
				return
			}
			this.loading = true
			const rows = await portalTaskRows(this.mijn)
			this.loading = false
			this.failed = rows === null
			this.rows = rows || []
		},
	},
}
</script>
