/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The `toLocaleString` options for a moment on the site: the day and the time
 * to the minute. A resident never reads seconds.
 *
 * @spec openspec/changes/date-times-read-without-seconds/specs/site-portal-parity/spec.md#requirement-a-date-and-time-reads-to-the-minute
 */
export const DAY_AND_MINUTE = Object.freeze({
	year: 'numeric',
	month: 'numeric',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
})
