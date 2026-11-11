<?php

/**
 * Golden outcomes of deciding a change proposal: who may accept or reject,
 * what the guard is asked, and what reaches the proposal service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/change-proposal-queue/specs/change-proposal-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ProposalController;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Session\BearerSessionResolver;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The allowed and refused decisions, and the calls they make.
 */
class ProposalDecisionGoldenTest extends TestCase {
	/**
	 * What the doubles were asked, by name.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $asked = [];

	/**
	 * The proposal the reader holds.
	 *
	 * @var array<string, mixed>
	 */
	private const PROPOSAL = ['uuid' => 'p-1', 'subjectRegister' => 'dossiq', 'subjectSchema' => 'zaak', 'subjectId' => 'zaak-1'];

	/**
	 * The controller over recording doubles.
	 *
	 * @param string|null               $uid        The signed-in user, or null.
	 * @param bool                      $mayReview  What the guard answers.
	 * @param array<string, mixed>|null $subjectRow The record the reader answers.
	 *
	 * @return ProposalController
	 */
	private function controller(?string $uid, bool $mayReview = true, ?array $subjectRow = ['phone' => '06']): ProposalController {
		$this->asked = ['reads' => [], 'guard' => [], 'service' => []];
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(function (string $register, string $schema, string $scopeField, string $subjectRef, string $id, mixed ...$rest) use ($subjectRow): ?array {
			$this->asked['reads'][] = [$register, $schema, $scopeField, $subjectRef, $id, $rest];
			if ($schema === 'changeProposal') {
				return ($id === 'p-1') ? self::PROPOSAL : null;
			}

			return $subjectRow;
		});
		$guard = $this->createMock(PortalCaseAccessGuard::class);
		$guard->method('mayAct')->willReturnCallback(function (IUser $user, string $register, string $schema, string $id, string $action) use ($mayReview): bool {
			$this->asked['guard'][] = [$user->getUID(), $register, $schema, $id, $action];

			return $mayReview;
		});
		$proposals = $this->createMock(ProposalService::class);
		foreach (['accept', 'acceptConfirmingDrift', 'reject'] as $method) {
			$proposals->method($method)->willReturnCallback(function (mixed ...$arguments) use ($method): array {
				$this->asked['service'][] = [$method, $arguments];

				return ['state' => $method];
			});
		}

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(($uid === null) ? null : $this->user(uid: $uid));

		return new ProposalController(
			$this->createMock(IRequest::class),
			$proposals,
			$this->createMock(PortalContributionRegistry::class),
			$reader,
			$this->createMock(BearerSessionResolver::class),
			$guard,
			$userSession
		);
	}//end controller()

	/**
	 * A user double with a uid.
	 *
	 * @param string $uid The user id.
	 *
	 * @return IUser
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * The three decisions of one controller, as [status, body] each, with what was asked.
	 *
	 * @param ProposalController $controller The controller.
	 * @param string             $id         The proposal id.
	 *
	 * @return array<string, mixed>
	 */
	private function decide(ProposalController $controller, string $id): array {
		$answers = [];
		foreach (['accept' => fn () => $controller->accept(id: $id), 'acceptConfirmingDrift' => fn () => $controller->acceptConfirmingDrift(id: $id), 'reject' => fn () => $controller->reject(id: $id, reason: 'Nee.')] as $name => $call) {
			$response       = $call();
			$answers[$name] = [$response->getStatus(), $response->getData()];
		}

		return ['answers' => $answers, 'asked' => $this->asked];
	}//end decide()

	/**
	 * A signed-in reviewer with write rights decides, and the service gets the
	 * proposal, the record and the reviewer.
	 *
	 * @return void
	 */
	public function testAReviewerWithWriteRightsDecides(): void {
		$rest         = ['', '', '', null, '', null, []];
		$proposalRead = ['portaliq', 'changeProposal', '', '', 'p-1', $rest];
		$recordRead   = ['dossiq', 'zaak', '', '', 'zaak-1', $rest];
		$guardCall    = ['anna', 'dossiq', 'zaak', 'zaak-1', PortalCaseAccessGuard::ACTION_REVIEW_PROPOSAL];
		$this->assertSame(
			[
				'answers' => ['accept' => [200, ['state' => 'accept']], 'acceptConfirmingDrift' => [200, ['state' => 'acceptConfirmingDrift']], 'reject' => [200, ['state' => 'reject']]],
				'asked'   => [
					'reads'   => [$proposalRead, $recordRead, $proposalRead, $recordRead, $proposalRead, $recordRead],
					'guard'   => [$guardCall, $guardCall, $guardCall],
					'service' => [
						['accept', [self::PROPOSAL, ['phone' => '06'], 'anna']],
						['acceptConfirmingDrift', [self::PROPOSAL, 'anna']],
						['reject', [self::PROPOSAL, 'anna', 'Nee.']],
					],
				],
			],
			$this->decide(controller: $this->controller(uid: 'anna'), id: 'p-1')
		);
	}//end testAReviewerWithWriteRightsDecides()

	/**
	 * A record that is gone reaches the service as an empty record.
	 *
	 * @return void
	 */
	public function testAMissingRecordIsAnEmptyRecord(): void {
		$this->controller(uid: 'anna', subjectRow: null)->accept(id: 'p-1');

		$this->assertSame(['accept', [self::PROPOSAL, [], 'anna']], $this->asked['service'][0]);
	}//end testAMissingRecordIsAnEmptyRecord()

	/**
	 * The refusals: no user, no proposal, an empty id and no write rights, each
	 * before the service is called, and the record unread when refused.
	 *
	 * @return void
	 */
	public function testTheRefusals(): void {
		$cases = [
			'noUser'     => [fn () => $this->controller(uid: null), 'p-1', 401, 'not_authenticated', 0, 0],
			'noProposal' => [fn () => $this->controller(uid: 'anna'), 'p-2', 404, 'not_found', 3, 0],
			'emptyId'    => [fn () => $this->controller(uid: 'anna'), '', 404, 'not_found', 0, 0],
			'noRights'   => [fn () => $this->controller(uid: 'anna', mayReview: false), 'p-1', 403, 'forbidden', 3, 3],
		];
		foreach ($cases as $name => [$controller, $id, $status, $error, $reads, $guardCalls]) {
			$result = $this->decide(controller: $controller(), id: $id);
			foreach ($result['answers'] as $answer) {
				$this->assertSame([$status, ['error' => $error]], $answer, $name);
			}

			$this->assertSame([$reads, $guardCalls, 0], [count($result['asked']['reads']), count($result['asked']['guard']), count($result['asked']['service'])], $name);
		}
	}//end testTheRefusals()
}//end class
