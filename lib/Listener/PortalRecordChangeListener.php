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
 * The message is in ONE language: the first locale of the organisation's
 * portal, else Dutch. Never Dutch and English glued into one string.
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
use OCA\Portaliq\Kernel\Contracts\ChangeRuleNoticesInterface;
use OCA\Portaliq\Kernel\Contracts\MessageBoxChannelInterface;
use OCA\Portaliq\Kernel\Contracts\NotificationDispatchServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalAccountServiceInterface;
use OCA\Portaliq\Kernel\Contracts\PortalChangeRuleIndexInterface;
use OCA\Portaliq\Kernel\Contracts\PortalNoticeLanguageInterface;
use OCA\Portaliq\Kernel\Contracts\PortalObjectWriterInterface;
use OCA\Portaliq\Kernel\Contracts\PortalWriteContextInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\Portaliq\Kernel\Contracts\NotificationRuleNormaliserInterface;
use OCA\Portaliq\Kernel\Contracts\PublicDetailShapeInterface;
use OCA\Portaliq\Kernel\Contracts\PublicIndexKindsInterface;

/**
 * Writes the inbox message for a declared change and dispatches the rule.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-declared-change-reaches-the-residents-inbox-req-nap-002
 *
 * @template-implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) -- the one listener for
 * every OpenRegister create and update meets OpenRegister's two events and
 * each delivery path a notice can take (inbox write, dispatch, message box,
 * claim-addressed recipients). Splitting it would mean two listeners reading
 * the same event and the same rule index, and a notice could be sent twice.
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
	 * @param PortalChangeRuleIndexInterface       $rules        Which apps want a notice for a record.
	 * @param PortalWriteContextInterface          $writeContext Whether portaliq itself is writing.
	 * @param PortalAccountServiceInterface        $accounts     Finds the resident's account.
	 * @param PortalObjectWriterInterface          $writer       Writes the inbox message.
	 * @param NotificationDispatchServiceInterface $dispatch     Dispatches the rule's key.
	 * @param PortalNoticeLanguageInterface        $language     The message text, in the portal's language.
	 * @param LoggerInterface             $logger       The logger.
	 * @param MessageBoxChannelInterface|null      $messageBox   Queues the government message box send.
	 * @param ChangeRuleNoticesInterface|null      $ruleNotices  Who a claim-addressed rule reaches, and a rule's own words.
	 */
	public function __construct(
		private readonly PortalChangeRuleIndexInterface $rules,
		private readonly PortalWriteContextInterface $writeContext,
		private readonly PortalAccountServiceInterface $accounts,
		private readonly PortalObjectWriterInterface $writer,
		private readonly NotificationDispatchServiceInterface $dispatch,
		private readonly PortalNoticeLanguageInterface $language,
		private readonly LoggerInterface $logger,
		private readonly ?MessageBoxChannelInterface $messageBox = null,
		private readonly ?ChangeRuleNoticesInterface $ruleNotices = null,
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

		$change = new RecordChange(
			newData: $new->getObject(),
			oldData: $old->getObject(),
			recordId: (string)($new->getUuid() ?? ''),
			organisation: (string)($new->getOrganisation() ?? '')
		);
		foreach ($rules as $rule) {
			$this->tellRule(change: $change, rule: $rule);
		}
	}//end onUpdated()

	/**
	 * Tell each recipient about one rule, when its field differs and, for a
	 * rule that declares its own words, when it has words for the new value.
	 *
	 * @param RecordChange          $change The record update.
	 * @param array<string, string> $rule   The rule.
	 *
	 * @return void
	 */
	private function tellRule(RecordChange $change, array $rule): void {
		$field = $rule['field'];
		if (($change->newData[$field] ?? null) === ($change->oldData[$field] ?? null)) {
			return;
		}

		// A rule that declares its own words speaks only for the values
		// it has words for (claim-addressed-change-notices).
		$messages = $this->rules->details(entry: $rule)['messages'];
		$value = $this->text(data: $change->newData, field: $field);
		if ($messages !== [] && isset($messages[$value]) === false) {
			return;
		}

		$notice = new ChangeNotice(rule: $rule, recordId: $change->recordId, value: $value);
		foreach ($this->recipients(rule: $rule, change: $change) as $recipient) {
			$this->tell(notice: $notice, recipient: $recipient);
		}
	}//end tellRule()

	/**
	 * Who hears about a change: the accounts a claim-addressed rule reaches,
	 * else the resident whose reference the record holds at the scope field.
	 *
	 * @param array<string, string> $rule   The rule.
	 * @param RecordChange          $change The record update.
	 *
	 * @return array<int, array<string, array<string, mixed>>> Each with `account` and `row`.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md
	 */
	private function recipients(array $rule, RecordChange $change): array {
		$data = $change->newData;
		if (($rule['recipientClaim'] ?? '') !== '') {
			return ($this->ruleNotices?->recipients(
				appId: $rule['app'],
				field: $rule['recipientField'],
				claim: $rule['recipientClaim'],
				collection: $this->rules->details(entry: $rule)['collection'],
				data: $data,
				record: ['id' => $change->recordId, 'organisation' => $change->organisation]
			) ?? []);
		}

		$account = $this->account(data: $data, scopeField: $rule['scopeField']);
		if ($account === null) {
			return [];
		}

		return [['account' => $account, 'row' => $data]];
	}//end recipients()

	/**
	 * Write one resident's inbox message and dispatch the rule's key.
	 *
	 * @param ChangeNotice                        $notice    The fired rule.
	 * @param array<string, array<string, mixed>> $recipient The resident's `account` and the `row` they may read.
	 *
	 * @return void
	 */
	private function tell(ChangeNotice $notice, array $recipient): void {
		$rule = $notice->rule;
		$account = $recipient['account'];
		(new ChangeMessageWriter(writer: $this->writer, language: $this->language, logger: $this->logger))->write(
			account: $account,
			message: new ChangeMessage(
				title: $this->title(data: $recipient['row'], rule: $rule),
				recordLink: ['app' => $rule['app'], 'collection' => $rule['collection'], 'id' => $notice->recordId],
				text: $this->ownWords(notice: $notice, recipient: $recipient)
			)
		);
		$this->dispatch->dispatch(
			ruleKey: $rule['ruleKey'],
			appId: $rule['app'],
			subject: $this->subject(account: $account),
			record: ['app' => $rule['app'], 'collection' => $rule['collection'], 'id' => $notice->recordId, 'label' => $rule['label']]
		);
	}//end tell()

	/**
	 * The rule's own words for the new value, in the resident's language, or
	 * null when the rule declares none.
	 *
	 * @param ChangeNotice                        $notice    The fired rule.
	 * @param array<string, array<string, mixed>> $recipient The resident's `account` and the `row` they may read.
	 *
	 * @return array<string, string>|null
	 */
	private function ownWords(ChangeNotice $notice, array $recipient): ?array {
		$messages = $this->rules->details(entry: $notice->rule)['messages'];
		if ($messages === []) {
			return null;
		}

		return $this->ruleNotices?->text(
			messages: $messages,
			value: $notice->value,
			row: $recipient['row'],
			language: $this->language->languageFor(organisation: (string)($recipient['account']['organisation'] ?? ''))
		);
	}//end ownWords()

	/**
	 * A scalar field value of the record as text; anything else is ''.
	 *
	 * @param array<string, mixed> $data  The record.
	 * @param string               $field The field.
	 *
	 * @return string
	 */
	private function text(array $data, string $field): string {
		$value = ($data[$field] ?? null);
		if (is_string($value) === true || is_int($value) === true) {
			return (string)$value;
		}

		return '';
	}//end text()

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

		$created = new CreatedRecord(data: $object->getObject(), recordId: (string)($object->getUuid() ?? ''));
		foreach ($this->rules->inboxesFor(register: $register, schema: $schema) as $inbox) {
			$this->reachInbox(created: $created, inbox: $inbox);
		}
	}//end onCreated()

	/**
	 * Nudge the resident of one inbox the new record belongs to, once per
	 * app and resident, and queue the government message box send.
	 *
	 * @param CreatedRecord        $created The created record.
	 * @param array<string, mixed> $inbox   The inbox that lists it.
	 *
	 * @return void
	 */
	private function reachInbox(CreatedRecord $created, array $inbox): void {
		$account = $this->account(data: $created->data, scopeField: $inbox['scopeField']);
		if ($account === null) {
			return;
		}

		// One message is one nudge, however many audiences list the inbox.
		$key = $inbox['app'].'|'.(string)($account['subjectRef'] ?? '');
		if (isset($created->told[$key]) === true) {
			return;
		}

		$created->told[$key] = true;
		if ($inbox['nudge'] === '1') {
			$this->dispatch->dispatch(
				ruleKey: NotificationDispatchServiceInterface::RULE_MESSAGE_CREATED,
				appId: $inbox['app'],
				subject: $this->subject(account: $account)
			);
		}

		// The same message may also go to the government message box
		// (inbox-berichtenbox-channel); the channel decides whether it may.
		$this->messageBox?->enqueue(account: $account, inbox: $inbox, recordId: $created->recordId);
	}//end reachInbox()

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
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-another-apps-notice-with-a-declared-rule-key-must-be-sent-by-email-req-wje-006
	 */
	private function onForeignMessage(array $data, string $recordId): void {
		$ruleKey = ($data['ruleKey'] ?? null);
		if (is_string($ruleKey) === false || preg_match(NotificationRuleNormaliserInterface::APP_RULE_KEY_PATTERN, $ruleKey, $match) !== 1) {
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
	 * A withdrawn (void) account is no recipient: it gets no notice.
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

		$account = $this->accounts->findBySubjectRef(subjectRef: $subjectRef);
		if (($account['status'] ?? '') === PortalAccountServiceInterface::STATUS_VOID) {
			$this->logger->info('Portaliq: no change notice to a withdrawn account', ['account' => (string)($account['id'] ?? '')]);
			return null;
		}

		return $account;
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

}//end class
