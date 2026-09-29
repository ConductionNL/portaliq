<?php

/**
 * Sends one inbox message to the resident's government message box, through
 * integriq's digital post adapter, and records the attempt.
 *
 * It asks the case app for the recipient, puts it into integriq's
 * DigitalPostSendRequestedEvent, and lets it go: the recipient is written to
 * no object, no log line and no response (inbox-berichtenbox-channel,
 * REQ-MBC-002). Integriq absent, or an event nobody handled, is a refusal,
 * never a send (REQ-MBC-003).
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

use OCA\Portaliq\Contribution\PortalProviderLocator;
use OCA\Portaliq\Contribution\TimelineProviderMethod;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalOrganisationConfigService;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Requests the send from integriq and logs what it answered.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
 */
class MessageBoxSender {

	/**
	 * Integriq's send command, named as a string so portaliq does not depend
	 * on integriq being installed.
	 *
	 * @var string
	 */
	public const SEND_EVENT = 'OCA\\Integriq\\Event\\DigitalPostSendRequestedEvent';

	/**
	 * Who portaliq says asked for the letter; integriq's status reports carry it back.
	 *
	 * @var string
	 */
	public const REQUESTED_BY = 'portaliq';

	/**
	 * Wire the sender.
	 *
	 * @param PortalOrganisationConfigService $orgConfig The organisation's source.
	 * @param PortalProviderLocator           $locator   Finds the case app's provider.
	 * @param PortalObjectReader              $reader    Reads the message, scoped to the resident.
	 * @param PortalObjectWriter              $writer    Writes the notification row.
	 * @param IEventDispatcher                $events    Dispatches integriq's command.
	 * @param LoggerInterface                 $logger    The logger; never given the recipient.
	 * @param string                          $sendEvent The command's class (a seam for "integriq is absent").
	 */
	public function __construct(
		private readonly PortalOrganisationConfigService $orgConfig,
		private readonly PortalProviderLocator $locator,
		private readonly PortalObjectReader $reader,
		private readonly PortalObjectWriter $writer,
		private readonly IEventDispatcher $events,
		private readonly LoggerInterface $logger,
		private readonly string $sendEvent = self::SEND_EVENT,
	) {
	}//end __construct()

	/**
	 * Send one message box job and record the attempt.
	 *
	 * @param array<string, mixed> $argument The job argument MessageBoxChannel queued.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-portaliq-asks-integriq-to-send-and-records-the-answer-req-mbc-003
	 */
	public function send(array $argument): void {
		$organisation = (string)($argument['organisation'] ?? '');
		$record = $this->record(argument: $argument);
		$offer = $this->orgConfig->messageBox(orgSlug: $organisation);
		$accountId = $this->accountId(subjectRef: (string)($argument['subjectRef'] ?? ''), organisation: $organisation);
		if ($offer === null || $record === null || $accountId === null) {
			return;
		}

		$message = $this->message(argument: $argument, record: $record);
		if ($message === null) {
			$this->logger->warning(
				'Portaliq: message box send skipped, the message could not be read',
				['app' => $record['app'], 'collection' => $record['collection']]
			);
			return;
		}

		$recipient = $this->recipient(record: $record, method: (string)($argument['recipientProvider'] ?? ''));
		if ($recipient === null) {
			// The case app keeps this message in the portal (REQ-MBC-002).
			return;
		}

		$letter = $this->letter(message: $message, fields: (array)($argument['letterFields'] ?? []));
		$outcome = $this->request(sourceId: $offer['sourceId'], recipient: $recipient, letter: $letter, record: $record);

		unset($recipient);

		$data = [
			'ruleKey' => (string)($argument['ruleKey'] ?? ''),
			'appId' => $record['app'],
			'channel' => MessageBoxChannel::CHANNEL,
			'attempts' => 0,
			'lastAttemptAt' => gmdate('c'),
			'recordLink' => ['app' => $record['app'], 'collection' => $record['collection'], 'id' => $record['id']],
		] + $outcome;

		$written = $this->writer->createObject(
			register: 'portaliq',
			schema: 'portalNotification',
			scopeField: 'accountRef',
			subjectRef: $accountId,
			organisation: $organisation,
			data: $data
		);
		if ($written === null) {
			$this->logger->warning('Portaliq: message box attempt was not logged', ['app' => $record['app'], 'status' => $outcome['status']]);
		}
	}//end send()

	/**
	 * Ask integriq to send the letter.
	 *
	 * @param string                $sourceId  The organisation's digital post source.
	 * @param string                $recipient The recipient identity; it goes into the event only.
	 * @param array<string, mixed>  $letter    The letter: subject, body, attachments.
	 * @param array<string, string> $record    The message's reference.
	 *
	 * @return array<string, string> `status`, and `externalMessageId` or `refusalCode`.
	 */
	private function request(string $sourceId, string $recipient, array $letter, array $record): array {
		if ($letter['body'] === '') {
			// Never an empty letter: the resident would receive a blank
			// message in their government message box. Refused and recorded.
			$this->logger->warning(
				'Portaliq: message box send refused, the message has no text',
				['app' => $record['app'], 'collection' => $record['collection'], 'id' => $record['id']]
			);
			return ['status' => 'failed', 'refusalCode' => 'empty_body'];
		}

		if (class_exists($this->sendEvent) === false) {
			return ['status' => 'failed', 'refusalCode' => 'not_installed'];
		}

		$event = new ($this->sendEvent)(
			sourceApp: self::REQUESTED_BY,
			sourceId: $sourceId,
			recipient: $recipient,
			subject: $letter['subject'],
			body: $letter['body'],
			attachments: $letter['attachments'],
			requestedBy: self::REQUESTED_BY,
			correlationId: $record['app'].'/'.$record['collection'].'/'.$record['id']
		);

		try {
			$this->events->dispatchTyped($event);
		} catch (Throwable $e) {
			// The exception's message may quote the recipient: log its class only.
			$this->logger->warning('Portaliq: message box send request failed', ['exception' => get_class($e)]);
			return ['status' => 'failed', 'refusalCode' => 'dispatch_failed'];
		}

		$refusal = $event->getRefusal();
		if (is_array($refusal) === true) {
			return ['status' => 'failed', 'refusalCode' => (string)($refusal['code'] ?? 'refused')];
		}

		$messageId = $event->getMessageId();
		if ($event->isHandled() === false || is_string($messageId) === false || $messageId === '') {
			return ['status' => 'failed', 'refusalCode' => 'unhandled'];
		}

		$early = (new MessageBoxStatus())->take(messageId: $messageId);

		return ['status' => ($early ?? 'sent'), 'externalMessageId' => $messageId];
	}//end request()

