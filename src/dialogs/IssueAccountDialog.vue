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
	<CnDialog
		:look="look"
		:name="t('portaliq', 'Issue an account')"
		:eyebrow="t('portaliq', 'Accounts')"
		size="normal"
		data-testid="issue-account"
		@closing="$emit('close', null)">
		<div class="issue__grid">
			<NcTextField
				v-model="fields.displayName"
				:label="t('portaliq', 'Name')"
				data-testid="issue-account-name" />
			<NcTextField
				v-model="fields.email"
				type="email"
				:label="t('portaliq', 'E-mail address')"
				data-testid="issue-account-email" />
			<div class="issue__wide">
				<NcCheckboxRadioSwitch
					v-model="fields.verifiedEmail"
					data-testid="issue-account-verified">
					{{ t('portaliq', 'I checked this address with its owner') }}
				</NcCheckboxRadioSwitch>
				<p class="issue__help issue__help--check">
					{{
						t(
							'portaliq',
							'The account gets this address as confirmed, without a confirmation e-mail.',
						)
					}}
				</p>
			</div>
			<div>
				<NcSelect
					v-model="identityType"
					:options="identityTypes"
					:inputLabel="t('portaliq', 'Identity type')"
					:clearable="true"
					data-testid="issue-account-identity-type" />
				<p class="issue__help">
					{{
						t(
							'portaliq',
							'DigiD, eHerkenning, eIDAS or another sign-in service.',
						)
					}}
				</p>
			</div>
			<div>
				<NcTextField
					v-model="fields.identityRef"
					:label="t('portaliq', 'Identity reference')"
					data-testid="issue-account-identity-ref" />
				<p class="issue__help">
					{{
						t(
							'portaliq',
							'The BSN, KVK number or other number the sign-in service returns.',
						)
					}}
				</p>
			</div>
			<NcTextField
				v-model="fields.organisation"
				:label="t('portaliq', 'Organisation')"
				data-testid="issue-account-organisation" />
			<div>
				<NcSelect
					v-model="audience"
					:options="audiences"
					:inputLabel="t('portaliq', 'Audience')"
					:clearable="false"
					:taggable="true"
					data-testid="issue-account-audience" />
				<p class="issue__help">
					{{ t('portaliq', 'For example client or supplier.') }}
				</p>
			</div>
		</div>
		<NcNoteCard type="info" class="issue__note">
			{{
				t(
					'portaliq',
					'If an account already exists for this identity, portaliq does not create a second one but tells you. The first sign-in links the account to the person.',
				)
			}}
		</NcNoteCard>
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
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('portaliq', 'Issue account') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import {
	audienceOption,
	audienceOptions,
	audienceValue,
} from '../lib/audienceOptions.js'

export default {
	name: 'IssueAccountDialog',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		CnDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Plus,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},

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
		/** The audience as a select option. */
		audience: {
			get() {
				return audienceOption(this.fields.audience)
			},

			set(option) {
				this.fields.audience = audienceValue(option)
			},
		},

		/** The audiences to pick from. */
		audiences() {
			return audienceOptions()
		},

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
.issue__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
	gap: 14px 16px;
	align-items: start;
}

.issue__wide {
	grid-column: 1 / -1;
}

.issue__help {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	line-height: 1.3;
}

.issue__help--check {
	margin-top: 0;
	padding-inline-start: 28px;
}

.issue__note {
	margin-top: 16px;
}

.issue__refusal {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
