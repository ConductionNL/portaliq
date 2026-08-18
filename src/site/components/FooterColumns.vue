<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<footer class="ac-footer pq-site__footer" data-testid="site-footer">
		<!-- The reference labels its footer for assistive tech and hides the
		     heading visually; a landmark with no name is announced as just
		     "footer". -->
		<h2 class="sr-only">{{ landmarkLabel }}</h2>

		<!--
			THE DECORATION IS OPT-IN, BY NAME.

			A canal scene is Conduction's own illustration. A municipality
			running this same renderer must not inherit it because it shares
			a footer component, so the portal names the decoration it wants
			and the default is none.
		-->
		<FooterCanal v-if="content.decoration === 'canal'" />

		<!--
			EVERY BAND NAMES ITSELF (task 2.2).

			These sections used to be styled positionally —
			`section:first-of-type` and `section:last-of-type:not(:only-of-type)`
			— which meant a footer could have exactly two bands. Adding a third
			did not add a band; it RESTYLED the other two, because the third
			became the new `:last-of-type` and inherited the legal bar's
			typography while the legal bar silently became a content band.

			With a class per band the styling follows the band's ROLE, so a
			portal can have one, two or five and each keeps its own.
		-->
		<section class="pq-footer__band pq-footer__band--content">
			<div class="container ac-footer__container">
				<!--
					THE BRAND COLUMN COMES FIRST.

					It used to be last, after the link menus, which is the one
					order the reference does not use: measured on
					docs.conduction.nl the grid is brand-then-columns, at
					297px against three of 198px. A footer that opens with
					"Documentation" and names its owner in the far right
					column reads as three menus and an afterthought.
				-->
				<div class="ac-footer__logo pq-footer__brand">
					<div class="con-logo-container footer" />
					<span class="pq-footer__wordmark">{{ title }}</span>

					<!-- The line under the wordmark. Portal CONTENT, from the
					     portal record — a renderer cannot know what a portal
					     is for. -->
					<p
						v-if="content.description"
						class="pq-footer__brand-text"
						data-testid="site-footer-description">
						{{ content.description }}
					</p>

					<!-- The tagline predates the brand line and is kept: a
					     portal that set one must not lose it. -->
					<p
						v-else-if="tagline"
						class="pq-footer__brand-text"
						data-testid="site-footer-tagline">
						{{ tagline }}
					</p>

					<!--
						SOCIALS CARRY A VISIBLE-TO-SCREEN-READER NAME, ALWAYS.

						The glyph is decorative and the anchor has no text of
						its own, so without the `sr-only` label these are five
						links announced as "link". The reference uses brand
						glyphs we do not ship; `external-link` is the honest
						stand-in rather than an invented logo.
					-->
					<ul
						v-if="content.socials.length"
						class="pq-footer__socials"
						data-testid="site-footer-socials">
						<li
							v-for="social in content.socials"
							:key="social.href">
							<a
								:href="social.href"
								:aria-label="social.label"
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

				<nav
					v-for="menu in menus"
					:key="menu.title"
					class="ac-footer__links"
					:aria-label="menu.title"
					data-testid="site-footer-menu">
					<h3 class="ac-footer__menu-title">{{ menu.title }}</h3>
					<ul>
						<li v-for="item in menu.items" :key="item.name">
							<!--
								The reference marks every footer link with an
								external-link glyph. It is DECORATIVE here —
								`aria-hidden` — because the link already has
								its own text; announcing "external link"
								twice per item helps nobody.
							-->
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
								@click="onFooterLink($event, item.link)">
								<CnSiteIcon
									v-if="isExternal(item.link)"
									name="external-link"
									:size="18" />
								<span>{{ item.name }}</span>
							</a>
						</li>
					</ul>
				</nav>
			</div>
		</section>

		<section class="ac-footer__sub-footer pq-footer__band pq-footer__band--legal">
			<div class="container pq-footer__legal">
				<!--
					THE LEGAL BAR HAS TWO SIDES, and the left one is not a menu.

					This used to be a horizontal nav of legal links and nothing
					else. The reference puts the COLOPHON first — the legal
					entity, its chamber-of-commerce number and its VAT number —
					then the legal links inline after it, and certification
					badges on the right. The links alone name who to complain
					to but not who is responsible.
				-->
				<div class="pq-footer__legal-left">
					<!--
						THE PORTAL TITLE IS THE FALLBACK COLOPHON, and removing
						it was a regression: a portal that has never set a
						`footer` block — which is every portal that existed
						before this field — showed its name here, and for one
						build showed nothing at all. An empty legal bar on a
						government site names nobody as responsible.
					-->
					<span
						v-if="content.colophon || title"
						class="pq-footer__colophon"
						data-testid="site-footer-colophon">
						{{ content.colophon || title }}
					</span>

					<!--
						The separator is a decorative character between links,
						so it is `aria-hidden`: read aloud, a list of four
						links becomes "Privacy middle dot Terms middle dot".
					-->
					<span
						v-if="legalLinks.length"
						class="pq-footer__legal-links"
						data-testid="site-footer-legal-links">
						<template
							v-for="(item, i) in legalLinks"
							:key="item.href">
							<span v-if="i > 0" aria-hidden="true">·</span>
							<a
								:href="item.href"
								@click.prevent="$emit('navigate', item.href)"
								>{{ item.label }}</a
							>
						</template>
					</span>
				</div>

				<!--
					BADGES ARE ONLY LINKS WHEN THEY LEAD SOMEWHERE. A
					certification badge with no certificate behind it is a
					claim; rendering it as an inert `span` rather than an
					anchor at least does not promise evidence that is missing.
				-->
				<ul
					v-if="content.badges.length"
					class="pq-footer__badges"
					data-testid="site-footer-badges">
					<li
						v-for="badge in content.badges"
						:key="badge.mark + badge.value">
						<component
							:is="badge.href ? 'a' : 'span'"
							class="pq-footer__badge"
							:href="badge.href || null"
							:target="badge.href ? '_blank' : null"
							:rel="badge.href ? 'noopener noreferrer' : null">
							<span class="pq-footer__badge-mark">{{
								badge.mark
							}}</span>
							<span class="pq-footer__badge-value">{{
								badge.value
							}}</span>
						</component>
					</li>
				</ul>
			</div>
		</section>
	</footer>
