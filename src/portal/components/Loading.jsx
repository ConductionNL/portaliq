// SPDX-License-Identifier: EUPL-1.2
//
// The portal's one loading indicator (portal-spa-nl-design-system-styling,
// WCAG 2.2 AA success criterion 4.1.3 Status messages). A sighted resident
// sees "…"; a screen reader hears "Loading…" in the resident's language, in a
// polite status region, instead of "dot dot dot" or nothing at all.
//
// @spec openspec/changes/portal-spa-nl-design-system-styling/specs/supplier-portal/spec.md#requirement-the-portal-shell-must-use-the-nl-design-system-component-set-and-meet-wcag-21-aa

/**
 * @param {object} root0 Props.
 * @param {Function} [root0.t] Translator; without one the key is spoken.
 * @param {string} [root0.className] The paragraph's class.
 * @return {object}
 */
export default function Loading({ t, className = 'portaliq-loading' }) {
	const translate = t || ((key) => key)
	return (
		<p className={className} role="status" aria-live="polite">
			<span aria-hidden="true">…</span>
			<span className="portaliq-sr-only">{translate('Loading…')}</span>
		</p>
	)
}
