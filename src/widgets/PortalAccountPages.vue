<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  PortalAccountPages: the pages hidden for one client, as a field on the
  portal account's page.

  Lists the pages the installed apps contribute for the account's audience,
  each with a "Hide for this client" switch. Saving writes the account's
  hiddenPages through PortalAccountPagesController, behind the staff account
  action. A hidden page also closes the collections only that page shows, so
  the client cannot read those records through the API either. A page the
  account hides that no installed app contributes now stays listed, so it can
  be shown again.

  @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
-->
<template>
	<div class="page-choice" data-testid="portal-account-pages">
		<p class="account-pages__intro">
			{{
				t(
					'portaliq',
					'A page hidden for this client is gone from their menu, and the records only that page shows can no longer be read by them.',
				)
			}}
		</p>

		<NcLoadingIcon v-if="loading" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<p v-if="items.length === 0" class="account-pages__empty">
				{{ t('portaliq', 'No installed app contributes pages for this account.') }}
			</p>

			<ul v-else class="page-choice__list">
				<li
					v-for="page in items"
					:key="page.page"
					class="page-choice__row"
					:data-testid="`account-page-${page.page}`">
					<span :id="labelId(page)" class="page-choice__label">
						{{ page.label }}
						<span class="page-choice__app">
							{{ page.contributed === false ? t('portaliq', '{app}, no longer installed', { app: page.appLabel }) : page.appLabel }}
						</span>
					</span>
					<NcCheckboxRadioSwitch
						:modelValue="page.hidden"
						type="switch"
						:aria-describedby="labelId(page)"
						@update:modelValue="(value) => setHidden(page, value)">
						{{ t('portaliq', 'Hide for this client') }}
					</NcCheckboxRadioSwitch>
				</li>
			</ul>

			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>

			<NcButton
				v-if="items.length > 0"
				variant="primary"
				:disabled="saving || !dirty"
				data-testid="account-pages-save"
				@click="save">
				{{ t('portaliq', 'Save') }}
			</NcButton>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { accountPagesUrl, hiddenPagesFrom } from '../lib/portalNavigation.js'
import { pageChoiceEditor } from './pageChoiceEditor.js'

export default {
	name: 'PortalAccountPages',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	mixins: [
		pageChoiceEditor({
			key: 'pages',
			field: 'hiddenPages',
			url: accountPagesUrl,
			shape: hiddenPagesFrom,
			copy: (pages) => pages.map((page) => ({ ...page })),
			loadError: () => t('portaliq', 'The pages of this account could not be loaded.'),
		}),
	],

	computed: {
		/**
		 * The account's subject reference.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		owner() {
			return String(this.objectData?.subjectRef || '')
		},
	},

	methods: {
		/**
		 * The id of a row's label, for the switch to point at.
		 *
		 * @param {object} page The page.
		 * @return {string}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		labelId(page) {
			return `account-page-label-${page.page}`.replace(/[^A-Za-z0-9_-]/g, '-')
		},

		/**
		 * Flip one switch.
		 *
		 * @param {object} page The page.
		 * @param {boolean} value Hidden or not.
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		setHidden(page, value) {
			page.hidden = Boolean(value)
			this.edited()
		},
	},
}
</script>

<style scoped src="./pageChoice.css"></style>
