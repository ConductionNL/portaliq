<?php

/**
 * ProposalController::proposeFromPortal(): the record read and the proposal
 * made for a portal subject, argument for argument.
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

use OCA\Portaliq\Contribution\PortalContributionRegistry;
use OCA\Portaliq\Controller\ProposalController;
use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalSessionService;
use OCA\Portaliq\Service\Proposals\ProposalService;
use OCA\Portaliq\Service\Tasks\PortalCaseAccessGuard;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The portal proposal's reads and writes, as recorded.
 */
class ProposalPortalProposeGoldenTest extends TestCase {
	/**
	 * What the reader and the proposal service were asked, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * A controller for one subject and one propose action.
	 *
	 * @param array<string, mixed>      $subject The session's subject.
	 * @param array<string, mixed>      $action  The propose action.
	 * @param array<string, mixed>|null $row     What the scoped read answers.
	 *
	 * @return ProposalController
	 */
	private function controller(array $subject, array $action, ?array $row): ProposalController {
		$this->calls = [];
		$request     = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturn('Bearer token');
		$session = $this->createMock(PortalSessionService::class);
		$session->method('resolveFromBearer')->willReturn($subject);
		$registry = $this->createMock(PortalContributionRegistry::class);
		$registry->method('aggregateFor')->willReturn(['contributions' => [['app' => 'dossiq', 'actions' => [$action]]]]);

		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readObject')->willReturnCallback(function (...$arguments) use ($row): ?array {
			$this->calls[] = ['readObject' => array_values($arguments)];
			return $row;
		});
		$proposals = $this->createMock(ProposalService::class);
		$proposals->method('propose')->willReturnCallback(function (...$arguments): array {
			$this->calls[] = ['propose' => array_values($arguments)];
			return ['proposal' => ['state' => 'queued']];
		});

		return new ProposalController(
			$request,
			$proposals,
			$registry,
			$reader,
			$session,
			$this->createMock(PortalCaseAccessGuard::class),
			$this->createMock(IUserSession::class)
		);
	}//end controller()

	/**
	 * A full subject and an action naming its scope: the scoped read gets
	 * every value, the proposal gets the record, the changes, the allow-list,
	 * the read row, the proposer, the portal channel and the note.
	 *
	 * @return void
	 */
	public function testTheReadAndTheProposalAreAsRecorded(): void {
		$action   = ['id' => 'p', 'type' => 'propose-change', 'register' => 'dossiq', 'schema' => 'zaak', 'scopeField' => 'applicant', 'scopeClaim' => 'kvk', 'proposable' => ['phone']];
		$response = $this->controller(subject: ['subjectRef' => 's-1', 'organisation' => 'org', 'audience' => 'business'], action: $action, row: ['phone' => '1'])
			->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'z-1', changes: [['property' => 'phone']], note: 'N');

		$this->assertSame([200, ['proposal' => ['state' => 'queued']]], [$response->getStatus(), $response->getData()]);
		$this->assertSame(
			[
				['readObject' => ['dossiq', 'zaak', 'applicant', 's-1', 'z-1', 'org', 'kvk', 'dossiq', null, 'business', null, []]],
				['propose' => [['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'z-1'], [['property' => 'phone']], ['phone'], ['phone' => '1'], 's-1', 'portal', 'N']],
			],
			$this->calls
		);
	}//end testTheReadAndTheProposalAreAsRecorded()

	/**
	 * A bare subject and an action without a scope read with the default
	 * scope field and empty values; a record that is not theirs proposes
	 * nothing.
	 *
	 * @return void
	 */
	public function testTheDefaultsAndANotOwnedRecordAreAsRecorded(): void {
		$action = ['id' => 'p', 'type' => 'propose-change', 'register' => 'dossiq', 'schema' => 'zaak', 'proposable' => ['phone']];
		$this->controller(subject: ['subjectRef' => 's-1'], action: $action, row: ['phone' => '1'])->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'z-1');
		$this->assertSame(
			[
				['readObject' => ['dossiq', 'zaak', 'subjectRef', 's-1', 'z-1', '', '', 'dossiq', null, '', null, []]],
				['propose' => [['register' => 'dossiq', 'schema' => 'zaak', 'id' => 'z-1'], [], ['phone'], ['phone' => '1'], 's-1', 'portal', '']],
			],
			$this->calls
		);

		$refused = $this->controller(subject: ['subjectRef' => 's-1'], action: $action, row: null)->proposeFromPortal(register: 'dossiq', schema: 'zaak', id: 'z-1');
		$this->assertSame([403, ['error' => 'not_yours']], [$refused->getStatus(), $refused->getData()]);
		$this->assertSame(['readObject'], array_map(static fn (array $call): string => (string)array_key_first($call), $this->calls));
	}//end testTheDefaultsAndANotOwnedRecordAreAsRecorded()
}//end class
