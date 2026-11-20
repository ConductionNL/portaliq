<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  NewPortalDialog: an instance administrator adds an organisation's portal.

  Its own file per ADR-004's modal-isolation rule. Spawned by the "New portal"
  header action on the Portals page (src/lib/portalProvisioning.js). The
  portal is created as a draft with its host, a menu and a home page; the
  dialog then shows the DNS record that verifies the host, because the
  administrator has to copy it. A refusal is shown here. It closes with the
  success sentence, or with null when cancelled.

  @spec openspec/changes/operate-portals-per-organisation/specs/portal-tenancy/spec.md#requirement-a-hosting-party-can-add-an-organisations-portal-in-one-step-req-opo-004
-->
<template>
	<CnDialog
		:look="look"
		:name="t('portaliq', 'New portal')"
		:eyebrow="t('portaliq', 'Portals')"
		size="normal"
		data-testid="new-portal"
		@closing="$emit('close', created)">
		<template v-if="dns === null">
			<NcTextField
				v-model="fields.title"
				:label="t('portaliq', 'Title')"
				:helperText="t('portaliq', 'For example Gemeente Zeist.')"
				data-testid="new-portal-title"
				@update:modelValue="suggestSlug" />
			<NcTextField
				v-model="fields.slug"
				:label="t('portaliq', 'Slug')"
				:helperText="
					t('portaliq', 'Lower case letters, digits and hyphens. It names the portal in links.')
				"
				data-testid="new-portal-slug"
				@update:modelValue="slugTouched = true" />
			<NcTextField
				v-model="fields.organisation"
				:label="t('portaliq', 'Organisation')"
				:helperText="t('portaliq', 'The organisation the portal and its data belong to.')"
				data-testid="new-portal-organisation" />
			<NcTextField
				v-model="fields.host"
				:label="t('portaliq', 'Web address')"
				:helperText="t('portaliq', 'Without https://, for example mijn.zeist.nl.')"
				data-testid="new-portal-host" />
			<NcNoteCard type="info" class="new-portal__note">
				{{
					t(
						'portaliq',
						'The portal is created as a draft with a menu and a home page. It goes live when its web address is verified and you publish it.',
					)
				}}
			</NcNoteCard>
			<p
				v-if="refusal"
				class="new-portal__refusal"
				role="alert"
				data-testid="new-portal-refusal">
				{{ refusal }}
			</p>
		</template>
		<template v-else>
			<p data-testid="new-portal-created">
				{{ created }}
			</p>
			<p>{{ t('portaliq', 'Publish this DNS TXT record to verify the web address:') }}</p>
			<dl class="new-portal__record" data-testid="new-portal-dns">
				<dt>{{ t('portaliq', 'Name') }}</dt>
				<dd><code>{{ dns.name }}</code></dd>
				<dt>{{ t('portaliq', 'Value') }}</dt>
				<dd><code>{{ dns.value }}</code></dd>
			</dl>
		</template>

		<template #actions>
			<template v-if="dns === null">
				<NcButton @click="$emit('close', null)">
					{{ t('portaliq', 'Cancel') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy"
					data-testid="new-portal-confirm"
					@click="confirm">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('portaliq', 'Create portal') }}
				</NcButton>
			</template>
			<NcButton
				v-else
				variant="primary"
				data-testid="new-portal-close"
				@click="$emit('close', created)">
				{{ t('portaliq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { slugFromTitle } from '../lib/portalProvisioning.js'

export default {
	name: 'NewPortalDialog',

	components: {
		CnDialog,
		NcButton,
		NcNoteCard,
		NcTextField,
		Plus,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},

		/** Posts the fields; answers `{ok, message, dns?}`. */
		submit: {
			type: Function,
			required: true,
		},
	},

	emits: ['close'],

	data() {
		return {
			fields: { title: '', slug: '', organisation: '', host: '' },
			slugTouched: false,
			refusal: '',
			busy: false,
			created: null,
			dns: null,
		}
	},

	methods: {
		t,

		/**
		 * Suggest a slug from the title until the slug is typed in itself.
		 *
		 * @param {string} title The title.
		 * @return {void}
		 */
		suggestSlug(title) {
			if (!this.slugTouched) {
				this.fields.slug = slugFromTitle(title)
			}
		},

		/**
		 * Submit, then show the DNS record on success or the refusal.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t07
		 */
		async confirm() {
			this.busy = true
			this.refusal = ''
			const outcome = await this.submit({ ...this.fields })
			this.busy = false
			if (!outcome.ok) {
				this.refusal = outcome.message
				return
			}
			this.created = outcome.message
			if (!outcome.dns) {
				this.$emit('close', outcome.message)
				return
			}
			this.dns = outcome.dns
		},
	},
}
</script>

<style scoped>
.new-portal__note {
	margin-top: 16px;
}

.new-portal__refusal {
	margin-top: 8px;
	color: var(--color-error-text);
}

.new-portal__record {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-top: 8px;
}

.new-portal__record dt {
	color: var(--color-text-maxcontrast);
}

.new-portal__record dd {
	margin: 0;
	overflow-wrap: anywhere;
}
</style>
