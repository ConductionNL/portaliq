<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\Portaliq\Listener\SubmissionWriteGuardListener;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * submission-row-actions 1.4: a submission's proof fields never change after
 * the write; linking a form and the receipt's own fields still may.
 *
 * @covers \OCA\Portaliq\Listener\SubmissionWriteGuardListener
 *
 * @spec openspec/changes/submission-row-actions/specs/supplier-portal/spec.md#requirement-an-employee-can-link-a-submission-to-the-request-form-it-came-through
 */
class SubmissionWriteGuardListenerTest extends TestCase {

	private const SUBMISSION_SCHEMA = 57;


	/**
	 * A stored submission.
	 *
	 * @return array<string, mixed>
	 */
	private static function stored(): array {
		return [
			'subjectRef' => 'bsn-1',
			'organisation' => 'gemeente',
			'appId' => 'pipelinq',
			'actionId' => 'bulky-waste',
			'actionTitle' => 'Grof afval laten ophalen',
			'payloadCopy' => ['address' => 'Lindelaan 1', 'items' => ['bank', 'kast']],
			'submittedAt' => '2026-11-02T09:41:00+00:00',
			'deliveryStatus' => 'failed',
		];
	}//end stored()


	/**
	 * Every proof field, changed on its own, is named as changed.
	 *
	 * @return void
	 */
	public function testEachProofFieldChangeIsNamed(): void {
		$changes = [
			'subjectRef' => 'bsn-2',
			'organisation' => 'other',
			'appId' => 'decidiq',
			'actionId' => 'other-action',
			'actionTitle' => 'Iets anders',
			'payloadCopy' => ['address' => 'Lindelaan 2', 'items' => ['bank', 'kast']],
			'submittedAt' => '2026-11-03T09:41:00+00:00',
		];
		foreach ($changes as $field => $value) {
			$after = ([$field => $value] + self::stored());
			$this->assertSame([$field], SubmissionWriteGuardListener::changedProofFields(before: self::stored(), after: $after), $field);
		}
	}//end testEachProofFieldChangeIsNamed()


	/**
	 * A removed proof field counts as changed.
	 *
	 * @return void
	 */
	public function testARemovedProofFieldCountsAsChanged(): void {
		$after = self::stored();
		unset($after['payloadCopy'], $after['actionTitle']);

		$this->assertSame(['actionTitle', 'payloadCopy'], SubmissionWriteGuardListener::changedProofFields(before: self::stored(), after: $after));
	}//end testARemovedProofFieldCountsAsChanged()


	/**
	 * Linking a form and marking the receipt delivered change no proof field,
	 * and neither does the payload's key order.
	 *
	 * @return void
	 */
	public function testTheLinkTheReceiptAndKeyOrderAreNotProofChanges(): void {
		$after = self::stored();
		$after['formBinding'] = '9b2e4c1a-0000-4000-8000-000000000001';
		$after['deliveryStatus'] = 'delivered';
		$after['receiptMessageRef'] = 'WMEBV-0011223344556677';
		$after['payloadCopy'] = ['items' => ['bank', 'kast'], 'address' => 'Lindelaan 1'];

		$this->assertSame([], SubmissionWriteGuardListener::changedProofFields(before: self::stored(), after: $after));
	}//end testTheLinkTheReceiptAndKeyOrderAreNotProofChanges()


	/**
	 * The order inside a list is part of the evidence.
	 *
	 * @return void
	 */
	public function testAReorderedListIsAChange(): void {
		$after = self::stored();
		$after['payloadCopy']['items'] = ['kast', 'bank'];

		$this->assertSame(['payloadCopy'], SubmissionWriteGuardListener::changedProofFields(before: self::stored(), after: $after));
	}//end testAReorderedListIsAChange()


	/**
	 * An update that changes the payload is refused on the event.
	 *
	 * @return void
	 */
	public function testAProofFieldChangeIsRefused(): void {
		if (class_exists(ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$after = self::stored();
		$after['payloadCopy'] = ['address' => 'Elders 9'];
		$event = new ObjectUpdatingEvent($this->entity($after), $this->entity(self::stored()));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('The proof of a submission cannot be changed.', $event->getErrors()['message']);
		$this->assertSame(['payloadCopy'], $event->getErrors()['fields']);
	}//end testAProofFieldChangeIsRefused()


	/**
	 * Linking a form passes, and a create is never checked.
	 *
	 * @return void
	 */
	public function testALinkAndACreatePass(): void {
		if (class_exists(ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$linked = (['formBinding' => '9b2e4c1a-0000-4000-8000-000000000001'] + self::stored());
		$update = new ObjectUpdatingEvent($this->entity($linked), $this->entity(self::stored()));
		$create = new ObjectCreatingEvent($this->entity(self::stored()));

		$this->listener()->handle($update);
		$this->listener()->handle($create);

		$this->assertFalse($update->isPropagationStopped());
		$this->assertFalse($create->isPropagationStopped());
	}//end testALinkAndACreatePass()


	/**
	 * Another schema's object is left alone.
	 *
	 * @return void
	 */
	public function testAnotherSchemasObjectIsLeftAlone(): void {
		if (class_exists(ObjectUpdatingEvent::class) === false) {
			$this->markTestSkipped('OpenRegister is not installed in this environment.');
		}

		$after = $this->entity(['payloadCopy' => []] + self::stored());
		$after->setSchema('12');
		$event = new ObjectUpdatingEvent($after, $this->entity(self::stored()));

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherSchemasObjectIsLeftAlone()


	/**
	 * A submission entity.
	 *
	 * @param array<string, mixed> $data The submission's fields.
	 *
	 * @return ObjectEntity
	 */
	private function entity(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid('s1');
		$entity->setSchema((string)self::SUBMISSION_SCHEMA);
		$entity->setObject($data);

		return $entity;
	}//end entity()


	/**
	 * The listener over portaliq's submission schema.
	 *
	 * @return SubmissionWriteGuardListener
	 */
	private function listener(): SubmissionWriteGuardListener {
		$schema = new Schema();
		$schema->setId(self::SUBMISSION_SCHEMA);
		$schemas = $this->getMockBuilder(SchemaMapper::class)->disableOriginalConstructor()->onlyMethods(['findByApplicationAndSlug'])->getMock();
		$schemas->method('findByApplicationAndSlug')->with('portalSubmission', 'portaliq')->willReturn($schema);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($schemas);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []) => vsprintf($text, $params));

		return new SubmissionWriteGuardListener($container, $l10n, new NullLogger());
	}//end listener()
}//end class
