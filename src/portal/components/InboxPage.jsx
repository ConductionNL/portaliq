// SPDX-License-Identifier: EUPL-1.2
//
// Unified inbox page (portal-inbox-v2 T05/T06). Renders the aggregated,
// provenance-tagged inbox from `GET /portal/api/inbox` — merged across every
// `kind: inbox` collection the subject's contributions declared, sorted
// newest-first. Each row shows an unread indicator + read-state toggle
// (`PATCH .../inbox/{register}/{schema}/{id}/read`, tamper-proof server-side)
// and, when the contributing app supplied them, the optional WMEBV art 2:10
// readiness fields (nature/rechtsgevolg/term) — absent fields render nothing,
// never an empty placeholder.

import React, { useCallback, useEffect, useState } from 'react'

/**
 *
 * @param value
 * @param locale
 */
function formatDateTime(value, locale) {
	if (!value) {
		return ''
	}
	try {
		return new Date(value).toLocaleString(locale === 'en' ? 'en-GB' : 'nl-NL')
	} catch (e) {
		return String(value)
	}
}

/**
 *
 * @param root0
 * @param root0.api
 * @param root0.t
 * @param root0.locale
 * @param root0.onRead
 */
export default function InboxPage({ api, t, locale, onRead }) {
	const [state, setState] = useState({ loading: true, messages: [] })
	const [busyId, setBusyId] = useState(null)

	const load = useCallback(async () => {
		setState((s) => ({ ...s, loading: true }))
		const messages = await api.fetchInbox()
		setState({ loading: false, messages })
	}, [api])

	useEffect(() => { load() }, [load])

	/**
	 *
	 * @param message
	 */
	async function onToggleRead(message) {
		const id = message.id || message['@self']?.id
		if (!id || message.read === true) {
			return
		}
		setBusyId(id)
		const result = await api.markMessageRead(message)
		setBusyId(null)
		if (result.ok) {
			// Optimistic, server-confirmed: only flip `read` on this row —
			// mirrors exactly what the server is guaranteed to have changed.
			setState((s) => ({
				...s,
				messages: s.messages.map((m) => ((m.id || m['@self']?.id) === id ? { ...m, read: true } : m)),
			}))
			// Tell the shell one message is no longer unread, so the cross-app
			// Inbox nav badge (portal-inbox-v2 T04) drops in lockstep.
			if (onRead) {
				onRead()
			}
		}
	}

	if (state.loading) {
		return <p className="portaliq-loading">…</p>
	}

	if (state.messages.length === 0) {
		return <p className="portaliq-empty"><em>{t('No messages.')}</em></p>
	}

	return (
		<ul className="portaliq-inbox">
			{state.messages.map((message, i) => {
				const id = message.id || message['@self']?.id || i
				const unread = message.read !== true
				const source = message._source || {}
				return (
					<li key={id} className={unread ? 'portaliq-inbox-row portaliq-inbox-row--unread' : 'portaliq-inbox-row'}>
						<div className="portaliq-inbox-row__header">
							{unread && <span className="portaliq-badge-unread" aria-label={t('Unread')}>●</span>}
							<span className="portaliq-inbox-row__subject">{message.subject || ''}</span>
							{source.label && <span className="portaliq-inbox-row__source">{source.label}</span>}
							<span className="portaliq-inbox-row__date">{formatDateTime(message.receivedAt, locale)}</span>
						</div>

						{message.body && <p className="portaliq-inbox-row__body">{message.body}</p>}

						{(message.nature || message.rechtsgevolg || message.term) && (
							<dl className="portaliq-inbox-row__meta">
								{message.nature && (
									<div className="portaliq-inbox-row__meta-item">
										<dt>{t('Nature')}</dt>
										<dd>{message.nature}</dd>
									</div>
								)}
								{message.rechtsgevolg && (
									<div className="portaliq-inbox-row__meta-item">
										<dt>{t('Legal effect')}</dt>
										<dd>{message.rechtsgevolg}</dd>
									</div>
								)}
								{message.term && (
									<div className="portaliq-inbox-row__meta-item">
										<dt>{t('Deadline')}</dt>
										<dd>{formatDateTime(message.term, locale)}</dd>
									</div>
								)}
							</dl>
						)}

						<button
							type="button"
							className="portaliq-inbox-row__toggle"
							disabled={!unread || busyId === id}
							onClick={() => onToggleRead(message)}
						>
							{unread ? t('Mark as read') : t('Read')}
						</button>
					</li>
				)
			})}
		</ul>
	)
}
