<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalSignin: how residents sign in to the portal's organisation, as a
  widget on the portal's own page (signin-integriq-broker-login T11).

  Per provider the route: the organisation's own OIDC broker or integriq's
  broker. The integriq broker needs its start and exchange addresses, the
  consumer id and a secret; the secret is write-only. Saving a broker route
  without every setting is refused. Saves through PortalSigninController,
  which is admin-only.

  @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
-->
<template>
	<div class="portal-signin" data-testid="portal-signin">
		<NcLoadingIcon v-if="state === 'loading'" />
		<NcNoteCard v-else-if="state === 'error'" type="error">
			{{
				t(
					'portaliq',
					'The sign-in settings could not be loaded. The portal needs an organisation.',
				)
			}}
		</NcNoteCard>
		<template v-else>
			<p class="portal-signin__scope">
				{{
					t(
						'portaliq',
						'These settings belong to the organisation {organisation} and apply to all its portals.',
						{ organisation },
					)
				}}
			</p>
			<fieldset
				v-for="row in providers"
				:key="row.provider"
				class="portal-signin__provider"
				:data-testid="`portal-signin-${row.provider}`">
				<legend>{{ label(row.provider) }}</legend>
				<NcCheckboxRadioSwitch
					:modelValue="row.route"
					value="oidc"
					type="radio"
					:name="`portal-signin-${row.provider}`"
					@update:modelValue="row.route = $event">
					{{
						t(
							'portaliq',
							"The organisation's own sign-in service (OIDC)",
						)
					}}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch
					:modelValue="row.route"
					value="broker"
					type="radio"
					:name="`portal-signin-${row.provider}`"
					@update:modelValue="row.route = $event">
					{{ t('portaliq', 'Integriq') }}
				</NcCheckboxRadioSwitch>
			</fieldset>
			<NcNoteCard type="warning">
				{{
					t(
						'portaliq',
						'Accounts do not carry over between routes. A resident who signed in before gets a new account after the route changes.',
					)
				}}
			</NcNoteCard>
			<fieldset class="portal-signin__broker">
				<legend>{{ t('portaliq', 'Integriq') }}</legend>
				<NcTextField
					v-model="broker.startUrl"
					:label="t('portaliq', 'Start address')"
					data-testid="portal-signin-start" />
				<NcTextField
					v-model="broker.exchangeUrl"
					:label="t('portaliq', 'Exchange address')"
					data-testid="portal-signin-exchange" />
				<NcTextField
					v-model="broker.consumerId"
					:label="t('portaliq', 'Consumer id')"
					data-testid="portal-signin-consumer" />
				<NcPasswordField
					v-model="secret"
					:label="
						hasSecret
							? t(
									'portaliq',
									'Consumer secret (stored, type to replace)',
								)
							: t('portaliq', 'Consumer secret')
					"
					autocomplete="new-password"
					data-testid="portal-signin-secret" />
			</fieldset>
			<NcNoteCard v-if="notice" :type="noticeType">
				{{ notice }}
			</NcNoteCard>
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="portal-signin-save"
				@click="save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcPasswordField,
	NcTextField,
} from '@nextcloud/vue'
import {
	createPortalSigninSettings,
	PROVIDER_LABELS,
	saveBody,
} from '../lib/portalSigninSettings.js'

export default {
	name: 'PortalSignin',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcTextField,
	},

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			state: 'loading',
			organisation: '',
			providers: [],
			broker: { startUrl: '', exchangeUrl: '', consumerId: '' },
			hasSecret: false,
			secret: '',
			saving: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		/**
		 * The portal's slug.
		 *
		 * @return {string}
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		slug() {
			return String(this.objectData?.slug || '')
		},
	},

	/**
	 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
	 */
	created() {
		this.api = createPortalSigninSettings({
			get: (url) => axios.get(url),
			put: (url, body) => axios.put(url, body),
			url: (path, params) => generateUrl('/apps/portaliq' + path, params),
		})
		this.load()
	},

	methods: {
		/**
		 * @param {string} provider The provider.
		 * @return {string}
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		label(provider) {
			return PROVIDER_LABELS[provider] || provider
		},

		/**
		 * @param {object|null} settings The settings the server answered.
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		apply(settings) {
			this.organisation = String(settings?.organisation || '')
			this.providers = (settings?.providers || []).map((row) => ({ ...row }))
			this.broker = {
				startUrl: '',
				exchangeUrl: '',
				consumerId: '',
				...(settings?.broker || {}),
			}
			this.hasSecret = settings?.hasSecret === true
			this.secret = ''
		},

		/**
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		async load() {
			const result = await this.api.load(this.slug)
			this.state = result.state
			this.apply(result.settings)
		},

		/**
		 * @spec openspec/changes/signin-integriq-broker-login/specs/portal-broker-envelope-login/spec.md#requirement-the-organisation-chooses-the-login-route-per-provider-req-bel-001
		 */
		async save() {
			this.saving = true
			const result = await this.api.save(
				this.slug,
				saveBody(this.providers, this.broker, this.secret),
			)
			this.saving = false
			if (result.outcome === 'saved') {
				this.apply(result.settings)
				this.noticeType = 'success'
				this.notice = t('portaliq', 'The sign-in settings are saved.')
				return
			}
			this.noticeType = 'error'
			this.notice =
				result.outcome === 'incomplete'
					? t(
							'portaliq',
							'A provider can only use integriq once the start address, the exchange address, the consumer id and the secret are all set.',
						)
					: t('portaliq', 'The sign-in settings could not be saved.')
		},
	},
}
</script>

<style scoped>
.portal-signin__provider,
.portal-signin__broker {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-block: 8px;
}
</style>
