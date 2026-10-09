<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
  The accessibility statement of this portal, in the sections of the national
  model (site-accessibility-statement REQ-SAS-002 and REQ-SAS-003). Every
  line comes from the server's statement, which follows the latest
  measurement and never claims a status beyond the recorded audit.

  @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
-->
<template>
	<section
		class="container pq-accessibility-statement"
		:aria-busy="loaded ? undefined : 'true'"
		data-testid="accessibility-statement">
		<h1 class="utrecht-heading-1">
			{{ t('Accessibility statement') }}
		</h1>
		<p v-if="!loaded" class="utrecht-paragraph" role="status">
			{{ t('Loading…') }}
		</p>
		<p v-else-if="failed" class="utrecht-paragraph" role="alert">
			{{
				t(
					'We cannot show the accessibility statement right now. Try again later.',
				)
			}}
		</p>
		<template v-else>
			<p class="utrecht-paragraph">
				{{
					t(
						'{organisation} wants everybody to be able to use {website}. This statement says how far that succeeds and what is still wrong.',
						{ organisation: statement.organisation || website, website },
					)
				}}
			</p>

			<h2 class="utrecht-heading-2">
				{{ t('Status') }}
			</h2>
			<p
				class="utrecht-paragraph"
				data-testid="accessibility-statement-status">
				{{ lines.status }}
			</p>
			<p v-if="lines.automated" class="utrecht-paragraph">
				{{ lines.automated }}
			</p>
			<p v-if="lines.auditExpired" class="utrecht-paragraph">
				{{ lines.auditExpired }}
			</p>

			<h2 class="utrecht-heading-2">
				{{ t('Evidence') }}
			</h2>
			<p
				v-if="lines.evidence"
				class="utrecht-paragraph"
				data-testid="accessibility-statement-evidence">
				{{ lines.evidence }}
			</p>
			<p v-if="lines.audit" class="utrecht-paragraph">
				{{ lines.audit }}
				<a
					v-if="lines.auditReport"
					class="utrecht-link"
					:href="lines.auditReport"
					>{{ t('Read the audit report') }}</a
				>
			</p>
			<p v-if="!lines.evidence && !lines.audit" class="utrecht-paragraph">
				{{ t('There is no measurement or audit yet.') }}
			</p>
			<p v-if="lines.registerUrl" class="utrecht-paragraph">
				<a class="utrecht-link" :href="lines.registerUrl">{{
					t('This statement in the national register')
				}}</a>
			</p>

			<h2 class="utrecht-heading-2">
				{{ t('Known issues') }}
			</h2>
			<p
				v-if="lines.issues.length === 0"
				class="utrecht-paragraph"
				data-testid="accessibility-statement-no-issues">
				{{ t('The last measurement found no issues.') }}
			</p>
			<ul
				v-else
				class="utrecht-unordered-list"
				data-testid="accessibility-statement-issues">
				<li
					v-for="issue in lines.issues"
					:key="issue.rule"
					class="utrecht-unordered-list__item">
					{{ issue.sentence }}
					({{ issue.detail }})
					<a
						v-if="issue.helpUrl"
						class="utrecht-link"
						:href="issue.helpUrl"
						>{{ t('More about this rule') }}</a
					>
				</li>
			</ul>
			<template v-if="lines.notMeasured.length">
				<p class="utrecht-paragraph">
					{{
						t(
							'These pages could not be measured, so nothing is claimed about them:',
						)
					}}
				</p>
				<ul
					class="utrecht-unordered-list"
					data-testid="accessibility-statement-not-measured">
					<li
						v-for="page in lines.notMeasured"
						:key="page.url"
						class="utrecht-unordered-list__item">
						{{ page.url }}: {{ page.reason }}
					</li>
				</ul>
			</template>

			<h2 class="utrecht-heading-2">
				{{ t('Report a barrier') }}
			</h2>
			<p class="utrecht-paragraph">
				{{
					t(
						'Did you run into something you could not use? Tell us, and we will help you another way.',
					)
				}}
			</p>
			<p v-if="lines.contact.email" class="utrecht-paragraph">
				<a class="utrecht-link" :href="`mailto:${lines.contact.email}`">{{
					lines.contact.email
				}}</a>
			</p>
			<p v-if="lines.contact.phone" class="utrecht-paragraph">
				{{ t('Phone: {phone}', { phone: lines.contact.phone }) }}
			</p>
		</template>
	</section>
</template>

<script>
import { fetchAccessibilityStatement } from '../lib/contentApi.js'
import { statementLines } from '../lib/statementLines.js'

export default {
	name: 'AccessibilityStatementPage',

	props: {
		/** The portal slug the site serves. */
		portalSlug: { type: String, default: '' },
		/** The portal's title. */
		portalName: { type: String, default: '' },
		/** The language the visitor chose. */
		locale: { type: String, default: 'nl' },
		/** The site's translator. */
		t: { type: Function, required: true },
	},

	data() {
		return { loaded: false, failed: false, statement: {} }
	},

	computed: {
		/**
		 * @return {string} The website's name.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		website() {
			return this.statement.website || this.portalName
		},

		/**
		 * @return {object} The page's lines.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		lines() {
			return statementLines(this.statement, this.t, this.formatDate)
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		locale() {
			this.load()
		},
	},

	/**
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	mounted() {
		this.load()
	},

	methods: {
		/**
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		async load() {
			try {
				const body = await fetchAccessibilityStatement(
					this.portalSlug,
					this.locale,
				)
				this.statement = body.statement || {}
				this.failed = false
			} catch {
				this.failed = true
			}
			this.loaded = true
		},

		/**
		 * @param {string} iso A date or date-time.
		 * @return {string} The date in the visitor's language.
		 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
		 */
		formatDate(iso) {
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return String(iso || '')
			}
			return date.toLocaleDateString(
				this.locale === 'en' ? 'en-GB' : 'nl-NL',
				{
					day: 'numeric',
					month: 'long',
					year: 'numeric',
					timeZone: 'Europe/Amsterdam',
				},
			)
		},
	},
}
</script>
