<?php

/**
 * PortalDraftController: the answers and the calls of showing and saving a
 * draft, against doubles of the draft collaborators.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use DateTimeImmutable;
use OCA\Portaliq\Controller\PortalDraftController;
use OCA\Portaliq\Service\Intake\ActionDraft;
use OCA\Portaliq\Service\Intake\DestinationDraft;
use OCA\Portaliq\Service\PortalSessionService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Show and save, every answer and the order of the calls below.
 */
class PortalDraftControllerGoldenTest extends TestCase {
	/**
	 * The calls made on the collaborators, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * Build the controller over doubles.
	 *
	 * @param array<string, mixed> $world What the doubles answer: subjectRef, match, draft, body, saved, fails.
	 *
	 * @return PortalDraftController
	 */
	private function controller(array $world): PortalDraftController {
		$this->calls = [];
		$request     = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer t');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn(['subjectRef' => ($world['subjectRef'] ?? 'sanne')]);

		$actions = $this->createMock(ActionDraft::class);
		$actions->method('actionFor')->willReturnCallback(function (array $subject, string $appId, string $actionId) use ($world) {
			$this->calls[] = "actionFor $appId/$actionId";
			if (($world['fails'] ?? false) === true) {
				throw new RuntimeException('down');
			}

			return ($world['match'] ?? null);
		});
		$actions->method('find')->willReturnCallback(function (array $action, string $subjectRef) use ($world) {
			$this->calls[] = "find $subjectRef";
			return ($world['draft'] ?? null);
		});
		$actions->method('bodyOf')->willReturnCallback(function (array $match, array $subject, array $answers) use ($world) {
			$this->calls[] = 'bodyOf ' . json_encode($answers);
			return ($world['body'] ?? null);
		});
		$actions->method('destinationOf')->willReturnCallback(function () {
			$this->calls[] = 'destinationOf';
			return ['register' => 'r', 'schema' => 's'];
		});
		$actions->method('answersOf')->willReturnCallback(function (array $action, array $draft) {
			$this->calls[] = 'answersOf ' . json_encode($draft['data'] ?? null);
			return ['title' => 'Half'];
		});
		$actions->method('retentionDays')->willReturnCallback(function () {
			$this->calls[] = 'retentionDays';
			return 14;
		});

		$drafts = $this->createMock(DestinationDraft::class);
		$drafts->method('saveDraft')->willReturnCallback(function (array $destination, array $payload, string $subjectRef, string $draftId = '', string $scope = '') use ($world) {
			$this->calls[] = "saveDraft $subjectRef [$draftId] $scope " . json_encode($payload);
			return $world['saved'];
		});
		$drafts->method('errorsOf')->willReturnCallback(function (array $findings) {
			$this->calls[] = 'errorsOf';
			return ['preferredDate' => 'Fill in a date.'];
		});

		return new PortalDraftController($request, $session, $actions, $drafts);
	}//end controller()

	/**
	 * Showing a draft: unknown action, no draft, a draft, and a failure below.
	 *
	 * @return void
	 */
	public function testShowingADraftAnswersAsRecorded(): void {
		$match = ['action' => ['id' => 'request']];

		$unknown = $this->controller(['match' => null])->show('dossiq', 'request');
		$this->assertSame([404, ['found' => false]], [$unknown->getStatus(), $unknown->getData()]);
		$this->assertSame(['actionFor dossiq/request'], $this->calls);

		$none = $this->controller(['match' => $match])->show('dossiq', 'request');
		$this->assertSame([200, ['found' => false]], [$none->getStatus(), $none->getData()]);
		$this->assertSame(['actionFor dossiq/request', 'find sanne'], $this->calls);

		$draft = ['id' => 7, 'updatedAt' => '2026-11-01', 'expiresAt' => '2026-11-15', 'data' => ['title' => 'Half']];
		$shown = $this->controller(['match' => $match, 'draft' => $draft])->show('dossiq', 'request');
		$this->assertSame(200, $shown->getStatus());
		$this->assertSame(
			['id' => '7', 'status' => 'draft', 'step' => '', 'updatedAt' => '2026-11-01', 'expiresAt' => '2026-11-15', 'answers' => ['title' => 'Half']],
			$shown->getData()
		);
		$this->assertSame(['actionFor dossiq/request', 'find sanne', 'answersOf {"title":"Half"}'], $this->calls);

		$noUpdate = $this->controller(['match' => $match, 'draft' => ['id' => 'd', 'expiresAt' => 'e']])->show('a', 'b')->getData();
		$this->assertSame('', $noUpdate['updatedAt']);

		$down = $this->controller(['fails' => true])->show('dossiq', 'request');
		$this->assertSame([503, ['error' => 'try_again_later']], [$down->getStatus(), $down->getData()]);

		$signedOut = $this->controller(['subjectRef' => ''])->show('dossiq', 'request');
		$this->assertSame([401, ['authenticated' => false]], [$signedOut->getStatus(), $signedOut->getData()]);
		$this->assertSame([], $this->calls);
	}//end testShowingADraftAnswersAsRecorded()

