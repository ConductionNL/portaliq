<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalIdentity: the portal's favicon, logo, hero image and kind of
  organisation, as a widget on the portal's own page
  (portal-identity-from-the-admin REQ-PIA-001, REQ-PIA-003).

  The images come from this portal's media library; a new image is uploaded
  on the Media page first. The kind of organisation comes from the TOOI list
  in OpenRegister's concept register; without that list the picker says so
  and offers nothing. The save goes through OpenRegister's object API, where
  the portal guard refuses a favicon that is not a PNG, SVG or ICO file.

  @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
-->
<template>
	<div class="portal-identity" data-testid="portal-identity">
		<NcLoadingIcon v-if="state === 'loading'" />
		<template v-else>
			<p class="portal-identity__intro">
				{{
					t(
						'portaliq',
						'Pick the images from the media library of this portal. Upload a new image on the Media page first.',
					)
				}}
			</p>
			<p v-if="images.length === 0" data-testid="portal-identity-no-images">
				{{ t('portaliq', 'This portal has no published images yet.') }}
			</p>
			<div
				v-for="field in fields"
				:key="field.key"
				class="portal-identity__field">
				<NcSelect
					v-model="chosen[field.key]"
					:inputLabel="field.label"
					:options="imageOptions"
					:reduce="(option) => option.id"
					label="title"
					:disabled="images.length === 0"
					:data-testid="`portal-identity-${field.key}`" />
				<p class="portal-identity__hint">
					{{ field.hint }}
				</p>
			</div>

			<div class="portal-identity__field">
				<NcSelect
					v-model="chosen.organisationType"
					:inputLabel="t('portaliq', 'Kind of organisation')"
					:options="types.options"
					:reduce="(option) => option.uri"
					label="label"
					:disabled="!types.installed"
					data-testid="portal-identity-organisation-type" />
				<p
					v-if="!types.installed"
					class="portal-identity__hint"
					data-testid="portal-identity-types-missing">
					{{
						t(
							'portaliq',
							'The TOOI list of organisation types is not installed in the concept register, so there is nothing to pick.',
						)
					}}
				</p>
			</div>

			<NcNoteCard v-if="notice" :type="noticeType">
				{{ notice }}
			</NcNoteCard>
			<NcButton
				variant="primary"
				:disabled="saving || !portalId"
				data-testid="portal-identity-save"
				@click="save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { choiceOf, createPortalIdentity } from '../lib/portalIdentity.js'

export default {
	name: 'PortalIdentity',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
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
			images: [],
			types: { installed: false, options: [] },
			chosen: choiceOf(this.objectData),
			saving: false,
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		/**
		 * The portal's id.
		 *
		 * @return {string}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		portalId() {
			return String(
				this.objectData?.id || this.objectData?.['@self']?.id || '',
			)
		},

		/**
		 * The library images as options.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		imageOptions() {
			return this.images
		},

		/**
		 * The three image pickers.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		fields() {
			return [
				{
					key: 'favicon',
					label: t('portaliq', 'Favicon'),
					hint: t(
						'portaliq',
						'The small image in the browser tab. A PNG, SVG or ICO file.',
					),
				},
				{
					key: 'logo',
					label: t('portaliq', 'Logo'),
					hint: t('portaliq', 'Shown in the header of the site.'),
				},
				{
					key: 'heroImage',
					label: t('portaliq', 'Hero image'),
					hint: t(
						'portaliq',
						'The large image at the top of the home page, when its hero has none.',
					),
				},
			]
		},
	},

	/**
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
	 */
	created() {
		this.api = createPortalIdentity({
			get: (address) => axios.get(address),
			put: (address, body) => axios.put(address, body),
			url: (path, params) => generateUrl('/apps/portaliq' + path, params),
			ocUrl: (path, params) => generateUrl(path, params),
			translate: (key) => t('portaliq', key),
		})
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the images and the kinds of organisation.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		async load() {
			const [images, types] = await Promise.all([
				this.api.images(String(this.objectData?.slug || '')),
				this.api.types(),
			])
			this.images = images.items
			this.types = types
			this.state = 'ready'
		},

		/**
		 * Save the choice; a refusal is shown as the guard words it.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
		 */
		async save() {
			this.saving = true
			const result = await this.api.save(
				this.portalId,
				this.chosen,
				this.types,
			)
			this.saving = false
			this.notice = result.message
			this.noticeType = result.ok ? 'success' : 'error'
		},
	},
}
</script>

<style scoped>
.portal-identity {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 3);
}

.portal-identity__hint {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
</style>
