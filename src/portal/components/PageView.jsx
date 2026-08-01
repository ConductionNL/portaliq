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

import React, { useCallback, useEffect, useRef, useState } from 'react'
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
// The scoped file-upload control (the file-upload block). Shown in a detail card
// only when the collection declares `filesUpload` — the server re-verifies
// ownership and requires the opt-in, so this is a convenience, not the authority.
const FileUpload = React.memo(function FileUpload({ collection, row, api, onUploaded }) {
	const [state, setState] = useState({ busy: false, message: null })
	const inputRef = useRef(null)

	async function onChange(e) {
		const file = e.target.files && e.target.files[0]
		if (!file) {
			return
		}
		const id = row.id || row['@self']?.id
		if (!id) {
			return
		}
		setState({ busy: true, message: null })
		const result = await api.uploadFile(collection, id, file)
		setState({ busy: false, message: result.ok ? `Bestand toegevoegd: ${result.file?.name || file.name}` : 'Uploaden is niet gelukt.' })
		if (inputRef.current) {
			inputRef.current.value = ''
		}
		// Let the detail card re-read the row's `_files` so the download list
		// picks up the just-uploaded attachment.
		if (result.ok && onUploaded) {
			onUploaded()
		}
	}

	return (
		<div className="portaliq-fileupload">
			<label>
				Bijlage toevoegen
				<input ref={inputRef} type="file" disabled={state.busy} onChange={onChange} />
			</label>
			{state.busy && <span> …</span>}
			{state.message && <p className="portaliq-fileupload-msg">{state.message}</p>}
		</div>
	)
})

// The scoped file-download list (the file-download block, portal-document-
// download — the read-side counterpart of FileUpload above). Shown in a
// detail card only when the collection declares `filesDownload` — the server
// re-verifies ownership + the opt-in on every download; this list is a
// convenience, not the authority. Reads `row._files` (id/name/size only,
// attached server-side by object() when the collection opts in).
function FileList({ collection, row, api }) {
	const [state, setState] = useState({ busyId: null, message: null })
	const files = Array.isArray(row?._files) ? row._files : []
	const id = row?.id || row?.['@self']?.id

	async function onDownload(file) {
		if (!id) {
			return
		}
		setState({ busyId: file.id, message: null })
		const result = await api.downloadFile(collection, id, file)
		setState({ busyId: null, message: result.ok ? null : 'Downloaden is niet gelukt.' })
	}

	if (files.length === 0) {
		return null
	}

	return (
		<div className="portaliq-filelist">
			<h4>Bijlagen</h4>
			<ul>
				{files.map((file) => (
					<li key={file.id}>
						<button type="button" disabled={state.busyId === file.id} onClick={() => onDownload(file)}>
							{file.name || `Bestand ${file.id}`}
						</button>
					</li>
				))}
			</ul>
			{state.message && <p className="portaliq-filelist-msg">{state.message}</p>}
		</div>
	)
}

function DetailCard({ collection, row, api }) {
	const rowId = row && (row.id || row['@self']?.id)
	// The FULL single-object read carries the server-attached `_files` listing
	// the file-download block needs — the collection list projection omits it.
	// Fetch it on selection and re-fetch after an upload, while keeping the
	// STABLE list `row` as FileUpload's upload target so an in-flight upload is
	// never disturbed by this refresh.
	const [full, setFull] = useState(row)
	const refresh = useCallback(async () => {
		if (!rowId || collection.filesDownload !== true || !api) {
			return
		}
		const f = await api.fetchObject(collection, rowId)
		if (f) {
			setFull(f)
		}
	}, [rowId, collection, api])
	useEffect(() => {
		setFull(row)
		refresh()
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [rowId])

	if (!row) {
		return <p className="portaliq-empty"><em>Selecteer een item.</em></p>
	}
	const detailRow = full || row
	const fields = (collection.detail && Array.isArray(collection.detail.fields) && collection.detail.fields.length > 0)
		? collection.detail.fields
		: Object.keys(detailRow).filter((k) => k !== '@self' && k !== '_files')
	return (
		<>
			<dl className={`portaliq-detail portaliq-detail-${collection.detail?.layout || 'card'}`}>
				{fields.map((f) => (
					<div key={f} className="portaliq-detail-row">
						<dt>{f}</dt>
						<dd>{detailRow[f] === null || detailRow[f] === undefined ? '' : String(detailRow[f])}</dd>
					</div>
				))}
			</dl>
			{collection.filesUpload === true && api && <FileUpload collection={collection} row={row} api={api} onUploaded={refresh} />}
			{collection.filesDownload === true && api && <FileList collection={collection} row={detailRow} api={api} />}
		</>
	)
}

export default function PageView({ page, contribution, api, dataByCollection, onCreated, onAction, onRowAction, busyRow }) {
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
						return <DetailCard key={i} collection={collection} row={selected[collection.id]} api={api} />
					}
					const rowActions = (collection.rowActions || [])
						.map((id) => findAction(contribution, id))
						.filter(Boolean)
					return (
						<div key={i} className="portaliq-block-collection">
							{collection.label && <h3>{collection.label}</h3>}
							<CollectionTable
								collection={collection}
								objects={loaded?.objects || []}
								loading={loaded?.loading}
								onSelect={(row) => setSelected((s) => ({ ...s, [collection.id]: row }))}
								rowActions={rowActions}
								busyRow={busyRow}
								onRowAction={(action, row) => onRowAction && onRowAction(action, row, collection)}
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
