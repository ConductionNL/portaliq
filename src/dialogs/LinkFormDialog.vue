<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  LinkFormDialog: which request form a submission came through.

  Its own file per ADR-004's modal-isolation rule. Spawned by the "Link a form"
  row action on the Submissions list (src/lib/submissionActions.js); it closes
  with the chosen form's id, or with nothing when cancelled. Only the forms of
  the submission's portals are offered.

  @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
-->
<template>
	<CnDialog
		:look="look"
		:name="t('portaliq', 'Link a form')"
		size="normal"
		data-testid="link-form"
		@closing="$emit('close', null)">
		<p class="link-form__intro">
			{{ t('portaliq', 'Choose the request form this submission came through') }}
		</p>
		<NcSelect
			v-model="chosen"
			:options="options"
			:inputLabel="t('portaliq', 'Request form')"
			:clearable="false"
			label="label"
			data-testid="link-form-select" />
		<p v-if="missing" class="link-form__missing" role="alert">
			{{ t('portaliq', 'Choose a request form.') }}
		</p>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="link-form-confirm"
				@click="confirm">
				{{ t('portaliq', 'Link a form') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcSelect } from '@nextcloud/vue'

export default {
	name: 'LinkFormDialog',

	components: {
		CnDialog,
		NcButton,
		NcSelect,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},

		/** The request forms to choose from: `{ id, label }`. */
		options: {
			type: Array,
			default: () => [],
		},

		/** The form the submission is linked to now, or ''. */
		current: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	data() {
		return {
			chosen: this.options.find((option) => option.id === this.current) || null,
			missing: false,
		}
	},

	methods: {
		t,

		/**
		 * Close with the chosen form's id, or say that one is needed.
		 *
		 * @return {void}
		 */
		confirm() {
			if (!this.chosen?.id) {
				this.missing = true
				return
			}
			this.$emit('close', this.chosen.id)
		},
	},
}
</script>

<style scoped>
.link-form__intro {
	margin-bottom: 8px;
}

.link-form__missing {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
