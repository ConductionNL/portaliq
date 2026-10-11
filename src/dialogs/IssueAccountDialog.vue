<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  IssueAccountDialog: a clerk issues a portal account at the desk.

  Its own file per ADR-004's modal-isolation rule. Spawned by the "Issue an
  account" header action on the Accounts page (src/lib/staffAccountActions.js).
  It submits through the provision route itself, so a refusal (a duplicate
  identity, a missing organisation) is shown here and the clerk can correct
  it. It closes with the success sentence, or with null when cancelled.

  @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Issue an account')"
		size="normal"
		data-testid="issue-account"
		@closing="$emit('close', null)">
		<NcTextField
			v-model="fields.displayName"
			:label="t('portaliq', 'Name')"
			data-testid="issue-account-name" />
		<NcTextField
			v-model="fields.email"
			type="email"
			:label="t('portaliq', 'E-mail address')"
			data-testid="issue-account-email" />
		<NcCheckboxRadioSwitch
			v-model="fields.verifiedEmail"
			data-testid="issue-account-verified">
			{{ t('portaliq', 'I checked this address with its owner') }}
		</NcCheckboxRadioSwitch>
		<p class="issue__intro">
			{{
				t(
					'portaliq',
					'The account gets this address as confirmed, without a confirmation e-mail.',
				)
			}}
		</p>
		<NcSelect
			v-model="identityType"
			:options="identityTypes"
			:inputLabel="t('portaliq', 'Identity type')"
			:clearable="true"
			data-testid="issue-account-identity-type" />
		<NcTextField
			v-model="fields.identityRef"
			:label="t('portaliq', 'Identity reference')"
			:helperText="
				t(
					'portaliq',
					'The BSN, KVK number or other number the sign-in service returns.',
				)
			"
			data-testid="issue-account-identity-ref" />
		<NcTextField
			v-model="fields.organisation"
			:label="t('portaliq', 'Organisation')"
			data-testid="issue-account-organisation" />
		<NcTextField
			v-model="fields.audience"
			:label="t('portaliq', 'Audience')"
			:helperText="t('portaliq', 'For example client or supplier.')"
			data-testid="issue-account-audience" />
		<p class="issue__intro">
			{{
				t(
					'portaliq',
					'If an account already exists for this identity, portaliq does not create a second one but tells you. The first sign-in links the account to the person.',
				)
			}}
		</p>
		<p
			v-if="refusal"
			class="issue__refusal"
			role="alert"
			data-testid="issue-account-refusal">
			{{ refusal }}
		</p>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="issue-account-confirm"
				@click="confirm">
				{{ t('portaliq', 'Issue account') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'IssueAccountDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcSelect,
		NcTextField,
	},

	props: {
		/** Posts the fields; answers `{ok, message}`. */
		submit: {
			type: Function,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			fields: {
				organisation: '',
				audience: 'client',
				displayName: '',
				identityRef: '',
				email: '',
				verifiedEmail: false,
			},

			identityType: null,
			refusal: '',
			busy: false,
		}
	},

	computed: {
		/**
		 * The identity types the account schema knows.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		identityTypes() {
			return [
				{ id: 'digid', label: 'DigiD' },
				{ id: 'eherkenning', label: 'eHerkenning' },
				{ id: 'eidas', label: 'eIDAS' },
				{ id: 'generic', label: t('portaliq', 'Other sign-in service') },
			]
		},
	},

	methods: {
		t,

		/**
		 * Submit, then close on success or show the refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-issue-and-withdraw-accounts-through-the-validated-actions-req-isa-003
		 */
		async confirm() {
			this.busy = true
			this.refusal = ''
			const outcome = await this.submit({
				...this.fields,
				identityType: this.identityType?.id || '',
			})
			this.busy = false
			if (outcome.ok) {
				this.$emit('close', outcome.message)
				return
			}
			this.refusal = outcome.message
		},
	},
}
</script>

<style scoped>
.issue__intro {
	margin-bottom: 8px;
}

.issue__refusal {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
