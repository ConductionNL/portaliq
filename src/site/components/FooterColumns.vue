<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		The footer as a block (`footerColumns`). The markup is the footer
		`App.vue` hard-coded before this change, plus two things.

		Every band names its role: `pq-footer__band--content` and
		`pq-footer__band--legal`. `nlds-app.css` styles the bands by POSITION
		(`section:first-of-type`, `section:last-of-type:not(:only-of-type)`),
		so a third band used to restyle the other two. `css/site-theme.css`
		restates those rules against the role classes (REQ-PTB-005).

		The legal band always names someone: the portal's colophon, or its
		title when it has none.
	-->
	<footer class="ac-footer pq-site__footer" data-testid="site-footer">
		<h2 class="sr-only">{{ landmarkLabel }}</h2>

		<section
			class="pq-footer__band pq-footer__band--content"
			:class="{ 'pq-footer__band--designed': designed }">
			<div class="container ac-footer__container">
				<!-- The brand column first on a designed footer, else last, as
				     it always was (site-chrome-follows-the-design). -->
				<template v-for="column in columns" :key="column.key">
					<div v-if="column.brand" class="ac-footer__logo">
						<div class="con-logo-container footer" />
						<span>
							<span>{{ title }}</span>
							<span v-if="tagline" data-testid="site-footer-tagline">
								{{ tagline }}
							</span>
							<span
								v-if="organisationKind"
								data-testid="site-footer-organisation-kind">
								{{ organisationKind }}
							</span>
						</span>
						<p
							v-if="content.description"
							class="pq-footer__description"
							data-testid="site-footer-description">
							{{ content.description }}
						</p>
						<!-- The brand column's button (site-chrome-follows-the-design). -->
						<a
							v-if="content.cta"
							class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--secondary-action pq-footer__cta"
							:href="content.cta.href"
							data-testid="site-footer-cta"
							@click="onLink($event, content.cta.href)">
							{{ content.cta.label }}
						</a>
						<!-- An icon link has no text of its own, so each carries its
						     label for screen readers. -->
						<ul
							v-if="content.socials.length"
							class="pq-footer__socials"
							data-testid="site-footer-socials">
							<li v-for="social in content.socials" :key="social.href">
								<a
									:href="social.href"
									target="_blank"
									rel="noopener noreferrer">
									<CnSiteIcon
										:name="social.icon || 'external-link'"
										:size="18" />
									<span class="sr-only">{{ social.label }}</span>
								</a>
							</li>
						</ul>
					</div>

					<!-- The contact column: plain lines, a line with a link as a
					     link (site-chrome-follows-the-design). -->
					<div
						v-else-if="column.contact"
						class="ac-footer__links pq-footer__contact"
						data-testid="site-footer-contact">
						<h3
							v-if="content.contact.title"
							class="ac-footer__menu-title">
							{{ content.contact.title }}
						</h3>
						<!-- Only the address is the link: "E-mail: " stays text
						     (board Voet, site-home-follows-the-school-boards). -->
						<p v-for="line in content.contact.lines" :key="line.text">
							{{ lineParts(line).before
							}}<a
								v-if="lineParts(line).linked"
								:href="line.href"
								@click="onLink($event, line.href)"
								>{{ lineParts(line).linked }}</a
							>
						</p>
					</div>
					<nav
						v-else
						class="ac-footer__links"
						:aria-label="column.menu.title"
						data-testid="site-footer-menu">
						<h3 class="ac-footer__menu-title">
							{{ column.menu.title }}
						</h3>
						<ul>
							<li v-for="item in column.menu.items" :key="item.name">
								<!-- The glyph is decorative: the link has its own text. -->
								<a
									class="ac-footer__link"
									:href="item.link"
									:target="
										isExternal(item.link) ? '_blank' : undefined
									"
									:rel="
										isExternal(item.link)
											? 'noopener noreferrer'
											: undefined
									"
									@click="onLink($event, item.link)">
									<CnSiteIcon
										v-if="isExternal(item.link)"
										name="external-link"
										:size="18" />
									<span>{{ item.name }}</span>
								</a>
							</li>
						</ul>
					</nav>
				</template>
			</div>
		</section>

		<section
			class="ac-footer__sub-footer pq-footer__band pq-footer__band--legal">
			<div class="container">
				<p data-testid="site-footer-colophon">
					{{ content.colophon || title }}
				</p>

				<nav
					v-if="legalLinks.length"
					class="ac-footer__sub-footer-links"
					:aria-label="legalLabel"
					data-testid="site-subfooter-menu">
					<ul class="ac-footer__sub-footer-horizontal">
						<li v-for="item in legalLinks" :key="item.href">
							<a
								:href="item.href"
								@click="onLink($event, item.href)"
								>{{ item.label }}</a
							>
						</li>
					</ul>
				</nav>

				<!-- A badge links to the evidence behind it; the content API drops
				     a badge without one. -->
				<ul
					v-if="content.badges.length"
					class="pq-footer__badges"
					data-testid="site-footer-badges">
					<li v-for="badge in content.badges" :key="badge.href">
						<a
							:href="badge.href"
							target="_blank"
							rel="noopener noreferrer"
							>{{ badge.label }}</a
						>
					</li>
				</ul>
			</div>
		</section>
	</footer>
