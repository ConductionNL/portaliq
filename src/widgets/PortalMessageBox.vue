<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalMessageBox: the "Berichtenbox van MijnOverheid" side card on a portal's
  settings tab (PtPortalInstellingen). Its switch turns the portal's
  organisation's message box offer on or off through
  /api/portals/{slug}/message-box. Without a digital post source configured in
  integriq the switch is disabled and the card says so.

  @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
-->
<template>
	<div class="portal-message-box" data-testid="portal-message-box">
		<h4 class="portal-message-box__title">
			{{ t('portaliq', 'MijnOverheid message box') }}
		</h4>
		<NcCheckboxRadioSwitch
			v-model="offered"
			type="switch"
			:disabled="loading || saving || !configured"
			data-testid="portal-message-box-switch"
			@update:modelValue="save">
			{{ t('portaliq', 'Also send letters and decisions to the message box') }}
		</NcCheckboxRadioSwitch>
		<p class="portal-message-box__hint">
			{{
				configured
					? t(
							'portaliq',
							'Through integriq. The case app chooses which message; portaliq does not keep the BSN. A resident can switch this off.',
						)
					: t(
							'portaliq',
							'Set up a digital post source in integriq first.',
						)
			}}
		</p>
		<p v-if="error" class="portal-message-box__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcCheckboxRadioSwitch } from '@nextcloud/vue'

export default {
	name: 'PortalMessageBox',

	components: {
		NcCheckboxRadioSwitch,
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
			offered: false,
			configured: false,
			loading: true,
			saving: false,
			error: '',
		}
	},

	computed: {
		/**
		 * The endpoint for this portal, or '' while the portal is unknown.
		 *
		 * @return {string} The URL.
		 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
		 */
		url() {
			const slug = String(this.objectData?.slug || '')
			return slug
				? generateUrl('/apps/portaliq/api/portals/{slug}/message-box', {
						slug,
					})
				: ''
		},
	},

	watch: {
		url: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	methods: {
		t,

		/**
		 * Read the offer.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
		 */
		async load() {
			if (!this.url) {
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(this.url)
				this.apply(data)
			} catch {
				this.error = t(
					'portaliq',
					'The message box setting could not be read.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Switch the offer, and show the stored state again when it was refused.
		 *
		 * @param {boolean} offered The new state.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
		 */
		async save(offered) {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(this.url, { offered })
				this.apply(data)
			} catch {
				this.offered = !offered
				this.error = t(
					'portaliq',
					'The message box setting could not be saved.',
				)
			} finally {
				this.saving = false
			}
		},

		/**
		 * Take the state the endpoint answered.
		 *
		 * @param {{offered: boolean, configured: boolean}} data The state.
		 * @return {void}
		 */
		apply(data) {
			this.offered = data?.offered === true
			this.configured = data?.configured === true
		},
	},
}
</script>

<style scoped>
/* A side card as the dossiq side cards and PortalSummaryCard draw it. */
.portal-message-box {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	padding: 20px 22px;
	font-size: 14px;
}

.portal-message-box__title {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast) !important;
	font-size: 15px;
	font-weight: 600;
	line-height: 1.2;
}

.portal-message-box__hint,
.portal-message-box__error {
	margin: 6px 0 0;
	font-size: 13px;
}

.portal-message-box__hint {
	color: var(--color-text-maxcontrast);
}

.portal-message-box__error {
	color: var(--color-error-text);
}
</style>
