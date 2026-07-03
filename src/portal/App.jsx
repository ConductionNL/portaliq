// SPDX-License-Identifier: EUPL-1.2
//
// Portaliq public portal shell (skeleton).
//
// Role-based white-label shell for the two external audiences (client / supplier).
// The auth edge, contribution rendering, and inbox land in the supplier-portal
// change (openspec/changes/supplier-portal). This skeleton establishes the shell
// contract: resolve a session from the portal auth edge (fail closed), then show
// either a login prompt or the audience home.

import React, { useEffect, useState } from 'react'

/**
 * Resolve the current portal session from the auth edge.
 *
 * TODO (supplier-portal T02): GET `${apiBase}/session` with the bearer session
 * cookie/token; the server derives supplierRef/organisation. MUST fail closed —
 * treat any non-200 as "not authenticated" and never trust client-supplied scope.
 */
async function resolveSession(config) {
	try {
		const res = await fetch(`${config.apiBase}/session`, {
			credentials: 'include',
			headers: { Accept: 'application/json' },
		})
		if (!res.ok) {
			return null
		}
		return await res.json()
	} catch (e) {
		return null
	}
}

export default function App({ config }) {
	const [state, setState] = useState({ loading: true, session: null })

	useEffect(() => {
		let active = true
		resolveSession(config).then((session) => {
			if (active) {
				setState({ loading: false, session })
			}
		})
		return () => { active = false }
	}, [config])

	return (
		<div className={`portaliq-shell theme-${config.theme}`}>
			<header className="portaliq-header">
				<span className="portaliq-org">{config.organisationName}</span>
			</header>

			<main className="portaliq-main">
				{state.loading && <p>…</p>}

				{!state.loading && !state.session && (
					<section className="portaliq-login">
						<h1>Welkom</h1>
						<p>Log in om uw gegevens te bekijken.</p>
						{/* TODO (supplier-portal T02/T12): eHerkenning login handshake for suppliers,
						    DigiD for clients — driven by config.audience + config.idp. */}
						<button type="button" disabled>
							{config.audience === 'supplier' ? 'Inloggen met eHerkenning' : 'Inloggen met DigiD'}
						</button>
					</section>
				)}

				{!state.loading && state.session && (
					<section className="portaliq-home">
						<h1>Mijn overzicht</h1>
						{/* TODO (supplier-portal T04–T07): render registered portal contributions
						    (collections + actions) read via OpenRegister, plus the unified inbox. */}
						<p>Geen bijdragen om weer te geven.</p>
					</section>
				)}
			</main>
		</div>
	)
}