</template>

<script>
import { CnSiteIcon } from '@conduction/nextcloud-vue/public'
import FooterCanal from './FooterCanal.vue'

/**
 * The portal's footer, as a BLOCK rather than as markup in the shell.
 *
 * WHY IT MOVED. The footer's two bands were selected in CSS by `:first-of-type`
 * and `:last-of-type:not(:only-of-type)`, so a third band was impossible and
 * adding one restyled the other two (task 0.1). Moving the footer into the
 * `footer` region is the first half of undoing that; per-band configuration
 * (task 2.2) is the second.
 *
 * IT RENDERS THE SAME DOM IT DID AS MARKUP, byte for byte, held against the
 * pre-move capture by `tests/shell-snapshot.mjs`.
 *
 * `navigate` is EMITTED rather than handled. An in-site link must not reload
 * the document, and a block cannot know how its host routes — the shell owns
 * the router and the block owns the markup.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'FooterColumns',

	components: { CnSiteIcon, FooterCanal },

	props: {
		/** The portal's title, the wordmark and the fallback colophon. */
		title: {
			type: String,
			default: '',
		},

		/** The line under the wordmark when there is no `description`. */
		tagline: {
			type: String,
			default: '',
		},

		/** The projected footer block: description, colophon, socials, badges, decoration. */
		content: {
			type: Object,
			default: () => ({
				description: '',
				colophon: '',
				socials: [],
				badges: [],
				legalLinks: [],
				decoration: 'none',
			}),
		},

		/** The link columns. */
		menus: {
			type: Array,
			default: () => [],
		},

		/** The legal bar's inline links. */
		legalLinks: {
			type: Array,
			default: () => [],
		},

		/** The footer landmark's accessible name. */
		landmarkLabel: {
			type: String,
			default: 'Footer',
		},
	},

	emits: ['navigate'],

	methods: {
		/**
		 * Whether a link leaves this site.
		 *
		 * @param {string} link The href.
		 * @return {boolean} True when external.
		 */
		isExternal(link) {
			return /^https?:\/\//.test(String(link || ''))
		},

		/**
		 * Route an in-site footer link without reloading the document.
		 *
		 * @param {Event}  event The click.
		 * @param {string} link  The href.
		 * @return {void}
		 */
		onFooterLink(event, link) {
			if (this.isExternal(link)) {
				return
			}

			event.preventDefault()
			this.$emit('navigate', link)
		},
	},
}
</script>
