<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<article class="utrecht-article" data-testid="contribution-page">
		<div class="container">
			<h1 class="utrecht-heading-1">{{ page.label || page.id }}</h1>

			<template v-for="(block, i) in blocks" :key="i">
				<!-- Prose the contributing app authored, sanitised by the same
				     block the CMS uses: it is the same class of input. -->
				<MarkdownBlock
					v-if="block.type === 'richText'"
					:source="block.markdown || ''" />

				<!--
					AN ACTION IS A FORM, AND A FORM NEEDS SOMEWHERE TO POST.

					A manifest's `anonymous: true` IS honoured by the server —
					on a `type: create` action, through the collection route,
					which admits a caller with no bearer and stamps no
					ownership. This renderer used to post every action to the
					endpoint-forward route instead, which refuses a create and
					requires a session, so an anonymous submission La Franken
					offers ("geen account nodig") was shown as sign-in-required
					while the server would have accepted it. The form is now
					offered exactly when the server will take it, and the
					sign-in notice appears only for the actions that genuinely
					need one.
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
							U moet ingelogd zijn om dit formulier te versturen.
						</p>

						<form
							v-else
							:data-testid="`action-form-${block.action}`"
							@submit.prevent="submit(block.action)">
							<div
								v-for="field in actionFor(block.action).fields || []"
								:key="field"
								class="pq-action__field">
								<label :for="fieldId(block.action, field)">{{
									field
								}}</label>
								<input
									:id="fieldId(block.action, field)"
									v-model="values[block.action + ':' + field]"
									type="text"
									:name="field" />
							</div>

							<button type="submit" :disabled="busy === block.action">
								{{ busy === block.action ? 'Bezig…' : 'Versturen' }}
							</button>

							<!--
								Said before submitting, not after. An unowned
								submission cannot be looked up again, and a
								visitor who would rather sign in first has to
								learn that while they still can.
							-->
							<p
								v-if="!session"
								class="utrecht-paragraph pq-action__note"
								data-testid="action-anonymous-note">
								U verstuurt dit zonder account. De melding wordt
								niet aan een account gekoppeld en is later niet
								terug te vinden.
							</p>
						</form>

						<!--
							The outcome is stated in words. A submitted form that
							merely empties itself is indistinguishable from one
							that silently failed.
						-->
						<p
							v-if="result[block.action]"
							class="utrecht-paragraph"
							:role="result[block.action].ok ? 'status' : 'alert'"
							:data-testid="`action-result-${block.action}`">
							{{ result[block.action].message }}
						</p>
					</template>

					<p v-else class="utrecht-paragraph" data-testid="action-unknown">
						Deze actie is niet beschikbaar.
					</p>
				</section>
			</template>
		</div>
	</article>
</template>

<script>
import MarkdownBlock from './MarkdownBlock.vue'
import { isAnonymouslySubmittable, submitAction } from '../lib/contributionApi.js'

/**
 * One page a contributing app publishes on this portal (ADR-046).
 *
 * WHY THIS EXISTS. The contributions index has always NAMED these pages while
 * nothing could open one, so its entries were deliberately inert text — a link
 * that goes nowhere is worse than plain words. This is the other half of that
 * bridge: the pages are routable, so the entries become links.
 *
 * A contributed page is a list of blocks the app declares: `richText` for prose
 * and `action` for something a visitor can do. Both arrive on the public
 * contributions contract, which is anonymous and publicly cacheable — so this
 * renders an INDEX of what is possible, never a visitor's own rows.
 *
 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
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

		/** The auth edge base, for posting an action. */
		apiBase: {
			type: String,
			default: '',
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
		 * The declared action for an id, or null.
		 *
		 * A block may name an action the contribution does not declare — the
		 * two lists are authored separately — and that has to render as
		 * "unavailable" rather than as a form with no destination.
		 *
		 * @param {string} id The action id.
		 * @return {object|null} The action.
		 */
		actionFor(id) {
			const actions = Array.isArray(this.contribution.actions)
				? this.contribution.actions
				: []
			return actions.find((a) => a && a.id === id) || null
		},

		/**
		 * Whether THIS visitor can submit this action right now.
		 *
		 * A session is sufficient for anything declared; without one, only an
		 * anonymously-submittable action qualifies. Asked in one place so the
		 * form's presence and the submit path cannot disagree — offering a
		 * form the post will refuse is the defect this replaces, inverted.
		 *
		 * @param {string} id The action id.
		 * @return {boolean} Whether to render the form.
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
		 * Submit one action's fields.
		 *
		 * Only the fields the action DECLARES are sent: the form cannot grow a
		 * key the manifest did not name. That is the same whitelist the server
		 * applies, and the reason it is applied here too rather than posting
		 * the whole model.
		 *
		 * @param {string} id The action id.
		 * @return {Promise<void>} Resolves once the attempt is reported.
		 */
		async submit(id) {
			const action = this.actionFor(id)
			if (!action || this.busy || this.submittable(id) === false) {
				return
			}

			const payload = {}
			for (const field of action.fields || []) {
				payload[field] = this.values[`${id}:${field}`] || ''
			}

			this.busy = id
			try {
				await submitAction({
					apiBase: this.apiBase,
					appId: this.contribution.app,
					action,
					payload,
					token: this.session ? this.session.token : '',
				})
				this.result = {
					...this.result,
					[id]: {
						ok: true,
						// An unowned submission produces no receipt — the
						// server issues one against a subject, and there is
						// none. Promising a confirmation that will not arrive
						// is worse than saying less.
						message: this.session
							? 'Verstuurd. U ontvangt een bevestiging.'
							: 'Verstuurd. Deze melding is anoniem verstuurd en niet aan een account gekoppeld.',
					},
				}
				for (const field of action.fields || []) {
					this.values[`${id}:${field}`] = ''
				}
			} catch (error) {
				this.result = {
					...this.result,
					[id]: {
						ok: false,
						message: `Versturen is niet gelukt (${error.status || 'fout'}). Probeer het later opnieuw.`,
					},
				}
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>
