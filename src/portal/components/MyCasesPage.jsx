// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "My cases" (cases-my-cases-page): every case the signed-in person may read,
// from every app that contributes cases, in one list, newest first. Each row
// names the app it comes from, and the mandate it is read under when there is
// one. Open and closed cases sit on their own tabs; the "Closed" tab is only
// there when a contributing collection declares what closed means. A case
// opens on the page of the app it came from.

import { useEffect, useState } from 'react'
import { caseTarget, caseTitle, splitCases } from '../lib/myCases.js'
import Loading from './Loading.jsx'

/**
 * A date in the reader's language, or '' when it does not parse.
 *
 * @param {string} value An ISO date.
 * @param {string} locale The reader's locale.
 * @return {string} The date.
 */
function formatDate(value, locale) {
	const time = Date.parse(value || '')
	if (Number.isNaN(time)) {
		return ''
	}
	try {
		return new Intl.DateTimeFormat(locale || 'nl', { dateStyle: 'long' }).format(new Date(time))
	} catch {
		return new Date(time).toISOString().slice(0, 10)
	}
}

/**
 * The "My cases" page.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {(key: string, vars?: object) => string} props.t The translator.
 * @param {string} [props.locale] The reader's locale.
 * @param {boolean} [props.closedMarker] Whether any case collection tells closed from open.
 * @param {string} [props.mandateId] The mandate the person acts under, or ''.
 * @param {(target: object) => boolean} props.canOpen Whether a page shows this case.
 * @param {(target: object) => void} props.onOpenCase Open the case on its page.
 * @param {object|null} [props.initialData] The list to show without fetching (test seam).
 * @param {string} [props.initialTab] 'open' or 'closed' (test seam).
 * @return {object} The element.
 *
 * @spec openspec/changes/cases-my-cases-page/specs/portal-my-cases/spec.md
 */
export default function MyCasesPage({
	api,
	t,
	locale = 'nl',
	closedMarker = false,
	mandateId = '',
	canOpen,
	onOpenCase,
	initialData = null,
	initialTab = 'open',
}) {
	const [data, setData] = useState(initialData)
	const [tab, setTab] = useState(initialTab)

	useEffect(() => {
		if (initialData !== null) {
			return undefined
		}
		let live = true
		setData(null)
		api.fetchMyCases(mandateId).then((answer) => {
			if (live) {
				setData(answer)
			}
		})
		return () => {
			live = false
		}
	}, [api, mandateId, initialData])

	const { open, closed } = splitCases(data?.cases)
	const shown = closedMarker && tab === 'closed' ? closed : open

	return (
		<section className="portaliq-cases" data-testid="my-cases">
			<h2>{t('My cases')}</h2>

			{data === null && <Loading t={t} />}

			{data !== null && data.ok === false && (
				<p className="portaliq-error" role="alert" data-testid="my-cases-error">
					{t('Your cases could not be loaded. Try again later.')}
				</p>
			)}

			{data !== null && data.ok !== false && open.length + closed.length === 0 && (
				<p data-testid="my-cases-empty">{t('No cases yet.')}</p>
			)}

			{data !== null && data.ok !== false && open.length + closed.length > 0 && (
				<>
					{closedMarker && (
						<div className="portaliq-cases__tabs" role="tablist" aria-label={t('My cases')}>
							<button
								type="button"
								role="tab"
								aria-selected={tab !== 'closed'}
								data-testid="my-cases-tab-open"
								onClick={() => setTab('open')}>
								{t('Open ({count})', { count: open.length })}
							</button>
							<button
								type="button"
								role="tab"
								aria-selected={tab === 'closed'}
								data-testid="my-cases-tab-closed"
								onClick={() => setTab('closed')}>
								{t('Closed ({count})', { count: closed.length })}
							</button>
						</div>
					)}

					{shown.length === 0 && (
						<p data-testid="my-cases-none-here">{t(tab === 'closed' ? 'No closed cases.' : 'No cases yet.')}</p>
					)}

					{shown.length > 0 && (
						<ul className="portaliq-cases__list" data-testid="my-cases-list">
							{shown.map((row, index) => {
								const target = caseTarget(row)
								const title = caseTitle(row)
								const date = formatDate(row.created || row.startedAt, locale)
								return (
									<li key={target ? `${target.app}:${target.collection}:${target.id}` : index} className="portaliq-cases__row" data-testid="my-cases-row">
										{target && canOpen(target)
											? <button type="button" className="portaliq-cases__open" onClick={() => onOpenCase(target)}>{title}</button>
											: <span className="portaliq-cases__title">{title}</span>}
										<span className="portaliq-cases__source">{row._source?.label || row._source?.appId || ''}</span>
										{row._mandate?.label && (
											<span className="portaliq-cases__mandate" data-testid="my-cases-mandate">{row._mandate.label}</span>
										)}
										{typeof row.status === 'string' && row.status !== '' && (
											<span className="portaliq-cases__status">{row.status}</span>
										)}
										{date !== '' && <span className="portaliq-cases__date">{date}</span>}
									</li>
								)
							})}
						</ul>
					)}
				</>
			)}
		</section>
	)
}
