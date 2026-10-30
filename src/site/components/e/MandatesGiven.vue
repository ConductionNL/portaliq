<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Wie mag zaken regelen voor u?" (site-mandates-the-represented-manage T4,
	board Machtigingen). The mandates and open invitations given on the
	session's own behalf, in a table: who, what they may arrange, until when,
	the state, and the end action. "Iemand machtigen" opens the dialog. A
	session that may not manage (acting under a mandate) sees nothing here.
-->
<template>
	<section
		v-if="items !== null"
		class="pq-mandates-given"
		aria-labelledby="pq-mandates-given-title"
		data-testid="mandates-given">
		<div class="pq-mandates-given__head">
			<h2 id="pq-mandates-given-title" class="utrecht-heading-3">
				{{ t('Who may arrange matters for you?') }}
			</h2>
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="mandates-authorise"
				@click="inviting = true">
				<svg
					class="pq-button-icon"
					viewBox="0 0 24 24"
					aria-hidden="true"
					focusable="false">
					<path
						d="M15,4A4,4 0 0,0 11,8A4,4 0 0,0 15,12A4,4 0 0,0 19,8A4,4 0 0,0 15,4M15,5.9C16.16,5.9 17.1,6.84 17.1,8C17.1,9.16 16.16,10.1 15,10.1A2.1,2.1 0 0,1 12.9,8A2.1,2.1 0 0,1 15,5.9M4,7V10H1V12H4V15H6V12H9V10H6V7H4M15,13C12.33,13 7,14.33 7,17V20H23V17C23,14.33 17.67,13 15,13M15,14.9C17.97,14.9 21.1,16.36 21.1,17V18.1H8.9V17C8.9,16.36 12,14.9 15,14.9Z" />
				</svg>
				{{ t('Authorise someone') }}
			</button>
		</div>
		<p
			v-if="notice !== ''"
			class="utrecht-paragraph"
			role="status"
			data-testid="mandates-notice">
			{{ t(notice) }}
		</p>
		<p
			v-if="problem !== ''"
			class="utrecht-paragraph pq-mandates-given__error"
			role="alert">
			{{ t(problem) }}
		</p>
		<p
			v-if="items.length === 0"
			class="utrecht-paragraph"
			data-testid="mandates-given-empty">
			{{ t('Nobody may arrange matters for you yet.') }}
		</p>
		<table v-else class="utrecht-table pq-mandates-given__table">
			<thead class="utrecht-table__header">
				<tr class="utrecht-table__row">
					<th class="utrecht-table__header-cell" scope="col">
						{{ t('Who') }}
					</th>
					<th class="utrecht-table__header-cell" scope="col">
						{{ t('What may this person arrange') }}
					</th>
					<th class="utrecht-table__header-cell" scope="col">
						{{ t('Until when') }}
					</th>
					<th class="utrecht-table__header-cell" scope="col">
						{{ t('Status') }}
					</th>
					<th class="utrecht-table__header-cell" scope="col">
						{{ t('Action') }}
					</th>
				</tr>
			</thead>
			<tbody class="utrecht-table__body">
				<tr
					v-for="item in items"
					:key="`${item.kind}-${item.id}`"
					class="utrecht-table__row"
					data-testid="mandates-given-row"
					:data-kind="item.kind">
					<td class="utrecht-table__cell">
						<strong translate="no">{{ who(item).name }}</strong>
						<span
							v-if="who(item).email !== ''"
							class="pq-mandates-given__email"
							>{{ who(item).email }}</span
						>
					</td>
					<td class="utrecht-table__cell">
						{{ scope(item) }}
					</td>
					<td class="utrecht-table__cell">
						{{ until(item) }}
					</td>
					<td class="utrecht-table__cell">
						<span
							class="pq-mandates-given__badge"
							:data-state="item.state"
							>{{ t(state(item)) }}</span
						>
					</td>
					<td class="utrecht-table__cell">
						<button
							type="button"
							class="utrecht-button utrecht-button--subtle"
							data-testid="mandates-given-end"
							@click="end(item)">
							{{ t(endAction(item)) }}
						</button>
					</td>
				</tr>
			</tbody>
		</table>
		<p class="utrecht-paragraph">
			{{
				t(
					'A mandate counts only once the other person signs in and accepts the invitation.',
				)
			}}
		</p>
		<MandateInviteModal
			v-if="inviting"
			:api="api"
			:t="t"
			:caseTypes="caseTypes"
			@cancel="inviting = false"
			@sent="invited" />
	</section>
</template>

<script>
import MandateInviteModal from '../../modals/e/MandateInviteModal.vue'
import { mandatesApi } from '../../../shared/areaApi.js'
import { longDate, readerLocale } from '../../pages/e/format.js'
import { endActionText, endText, scopeText, stateText, whoOf } from './mandates.js'

export default {
	name: 'MandatesGiven',

	components: { MandateInviteModal },

	props: {
		/** The portal API adapter (`createPortalApi` shape). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The portal record; its slug names the case types to choose from. */
		portal: { type: Object, default: null },
		/** The reader's locale; the page's `<html lang>` when empty. */
		locale: { type: String, default: '' },
	},

	data() {
		return {
			// null until read, and when the session may not manage.
			items: null,
			caseTypes: [],
			inviting: false,
			notice: '',
			problem: '',
		}
	},

	computed: {
		/**
		 * @return {object} The mandate calls over the portal api.
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		client() {
			return mandatesApi(this.api)
		},
	},

	/**
	 * Read the list and the case types when the section opens.
	 *
	 * @return {Promise<void>} Resolves when read.
	 *
	 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
	 */
	async mounted() {
		await this.load()
		if (this.items !== null) {
			this.caseTypes = await this.client.caseTypes(this.portal?.slug || '')
		}
	},

	methods: {
		who: whoOf,
		state: stateText,
		endAction: endActionText,

		/**
		 * What the item covers.
		 *
		 * @param {object} item The mandate or invitation.
		 * @return {string}
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		scope(item) {
			return item.label || scopeText(item.caseTypes, this.caseTypes, this.t)
		},

		/**
		 * The item's end, in the reader's language.
		 *
		 * @param {object} item The mandate or invitation.
		 * @return {string}
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		until(item) {
			return endText(
				item.expiresAt,
				(value) => longDate(value, readerLocale(this.locale)),
				this.t,
			)
		},

		/**
		 * Read the list again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		async load() {
			this.items = await this.client.given()
		},

		/**
		 * The invitation went: close the dialog, say so and read again.
		 *
		 * @return {Promise<void>} Resolves when read.
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		async invited() {
			this.inviting = false
			this.notice = 'The invitation is sent.'
			await this.load()
		},

		/**
		 * Revoke a mandate or take back an invitation, then read again.
		 *
		 * @param {object} item The mandate or invitation.
		 * @return {Promise<void>} Resolves when done.
		 * @spec openspec/changes/site-mandates-the-represented-manage/tasks.md#t4
		 */
		async end(item) {
			this.notice = ''
			this.problem = ''
			const answer = await this.client.end(item)
			if (!answer.ok) {
				this.problem = 'That did not work. Try again later.'
			}
			await this.load()
		},
	},
}
</script>

<style scoped>
.pq-mandates-given > * + * {
	margin-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-mandates-given__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-mandates-given__table {
	inline-size: 100%;
}

.pq-mandates-given__email {
	display: block;
}

.pq-mandates-given__badge {
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}

.pq-mandates-given__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
	font-weight: var(--utrecht-typography-weight-scale-bold-font-weight, bold);
}
</style>