</template>

<script>
import { CnSiteIcon } from '@conduction/nextcloud-vue/public'
import { contactLineParts } from '../lib/footerLine.js'
import { footerContentOf } from '../lib/shellData.js'

// The button links' classes need their stylesheet, or the browser draws its own blue link.
import '@utrecht/button-link-css/dist/index.css'

/**
 * The portal's footer block (`footerColumns`).
 *
 * Takes its data as props and emits `navigate` for in-site links, so it
 * mounts at a public origin and in an editor canvas alike.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
 */
export default {
	name: 'FooterColumns',

	components: { CnSiteIcon },

	props: {
		/** The portal's name: the brand column and the fallback colophon. */
		title: { type: String, default: '' },
		/** The line under the name. */
		tagline: { type: String, default: '' },
		/**
		 * The kind of organisation that runs the portal, such as waterschap
		 * (portal-identity-from-the-admin REQ-PIA-003). Empty shows nothing.
		 */
		organisationKind: { type: String, default: '' },
		/** The footer's link columns. */
		menus: { type: Array, default: () => [] },
		/** `{label, href}` legal links. */
		legalLinks: { type: Array, default: () => [] },
		/** The portal's `footer` content: description, colophon, socials, badges. */
		footer: { type: Object, default: () => ({}) },
		/** The footer landmark's heading, for screen readers. */
		landmarkLabel: { type: String, default: 'Footer' },
		/** The legal links' accessible name. */
		legalLabel: { type: String, default: 'Juridische informatie' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * Whether the footer is a designed one: it carries a button or a
		 * contact column (site-chrome-follows-the-design).
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
		 */
		designed() {
			return Boolean(this.content.cta || this.content.contact)
		},

		/**
		 * The footer content in a fixed shape.
		 *
		 * @return {object} `{description, colophon, socials, legalLinks, badges}`.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
		 */
		content() {
			return footerContentOf({ footer: this.footer })
		},

		/**
		 * The columns in reading order. A footer with a button or a contact
		 * column is a designed one: the brand column first, then contact, then
		 * the menus. Any other footer keeps the menus first and the brand last.
		 *
		 * @return {Array<object>} `{key, brand?, contact?, menu?}` entries.
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-footer-must-carry-the-motif-the-light-logo-and-the-brand-column-first
		 */
		columns() {
			const menus = this.menus.map((menu) => ({
				key: 'menu:' + menu.title,
				menu,
			}))
			const brand = { key: 'brand', brand: true }
			if (!this.content.cta && !this.content.contact) {
				return [...menus, brand]
			}
			return [
				brand,
				...(this.content.contact ? [{ key: 'contact', contact: true }] : []),
				...menus,
			]
		},
	},

	methods: {
		/**
		 * @param {{text: string, href?: string}} line A contact line.
		 * @return {{before: string, linked: string}} Its plain words and its linked words.
		 * @spec openspec/changes/site-home-follows-the-school-boards/specs/site-look/spec.md#requirement-only-the-address-of-a-footer-contact-line-is-a-link
		 */
		lineParts(line) {
			return contactLineParts(line)
		},

		/**
		 * Whether a link leaves this portal.
		 *
		 * @param {string} link The href.
		 * @return {boolean} True for an absolute http(s) address.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
		 */
		isExternal(link) {
			return /^https?:\/\//i.test(String(link || ''))
		},

		/**
		 * Follow an in-site link without leaving the document.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {string}     link  The href.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
		 */
		onLink(event, link) {
			if (String(link || '').startsWith('/') === false) {
				return
			}

			event.preventDefault()
			this.$emit('navigate', link)
		},
	},
}
</script>
