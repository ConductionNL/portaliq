<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalDetailsForm: "Gegevens van de portal", the first card of a portal's
  Instellingen tab (screens PtPortalInstellingen). Name, slogan, address,
  organisation, kind, header, languages, the login button, the logo and the
  websites that may embed the portal. Saves the portal object in OpenRegister.

  @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
-->
<template>
	<form class="portal-details" data-testid="portal-details" @submit.prevent="save">
		<NcTextField
			v-model="form.title"
			:label="t('portaliq', 'Name')"
			:error="Boolean(errors.title)"
			:helperText="errors.title ? t('portaliq', errors.title) : ''"
			data-testid="portal-details-title" />
		<NcTextField v-model="form.tagline" :label="t('portaliq', 'Tagline')" />
		<NcTextField
			v-model="form.slug"
			:label="t('portaliq', 'Address in portaliq')"
			:error="Boolean(errors.slug)"
			:helperText="errors.slug ? t('portaliq', errors.slug) : t('portaliq', 'Pages and menus belong to the portal through this address.')"
			data-testid="portal-details-slug" />
		<NcTextField v-model="form.organisation" :label="t('portaliq', 'Organisation')" />
		<NcSelect
			v-model="kind"
			:inputLabel="t('portaliq', 'Kind')"
			:options="kindOptions"
			:clearable="false"
			label="label" />
		<NcSelect
			v-model="header"
			:inputLabel="t('portaliq', 'Header')"
			:options="headerOptions"
			:clearable="false"
			label="label" />
		<NcSelect
			v-model="form.locales"
			:inputLabel="t('portaliq', 'Languages')"
			:options="localeOptions"
			:multiple="true"
			:taggable="true"
			:clearable="false"
			data-testid="portal-details-locales" />
		<p v-if="errors.locales" class="portal-details__error">
			{{ t('portaliq', errors.locales) }}
		</p>
		<p class="portal-details__hint">
			{{ t('portaliq', 'With more than one language the header shows a language choice.') }}
		</p>
		<NcTextField v-model="form.accountLabel" :label="t('portaliq', 'Name of the login button')" />
		<NcTextField
			v-model="form.logo"
			:label="t('portaliq', 'Logo')"
			:helperText="t('portaliq', 'The address of the logo, or a reference to a file in Media.')" />
		<NcSelect
			v-model="form.frameAncestors"
			:inputLabel="t('portaliq', 'May be embedded on')"
			:options="[]"
			:multiple="true"
			:taggable="true"
			:noDrop="true"
			data-testid="portal-details-origins" />
		<p v-if="errors.frameAncestors" class="portal-details__error">
			{{ t('portaliq', errors.frameAncestors) }}
		</p>
		<NcNoteCard v-if="notice" :type="noticeType" data-testid="portal-details-notice">
			{{ notice }}
		</NcNoteCard>
		<div class="portal-details__actions">
			<NcButton variant="primary" type="submit" :disabled="saving" data-testid="portal-details-save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</div>
	</form>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import {
	formFrom,
	HEADER_OPTIONS,
	KIND_OPTIONS,
	LOCALE_OPTIONS,
	portalBody,
	validateDetails,
} from '../lib/portalDetails.js'

export default {
	name: 'PortalDetailsForm',

	components: { NcButton, NcNoteCard, NcSelect, NcTextField },

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			form: formFrom(this.objectData),
			errors: {},
			saving: false,
			notice: '',
			noticeType: 'success',
			localeOptions: LOCALE_OPTIONS,
		}
	},

	computed: {
		kindOptions() {
			return KIND_OPTIONS.map((o) => ({ id: o.id, label: t('portaliq', o.label) }))
		},

		headerOptions() {
			return HEADER_OPTIONS.map((o) => ({ id: o.id, label: t('portaliq', o.label) }))
		},

		kind: {
			get() {
				return this.kindOptions.find((o) => o.id === this.form.kind)
			},

			set(option) {
				this.form.kind = option?.id || 'site'
			},
		},

		header: {
			get() {
				return this.headerOptions.find((o) => o.id === this.form.headerVariant)
			},

			set(option) {
				this.form.headerVariant = option?.id || 'double'
			},
		},
	},

	methods: {
		t,

		/**
		 * Check and save the portal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async save() {
			this.errors = validateDetails(this.form)
			if (Object.keys(this.errors).length > 0) {
				return
			}
			const id = this.objectData?.['@self']?.id || this.objectData?.id
			if (!id) {
				return
			}
			this.saving = true
			this.notice = ''
			try {
				await axios.put(
					generateUrl(`/apps/openregister/api/objects/portaliq/portal/${id}`),
					portalBody(this.objectData, this.form),
				)
				this.noticeType = 'success'
				this.notice = t('portaliq', 'The portal is saved.')
			} catch {
				this.noticeType = 'error'
				this.notice = t('portaliq', 'The portal could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.portal-details {
	display: flex;
	flex-direction: column;
	gap: 14px;
}
.portal-details__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}
.portal-details__error {
	margin: 0;
	color: var(--color-error-text);
}
.portal-details__actions {
	display: flex;
	justify-content: flex-end;
}
</style>
