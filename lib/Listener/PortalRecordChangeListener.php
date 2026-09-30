<?php

/**
 * Tells a resident when a record they follow in the portal changes.
 *
 * A case app declares a change rule on one of its collections (REQ-NAP-001).
 * When OpenRegister reports an update to a record of that collection and the
 * rule's field differs between the old and the new record, the resident whose
 * reference the record holds gets a message in their portal inbox, with a
 * link to the record, and the rule's key is dispatched so the e-mail and push
 * preferences decide what else reaches them (REQ-NAP-002).
 *
 * The same listener hears new records in a case app's own `kind: inbox`
 * collection and dispatches `message.created` for them, so a handler's
 * message gets the same e-mail nudge portaliq's own messages get (REQ-NAP-004),
 * and hands them to the message box channel, which queues a send to the
 * resident's government message box when that may go (inbox-berichtenbox-channel).
 *
 * 🔴 IT NEVER FAILS A SAVE. It runs inside OpenRegister's save of somebody
 * else's record; every failure is caught and logged. A missed notice is a
 * nuisance, a failed save is data loss.
 *
 * 🔴 NO OLD RECORD, NO NOTICE. A change it cannot see is not reported.
 *
 * 🔴 NOT THE RESIDENT'S OWN WRITE. Saves portaliq makes on the resident's
 * behalf run inside PortalWriteContext and are skipped (REQ-NAP-003).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Listener
 * @package  OCA\Portaliq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 *
 * @template-implements IEventListener<Event>
 */

declare(strict_types=1);

namespace OCA\Portaliq\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Portaliq\Service\NotificationDispatchService;
use OCA\Portaliq\Service\Notifications\MessageBoxChannel;
use OCA\Portaliq\Service\Notifications\PortalChangeRuleIndex;
use OCA\Portaliq\Service\PortalAccountService;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalWriteContext;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the inbox message for a declared change and dispatches the rule.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 *
 * @template-implements IEventListener<Event>
 */
class PortalRecordChangeListener implements IEventListener {

	/**
	 * The inbox message's subject, `%1$s` the record's title.
	 *
	 * @var string
	 */
	public const SUBJECT_KEY = '%1$s has been updated';

	/**
	 * The inbox message's body. It carries no field value of the record.
	 *
	 * @var string
	 */
	public const BODY_KEY = 'Open it to see what changed.';

	/**
	 * Wire the listener.
	 *
	 * @param PortalChangeRuleIndex       $rules        Which apps want a notice for a record.
	 * @param PortalWriteContext          $writeContext Whether portaliq itself is writing.
	 * @param PortalAccountService        $accounts     Finds the resident's account.
	 * @param PortalObjectWriter          $writer       Writes the inbox message.
	 * @param NotificationDispatchService $dispatch     Dispatches the rule's key.
	 * @param IFactory                    $l10nFactory  The message text, Dutch and English.
	 * @param LoggerInterface             $logger       The logger.
	 * @param MessageBoxChannel|null      $messageBox   Queues the government message box send.
	 */
	public function __construct(
		private readonly PortalChangeRuleIndex $rules,
		private readonly PortalWriteContext $writeContext,
		private readonly PortalAccountService $accounts,
		private readonly PortalObjectWriter $writer,
		private readonly NotificationDispatchService $dispatch,
		private readonly IFactory $l10nFactory,
		private readonly LoggerInterface $logger,
		private readonly ?MessageBoxChannel $messageBox = null,
	) {
	}//end __construct()

	/**
	 * Handle an OpenRegister create or update.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false && ($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		if ($this->writeContext->isActive() === true) {
			return;
		}

		try {
			if ($event instanceof ObjectUpdatedEvent) {
				$this->onUpdated(event: $event);
				return;
			}

			$this->onCreated(event: $event);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: record change notice failed; the save itself stands', ['reason' => $e->getMessage()]);
		}
	}//end handle()

	/**
	 * A record changed: report each matching rule whose field differs.
	 *
	 * @param ObjectUpdatedEvent $event The event.
	 *
	 * @return void
	 */
	private function onUpdated(ObjectUpdatedEvent $event): void {
		$old = $event->getOldObject();
		if ($old === null) {
			return;
		}

		$new = $event->getNewObject();
		$rules = $this->rules->changeRulesFor(register: (string)$new->getRegister(), schema: (string)$new->getSchema());
		if ($rules === []) {
			return;
		}

		$newData = $new->getObject();
		$oldData = $old->getObject();
		foreach ($rules as $rule) {
			$field = $rule['field'];
			if (($newData[$field] ?? null) === ($oldData[$field] ?? null)) {
				continue;
			}

			$account = $this->account(data: $newData, scopeField: $rule['scopeField']);
			if ($account === null) {
				continue;
			}

			$recordId = (string)($new->getUuid() ?? '');
			$title = $this->title(data: $newData, rule: $rule);
			$this->writeMessage(account: $account, title: $title, recordLink: ['app' => $rule['app'], 'collection' => $rule['collection'], 'id' => $recordId]);
			$this->dispatch->dispatch(
				ruleKey: $rule['ruleKey'],
				appId: $rule['app'],
				subject: $this->subject(account: $account),
				record: ['app' => $rule['app'], 'collection' => $rule['collection'], 'id' => $recordId, 'label' => $rule['label']]
			);
		}//end foreach
	}//end onUpdated()

