// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2
//
// The words of the collection pages, in Dutch and English. Keys are the
// English source text, as in src/shared/i18n/*.json; where the React portal
// already had a key, it is reused with its text. Strings the React portal
// wrote in Dutch only get an English key here.
//
// Imports nothing, so tests/site-collections.spec.mjs reads it as node.
//
// @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-contribution-page-must-render-its-blocks-req-srp-014

export default {
	nl: {
		'Loading…': 'Laden…',
		Other: 'Overig',
		'No items.': 'Geen items.',
		'No messages.': 'Geen berichten.',
		Actions: 'Acties',
		Open: 'Openen',
		Yes: 'Ja',
		No: 'Nee',
		'Select an item.': 'Kies een item.',
		'Add an attachment': 'Bijlage toevoegen',
		'File added: {name}': 'Bestand toegevoegd: {name}',
		'The upload did not work.': 'Uploaden is niet gelukt.',
		'The download did not work.': 'Downloaden is niet gelukt.',
		Attachments: 'Bijlagen',
		'File {id}': 'Bestand {id}',
		'What happened': 'Wat er is gebeurd',
		'Nothing has happened yet.': 'Er is nog niets gebeurd.',
		'This record is not in your list, so nothing of it is shown.':
			'Dit staat niet in uw lijst, dus we tonen er niets van.',
		'In this dossier': 'In dit dossier',
		'The items could not be loaded.': 'De inhoud kon niet worden geladen.',
		'Removed.': 'Verwijderd.',
		'This can no longer be done for this item.':
			'Dit kan voor dit item niet meer.',
		'Nothing in this dossier yet.': 'Er staat nog niets in dit dossier.',
		'No longer public': 'Niet meer openbaar',
		Remove: 'Verwijderen',
		'Remove {title}': '{title} verwijderen',
	},
	en: {
		'Loading…': 'Loading…',
		Other: 'Other',
		'No items.': 'No items.',
		'No messages.': 'No messages.',
		Actions: 'Actions',
		Open: 'Open',
		Yes: 'Yes',
		No: 'No',
		'Select an item.': 'Select an item.',
		'Add an attachment': 'Add an attachment',
		'File added: {name}': 'File added: {name}',
		'The upload did not work.': 'The upload did not work.',
		'The download did not work.': 'The download did not work.',
		Attachments: 'Attachments',
		'File {id}': 'File {id}',
		'What happened': 'What happened',
		'Nothing has happened yet.': 'Nothing has happened yet.',
		'This record is not in your list, so nothing of it is shown.':
			'This record is not in your list, so nothing of it is shown.',
		'In this dossier': 'In this dossier',
		'The items could not be loaded.': 'The items could not be loaded.',
		'Removed.': 'Removed.',
		'This can no longer be done for this item.':
			'This can no longer be done for this item.',
		'Nothing in this dossier yet.': 'Nothing in this dossier yet.',
		'No longer public': 'No longer public',
		Remove: 'Remove',
		'Remove {title}': 'Remove {title}',
	},
}
