/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Whether the portal offers the assistant (search-assistant-from-public-content).
 * Kept apart from the call itself so the page shell carries only this flag and
 * the call loads with the widget.
 */

let available = false

/**
 * Remember whether the portal offers the assistant (the site record says so).
 * The widget is not mountable, and not offered in the page designer, while
 * this is false.
 *
 * @param {boolean} enabled The site record's `assistantEnabled`.
 * @return {void}
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
 */
export function setAssistantAvailable(enabled) {
	available = enabled === true
}

/**
 * Whether the portal offers the assistant.
 *
 * @return {boolean} True only when the site record said so.
 * @spec openspec/changes/search-assistant-from-public-content/tasks.md#t05
 */
export function assistantAvailable() {
	return available
}