	/**
	 * Saving a draft: unknown action, forbidden body, refused answers, a new
	 * draft and an updated one, in the recorded order of calls.
	 *
	 * @return void
	 */
	public function testSavingADraftAnswersAsRecorded(): void {
		$match = ['action' => ['id' => 'request']];

		$unknown = $this->controller(['match' => null])->save('dossiq', 'request', ['title' => 'x']);
		$this->assertSame([404, ['found' => false]], [$unknown->getStatus(), $unknown->getData()]);

		$forbidden = $this->controller(['match' => $match, 'body' => null])->save('dossiq', 'request', ['title' => 'x']);
		$this->assertSame([403, ['error' => 'forbidden']], [$forbidden->getStatus(), $forbidden->getData()]);
		$this->assertSame(['actionFor dossiq/request', 'bodyOf {"title":"x"}'], $this->calls);

		$refused = $this->controller(['match' => $match, 'body' => ['title' => 'x'], 'saved' => ['ok' => false, 'status' => 422, 'findings' => [['f']]]])
			->save('dossiq', 'request', ['title' => 'x']);
		$this->assertSame(
			[422, ['saved' => false, 'error' => 'invalid', 'errors' => ['preferredDate' => 'Fill in a date.']]],
			[$refused->getStatus(), $refused->getData()]
		);
		$this->assertSame(
			['actionFor dossiq/request', 'bodyOf {"title":"x"}', 'find sanne', 'destinationOf', 'saveDraft sanne [] dossiq/request {"title":"x"}', 'errorsOf'],
			$this->calls
		);

		$before = new DateTimeImmutable('+14 days -1 minute');
		$saved  = $this->controller(['match' => $match, 'body' => ['title' => 'Half'], 'draft' => ['id' => 'd-0'], 'saved' => ['ok' => true, 'answer' => ['id' => 9]]])
			->save('dossiq', 'request', ['title' => 'Half']);
		$after = new DateTimeImmutable('+14 days +1 minute');
		$data  = $saved->getData();
		$this->assertSame(200, $saved->getStatus());
		$this->assertSame(['id', 'status', 'step', 'expiresAt', 'answers'], array_keys($data));
		$this->assertSame(['9', 'draft', '', ['title' => 'Half']], [$data['id'], $data['status'], $data['step'], $data['answers']]);
		$expires = new DateTimeImmutable($data['expiresAt']);
		$this->assertTrue($expires >= $before && $expires <= $after);
		$this->assertSame(
			['actionFor dossiq/request', 'bodyOf {"title":"Half"}', 'find sanne', 'destinationOf', 'saveDraft sanne [d-0] dossiq/request {"title":"Half"}', 'retentionDays', 'answersOf {"title":"Half"}'],
			$this->calls
		);

		$down = $this->controller(['fails' => true])->save('dossiq', 'request', []);
		$this->assertSame([503, ['error' => 'try_again_later']], [$down->getStatus(), $down->getData()]);

		$signedOut = $this->controller(['subjectRef' => ''])->save('dossiq', 'request', []);
		$this->assertSame([401, ['authenticated' => false]], [$signedOut->getStatus(), $signedOut->getData()]);
		$this->assertSame([], $this->calls);
	}//end testSavingADraftAnswersAsRecorded()
}//end class
