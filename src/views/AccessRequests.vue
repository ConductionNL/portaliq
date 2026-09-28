<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  AccessRequests: the owner's side of an access request.

  A portal user asks for access to an organisation's cases; here staff who hold
  `portal.answer-access-request` see the pending requests and grant or refuse
  each one. A grant records the mandate that opens the cases. A refusal needs
  a reason, asked for in RefuseAccessRequestDialog.

  Custom because grant and refuse are actions with a server-side guard, not
  field edits on the request row.

  @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
-->
<template>
	<div class="access-requests" data-testid="access-requests">
		<h2>{{ t('portaliq', 'Access requests') }}</h2>
		<p class="access-requests__intro">
			{{
				t(
					'portaliq',
					'People who ask to see the cases of a company or person. Grant a request to open those cases to them.',
				)
			}}
		</p>

		<NcNoteCard v-if="error" type="error" data-testid="access-requests-error">
			{{ error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="loading" />
		<NcEmptyContent
			v-else-if="requests.length === 0"
			:name="t('portaliq', 'No requests waiting')"
			:description="
				t('portaliq', 'A new request shows up here as soon as someone asks.')
			" />
		<ul v-else class="access-requests__list">
			<li
				v-for="request in requests"
				:key="idOf(request)"
				class="access-requests__item"
				:data-testid="`access-request-${idOf(request)}`">
				<div class="access-requests__who">
					<strong>{{ request.displayName || request.subjectRef }}</strong>
					<span>{{
						t('portaliq', 'Asks for the cases of {party}', {
							party: request.onBehalfOf || request.organisation,
						})
					}}</span>
					<span class="access-requests__reason">{{ request.reason }}</span>
				</div>
				<div class="access-requests__actions">
					<NcButton
						variant="primary"
						:disabled="busy"
						:data-testid="`access-request-grant-${idOf(request)}`"
						@click="grant(request)">
						{{ t('portaliq', 'Grant') }}
					</NcButton>
					<NcButton
						:disabled="busy"
						:data-testid="`access-request-refuse-${idOf(request)}`"
						@click="startRefusal(request)">
						{{ t('portaliq', 'Refuse') }}
					</NcButton>
				</div>
			</li>
		</ul>

		<RefuseAccessRequestDialog
			:open="refusing"
			@update:open="refusing = $event"
			@refuse="refuse" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import RefuseAccessRequestDialog from '../dialogs/RefuseAccessRequestDialog.vue'

export default {
	name: 'AccessRequests',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		RefuseAccessRequestDialog,
	},

	data() {
		return {
			requests: [],
			loading: true,
			busy: false,
			error: '',
			refusing: false,
			selected: null,
		}
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * The request's id, wherever the register put it.
		 *
		 * @param {object} request The request row.
		 * @return {string} The id.
		 */
		idOf(request) {
			return String(request.uuid || request.id || request['@self']?.id || '')
		},

		/**
		 * Read the pending requests.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(
					generateUrl('/apps/portaliq/api/access-requests'),
				)
				this.requests = Array.isArray(data?.requests) ? data.requests : []
			} catch (error) {
				this.error = this.messageFor(error)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Grant one request.
		 *
		 * @param {object} request The request row.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-a-granted-request-opens-the-cases-req-iar-003
		 */
		async grant(request) {
			await this.answer(request, 'grant', {})
		},

		/**
		 * Open the refusal dialog for one request.
		 *
		 * @param {object} request The request row.
		 * @return {void}
		 */
		startRefusal(request) {
			this.selected = request
			this.refusing = true
		},

		/**
		 * Refuse the selected request with the reason given.
		 *
		 * @param {string} reason Why it is refused.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/identity-access-requests/specs/portal-access-requests/spec.md#requirement-staff-answer-the-requests-of-their-organisation-req-iar-002
		 */
		async refuse(reason) {
			if (this.selected === null) {
				return
			}
			await this.answer(this.selected, 'refuse', { reason })
			this.selected = null
		},

		/**
		 * Post one answer, then read the list again.
		 *
		 * @param {object} request The request row.
		 * @param {string} verb `grant` or `refuse`.
		 * @param {object} body Extra fields for the answer.
		 * @return {Promise<void>}
		 */
		async answer(request, verb, body) {
			this.busy = true
			this.error = ''
			try {
				const url = generateUrl(
					'/apps/portaliq/api/access-requests/{id}/{verb}',
					{ id: this.idOf(request), verb },
				)
				await axios.post(url, {
					...body,
					organisation: request.organisation,
				})
				await this.load()
			} catch (error) {
				this.error = this.messageFor(error)
			} finally {
				this.busy = false
			}
		},

		/**
		 * A sentence for a failed call.
		 *
		 * @param {object} error The axios error.
		 * @return {string} The sentence.
		 */
		messageFor(error) {
			const status = error?.response?.status
			if (status === 403) {
				return t(
					'portaliq',
					'You may not answer access requests. Ask an administrator for this right.',
				)
			}
			if (status === 409) {
				return t('portaliq', 'Someone already answered this request.')
			}
			if (status === 502) {
				return t(
					'portaliq',
					'The access could not be recorded, so the request is still waiting. Try again.',
				)
			}
			return t(
				'portaliq',
				'The access requests could not be loaded or saved. Try again.',
			)
		},
	},
}
</script>

<style scoped>
.access-requests {
	padding: 16px 24px;
}

.access-requests__intro {
	margin-bottom: 16px;
	color: var(--color-text-maxcontrast);
}

.access-requests__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.access-requests__item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16px;
	padding: 12px 0;
	border-bottom: 1px solid var(--color-border);
}

.access-requests__who {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.access-requests__reason {
	color: var(--color-text-maxcontrast);
}

.access-requests__actions {
	display: flex;
	gap: 8px;
}
</style>