	/**
	 * The resident's account id, the notification row's scope, or null when
	 * the account is gone.
	 *
	 * @param string $subjectRef   The resident.
	 * @param string $organisation The organisation.
	 *
	 * @return string|null
	 */
	private function accountId(string $subjectRef, string $organisation): ?string {
		if ($subjectRef === '') {
			return null;
		}

		$accounts = $this->reader->readCollection(
			register: 'portaliq',
			schema: 'portalAccount',
			scopeField: 'subjectRef',
			subjectRef: $subjectRef,
			organisation: $organisation,
			limit: 2
		);
		$account = ($accounts[0] ?? []);
		$id = ($account['@self']['id'] ?? $account['id'] ?? $account['uuid'] ?? null);
		if (is_string($id) === false && is_int($id) === false) {
			return null;
		}

		return (string)$id;
	}//end accountId()

	/**
	 * The message's reference from the job, or null when it is incomplete.
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return array<string, string>|null
	 */
	private function record(array $argument): ?array {
		$record = ($argument['record'] ?? null);
		if (is_array($record) === false) {
			return null;
		}

		$out = [];
		foreach (['app', 'collection', 'id'] as $key) {
			$value = ($record[$key] ?? null);
			if (is_string($value) === false || $value === '') {
				return null;
			}

			$out[$key] = $value;
		}

		return $out;
	}//end record()

	/**
	 * The message, read the way the resident's own inbox reads it.
	 *
	 * @param array<string, mixed>  $argument The job argument.
	 * @param array<string, string> $record   The message's reference.
	 *
	 * @return array<string, mixed>|null
	 */
	private function message(array $argument, array $record): ?array {
		$source = ($argument['source'] ?? null);
		if (is_array($source) === false) {
			return null;
		}

		return $this->reader->readObject(
			register: (string)($source['register'] ?? ''),
			schema: (string)($source['schema'] ?? ''),
			scopeField: (string)($source['scopeField'] ?? 'subjectRef'),
			subjectRef: (string)($argument['subjectRef'] ?? ''),
			id: $record['id'],
			organisation: (string)($argument['organisation'] ?? '')
		);
	}//end message()

	/**
	 * Ask the case app for the recipient of this message, or null.
	 *
	 * @param array<string, string> $record The message's reference.
	 * @param string                $method The declared recipient method.
	 *
	 * @return string|null
	 */
	private function recipient(array $record, string $method): ?string {
		$provider = $this->locator->locate(appId: $record['app']);
		if ($provider === null || (new TimelineProviderMethod())->callableOn(provider: $provider, method: $method) === false) {
			return null;
		}

		try {
			$recipient = $provider->{$method}($record['id']);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Portaliq: message box recipient method failed',
				['app' => $record['app'], 'method' => $method, 'exception' => get_class($e)]
			);
			return null;
		}

		if (is_string($recipient) === false || trim($recipient) === '') {
			return null;
		}

		return trim($recipient);
	}//end recipient()

	/**
	 * The letter from the message: the text and subject from the fields the
	 * collection declares, else the first usual name that holds text.
	 *
	 * Dossiq keeps a portal letter's text in `content`; reading `body` alone
	 * sent it with an empty body.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param array<string, mixed> $fields  The declared `body` and `subject` field names.
	 *
	 * @return array{subject: string, body: string, attachments: array<int, array<string, mixed>>}
	 */
	private function letter(array $message, array $fields): array {
		return [
			'subject'     => $this->firstText(message: $message, names: [(string)($fields['subject'] ?? ''), 'subject', 'title']),
			'body'        => $this->firstText(message: $message, names: [(string)($fields['body'] ?? ''), 'body', 'content', 'text']),
			'attachments' => $this->attachments(value: ($message['attachments'] ?? null)),
		];
	}//end letter()

	/**
	 * The first of the named fields that holds non-blank text, or ''.
	 *
	 * @param array<string, mixed> $message The message.
	 * @param array<int, string>   $names   The field names, in order.
	 *
	 * @return string
	 */
	private function firstText(array $message, array $names): string {
		foreach ($names as $name) {
			$value = $this->text(value: ($message[$name] ?? null));
			if ($name !== '' && trim($value) !== '') {
				return $value;
			}
		}

		return '';
	}//end firstText()

	/**
	 * A text value, or ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_string($value) === false) {
			return '';
		}

		return $value;
	}//end text()

	/**
	 * The message's attachment references as a list of arrays, the shape
	 * integriq takes. Integriq resolves them or refuses.
	 *
	 * @param mixed $value The message's attachments.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function attachments(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $attachment) {
			if (is_array($attachment) === true) {
				$out[] = $attachment;
			} elseif (is_string($attachment) === true && $attachment !== '') {
				$out[] = ['id' => $attachment];
			}
		}

		return $out;
	}//end attachments()
}//end class
