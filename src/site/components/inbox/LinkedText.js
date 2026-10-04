/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A text with named links in it: the parts `bodyParts()` in
 * src/site/pages/inbox/inbox.js makes. Text renders as text and a link as an
 * `<a>` with its name as text content, never as HTML. A link with an in-site
 * route opens through the site's own navigation; with a modifier key, or
 * without a route, the browser follows the address. A render function, so no
 * template whitespace lands between a text and its link. It renders its own
 * element (`as`), so a text without links renders as it did before.
 */

import { h } from 'vue'

/**
 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-shows-other-site-addresses-as-named-links-req-nap-013
 */
export default {
	name: 'LinkedText',

	props: {
		/** The parts: `{text}` or `{text, href, route}`. */
		parts: { type: Array, default: () => [] },
		/** The element the text renders in; its class and lang fall through. */
		as: { type: String, default: 'span' },
	},

	emits: ['navigate'],

	methods: {
		/**
		 * Follow a link inside the site when it names a route.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {{route?: (string|null)}} part The link.
		 * @return {void}
		 * @spec openspec/changes/woo-inbox-notices/specs/portal-notifications-and-preferences/spec.md#requirement-the-inbox-shows-other-site-addresses-as-named-links-req-nap-013
		 */
		follow(event, part) {
			if (
				!part.route
				|| event?.ctrlKey
				|| event?.metaKey
				|| event?.shiftKey
				|| event?.altKey
			) {
				return
			}
			event?.preventDefault?.()
			this.$emit('navigate', part.route)
		},
	},

	render() {
		const children = this.parts.map((part) =>
			part.href
				? h(
						'a',
						{
							class: 'utrecht-link',
							href: part.href,
							'data-testid': 'inbox-body-link',
							onClick: (event) => this.follow(event, part),
						},
						part.text,
					)
				: part.text,
		)
		return h(this.as, null, children)
	},
}
