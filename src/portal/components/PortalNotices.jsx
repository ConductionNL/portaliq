// SPDX-License-Identifier: EUPL-1.2
//
// Maintenance and warning notices above every page of the signed-in portal
// (operate-maintenance-notice). Deliberately not an alert role: a notice that
// is there on every page load must not interrupt a screen reader each time.
//
// @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001

import { useState } from 'react'
import { closedNotices, closeNotice, sessionStore, visibleNotices } from '../../shared/notices.js'

/**
 * @param {object} root0 Props.
 * @param {Array<object>} root0.notices The active notices from the runtime config.
 * @param {(key: string) => string} [root0.t] Translator.
 * @return {object|null}
 */
export default function PortalNotices({ notices, t }) {
	const translate = t || ((key) => key)
	const [closed, setClosed] = useState(() => closedNotices(sessionStore()))
	const shown = visibleNotices(notices, closed, Date.now())
	if (shown.length === 0) {
		return null
	}

	return (
		<section className="portaliq-notices" aria-label={translate('Notice')} data-testid="portal-notices">
			{shown.map((notice) => (
				<div key={notice.id} className={`utrecht-alert utrecht-alert--${notice.level === 'warning' ? 'warning' : 'info'}`} data-testid="portal-notice">
					<p className="utrecht-paragraph">
						{notice.message}
						{notice.linkUrl && (
							<>
								{' '}
								<a className="utrecht-link" href={notice.linkUrl}>{notice.linkLabel || translate('More information')}</a>
							</>
						)}
					</p>
					<button
						type="button"
						className="utrecht-button utrecht-button--subtle"
						onClick={() => {
							closeNotice(sessionStore(), notice.id)
							setClosed([...closed, notice.id])
						}}
					>
						{translate('Close this notice')}
					</button>
				</div>
			))}
		</section>
	)
}
