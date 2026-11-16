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
	<div class="account-pages" data-testid="portal-account-pages">
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
			<p v-if="pages.length === 0" class="account-pages__empty">
				{{ t('portaliq', 'No installed app contributes pages for this account.') }}
			</p>

			<ul v-else class="account-pages__list">
				<li
					v-for="page in pages"
					:key="page.page"
					class="account-pages__row"
					:data-testid="`account-page-${page.page}`">
					<span :id="labelId(page)" class="account-pages__label">
						{{ page.label }}
						<span class="account-pages__app">
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
				v-if="pages.length > 0"
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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
} from '@nextcloud/vue'
import { accountPagesUrl, hiddenPagesFrom } from '../lib/portalNavigation.js'

export default {
	name: 'PortalAccountPages',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** The account the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			saved: [],
			pages: [],
			loading: true,
			saving: false,
			error: '',
			notice: '',
		}
	},

	computed: {
		/**
		 * The account's subject reference.
		 *
		 * @return {string}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		subjectRef() {
			return String(this.objectData?.subjectRef || '')
		},

		/**
		 * Whether the switches differ from what is stored.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		dirty() {
			return (
				JSON.stringify(hiddenPagesFrom(this.pages))
				!== JSON.stringify(hiddenPagesFrom(this.saved))
			)
		},
	},

	watch: {
		/**
		 * Load again when the detail page hands over another account.
		 *
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		subjectRef() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the account's pages.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			if (this.subjectRef === '') {
				this.loading = false
				return
			}
			try {
				const { data } = await axios.get(accountPagesUrl(this.subjectRef, generateUrl))
				this.accept(data.pages || [])
			} catch {
				this.error = t('portaliq', 'The pages of this account could not be loaded.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Take a list from the server as the stored state.
		 *
		 * @param {Array<object>} pages The pages with `hidden`.
		 * @return {void}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		accept(pages) {
			this.saved = pages.map((page) => ({ ...page }))
			this.pages = pages.map((page) => ({ ...page }))
		},

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
			this.notice = ''
		},

		/**
		 * Save the hidden list.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/operate-pages-per-portal-and-client/specs/portal-page-choice/spec.md#requirement-a-client-sees-only-the-pages-and-records-left-to-them-req-pgc-002
		 */
		async save() {
			this.saving = true
			this.error = ''
			try {
				const { data } = await axios.put(accountPagesUrl(this.subjectRef, generateUrl), {
					hiddenPages: hiddenPagesFrom(this.pages),
				})
				this.accept(data.pages || [])
				this.notice = t('portaliq', 'Your choices are saved.')
			} catch {
				this.error = t('portaliq', 'Your choices could not be saved. Try again.')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.account-pages {
	padding: calc(var(--default-grid-baseline) * 2);
}

.account-pages__list {
	list-style: none;
	padding: 0;
	margin: calc(var(--default-grid-baseline) * 4) 0;
}

.account-pages__row {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 4);
	padding: calc(var(--default-grid-baseline) * 2) 0;
	border-bottom: 1px solid var(--color-border);
}

.account-pages__label {
	display: flex;
	flex-direction: column;
	flex: 1 1 200px;
	font-weight: bold;
}

.account-pages__app {
	font-weight: normal;
	color: var(--color-text-maxcontrast);
}
</style>
