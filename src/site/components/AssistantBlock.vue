<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Ask a question" (search-assistant-from-public-content). A visitor types a
	question and gets a short answer from the organisation's published content,
	with the pages it came from. The widget says it is an AI before anything is
	typed, says when it found nothing, and never carries the visitor's session.
-->
<template>
	<section class="pq-assistant" data-testid="assistant">
		<h2 v-if="title" class="utrecht-heading-2">
			{{ title }}
		</h2>
		<p class="utrecht-paragraph" data-testid="assistant-disclosure">
			{{ words.disclosure }}
		</p>
		<form class="pq-assistant__form" @submit.prevent="ask">
			<label class="utrecht-form-label" for="pq-assistant-question">{{
				words.label
			}}</label>
			<input
				id="pq-assistant-question"
				v-model="question"
				class="utrecht-textbox utrecht-textbox--html-input"
				type="text"
				maxlength="500"
				autocomplete="off"
				aria-describedby="pq-assistant-note" />
			<p
				id="pq-assistant-note"
				class="utrecht-paragraph"
				data-testid="assistant-note">
				{{ words.note }}
			</p>
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy">
				{{ words.submit }}
			</button>
		</form>
		<div aria-live="polite" data-testid="assistant-result">
			<p v-if="busy" class="utrecht-paragraph">
				{{ words.busy }}
			</p>
			<p
				v-if="removed"
				class="utrecht-paragraph"
				data-testid="assistant-removed">
				{{ words.removed }}
			</p>
			<p
				v-if="failed"
				class="utrecht-alert utrecht-alert--error"
				role="alert"
				data-testid="assistant-failed">
				{{ words.failed }}
			</p>
			<div
				v-if="reply && reply.status === 'answered'"
				data-testid="assistant-answer">
				<p class="utrecht-paragraph">
					{{ reply.answer }}
				</p>
				<h3 class="utrecht-heading-3">
					{{ words.sources }}
				</h3>
				<ul>
					<li v-for="source in reply.sources" :key="source.url">
						<a :href="source.url">{{ source.title }}</a>
					</li>
				</ul>
			</div>
			<p
				v-else-if="reply && reply.status === 'abstained'"
				class="utrecht-paragraph"
				data-testid="assistant-abstained">
				{{ words.abstained }}
				<a :href="contactRoute" data-testid="assistant-contact">{{
					words.contact
				}}</a>
			</p>
		</div>
	</section>
</template>

<script>
import { askAssistant } from '../lib/assistantApi.js'
import { resolveApiBase } from '../lib/contentApi.js'
import { pageLocale } from '../pages/inbox/translate.js'

import '@utrecht/paragraph-css/dist/index.css'

const STRINGS = {
	nl: {
		disclosure:
			'Antwoorden komen van een AI-assistent en kunnen onjuist zijn. Controleer de pagina waarnaar hij verwijst.',
		label: 'Stel een vraag',
		note: 'Typ geen persoonsgegevens, zoals uw burgerservicenummer.',
		submit: 'Vraag het',
		busy: 'Even zoeken…',
		removed: 'Wij hebben persoonsgegevens uit uw vraag gehaald.',
		failed: 'De assistent is nu niet te bereiken. Probeer het later opnieuw.',
		sources: 'Bronnen',
		abstained: 'Ik kon dit niet vinden in onze informatie.',
		contact: 'Neem contact met ons op.',
	},
	en: {
		disclosure:
			'Answers come from an AI assistant and can be wrong. Check the page it links to.',
		label: 'Ask a question',
		note: 'Do not type personal details such as your citizen service number.',
		submit: 'Ask',
		busy: 'Looking…',
		removed: 'We removed personal details from your question.',
		failed: 'The assistant is not available right now. Try again later.',
		sources: 'Sources',
		abstained: 'I could not find this in our information.',
		contact: 'You can contact us.',
	},
}

/**
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
 */
export default {
	name: 'AssistantBlock',
	props: {
		/** A heading above the widget; none by default. */
		title: { type: String, default: '' },
		/** The route of the portal's contact page, linked from an abstention. */
		contactRoute: { type: String, default: '/contact' },
		/** The serving portal's slug, supplied by the host. */
		portal: { type: String, default: '' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
		/** The function that asks (test seam); the public route otherwise. */
		askOverride: { type: Function, default: null },
	},

	data() {
		return {
			question: '',
			busy: false,
			failed: false,
			removed: false,
			reply: null,
			conversationId: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
		 */
		words() {
			return STRINGS[pageLocale(this.locale)] || STRINGS.nl
		},
	},

	methods: {
		/**
		 * Send the question and show what came back. Counts one use without
		 * its text.
		 *
		 * @return {Promise<void>} Resolves when answered or failed.
		 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
		 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t06
		 */
		async ask() {
			const question = String(this.question || '').trim()
			if (question === '' || this.busy) {
				return
			}

			this.busy = true
			this.failed = false
			this.reply = null
			try {
				const send =
					this.askOverride
					|| ((body) => askAssistant(resolveApiBase(), body))
				const reply = await send({
					portal: this.portal,
					question,
					locale: pageLocale(this.locale),
					conversationId: this.conversationId,
				})
				this.reply = reply
				this.removed = reply.removed === true
				this.conversationId = reply.conversationId || this.conversationId
				const client =
					typeof window !== 'undefined' ? window.portaliqTraffic : null
				if (client && typeof client.track === 'function') {
					// A count only: the question and the answer never go with it.
					client.track('assistant_asked', {})
				}
			} catch {
				this.failed = true
			} finally {
				this.busy = false
			}
		},
	},
}
</script>
