// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// "My details" (identity-registered-details T05, T06, T07). What the base
// registrations hold about the signed-in person: the BRP record for a
// resident, the KvK record for a business user. Read when the section opens,
// never stored. Each empty state says why, so an empty record never reads as
// "the registration holds nothing". The request links appear only when the
// portal bound a published form to them.

import { useEffect, useState } from 'react'
import Loading from './Loading.jsx'

/**
 * The sentence for an answer that holds no record, as an English source key.
 *
 * @param {string} reason The reason the server gave.
 * @return {string} The sentence key.
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-an-unavailable-source-says-so-req-ird-003
 */
export function reasonText(reason) {
	if (reason === 'no_registration_identifier') {
		return 'The portal cannot show registered details for this way of signing in.'
	}

	if (reason === 'not_found') {
		return 'No registered details were found for you.'
	}

	if (reason === 'no_account') {
		return 'There is no portal account for this sign-in.'
	}

	return 'Your registered details cannot be shown right now.'
}

/**
 * A date of birth in the reader's language, or the raw value.
 *
 * @param {string} value An ISO date.
 * @param {string} locale The reader's locale.
 * @return {string} The date.
 */
function formatDate(value, locale) {
	const time = Date.parse(`${value || ''}T00:00:00Z`)
	if (Number.isNaN(time)) {
		return value || ''
	}

	try {
		return new Intl.DateTimeFormat(locale || 'nl', { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(time))
	} catch {
		return value
	}
}

/**
 * One label and value row.
 *
 * @param {object} props Props.
 * @param {string} props.label The label.
 * @param {string} props.value The value.
 * @return {object|null} The row, or nothing for an empty value.
 */
function Row({ label, value }) {
	if (!value) {
		return null
	}

	return (
		<div className="portaliq-details__row">
			<dt>{label}</dt>
			<dd>{value}</dd>
		</div>
	)
}

/**
 * The resident's BRP record.
 *
 * @param {object} props Props.
 * @param {object} props.person The mapped person.
 * @param {object} props.links The request links.
 * @param {Function} props.t The translator.
 * @param {string} props.locale The reader's locale.
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-resident-sees-their-own-brp-record-req-ird-001
 */
function PersonRecord({ person, links, t, locale }) {
	const address = person.address || {}
	const count = person.residentsAtAddress
	return (
		<>
			<dl className="portaliq-details__list">
				<Row label={t('Name')} value={person.name} />
				<Row label={t('Date of birth')} value={formatDate(person.birthDate, locale)} />
			</dl>
			<h3>{t('Address')}</h3>
			<p>
				{[address.street, address.number].filter(Boolean).join(' ')}
				<br />
				{[address.postcode, address.city].filter(Boolean).join(' ')}
			</p>
			<p>
				{Number.isInteger(count)
					? t('{count} people are registered at this address.', { count })
					: t('The number of residents at this address is not available.')}
			</p>
			{links?.addressInvestigation && (
				<p><a href={links.addressInvestigation}>{t('Something wrong at this address?')}</a></p>
			)}
		</>
	)
}

/**
 * The company's KvK record with its branches.
 *
 * @param {object} props Props.
 * @param {object} props.company The mapped company.
 * @param {Function} props.t The translator.
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md#requirement-a-business-user-sees-their-companys-kvk-record-req-ird-002
 */
function CompanyRecord({ company, t }) {
	const branches = Array.isArray(company.branches) ? company.branches : []
	return (
		<>
			<dl className="portaliq-details__list">
				<Row label={t('Trade name')} value={company.tradeName} />
				<Row label={t('KvK number')} value={company.kvkNumber} />
				<Row label={t('Legal form')} value={company.legalForm} />
			</dl>
			<h3>{t('Branches')}</h3>
			{branches.length === 0
				? <p>{t('The KvK lists no branches for this company.')}</p>
				: (
					<ul className="portaliq-details__branches">
						{branches.map((branch) => (
							<li key={branch.number}>
								<strong>{branch.name}</strong>
								{branch.main && <> ({t('Main branch')})</>}
								<br />
								{t('Branch number {number}', { number: branch.number })}
								{branch.address && <><br />{branch.address}</>}
							</li>
						))}
					</ul>
				)}
		</>
	)
}

/**
 * The "My details" section.
 *
 * @param {object} props Props.
 * @param {object} props.api The portal API adapter.
 * @param {Function} props.t The translator.
 * @param {string} [props.locale] The reader's locale.
 * @param {object|null} [props.initialDetails] An answer to show without fetching (test seam).
 * @return {object} The element.
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
export default function RegisteredDetailsPage({ api, t, locale = 'nl', initialDetails = null }) {
	const [details, setDetails] = useState(initialDetails)

	useEffect(() => {
		if (initialDetails !== null) {
			return undefined
		}

		let live = true
		api.fetchRegisteredDetails().then((answer) => {
			if (live) {
				setDetails(answer)
			}
		})
		return () => {
			live = false
		}
	}, [api, initialDetails])

	if (details === null) {
		return <Loading t={t} />
	}

	const links = details.links || {}
	return (
		<section className="portaliq-details" aria-labelledby="portaliq-details-title">
			<h2 id="portaliq-details-title">{t('My details')}</h2>
			{details.available !== true && <p role="status">{t(reasonText(details.reason))}</p>}
			{details.available === true && details.kind === 'person' && (
				<>
					<p>{t('These are the details the Personal Records Database (BRP) holds about you.')}</p>
					<PersonRecord person={details.person || {}} links={links} t={t} locale={locale} />
				</>
			)}
			{details.available === true && details.kind === 'company' && (
				<>
					<p>{t('These are the details the Chamber of Commerce (KvK) holds about your company.')}</p>
					<CompanyRecord company={details.company || {}} t={t} />
				</>
			)}
			{details.available === true && links.correction && (
				<p><a href={links.correction}>{t('Report an error in these details')}</a></p>
			)}
		</section>
	)
}
