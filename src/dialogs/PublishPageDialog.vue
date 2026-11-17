<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PublishPageDialog: the confirmation before a page is published
  (page-public-on-purpose). Publishing is the act that makes a page readable
  without signing in, so the editor is told that, and where, before it
  happens. Closes with true to publish, false to leave the page as it is.

  Its own file per ADR-004's modal-isolation rule; the page designer and the
  site edit mode both mount it.

  @spec openspec/changes/page-public-on-purpose/tasks.md#1.1
-->
<template>
	<CnDialog
		:look="look"
		:name="t('portaliq', 'Publish this page?')"
		size="small"
		data-testid="publish-page-dialog"
		@closing="$emit('close', false)">
		<p class="publish-page__lead">
			{{ t('portaliq', 'Anyone can read this page, without signing in.') }}
		</p>
		<p v-if="address" class="publish-page__address">
			{{ t('portaliq', 'Its address:') }}
			<a :href="address" target="_blank" rel="noopener" data-testid="publish-page-address">{{ address }}</a>
		</p>

		<template #actions>
			<NcButton data-testid="publish-page-cancel" @click="$emit('close', false)">
				{{ t('portaliq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="publish-page-confirm"
				@click="$emit('close', true)">
				{{ t('portaliq', 'Publish') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { translate as t } from '@nextcloud/l10n'
import { NcButton } from '@nextcloud/vue'

export default {
	name: 'PublishPageDialog',

	components: {
		CnDialog,
		NcButton,
	},

	props: {
		/** The look to draw in; a spawned dialog gets the app's (src/lib/dialogLook.js). */
		look: {
			type: String,
			default: undefined,
		},

		/** The public address the page will be served at. */
		address: {
			type: String,
			default: '',
		},
	},

	emits: ['close'],

	methods: {
		t,
	},
}
</script>

<style scoped>
.publish-page__address {
	margin-block-start: var(--default-grid-baseline, 4px);
	overflow-wrap: anywhere;
}
</style>
