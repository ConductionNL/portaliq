<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PublicRecordController;
use OCA\Portaliq\Service\PublicRecordReader;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * site-member-voting-record-and-confidential-papers REQ-SCR-006: the routes
 * are public and an unlisted record is a 404.
 *
 * @spec openspec/changes/site-member-voting-record-and-confidential-papers/tasks.md#t2
 */
class PublicRecordControllerTest extends TestCase {

	public function testAnUnlistedRecordIsNotFound(): void {
		$reader = $this->createMock(PublicRecordReader::class);
		$reader->method('record')->willReturn(null);

		$response = (new PublicRecordController($this->createMock(IRequest::class), $reader))->record(app: 'decidiq', list: 'memberVotingRecords', id: 'gone');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());

	}//end testAnUnlistedRecordIsNotFound()

	public function testAListedRecordAndTheListAreServed(): void {
		$reader = $this->createMock(PublicRecordReader::class);
		$reader->method('record')->willReturn(['title' => 'Sanne Mulder']);
		$reader->method('entries')->willReturnCallback(static fn (string $app, string $list) => ($list === 'memberVotingRecords' ? [['id' => 'p1', 'title' => 'Sanne']] : null));
		$controller = new PublicRecordController($this->createMock(IRequest::class), $reader);

		$this->assertSame('Sanne Mulder', $controller->record(app: 'decidiq', list: 'memberVotingRecords', id: 'p1')->getData()['title']);
		$this->assertSame('p1', $controller->list(app: 'decidiq', list: 'memberVotingRecords')->getData()['entries'][0]['id']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->list(app: 'decidiq', list: 'nope')->getStatus());

	}//end testAListedRecordAndTheListAreServed()

	public function testBothRoutesArePublicAndThrottled(): void {
		foreach (['list', 'record'] as $method) {
			$attributes = array_map(static fn ($a): string => $a->getName(), (new ReflectionMethod(PublicRecordController::class, $method))->getAttributes());
			$this->assertContains('OCP\AppFramework\Http\Attribute\PublicPage', $attributes);
			$this->assertContains('OCP\AppFramework\Http\Attribute\AnonRateLimit', $attributes);
		}

	}//end testBothRoutesArePublicAndThrottled()
}//end class
