<?php

/**
 * Portaliq Change Message Writer
 *
 * Writes the inbox message of a fired change rule, in the portal's language.
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\Portaliq\Kernel\Contracts\PortalNoticeLanguageInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use Psr\Log\LoggerInterface;

/**
 * The inbox-writing half of PortalRecordChangeListener, built by it for each
 * message.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 */
final class ChangeMessageWriter {
	/**
	 * Constructor.
	 *
	 * @param PortalObjectWriterInterface   $writer   Writes the inbox message.
	 * @param PortalNoticeLanguageInterface $language The message text, in the portal's language.
	 * @param LoggerInterface      $logger   Records a message that was not written.
	 */
	public function __construct(
		private readonly PortalObjectWriterInterface $writer,
		private readonly PortalNoticeLanguageInterface $language,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the inbox message in the portal's language.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param ChangeMessage        $message The message.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
	 */
	public function write(array $account, ChangeMessage $message): void {
		$words = $this->messageWords(account: $account, message: $message);
		$written = $this->writer->createObject(
			register: 'portaliq',
			schema: 'portalMessage',
			scopeField: 'subjectRef',
			subjectRef: (string)($account['subjectRef'] ?? ''),
			organisation: (string)($account['organisation'] ?? ''),
			data: [
				'subject' => $words['subject'],
				'body' => $words['body'],
				'read' => false,
				'receivedAt' => gmdate('c'),
				'recordLink' => $message->recordLink,
			]
		);
		if ($written === null) {
			$this->logger->warning(
				'Portaliq: change message was not written',
				['app' => $message->recordLink['app'], 'collection' => $message->recordLink['collection']]
			);
		}
	}//end write()

	/**
	 * The message's subject and body: the app's own words, else portaliq's in
	 * the portal's language.
	 *
	 * @param array<string, mixed> $account The account.
	 * @param ChangeMessage        $message The message.
	 *
	 * @return array{subject: string, body: string}
	 */
	private function messageWords(array $account, ChangeMessage $message): array {
		$l10n = $this->language->forOrganisation(organisation: (string)($account['organisation'] ?? ''));
		$subject = ($message->text['subject'] ?? '');
		if ($subject === '') {
			$subject = $l10n->t(PortalRecordChangeListener::SUBJECT_KEY, [$message->title]);
		}

		$body = ($message->text['body'] ?? '');
		if ($body === '') {
			$body = $l10n->t(PortalRecordChangeListener::BODY_KEY);
		}

		return ['subject' => $subject, 'body' => $body];
	}//end messageWords()
}//end class
