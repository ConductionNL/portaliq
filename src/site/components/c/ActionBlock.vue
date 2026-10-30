<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		v-if="resolved"
		class="pq-action-block"
		:data-testid="`action-block-${resolved.id}`">
		<template v-if="isForm">
			<h2 v-if="headed" class="utrecht-heading-2">
				{{ resolved.label || resolved.id }}
			</h2>
			<SchemaForm
				:action="resolved"
				:api="api"
				:t="t"
				cancellable
				:portalHelp="portalHelp"
				@cancel="leave"
				@submitted="(object) => $emit('created', object, resolved)" />
		</template>
		<!-- A call to action that creates something about the open record
		     (a bezwaar, a klacht on a case page): a button that opens the
		     action's form, with the record already in its `recordField`
		     (case-actions-on-the-case-page). -->
		<template v-else-if="isRecordForm">
			<div class="pq-action-button">
				<button
					ref="opener"
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:aria-expanded="String(open)"
					:aria-controls="`pq-action-form-${resolved.id}`"
					:data-testid="`action-open-${resolved.id}`"
					@click="open = !open">
					{{ block.label || resolved.label || resolved.id }}
				</button>
			</div>
			<div
				v-if="open"
				:id="`pq-action-form-${resolved.id}`"
				class="pq-action-block__form">
				<h2 class="utrecht-heading-3">
					{{ block.label || resolved.label || resolved.id }}
				</h2>
				<SchemaForm
					:action="resolved"
					:api="api"
					:t="t"
					:preset="{ [resolved.recordField]: block.record }"
					cancellable
					@cancel="close"
					@submitted="(object) => $emit('created', object, resolved)" />
			</div>
		</template>
		<!-- An endpoint action that needs input, on an `action` block: its
		     form, sent to the app's endpoint (site-action-forms). -->
		<template v-else-if="isEndpointForm">
			<h2 v-if="headed" class="utrecht-heading-2">
				{{ resolved.label || resolved.id }}
			</h2>
			<SchemaForm
				:action="resolved"
				:api="api"
				:t="t"
				:send="sendForward"
				cancellable
				:portalHelp="portalHelp"
				@cancel="leave"
				@submitted="(object) => $emit('created', object, resolved)" />
		</template>
		<!-- A call to action (a cta, a greeting's button) whose action needs
		     input: the button opens the form instead of sending nothing
		     (site-action-forms). -->
		<template v-else-if="opensForm">
			<div class="pq-action-button">
				<button
					ref="opener"
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:aria-expanded="String(open)"
					:aria-controls="`pq-action-form-${resolved.id}`"
					:data-testid="`action-open-${resolved.id}`"
					@click="open = !open">
					{{ block.label || resolved.label || resolved.id }}
				</button>
			</div>
			<div
				v-if="open"
				:id="`pq-action-form-${resolved.id}`"
				class="pq-action-block__form">
				<h2 class="utrecht-heading-3">
					{{ resolved.label || block.label || resolved.id }}
				</h2>
				<SchemaForm
					:action="resolved"
					:api="api"
					:t="t"
					:send="endpoint ? sendForward : null"
					cancellable
					@cancel="close"
					@submitted="(object) => $emit('created', object, resolved)" />
			</div>
		</template>
		<ActionButton
			v-else
			:action="resolved"
			:app="(contribution && contribution.app) || ''"
			:label="block.type === 'cta' ? block.label || '' : ''"
			:requireEndpoint="block.type === 'action'"
			:api="api"
			:t="t"
			:navigate="navigate" />
	</div>
</template>

<script>
import ActionButton from './ActionButton.vue'
import SchemaForm from './SchemaForm.vue'
import { redirectTarget } from '../../../shared/rowAction.js'
import { forwardApi } from '../../lib/residentActions.js'
import { residentAuthBase, residentToken } from '../../lib/residentSession.js'
import { siteHref } from '../mijn/rows.js'
import { asksInput, forwardResult, isEndpointAction } from './forms.js'

/**
 * Sets `window.location` to a checked https URL.
 *
 * @param {string} url The URL.
 */
function goTo(url) {
	window.location.assign(url)
}

/**
 * One `action` or `cta` block of a contributed page (PageView.jsx): a
 * `create` or `update` action renders its schema form, and so does an
 * endpoint action with fields to fill in, which sends its answers to the
 * app's endpoint. A cta whose action needs input opens that form; every other
 * action is a button that forwards it. The block's action is looked up in the
 * contribution, so a block can only run an action that contribution declares.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-action-must-show-its-answer-or-follow-its-redirect-req-srp-027
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
 */
export default {
	name: 'ActionBlock',

	components: { ActionButton, SchemaForm },

	inheritAttrs: false,

	props: {
		/** The page block: `{type: 'action'|'cta', action, label?, pageTitle?}`. */
		block: { type: Object, required: true },
		/** The contribution the page belongs to (`app`, `actions`). */
		contribution: { type: Object, default: () => ({}) },
		/** The portal api. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** The block's action when the page already resolved it. */
		action: { type: Object, default: null },
		/** Where a checked redirect goes; the browser by default. */
		navigate: { type: Function, default: goTo },
		/** The portal's help details, offered on a form with a page of its own. */
		portalHelp: { type: Object, default: null },
	},

	emits: ['created'],

	data() {
		return { open: false }
	},

	computed: {
		/**
		 * Whether the form prints the action's label as its heading: not when
		 * the page it stands on already carries that title as its h1. A start
		 * point's page is titled after its action, so the label printed twice,
		 * one under the other (zuiddrecht live test, 14 October 2026).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/site-forms/spec.md#requirement-a-form-on-a-page-of-its-own-does-not-repeat-the-page-title-req-eaf-004
		 */
		headed() {
			const title = String(this.block.pageTitle || '').trim()
			const label = String(
				(this.resolved && (this.resolved.label || this.resolved.id)) || '',
			).trim()
			return title === '' || title !== label
		},

		resolved() {
			return (
				this.action
				|| ((this.contribution && this.contribution.actions) || []).find(
					(a) => a && a.id === this.block.action,
				)
				|| null
			)
		},

		/**
		 * Whether the action goes to its app's own endpoint.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
		 */
		endpoint() {
			return isEndpointAction(this.resolved)
		},

		/**
		 * An `action` block on an endpoint action that needs input: its form.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
		 */
		isEndpointForm() {
			return (
				this.block.type === 'action'
				&& this.endpoint
				&& asksInput(this.resolved)
			)
		},

		/**
		 * A cta on a create or endpoint action that needs input: a button
		 * that opens its form. A create about a record (`recordField`) opens
		 * only with that record, above (isRecordForm).
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
		 */
		opensForm() {
			return (
				this.block.type === 'cta'
				&& (this.endpoint
					|| (this.resolved.type === 'create'
						&& !this.resolved.recordField))
				&& asksInput(this.resolved)
			)
		},

		isForm() {
			return (
				this.block.type === 'action'
				&& (this.resolved.type === 'create'
					|| this.resolved.type === 'update')
			)
		},

		/**
		 * Whether this is a call to action that creates something about the
		 * open record: a `cta` with `withRecord`, on a record page (the page
		 * sets `record`), for a create action that names its `recordField`.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		isRecordForm() {
			return (
				this.block.type === 'cta'
				&& this.block.withRecord === true
				&& typeof this.block.record === 'string'
				&& this.block.record !== ''
				&& this.resolved.type === 'create'
				&& typeof this.resolved.recordField === 'string'
				&& (this.resolved.fields || []).includes(this.resolved.recordField)
			)
		},
	},

	methods: {
		/**
		 * Leave a form that has a page of its own: back to where the
		 * resident came from, or to the site's home when the form was the
		 * first page they opened.
		 *
		 * @spec exclude navigation of the form's cancel button, no requirement yet
		 */
		leave() {
			if (typeof window === 'undefined') {
				return
			}
			if (window.history.length > 1) {
				window.history.back()
				return
			}
			window.location.assign(siteHref('/'))
		},

		/**
		 * Close a form a button opened, and give the focus back to that
		 * button.
		 *
		 * @spec exclude navigation of the form's cancel button, no requirement yet
		 */
		close() {
			this.open = false
			this.$nextTick(() => this.$refs.opener?.focus?.())
		},

		/**
		 * Send the form's answers to the action's endpoint through the portal,
		 * and follow a checked redirect the app answers with.
		 *
		 * @param {object} body The typed answers.
		 * @param {{submissionKey?: string}} [options] The form's submission key (REQ-AFS-001).
		 * @return {Promise<object>} The result in the form's send shape.
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-action-that-needs-input-must-open-its-form-before-it-sends
		 * @spec openspec/changes/action-forward-survives-a-slow-answer/specs/portal-contribution-contract/spec.md#requirement-a-repeated-submission-carries-the-same-key-req-afs-001
		 */
		async sendForward(body, options = {}) {
			const api =
				this.api && typeof this.api.forwardAction === 'function'
					? this.api
					: forwardApi(residentAuthBase(), residentToken)
			const app =
				this.resolved.app
				|| (this.contribution && this.contribution.app)
				|| ''
			const result = await api.forwardAction(
				app,
				this.resolved.id,
				body,
				options,
			)
			const redirect = redirectTarget(result)
			if (redirect) {
				this.navigate(redirect)
			}
			return forwardResult(result)
		},
	},
}
</script>
