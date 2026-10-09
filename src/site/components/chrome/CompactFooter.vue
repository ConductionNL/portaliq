<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE SHORT FOOTER OF THE OWN AREA ON A PHONE (mijn-phone-chrome), as the
		school MobielHome boards draw it: the light logo on the brand band, one
		line and a few links. It is in the page only where the portal writes
		one, and shows only on a phone inside the own area (AccountArea's
		phone rules); elsewhere the full footer stands.
	-->
	<footer
		class="ac-footer pq-site__footer pq-compact-footer"
		data-testid="site-compact-footer">
		<section
			class="pq-footer__band pq-footer__band--content pq-footer__band--designed">
			<div class="container pq-compact-footer__inner">
				<div
					class="con-logo-container footer pq-compact-footer__logo"
					role="img"
					:aria-label="title" />
				<p v-if="footer.text" class="pq-compact-footer__text">
					{{ footer.text }}
				</p>
				<ul v-if="links.length > 0" class="pq-compact-footer__links">
					<li v-for="link in links" :key="link.href">
						<a :href="link.href" @click="onLink($event, link.href)">{{
							link.label
						}}</a>
					</li>
				</ul>
			</div>
		</section>
	</footer>
</template>

<script>
/**
 * @spec openspec/changes/mijn-phone-chrome/specs/site-chrome/spec.md#requirement-the-own-area-may-end-in-a-short-footer-on-a-phone
 */
export default {
	name: 'CompactFooter',

	props: {
		/** `{text, links: [{label, href}]}` from the portal's `footer.compact`. */
		footer: { type: Object, required: true },
		/** The portal's name, for the logo. */
		title: { type: String, default: '' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {Array<{label: string, href: string}>} The links that have both parts.
		 * @spec openspec/changes/mijn-phone-chrome/specs/site-chrome/spec.md#requirement-the-own-area-may-end-in-a-short-footer-on-a-phone
		 */
		links() {
			return (
				Array.isArray(this.footer.links) ? this.footer.links : []
			).filter((link) => link && link.label && link.href)
		},
	},

	methods: {
		/**
		 * A path inside the site opens without a reload; any other address
		 * is followed as it is.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {string} href The address.
		 * @return {void}
		 * @spec openspec/changes/mijn-phone-chrome/specs/site-chrome/spec.md#requirement-the-own-area-may-end-in-a-short-footer-on-a-phone
		 */
		onLink(event, href) {
			if (href.startsWith('/') && !href.startsWith('//')) {
				event.preventDefault()
				this.$emit('navigate', href)
			}
		},
	},
}
</script>

<style>
/* Hidden unless AccountArea's phone rules show it. */
.pq-compact-footer {
	display: none;
}

.pq-compact-footer__inner {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding-block: 24px;
}

.pq-compact-footer__text {
	margin: 0;
}

.pq-compact-footer__links {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-compact-footer__links a {
	color: inherit;
}
</style>
