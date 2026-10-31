/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * One contact line of the footer, split for its link
 * (site-home-follows-the-school-boards).
 *
 * The boards Voet draw "E-mail: [e-mailadres]" with only the address as a
 * link. A line with an address and a label before a colon links the part
 * after the colon; any other line with an address links as a whole.
 */

/**
 * @param {{text: string, href?: string}} line The authored line.
 * @return {{before: string, linked: string}} The words before the link and the linked words; `linked` is '' without an address.
 * @spec openspec/changes/site-home-follows-the-school-boards/specs/site-look/spec.md#requirement-only-the-address-of-a-footer-contact-line-is-a-link
 */
export function contactLineParts(line) {
	const text = String(line?.text ?? '')
	if (!line?.href) {
		return { before: text, linked: '' }
	}
	const match = /^([^:]{1,40}:\s+)(\S[\s\S]*)$/.exec(text)
	return match
		? { before: match[1], linked: match[2] }
		: { before: '', linked: text }
}
