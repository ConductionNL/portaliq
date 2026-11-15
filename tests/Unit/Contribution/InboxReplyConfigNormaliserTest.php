<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\InboxReplyConfigNormaliser;
use OCA\Portaliq\Contribution\PortalManifestNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * inbox-reply-with-attachments REQ-IRA-001: an inbox collection's reply names a
 * create action of the same contribution, and carries only fields both sides have.
 *
 * @spec openspec/changes/inbox-reply-with-attachments/tasks.md#t01
 */
class InboxReplyConfigNormaliserTest extends TestCase {

	private const ACTIONS = [
		['id' => 'replyToMessage', 'type' => 'create', 'register' => 'dossiq', 'schema' => 'portaalBericht', 'fields' => ['subject', 'content', 'attachments', 'caseId']],
		['id' => 'changeAddress', 'type' => 'update', 'register' => 'dossiq', 'schema' => 'case', 'fields' => ['street']],
	];

	private function inbox(array $reply, array $extra=[]): array {
		return [$extra + ['id' => 'berichten', 'kind' => 'inbox', 'register' => 'dossiq', 'schema' => 'portaalBericht', 'fields' => ['subject', 'content', 'caseId'], 'reply' => $reply]];
	}

	public function testKeepsAWellFormedReply(): void {
		$out = (new InboxReplyConfigNormaliser())->resolve(
			collections: $this->inbox(['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId'], 'subjectFrom' => 'subject']),
			actions: self::ACTIONS
		);

		$this->assertSame(['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId'], 'subjectFrom' => 'subject'], $out[0]['reply']);

	}//end testKeepsAWellFormedReply()

	public function testDropsAForeignAction(): void {
		foreach (['unknownAction', 'changeAddress', 7, null] as $action) {
			$out = (new InboxReplyConfigNormaliser())->resolve(collections: $this->inbox(['action' => $action]), actions: self::ACTIONS);
			$this->assertArrayNotHasKey('reply', $out[0], json_encode($action));
			$this->assertSame('berichten', $out[0]['id'], 'the collection stays');
		}

	}//end testDropsAForeignAction()

	public function testDropsACarryOutsideTheWhitelist(): void {
		$out = (new InboxReplyConfigNormaliser())->resolve(
			collections: $this->inbox(['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId', 'direction' => 'subject', 'subject' => 'notProjected', 'bad name' => 'x'], 'subjectFrom' => 'hidden']),
			actions: self::ACTIONS
		);

		$this->assertSame(['caseId' => 'caseId'], $out[0]['reply']['carry'], 'not whitelisted, not projected, not a name');
		$this->assertArrayNotHasKey('subjectFrom', $out[0]['reply'], 'a subject the collection does not project');

	}//end testDropsACarryOutsideTheWhitelist()

	public function testOnlyAnInboxCollectionCanDeclareAReply(): void {
		$out = (new InboxReplyConfigNormaliser())->resolve(
			collections: $this->inbox(['action' => 'replyToMessage'], ['kind' => 'cases']),
			actions: self::ACTIONS
		);

		$this->assertArrayNotHasKey('reply', $out[0]);

	}//end testOnlyAnInboxCollectionCanDeclareAReply()

	public function testTheWholeManifestKeepsTheReplyAndOwnMessagesDeclareNone(): void {
		$out = (new PortalManifestNormaliser())->normalise([
			'collections' => array_merge(
				$this->inbox(['action' => 'replyToMessage', 'carry' => ['caseId' => 'caseId']]),
				[['id' => 'portalMessages', 'kind' => 'inbox', 'register' => 'portaliq', 'schema' => 'portalMessage']]
			),
			'actions' => self::ACTIONS,
		]);

		$this->assertSame('replyToMessage', $out['collections'][0]['reply']['action']);
		$this->assertArrayNotHasKey('reply', $out['collections'][1]);

	}//end testTheWholeManifestKeepsTheReplyAndOwnMessagesDeclareNone()
}//end class
