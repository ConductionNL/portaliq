<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A resident asks a question that is not about a case (contact-page-question-
	form-and-not-found REQ-SCN-001). The block is bound to a contribution
	create action and writes through the contribution create path, so the
	resident's identity and the action's whitelist apply. The Onderwerp choice
	comes from the action's own enum field.

	Signed out, the block posts nothing: it says to sign in and offers the
	portal's sign-in routes.
-->
<template>
	<div class="pq-contact-form" data-testid="contact-form">
		<p v-if="intro" class="utrecht-paragraph">
			{{ intro }}
		</p>

		<div v-if="!signedIn" data-testid="contact-form-signed-out">
			<p class="utrecht-paragraph">
				{{ words.signIn }}
			</p>
			<ul v-if="ways.length > 0">
				<li v-for="way in ways" :key="way.id">
					<a :href="way.href">{{ way.label }}</a>
				</li>
			</ul>
		</div>

		<p
			v-else-if="state === 'loading'"
			class="utrecht-paragraph"
			role="status"
			data-testid="contact-form-loading">
			{{ words.loading }}
		</p>

		<p
			v-else-if="state === 'missing'"
			class="utrecht-paragraph"
			role="alert"
			data-testid="contact-form-missing">
			{{ words.missing }}
		</p>

		<div
			v-else-if="state === 'sent'"
			role="status"
			data-testid="contact-form-sent">
			<p class="utrecht-paragraph">
				{{ words.sent }}
			</p>
			<a :href="questionsHref" data-testid="contact-form-questions">{{
				words.questions
			}}</a>
		</div>

		<SchemaForm
			v-else-if="state === 'ready'"
			:action="resolved"
			:api="api"
			@submitted="state = 'sent'" />
	</div>
</template>

<script>
import SchemaForm from './c/SchemaForm.vue'
import { createPortalApi } from '../../shared/portalApi.js'
import {
	adoptSessionToken,
	authBaseFrom,
	storeSessionToken,
} from '../lib/authApi.js'
import { resolveApiBase, runtimeConfig } from '../lib/contentApi.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/paragraph-css/dist/index.css'

const STRINGS = {
	nl: {
		signIn: 'Log in om een vraag te stellen.',
		loading: 'Laden…',
		missing: 'U kunt nu geen vraag stellen. Probeer het later opnieuw.',
		sent: 'Wij hebben uw vraag ontvangen. U vindt hem terug bij Mijn vragen.',
		questions: 'Naar Mijn vragen',
	},
	en: {
		signIn: 'Log in to ask a question.',
		loading: 'Loading…',
		missing: 'You cannot ask a question right now. Try again later.',
		sent: 'We have received your question. You find it back under My questions.',
		questions: 'Go to My questions',
	},
}

/**
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t01
 */
export default {
	name: 'ContactForm',

	components: { SchemaForm },

	props: {
		/** The contributing app whose create action takes the question. */
		app: { type: String, default: '' },
		/** The id of the contribution's create action for questions. */
		action: { type: String, default: '' },
		/** The action's enum field shown as Onderwerp. */
		topicField: { type: String, default: '' },
		/** A line above the form. */
		intro: { type: String, default: '' },
		/** The route of the page that lists the resident's questions. */
		questionsRoute: { type: String, default: '/mijn' },
		/** The serving portal's slug, supplied by the host. */
		portal: { type: String, default: '' },
		/** Whether the visitor holds a portal session, supplied by the host. */
		signedIn: { type: Boolean, default: false },
		/** The portal's sign-in routes, supplied by the host. */
		ways: { type: Array, default: () => [] },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
		/** The portal api (test seam); built from the page's session otherwise. */
		apiOverride: { type: Object, default: null },
	},

	data() {
		return { state: 'idle', resolved: null }
	},

	computed: {
		/**
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t01
		 */
		words() {
			return STRINGS[pageLocale(this.locale)] || STRINGS.nl
		},

		/**
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t01
		 */
		api() {
			if (this.apiOverride) {
				return this.apiOverride
			}
			return createPortalApi(
				{
					apiBase: authBaseFrom(resolveApiBase()),
					organisationSlug: this.portal,
					audience: runtimeConfig().signin?.audience || '',
					language: pageLocale(this.locale),
				},
				{
					getToken: () => adoptSessionToken() || null,
					setToken: storeSessionToken,
				},
			)
		},

		/**
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t01
		 */
		questionsHref() {
			return this.questionsRoute
		},
	},

	/**
	 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t01
	 */
	mounted() {
		if (this.signedIn) {
			this.load()
		}
	},

	methods: {
		/**
		 * Find the question action among the resident's contributions. The
		 * block never posts to an action it could not find there, so the
		 * action's whitelist and audience are the server's.
		 *
		 * @return {Promise<void>} Resolves when found or missing.
		 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t01
		 */
		async load() {
			this.state = 'loading'
			try {
				const answer = await this.api.getContributions()
				const contribution = (answer?.contributions || []).find(
					(entry) => !this.app || entry?.app === this.app,
				)
				const found = (contribution?.actions || []).find(
					(entry) => entry?.id === this.action && entry?.type === 'create',
				)
				this.resolved = found || null
			} catch {
				this.resolved = null
			}
			this.state = this.resolved ? 'ready' : 'missing'
		},
	},
}
</script>