	/**
	 * A record was created: a case app's inbox message gets its e-mail nudge.
	 *
	 * @param ObjectCreatedEvent $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-apps-message-triggers-an-e-mail-req-nap-004
	 */
	private function onCreated(ObjectCreatedEvent $event): void {
		$object = $event->getObject();
		$register = (string)$object->getRegister();
		$schema = (string)$object->getSchema();

		// Portaliq's own messages are dispatched by whoever wrote them. A
		// message ANOTHER app wrote with a rule key is sent on here
		// (woo-journey-entry-points D4); portaliq's own writes never reach
		// this point, the write-context guard in handle() stops them.
		if ($this->rules->isPortalMessage(register: $register, schema: $schema) === true) {
			$this->onForeignMessage(data: $object->getObject(), recordId: (string)($object->getUuid() ?? ''));
			return;
		}

		$data = $object->getObject();
		$told = [];
		foreach ($this->rules->inboxesFor(register: $register, schema: $schema) as $inbox) {
			$account = $this->account(data: $data, scopeField: $inbox['scopeField']);
			if ($account === null) {
				continue;
			}

			// One message is one nudge, however many audiences list the inbox.
			$key = $inbox['app'].'|'.(string)($account['subjectRef'] ?? '');
			if (isset($told[$key]) === true) {
				continue;
			}

			$told[$key] = true;
			if ($inbox['nudge'] === '1') {
				$this->dispatch->dispatch(
					ruleKey: NotificationDispatchService::RULE_MESSAGE_CREATED,
					appId: $inbox['app'],
					subject: $this->subject(account: $account)
				);
			}

			// The same message may also go to the government message box
			// (inbox-berichtenbox-channel); the channel decides whether it may.
			$this->messageBox?->enqueue(account: $account, inbox: $inbox, recordId: (string)($object->getUuid() ?? ''));
		}//end foreach
	}//end onCreated()

	/**
	 * Send the email for a portalMessage another app wrote with a `ruleKey`.
	 *
	 * The app is the part of the rule key before its first dot, so a message
	 * cannot borrow another app's key: `dispatch()` sends only when THAT app
	 * declares it. No Berichtenbox job: the journey that needs this sends
	 * inbox and email only (hydra woo-citizen-journey, #730).
	 *
	 * @param array<string, mixed> $data     The message.
	 * @param string               $recordId The message's id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-journey-entry-points/specs/portal-notifications-and-preferences/spec.md#requirement-another-apps-notice-with-a-declared-rule-key-must-be-sent-by-email-req-wje-006
	 */
	private function onForeignMessage(array $data, string $recordId): void {
		$ruleKey = ($data['ruleKey'] ?? null);
		if (is_string($ruleKey) === false || preg_match('/^([a-z][a-z0-9_-]*)\.[A-Za-z0-9_.-]+$/', $ruleKey, $match) !== 1) {
			return;
		}

		$account = $this->account(data: $data, scopeField: 'subjectRef');
		if ($account === null) {
			return;
		}

		$link = ($data['recordLink'] ?? null);
		$record = ['id' => $recordId];
		if (is_array($link) === true) {
			$record = array_intersect_key($link, array_flip(['app', 'collection', 'id']));
		}

		$this->dispatch->dispatch(ruleKey: $ruleKey, appId: $match[1], subject: $this->subject(account: $account), record: $record);
	}//end onForeignMessage()

	/**
	 * The resident's account for the reference the record holds, or null.
	 *
	 * @param array<string, mixed> $data       The record.
	 * @param string               $scopeField The collection's scope field.
	 *
	 * @return array<string, mixed>|null
	 */
	private function account(array $data, string $scopeField): ?array {
		$subjectRef = ($data[$scopeField] ?? null);
		if (is_string($subjectRef) === false || $subjectRef === '') {
			return null;
		}

		return $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
	}//end account()

	/**
	 * The subject the dispatch resolves the manifest for.
	 *
	 * @param array<string, mixed> $account The account.
	 *
	 * @return array<string, string>
	 */
	private function subject(array $account): array {
		return [
			'subjectRef' => (string)($account['subjectRef'] ?? ''),
			'organisation' => (string)($account['organisation'] ?? ''),
			'audience' => (string)($account['audience'] ?? ''),
			'trust' => 'high',
		];
	}//end subject()

	/**
	 * The record's title: its title field, else the collection label.
	 *
	 * @param array<string, mixed>  $data The record.
	 * @param array<string, string> $rule The rule.
	 *
	 * @return string
	 */
	private function title(array $data, array $rule): string {
		$value = ($data[$rule['titleField']] ?? null);
		if ($rule['titleField'] !== '' && (is_string($value) === true || is_int($value) === true) && (string)$value !== '') {
			return (string)$value;
		}

		return $rule['label'];
	}//end title()

	/**
	 * Write the inbox message, Dutch first and English second, as every
	 * portaliq message is.
	 *
	 * @param array<string, mixed>  $account    The account.
	 * @param string                $title      The record's title.
	 * @param array<string, string> $recordLink The record the message is about.
	 *
	 * @return void
	 */
	private function writeMessage(array $account, string $title, array $recordLink): void {
		$dutch = $this->l10nFactory->get('portaliq', 'nl');
		$english = $this->l10nFactory->get('portaliq', 'en');
		$written = $this->writer->createObject(
			register: 'portaliq',
			schema: 'portalMessage',
			scopeField: 'subjectRef',
			subjectRef: (string)($account['subjectRef'] ?? ''),
			organisation: (string)($account['organisation'] ?? ''),
			data: [
				'subject' => $dutch->t(self::SUBJECT_KEY, [$title]).' / '.$english->t(self::SUBJECT_KEY, [$title]),
				'body' => $dutch->t(self::BODY_KEY)."\n\n".$english->t(self::BODY_KEY),
				'read' => false,
				'receivedAt' => gmdate('c'),
				'recordLink' => $recordLink,
			]
		);
		if ($written === null) {
			$this->logger->warning('Portaliq: change message was not written', ['app' => $recordLink['app'], 'collection' => $recordLink['collection']]);
		}
	}//end writeMessage()
}//end class
