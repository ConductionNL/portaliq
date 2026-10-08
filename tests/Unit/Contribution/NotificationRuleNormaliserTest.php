<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\NotificationRuleNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A contribution's `notifications` accepts change rules next to plain keys
 * (REQ-NAP-001).
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */
class NotificationRuleNormaliserTest extends TestCase {

	/**
	 * The collections of the contribution under test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collections(): array {
		return [
			['id' => 'mijnZaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'initiator', 'label' => 'My cases', 'fields' => ['identifier', 'status', 'title']],
			['id' => 'viaZaken', 'register' => 'zaken', 'schema' => 'zaak', 'via' => ['collection' => 'x'], 'fields' => ['status']],
			['id' => 'claimZaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeClaim' => 'email', 'fields' => ['status']],
		];
	}//end collections()

	/**
	 * A rule on a default-scoped collection and a projected field is kept.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedRule(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'identifier']],
			collections: $this->collections()
		);

		$this->assertSame(
			[['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'identifier']],
			$result['kept']
		);
		$this->assertSame([], $result['dropped']);
	}//end testKeepsAWellFormedRule()

	/**
	 * A rule on a collection scoped through `via` or `scopeClaim` is dropped:
	 * the listener could not tell whose record it is.
	 *
	 * @return void
	 */
	public function testDropsARuleOnAViaCollection(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				['ruleKey' => 'case.updated', 'collection' => 'viaZaken', 'on' => ['field' => 'status', 'operator' => 'changed']],
				['ruleKey' => 'case.updated', 'collection' => 'claimZaken', 'on' => ['field' => 'status', 'operator' => 'changed']],
				['ruleKey' => 'case.updated', 'collection' => 'elsewhere', 'on' => ['field' => 'status', 'operator' => 'changed']],
			],
			collections: $this->collections()
		);

		$this->assertSame([], $result['kept']);
		$this->assertCount(3, $result['dropped']);
		$this->assertStringContainsString('viaZaken', $result['dropped'][0]);
	}//end testDropsARuleOnAViaCollection()

	/**
	 * A rule on a field the collection does not project is dropped, and so is
	 * an operator other than `changed`.
	 *
	 * @return void
	 */
	public function testDropsAnUnprojectedField(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'internalNote', 'operator' => 'changed']],
				['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'equals']],
			],
			collections: $this->collections()
		);

		$this->assertSame([], $result['kept']);
		$this->assertCount(2, $result['dropped']);
		$this->assertStringContainsString('internalNote', $result['dropped'][0]);
	}//end testDropsAnUnprojectedField()

	/**
	 * Plain rule keys still pass, untouched, next to a rule object; a title
	 * field that is not projected is left off rather than dropping the rule.
	 *
	 * @return void
	 */
	public function testPlainStringsStillPass(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: ['message.created', '', 7, ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'secret']],
			collections: $this->collections()
		);

		$this->assertSame(
			['message.created', ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed']]],
			$result['kept']
		);
	}//end testPlainStringsStillPass()

	/**
	 * The two keys portaliq fires itself, the key of a change rule beside it
	 * and a key in the app's own namespace are kept (#701).
	 *
	 * @return void
	 */
	public function testKeepsKeysSomethingFires(): void {
		$rule = ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed']];
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: ['message.created', 'status.changed', 'case.updated', 'dossiq.tenderPublished', $rule],
			collections: $this->collections(),
			appId: 'dossiq'
		);

		$this->assertSame([], $result['dropped']);
		$this->assertSame(['message.created', 'status.changed', 'case.updated', 'dossiq.tenderPublished', $rule], $result['kept']);
	}//end testKeepsKeysSomethingFires()

	/**
	 * A bare key nothing fires is dropped with a reason that names it and
	 * says how to declare it, so the app's developer sees it in the log
	 * instead of waiting for an e-mail that never comes (#701).
	 *
	 * @return void
	 */
	public function testDropsABareKeyNothingFires(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: ['tenderPublished', 'message.created'],
			collections: $this->collections(),
			appId: 'dossiq'
		);

		$this->assertSame(['message.created'], $result['kept']);
		$this->assertCount(1, $result['dropped']);
		$this->assertStringContainsString('tenderPublished', $result['dropped'][0]);
		$this->assertStringContainsString('dossiq.tenderPublished', $result['dropped'][0]);
	}//end testDropsABareKeyNothingFires()

	/**
	 * A key in another app's namespace is dropped: a portalMessage carrying
	 * it is dispatched for that other app, so it never fires for this one.
	 * A change rule's key whose rule was dropped fires nothing either.
	 *
	 * @return void
	 */
	public function testDropsAnotherAppsKeyAndTheKeyOfADroppedRule(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				'pipelinq.question.answered',
				'case.updated',
				['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'internalNote', 'operator' => 'changed']],
			],
			collections: $this->collections(),
			appId: 'opencatalogi'
		);

		$this->assertSame([], $result['kept']);
		$this->assertCount(3, $result['dropped']);
		$this->assertStringContainsString('internalNote', $result['dropped'][0]);
		$this->assertStringContainsString('"pipelinq"', $result['dropped'][1]);
		$this->assertStringContainsString('case.updated', $result['dropped'][2]);
	}//end testDropsAnotherAppsKeyAndTheKeyOfADroppedRule()

	/**
	 * A dropped key is logged with the app that declared it.
	 *
	 * @return void
	 */
	public function testLogsADroppedKeyWithTheApp(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				'Portaliq: notification rule dropped',
				$this->callback(static fn (array $context): bool => $context['app'] === 'dossiq' && str_contains($context['rule'], 'invoiceDue'))
			);

		$contribution = (new NotificationRuleNormaliser())->normaliseContribution(
			contribution: ['notifications' => ['invoiceDue', 'status.changed']],
			appId: 'dossiq',
			logger: $logger
		);

		$this->assertSame(['status.changed'], $contribution['notifications']);
	}//end testLogsADroppedKeyWithTheApp()

	/**
	 * A rule that names its recipients by a claim may sit on a `via` or
	 * `scopeClaim` collection: the record holds the value of the recipients'
	 * claim, so the listener can tell whose it is. The claim is kept in the
	 * contributing app's own namespace, and a message text per new value is
	 * kept with it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function testKeepsAClaimAddressedRuleOnAViaCollection(): void {
		$messages = [
			'acknowledged' => ['subject' => ['nl' => 'Bevestigd', 'en' => 'Confirmed'], 'body' => ['nl' => 'Op {startsAt|datetime}, met {teacherName}.']],
			'declined' => ['subject' => 'Declined', 'body' => 'Reason: {declineNote}'],
		];
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				['ruleKey' => 'booking.answered', 'collection' => 'bookings', 'on' => ['field' => 'lifecycle', 'operator' => 'changed'], 'recipients' => ['field' => 'guardianRef', 'claim' => 'learniq.guardianRef'], 'messages' => $messages],
			],
			collections: $this->claimCollections(),
			appId: 'learniq'
		);

		$this->assertSame([], $result['dropped']);
		$this->assertSame(
			[[
				'ruleKey' => 'booking.answered',
				'collection' => 'bookings',
				'on' => ['field' => 'lifecycle', 'operator' => 'changed'],
				'recipients' => ['field' => 'guardianRef', 'claim' => 'guardianRef'],
				'messages' => $messages,
			]],
			$result['kept']
		);
	}//end testKeepsAClaimAddressedRuleOnAViaCollection()

	/**
	 * A recipient claim of another app, a malformed recipient, and a message
	 * that would print a field the collection does not project are dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/claim-addressed-change-notices/specs/portal-notifications-and-preferences/spec.md
	 */
	public function testDropsAForeignClaimAMalformedRecipientAndAnUnprojectedPlaceholder(): void {
		$on = ['field' => 'lifecycle', 'operator' => 'changed'];
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				['ruleKey' => 'a', 'collection' => 'bookings', 'on' => $on, 'recipients' => ['field' => 'guardianRef', 'claim' => 'pipelinq.linkedContactId']],
				['ruleKey' => 'b', 'collection' => 'bookings', 'on' => $on, 'recipients' => ['field' => '', 'claim' => 'guardianRef']],
				['ruleKey' => 'c', 'collection' => 'bookings', 'on' => $on, 'recipients' => ['field' => 'guardianRef', 'claim' => 'guardianRef'], 'messages' => ['declined' => ['subject' => 'x', 'body' => 'Note: {internalNote}']]],
				['ruleKey' => 'd', 'collection' => 'bookings', 'on' => $on, 'recipients' => ['field' => 'guardianRef', 'claim' => 'guardianRef'], 'messages' => ['declined' => ['subject' => '', 'body' => 'x']]],
			],
			collections: $this->claimCollections(),
			appId: 'learniq'
		);

		$this->assertSame([], $result['kept']);
		$this->assertCount(4, $result['dropped']);
		$this->assertStringContainsString('another app', $result['dropped'][0]);
		$this->assertStringContainsString('internalNote', $result['dropped'][2]);
	}//end testDropsAForeignClaimAMalformedRecipientAndAnUnprojectedPlaceholder()

	/**
	 * A school app's bookings collection, reached through a child join.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function claimCollections(): array {
		return [
			[
				'id' => 'bookings',
				'register' => 'learniq',
				'schema' => 'conference-signup',
				'scopeField' => 'learnerRef',
				'scopeClaim' => 'guardianRef',
				'via' => ['register' => 'learniq', 'schema' => 'learner-profile', 'scopeField' => 'guardianRefs'],
				'fields' => ['lifecycle', 'startsAt', 'teacherName', 'declineNote'],
			],
		];
	}//end claimCollections()
}//end class
