<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  RevealedContactDialog: what the reporter gave about themselves, shown once
  to the custodian who allowed the reveal. It is not stored on the page and is
  gone when the dialog closes; the report afterwards shows who asked and who
  allowed it, never the contact.

  @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
-->
<template>
	<CnDialog
		:look="look"
		:name="t('portaliq', 'What the reporter gave')"
		size="normal"
		data-testid="revealed-contact"
		@closing="$emit('close')">
		<NcNoteCard type="warning">
			{{
				t(
					'portaliq',
					'You see this once. Write down only what you need, where it belongs.',
				)
			}}
		</NcNoteCard>
		<dl class="revealed-contact">
			<template v-for="row in rows" :key="row.key">
				<dt>{{ row.label }}</dt>
				<dd>{{ row.value }}</dd>
			</template>
		</dl>
		<p v-if="rows.length === 0">
			{{ t('portaliq', 'The reporter gave nothing about themselves.') }}
		</p>

		<template #actions>
			<NcButton variant="primary" @click="$emit('close')">
				{{ t('portaliq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'RevealedContactDialog',

	components: {
		NcButton,
		CnDialog,
		NcNoteCard,
	},

	props: {
		/** What the reporter gave: `name`, `email`, `phone`. */
		contact: { type: Object, default: () => ({}) },
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},
	},

	emits: ['close'],

	computed: {
		/**
		 * The fields the reporter filled in, labelled.
		 *
		 * @return {Array<{key: string, label: string, value: string}>}
		 * @spec openspec/changes/intake-report-pages/specs/report-pages/spec.md#requirement-the-custodian-decides-a-reveal-on-a-screen-of-their-own-req-irp-006
		 */
		rows() {
			const labels = {
				name: t('portaliq', 'Name'),
				email: t('portaliq', 'E-mail address'),
				phone: t('portaliq', 'Phone number'),
			}
			return Object.keys(labels)
				.filter((key) => String(this.contact?.[key] || '').trim() !== '')
				.map((key) => ({
					key,
					label: labels[key],
					value: String(this.contact[key]).trim(),
				}))
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped>
.revealed-contact {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
	margin-top: 12px;
}

.revealed-contact dt {
	font-weight: bold;
}
</style>
