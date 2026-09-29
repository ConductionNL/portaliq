// SPDX-License-Identifier: EUPL-1.2
//
// The government message box channel in the resident's inbox
// (inbox-berichtenbox-channel). The server marks a message with
// `_deliveries` once its message box send was delivered or read, and the
// notification settings answer with `messageBox: {label}` when the
// organisation offers the channel. The label is the organisation's own, for
// example "MijnOverheid Berichtenbox"; portaliq names no product itself.
//
// @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-sees-only-a-real-delivery-req-mbc-004

/**
 * The line under a message that also reached the message box, or null.
 *
 * @param {object} message The inbox message.
 * @param {Function} t The translator.
 * @return {string|null}
 */
export function deliveryLine(message, t) {
	const deliveries = Array.isArray(message?._deliveries) ? message._deliveries : []
	const delivery = deliveries.find(
		(d) =>
			d
			&& d.channel === 'messageBox'
			&& typeof d.label === 'string'
			&& d.label !== '',
	)
	return delivery ? t('Also sent to {label}.', { label: delivery.label }) : null
}

/**
 * The resident's message box choice, or null when the organisation does not
 * offer the channel. A missing choice means on.
 *
 * @param {object} loaded The notification settings answer.
 * @return {{label: string, enabled: boolean}|null}
 */
export function messageBoxChoice(loaded) {
	const label = loaded?.messageBox?.label
	if (typeof label !== 'string' || label === '') {
		return null
	}
	return {
		label,
		enabled: loaded?.preferences?.messageBox?.enabled !== false,
	}
}

/**
 * The choices with the message box switched on or off, the rest untouched.
 *
 * @param {object} choices The current choices.
 * @param {boolean} enabled On or off.
 * @return {object}
 */
export function withMessageBoxChoice(choices, enabled) {
	return { ...choices, messageBox: { enabled } }
}
