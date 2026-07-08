// SPDX-License-Identifier: EUPL-1.2
//
// Manifest-v3 page layout driver (Phase 3d). Renders one page's ordered `blocks`,
// resolving each block's `collection`/`action` reference against the (already
// trust-filtered + normalised) contribution. This generalises tilburg's
// `my-account` tabs pattern: one view per page, schema-/manifest-driven table,
// form, and detail inside — the block `type` selects the renderer.
//
// The server-side normaliser has already dropped unresolvable/cross-contribution
// blocks, so a ref that does not resolve here is a defensive skip, not expected.

import React, { useState } from 'react'
import CollectionTable from './CollectionTable.jsx'
import SchemaForm from './SchemaForm.jsx'
import RichText from './RichText.jsx'

function findCollection(contribution, id) {
	return (contribution.collections || []).find((c) => c.id === id) || null
}

function findAction(contribution, id) {
	return (contribution.actions || []).find((a) => a.id === id) || null
}

// A single-object detail rendered from an already-loaded row (no extra fetch —
// works pre-#25). Uses the collection's `detail.fields` when declared, else the
// row keys.
function DetailCard({ collection, row }) {
	if (!row) {
		return <p className="portaliq-empty"><em>Selecteer een item.</em></p>
	}
	const fields = (collection.detail && Array.isArray(collection.detail.fields) && collection.detail.fields.length > 0)
		? collection.detail.fields
		: Object.keys(row).filter((k) => k !== '@self')
	return (
		<dl className={`portaliq-detail portaliq-detail-${collection.detail?.layout || 'card'}`}>
			{fields.map((f) => (
				<div key={f} className="portaliq-detail-row">
					<dt>{f}</dt>
					<dd>{row[f] === null || row[f] === undefined ? '' : String(row[f])}</dd>
				</div>
			))}
		</dl>
	)
}

export default function PageView({ page, contribution, api, dataByCollection, onCreated, onAction }) {
	// The row selected in a table on this page, keyed by collection id — feeds
	// any `detail` block for the same collection.
	const [selected, setSelected] = useState({})

	return (
		<section className="portaliq-page">
			{(page.blocks || []).map((block, i) => {
				switch (block.type) {
					case 'richText':
						return <RichText key={i} markdown={block.markdown} />

					case 'collection':
					case 'detail': {
						const collection = findCollection(contribution, block.collection)
						if (!collection) {
							return null
						}
						const loaded = dataByCollection[collection.id]
						if (block.type === 'detail') {
							return <DetailCard key={i} collection={collection} row={selected[collection.id]} />
						}
						return (
							<div key={i} className="portaliq-block-collection">
								{collection.label && <h3>{collection.label}</h3>}
								<CollectionTable
									collection={collection}
									objects={loaded?.objects || []}
									loading={loaded?.loading}
									onSelect={(row) => setSelected((s) => ({ ...s, [collection.id]: row }))}
								/>
							</div>
						)
					}

					case 'action': {
						const action = findAction(contribution, block.action)
						if (!action) {
							return null
						}
						if (action.type === 'create' || action.type === 'update') {
							return (
								<div key={i} className="portaliq-block-action">
									<h3>{action.label || action.id}</h3>
									<SchemaForm action={action} api={api} onSubmitted={(obj) => onCreated && onCreated(obj, action)} />
								</div>
							)
						}
						// Endpoint / A6 action → a button the shell forwards.
						return (
							<button key={i} type="button" className="portaliq-cta" disabled={!action.endpoint} onClick={() => onAction && onAction(action)}>
								{action.label || action.id}
							</button>
						)
					}

					case 'cta': {
						const action = findAction(contribution, block.action)
						if (!action) {
							return null
						}
						return (
							<button key={i} type="button" className="portaliq-cta" onClick={() => onAction && onAction(action)}>
								{block.label}
							</button>
						)
					}

					default:
						return null
				}
			})}
		</section>
	)
}
