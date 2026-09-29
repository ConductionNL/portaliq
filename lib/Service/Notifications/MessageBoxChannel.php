<?php

/**
 * Decides whether a new inbox message also goes to the resident's government
 * message box, and queues the send.
 *
 * Three things must hold (inbox-berichtenbox-channel, REQ-MBC-001, REQ-MBC-003,
 * REQ-MBC-005): the inbox collection names a recipient method on its app's
 * provider, the resident's organisation offers the channel, and the resident
 * did not switch it off. The queued job carries the message's reference and
 * the method's name, never a recipient: portaliq holds none, and asks the case
 * app for it only when the job runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\BackgroundJob\MessageBoxDispatchJob;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Queues a message box send when the organisation and the resident allow it.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
 */
class MessageBoxChannel {

	/**
	 * The channel's name in the notification log and the job argument.
	 *
	 * @var string
	 */
	public const CHANNEL = 'messageBox';

	/**
	 * Wire the channel.
	 *
	 * @param PortalOrganisationConfigService $orgConfig Whether the organisation offers it.
	 * @param IJobList                        $jobList   Queues the send.
	 * @param LoggerInterface                 $logger    The logger.
	 */
	public function __construct(
		private readonly PortalOrganisationConfigService $orgConfig,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the resident wants letters in the message box. A missing choice
	 * means on (REQ-MBC-005).
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-resident-can-switch-the-channel-off-req-mbc-005
	 */
	public function wants(array $account): bool {
		$preferences = ($account['notificationPreferences'] ?? null);
		if (is_array($preferences) === false || is_array(($preferences[self::CHANNEL] ?? null)) === false) {
			return true;
		}

		return ($preferences[self::CHANNEL]['enabled'] ?? true) !== false;
	}//end wants()

	/**
	 * Queue the send for one new message, when it may go.
	 *
	 * @param array<string, mixed>  $account  The resident's account.
	 * @param array<string, string> $inbox    The inbox entry: app, collection, label, register, schema, scopeField, recipientProvider.
	 * @param string                $recordId The new message's id.
	 *
	 * @return bool Whether a send was queued.
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function enqueue(array $account, array $inbox, string $recordId): bool {
		$organisation = (string)($account['organisation'] ?? '');
		$subjectRef = (string)($account['subjectRef'] ?? '');
		if ($recordId === '' || $subjectRef === '' || ($inbox['recipientProvider'] ?? '') === '') {
			return false;
		}

		if ($this->orgConfig->messageBox(orgSlug: $organisation) === null || $this->wants(account: $account) === false) {
			return false;
		}

		try {
			$this->jobList->add(
				MessageBoxDispatchJob::class,
				[
					'subjectRef' => $subjectRef,
					'organisation' => $organisation,
					'audience' => (string)($account['audience'] ?? ''),
					'appId' => $inbox['app'],
					'ruleKey' => NotificationDispatchService::RULE_MESSAGE_CREATED,
					'channel' => self::CHANNEL,
					'recipientProvider' => $inbox['recipientProvider'],
					'record' => ['app' => $inbox['app'], 'collection' => $inbox['collection'], 'id' => $recordId, 'label' => $inbox['label']],
					'source' => ['register' => $inbox['register'], 'schema' => $inbox['schema'], 'scopeField' => $inbox['scopeField']],
				]
			);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: message box send was not queued', ['app' => $inbox['app'], 'reason' => $e->getMessage()]);
			return false;
		}

		return true;
	}//end enqueue()
}//end class
