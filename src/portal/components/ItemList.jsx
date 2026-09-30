// SPDX-License-Identifier: EUPL-1.2
//
// What is in a record, as its app lists it (my-dossiers): a dossier's
// publications with their links and notes. An item whose publication is no
// longer public stays, marked so. With a declared remove action, each item
// can be removed on its own.

import { useCallback, useEffect, useState } from 'react'
import { itemRows, removeItem } from '../lib/itemList.js'
import Loading from './Loading.jsx'

/**
 * The item list of one record.
 *
 * @param {object} props            The props.
 * @param {object} props.collection The collection, with `itemList`.
 * @param {object} props.row        The record on screen.
 * @param {object} props.api        The portal api.
 * @param {(key: string) => string} props.t The translator.
 * @return {object} The list.
 * @spec openspec/changes/my-dossiers/specs/portal-contribution-contract/spec.md#requirement-an-item-that-is-no-longer-public-must-say-so-req-myd-002
 */
export default function ItemList({ collection, row, api, t }) {
	const rowId = row && (row.id || row['@self']?.id)
	const [answer, setAnswer] = useState(null)
	const [message, setMessage] = useState('')

	const load = useCallback(async () => {
		setAnswer(null)
		const body = rowId && api && api.fetchItems ? await api.fetchItems(collection, rowId) : null
		setAnswer(body || false)
	}, [collection, rowId, api])

	useEffect(() => {
		load()
	}, [load])

	const heading = collection.itemList?.label || t('In this dossier')
	if (answer === null) {
		return (
			<section className="portaliq-item-list" aria-busy="true">
				<h4>{heading}</h4>
				<Loading t={t} />
			</section>
		)
	}
	if (answer === false) {
		return (
			<section className="portaliq-item-list">
				<h4>{heading}</h4>
				<p className="portaliq-empty">{t('The items could not be loaded.')}</p>
			</section>
		)
	}

	const rows = itemRows(answer)
	const canRemove = typeof collection.itemList?.removeAction === 'string' && collection.itemList.removeAction !== ''

	/**
	 * Remove one item, then read the list again.
	 *
	 * @param {string} itemId The item.
	 */
	async function onRemove(itemId) {
		const result = await removeItem(api, collection, row, itemId)
		setMessage(result.ok ? t('Removed.') : t('This can no longer be done for this item.'))
		if (result.ok) {
			load()
		}
	}

	return (
		<section className="portaliq-item-list" data-testid="item-list">
			<h4>{heading}</h4>
			{rows.length === 0
				? <p className="portaliq-empty"><em>{t('Nothing in this dossier yet.')}</em></p>
				: (
					<ul>
						{rows.map((item) => (
							<li key={item.id || item.title} data-testid="item-list-item">
								{item.href ? <a href={item.href}>{item.title}</a> : <span>{item.title}</span>}
								{item.notPublic && <strong className="portaliq-badge" data-testid="item-not-public"> {t('No longer public')}</strong>}
								{item.note && <p className="portaliq-item-note">{item.note}</p>}
								{canRemove && item.id && (
									<button type="button" className="portaliq-cta-subtle" aria-label={`${t('Remove')}: ${item.title}`} onClick={() => onRemove(item.id)}>
										{t('Remove')}
									</button>
								)}
							</li>
						))}
					</ul>
				)}
			{message !== '' && <p role="status">{message}</p>}
		</section>
	)
}
