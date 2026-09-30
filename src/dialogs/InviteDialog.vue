<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  InviteDialog: a clerk invites an e-mail address into the portal.

  Its own file per ADR-004's modal-isolation rule. Spawned by the "Invite
  someone" header action (src/lib/staffAccountActions.js). Portaliq mails the
  link to the address; the clerk only reads that it was sent and until when.
  A refusal is shown here. It closes with the success sentence, or with null
  when cancelled.

  @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
-->
<template>
	<NcDialog
		:name="t('portaliq', 'Invite someone')"
		size="normal"
		data-testid="invite-someone"
		@closing="$emit('close', null)">
		<p class="invite__intro">
			{{
				t(
					'portaliq',
					'We mail a link to this address. You see when it runs out, not the link itself.',
				)
			}}
		</p>
		<NcTextField
			v-model="fields.email"
			type="email"
			:label="t('portaliq', 'E-mail address')"
			data-testid="invite-someone-email" />
		<NcTextField
			v-model="fields.organisation"
			:label="t('portaliq', 'Organisation')"
			data-testid="invite-someone-organisation" />
		<NcTextField
			v-model="fields.audience"
			:label="t('portaliq', 'Audience')"
			:helperText="t('portaliq', 'For example client or supplier.')"
			data-testid="invite-someone-audience" />
		<p v-if="refusal" class="invite__refusal" role="alert" data-testid="invite-someone-refusal">
			{{ refusal }}
		</p>

		<template #actions>
			<NcButton @click="$emit('close', null)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="invite-someone-confirm"
				@click="confirm">
				{{ t('portaliq', 'Send invitation') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'

export default {
	name: 'InviteDialog',

	components: {
		NcButton,
		NcDialog,
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
			fields: { email: '', organisation: '', audience: 'client' },
			refusal: '',
			busy: false,
		}
	},

	methods: {
		t,

		/**
		 * Submit, then close on success or show the refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-invite-an-address-and-portaliq-mails-it-req-isa-001
		 */
		async confirm() {
			this.busy = true
			this.refusal = ''
			const outcome = await this.submit({ ...this.fields })
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
.invite__intro {
	margin-bottom: 8px;
}

.invite__refusal {
	margin-top: 8px;
	color: var(--color-error-text);
}
</style>
