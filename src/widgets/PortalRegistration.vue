<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalRegistration: who may make an account on this portal, and the
  registrations waiting for a decision, as a widget on the portal's own page
  (identity-staff-account-screens T07).

  The policy (off, approval, activation) and the allowed domains are saved on
  the portal record, where PortalRegistrationPolicyService reads them. Under
  approval, "Waiting for approval" lists the organisation's pending
  self-registrations with Approve and Refuse, which go through
  PortalAccountAdminController behind portal.provision.

  @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
-->
<template>
	<div class="portal-registration" data-testid="portal-registration">
		<fieldset class="portal-registration__policy">
			<legend>{{ t('portaliq', 'Who may make an account') }}</legend>
			<NcCheckboxRadioSwitch
				v-for="option in policies"
				:key="option.id"
				:modelValue="policy"
				:value="option.id"
				type="radio"
				name="portal-registration-policy"
				:data-testid="`portal-registration-${option.id}`"
				@update:modelValue="policy = $event">
				{{ option.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>
		<NcTextArea
			v-model="domainsTyped"
			:label="t('portaliq', 'Allowed e-mail domains')"
			:helperText="
				t('portaliq', 'One per line. Leave empty to allow every address.')
			"
			data-testid="portal-registration-domains" />
		<NcNoteCard v-if="notice" :type="noticeType">
			{{ notice }}
		</NcNoteCard>
		<NcButton
			variant="primary"
			:disabled="saving"
			data-testid="portal-registration-save"
			@click="save">
			{{ t('portaliq', 'Save') }}
		</NcButton>

		<section
			class="portal-registration__waiting"
			data-testid="portal-registration-waiting">
			<h3>{{ t('portaliq', 'Waiting for approval') }}</h3>
			<NcLoadingIcon v-if="loading" />
			<p v-else-if="waiting.length === 0" class="portal-registration__empty">
				{{ t('portaliq', 'Nobody is waiting for approval.') }}
			</p>
			<ul v-else>
				<li
					v-for="row in waiting"
					:key="row.subjectRef"
					class="portal-registration__row"
					:data-testid="`portal-registration-row-${row.subjectRef}`">
					<span class="portal-registration__who">
						{{ row.displayName || row.email }}
						<span
							v-if="row.displayName"
							class="portal-registration__email"
							>{{ row.email }}</span
						>
						<span class="portal-registration__unverified">
							{{ t('portaliq', 'This address is not verified.') }}
						</span>
					</span>
					<NcButton
						variant="primary"
						:data-testid="`portal-registration-approve-${row.subjectRef}`"
						@click="approve(row)">
						{{ t('portaliq', 'Approve') }}
					</NcButton>
					<NcButton
						variant="error"
						:data-testid="`portal-registration-refuse-${row.subjectRef}`"
						@click="refuse(row)">
						{{ t('portaliq', 'Refuse') }}
					</NcButton>
				</li>
			</ul>
		</section>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcTextArea,
} from '@nextcloud/vue'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import VoidAccountDialog from '../dialogs/VoidAccountDialog.vue'
import {
	createRegistrationSettings,
	parseDomains,
	registrationOf,
} from '../lib/registrationSettings.js'

export default {
	name: 'PortalRegistration',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcTextArea,
	},

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		const registration = registrationOf(this.objectData)
		return {
			policy: registration.policy,
			domainsTyped: registration.allowedDomains.join('\n'),
			saving: false,
			notice: '',
			noticeType: 'success',
			loading: true,
			waiting: [],
		}
	},

	computed: {
		/**
		 * The three policies in words.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		policies() {
			return [
				{
					id: 'off',
					label: t(
						'portaliq',
						'Nobody: accounts are issued or invited by staff',
					),
				},
				{
					id: 'approval',
					label: t('portaliq', 'Anyone, after a staff member approves'),
				},
				{
					id: 'activation',
					label: t('portaliq', 'Anyone who confirms their e-mail address'),
				},
			]
		},
	},

	/**
	 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
	 */
	created() {
		this.api = createRegistrationSettings({
			get: (url) => axios.get(url),
			put: (url, body) => axios.put(url, body),
			post: (url, body) => axios.post(url, body),
			url: generateUrl,
			translate: (text) => t('portaliq', text),
		})
		this.loadWaiting()
	},

	methods: {
		t,

		/**
		 * Read the registrations waiting for a decision.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async loadWaiting() {
			this.loading = true
			try {
				this.waiting = await this.api.waiting(
					this.objectData?.organisation || '',
				)
			} catch {
				this.waiting = []
			}
			this.loading = false
		},

		/**
		 * Save the policy and the domains on the portal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async save() {
			const id = String(
				this.objectData?.id
					|| this.objectData?.uuid
					|| this.objectData?.['@self']?.id
					|| '',
			)
			this.saving = true
			const outcome = await this.api.save(id, {
				policy: this.policy,
				allowedDomains: parseDomains(this.domainsTyped),
			})
			this.saving = false
			this.show(outcome)
		},

		/**
		 * Approve one registration and take it off the list.
		 *
		 * @param {object} row The account row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async approve(row) {
			this.show(await this.api.approve(row))
			await this.loadWaiting()
		},

		/**
		 * Refuse one registration with a reason and take it off the list.
		 *
		 * @param {object} row The account row.
		 * @return {Promise<void>}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		async refuse(row) {
			const reason = await spawnDialog(VoidAccountDialog, {
				title: t('portaliq', 'Refuse this registration'),
				confirmLabel: t('portaliq', 'Refuse'),
			})
			if (typeof reason !== 'string' || reason === '') {
				return
			}
			this.show(await this.api.refuse(row, reason))
			await this.loadWaiting()
		},

		/**
		 * Show an outcome under the form.
		 *
		 * @param {{ok: boolean, message: string}} outcome The outcome.
		 * @return {void}
		 * @spec openspec/specs/portal-account-administration/spec.md#requirement-staff-set-the-registration-policy-and-approve-registrations-req-isa-004
		 */
		show(outcome) {
			this.notice = outcome.message
			this.noticeType = outcome.ok ? 'success' : 'error'
		},
	},
}
</script>

<style scoped>
.portal-registration {
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-start;
}

.portal-registration__policy {
	border: 0;
	padding: 0;
}

.portal-registration__waiting {
	width: 100%;
	margin-top: 16px;
}

.portal-registration__row {
	display: flex;
	gap: 8px;
	align-items: center;
	padding: 4px 0;
}

.portal-registration__who {
	display: flex;
	flex-direction: column;
	flex: 1;
}

.portal-registration__email,
.portal-registration__unverified,
.portal-registration__empty {
	color: var(--color-text-maxcontrast);
}
</style>
