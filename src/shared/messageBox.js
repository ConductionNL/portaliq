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
 * The line under a message that also reached the message box, or null:
 * "Ook verstuurd naar {label}, op {date} om {time}." when the delivery says
 * when, else without the moment.
 *
 * @param {object} message The inbox message.
 * @param {{t: Function, locale: string}} words The translator and the reader's locale.
 * @return {string|null}
 */
export function deliveryLine(message, words) {
	const delivery = messageBoxDelivery(message)
	if (!delivery) {
		return null
	}
	const moment = deliveryMoment(delivery.at, words.locale)
	const label = delivery.label
	return moment
		? words.t('Also sent to {label}, on {date} at {time}.', { label, ...moment })
		: words.t('Also sent to {label}.', { label })
}

/**
 * The message box delivery of a message, or null.
 *
 * @param {object} message The inbox message.
 * @return {{label: string, at?: string}|null}
 */
function messageBoxDelivery(message) {
	const deliveries = Array.isArray(message?._deliveries) ? message._deliveries : []
	return (
		deliveries.find(
			(d) => d && d.channel === 'messageBox' && typeof d.label === 'string' && d.label !== '',
		) || null
	)
}

/**
 * When a delivery happened, as the line names it: "vrijdag 2 oktober" and
 * "14:02", in Dutch time. Null without a readable moment.
 *
 * @param {string|undefined} at The moment, ISO 8601.
 * @param {string} locale The reader's locale.
 * @return {{date: string, time: string}|null}
 */
export function deliveryMoment(at, locale) {
	const when = new Date(typeof at === 'string' ? at : '')
	if (Number.isNaN(when.getTime())) {
		return null
	}
	const lang = locale || 'nl'
	const zone = { timeZone: 'Europe/Amsterdam' }
	return {
		date: when.toLocaleDateString(lang, { ...zone, weekday: 'long', day: 'numeric', month: 'long' }),
		time: when.toLocaleTimeString(lang, { ...zone, hour: '2-digit', minute: '2-digit' }),
	}
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
