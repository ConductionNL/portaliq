// SPDX-License-Identifier: EUPL-1.2
//
// Minimal, safe markdown block (Phase 3). Renders the `richText` block's
// `markdown` as headings (#, ##, ###) and paragraphs — text only, no raw HTML,
// so a manifest can never inject markup. A fuller renderer (tilburg's RichText
// block / ToastUI) can replace this later without changing the block contract.

import React from 'react'

/**
 *
 * @param root0
 * @param root0.markdown
 */
export default function RichText({ markdown }) {
	const lines = String(markdown || '').split('\n')
	return (
		<div className="portaliq-richtext">
			{lines.map((line, i) => {
				const trimmed = line.trim()
				if (trimmed === '') {
					return null
				}
				if (trimmed.startsWith('### ')) {
					return <h4 key={i}>{trimmed.slice(4)}</h4>
				}
				if (trimmed.startsWith('## ')) {
					return <h3 key={i}>{trimmed.slice(3)}</h3>
				}
				if (trimmed.startsWith('# ')) {
					return <h2 key={i}>{trimmed.slice(2)}</h2>
				}
				return <p key={i}>{trimmed}</p>
			})}
		</div>
	)
}
