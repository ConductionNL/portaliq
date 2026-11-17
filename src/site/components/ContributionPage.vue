<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<article class="utrecht-article" data-testid="contribution-page">
		<div class="container">
			<h1 class="utrecht-heading-1">
				{{ page.label || page.id }}
			</h1>

			<template v-for="(block, i) in blocks" :key="i">
				<!-- Prose the contributing app authored, sanitised by the same
				     block the CMS uses: it is the same class of input. -->
				<MarkdownBlock
					v-if="block.type === 'richText'"
					:source="block.markdown || ''" />

				<!--
					AN ACTION IS A FORM, AND A FORM NEEDS SOMEWHERE TO POST.

					The form is offered exactly when the server will take it: a
					session is enough for any declared action, and without one
					only a `type: create` action marked `anonymous` qualifies,
					because only the collection route admits a caller with no
					bearer (contributionApi.js).
				-->
				<section
					v-else-if="block.type === 'action'"
					class="pq-action"
					:data-action="block.action">
					<template v-if="actionFor(block.action)">
						<h2 class="utrecht-heading-2">
							{{ actionFor(block.action).label || block.action }}
						</h2>

						<p
							v-if="!submittable(block.action)"
							class="utrecht-paragraph"
							data-testid="action-signin-required">
							{{ t('You need to sign in to send this form.') }}
						</p>

						<form
							v-else
							:data-testid="`action-form-${block.action}`"
							@submit.prevent="submit(block.action)">
							<div
								v-for="field in fieldsOf(block.action)"
								:key="field"
								class="pq-action__field">
								<label :for="fieldId(block.action, field)">{{ field }}</label>
								<input
									:id="fieldId(block.action, field)"
									v-model="values[block.action + ':' + field]"
									type="text"
									:name="field">
							</div>

							<button type="submit" :disabled="busy === block.action">
								{{ busy === block.action ? t('Sending…') : t('Send') }}
							</button>

							<!-- Said before submitting, not after: an unowned
							     submission cannot be looked up again. -->
							<p
								v-if="!session"
								class="utrecht-paragraph pq-action__note"
								data-testid="action-anonymous-note">
								{{ t('You send this without an account. It is not linked to an account and cannot be found again later.') }}
							</p>
						</form>

						<!-- The outcome is stated in words: a form that merely
						     empties itself looks the same as one that failed. -->
						<p
							v-if="result[block.action]"
							class="utrecht-paragraph"
							:role="result[block.action].ok ? 'status' : 'alert'"
							:data-testid="`action-result-${block.action}`">
							{{ result[block.action].message }}
						</p>
					</template>

					<p v-else class="utrecht-paragraph" data-testid="action-unknown">
						{{ t('This action is not available.') }}
					</p>
				</section>
			</template>
		</div>
	</article>
</template>

<script>
import MarkdownBlock from './MarkdownBlock.vue'
import { adoptSessionToken } from '../lib/authApi.js'
import { isAnonymouslySubmittable, submitAction } from '../lib/contributionApi.js'

/**
 * One page a contributing app publishes on this portal (ADR-046), at
 * `/diensten/{app}/{page}`.
 *
 * A contributed page is a list of blocks the app declares: `richText` for
 * prose and `action` for something a visitor can do. Both arrive on the public
 * contributions contract, which is anonymous and publicly cacheable, so this
 * renders what is possible, never a visitor's own rows.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-page-must-be-reachable-at-its-own-route-req-ptb-011
 */
export default {
	name: 'ContributionPage',

	components: { MarkdownBlock },

	props: {
		/** The contributed page record. */
		page: {
			type: Object,
			required: true,
		},

		/** The contribution it belongs to, for its action list. */
		contribution: {
			type: Object,
			required: true,
		},

		/** The visitor's session, or null. */
		session: {
			type: Object,
			default: null,
		},

		/** The portal API base, for posting an action. */
		apiBase: {
			type: String,
			default: '',
		},

		/** The translator. */
		t: {
			type: Function,
			default: (key) => key,
		},
	},

	data() {
		return {
			/** Field values, keyed `actionId:field` so two forms cannot collide. */
			values: {},
			/** The action currently in flight, or ''. */
			busy: '',
			/** Per-action outcome: `{ok, message}`. */
			result: {},
		}
	},

	computed: {
		/**
		 * @return {Array} The page's blocks, always an array.
		 */
		blocks() {
			return Array.isArray(this.page.blocks) ? this.page.blocks : []
		},
	},

	methods: {
		/**
		 * The declared action for an id, or null. A block may name an action
		 * the contribution does not declare; that renders as unavailable.
		 *
		 * @param {string} id The action id.
		 * @return {object|null} The action.
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
		 */
		actionFor(id) {
			const actions = Array.isArray(this.contribution.actions)
				? this.contribution.actions
				: []
			return actions.find((a) => a && a.id === id) || null
		},

		/**
		 * The fields an action declares, always an array.
		 *
		 * @param {string} id The action id.
		 * @return {Array<string>} The field names.
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
		 */
		fieldsOf(id) {
			const action = this.actionFor(id)
			return action && Array.isArray(action.fields) ? action.fields : []
		},

		/**
		 * Whether this visitor can submit this action now: a session for any
		 * declared action, else only an anonymously submittable one.
		 *
		 * @param {string} id The action id.
		 * @return {boolean} Whether to render the form.
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
		 */
		submittable(id) {
			const action = this.actionFor(id)
			if (action === null) {
				return false
			}

			return Boolean(this.session) || isAnonymouslySubmittable(action)
		},

		/**
		 * A DOM id for a field, unique per action.
		 *
		 * @param {string} action The action id.
		 * @param {string} field  The field name.
		 * @return {string} The id.
		 */
		fieldId(action, field) {
			return `pq-${action}-${field}`.replace(/[^a-zA-Z0-9-]/g, '-')
		},

		/**
		 * Submit one action's fields. Only the fields the action declares are
		 * sent, the same whitelist the server applies.
		 *
		 * @param {string} id The action id.
		 * @return {Promise<void>} Resolves once the attempt is reported.
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-a-contributed-action-must-post-where-its-type-is-honoured-req-ptb-012
		 */
		async submit(id) {
			const action = this.actionFor(id)
			if (!action || this.busy || this.submittable(id) === false) {
				return
			}

			const payload = {}
			for (const field of this.fieldsOf(id)) {
				payload[field] = this.values[`${id}:${field}`] || ''
			}

			this.busy = id
			try {
				await submitAction({
					apiBase: this.apiBase,
					appId: this.contribution.app,
					action,
					payload,
					// The anonymous path sends no bearer at all (REQ-PTB-012).
					token: this.session ? adoptSessionToken() : '',
				})
				this.result = {
					...this.result,
					// An unowned submission gets no receipt: there is no
					// subject to send one to.
					[id]: {
						ok: true,
						message: this.session
							? this.t('Sent. You will receive a confirmation.')
							: this.t('Sent anonymously. It is not linked to an account.'),
					},
				}
				for (const field of this.fieldsOf(id)) {
					this.values[`${id}:${field}`] = ''
				}
			} catch (error) {
				this.result = {
					...this.result,
					[id]: {
						ok: false,
						message: error.status
							? this.t('Sending did not work ({status}). Try again later.', { status: error.status })
							: this.t('Sending did not work. Try again later.'),
					},
				}
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.pq-action {
	margin-block-start: var(--utrecht-space-block-lg, 1.5rem);
}

.pq-action__field {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.25rem);
	margin-block-end: var(--utrecht-space-block-sm, 0.5rem);
}
</style>
