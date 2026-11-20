<?php

/**
 * Unit tests for the action matrix seeding when the matrix cannot be written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use JsonException;
use OCA\Portaliq\Repair\InitializeActions;
use OCA\Portaliq\Service\ActionAuthService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A matrix that cannot be encoded is reported as a warning and nothing is
 * claimed as added; a matrix holding every seeded action is not written.
 */
#[CoversClass(InitializeActions::class)]
class InitializeActionsWriteFailureTest extends TestCase {

	/**
	 * The step names itself after the decision it implements.
	 *
	 * @return void
	 */
	public function testTheName(): void {
		$repair = new InitializeActions($this->createMock(ActionAuthService::class), $this->createMock(LoggerInterface::class));

		$this->assertSame('Initialize action-authorization matrix (ADR-023)', $repair->getName());
	}//end testTheName()

	/**
	 * A failing write warns and neither reports nor logs an addition.
	 *
	 * @return void
	 */
	public function testAFailingWriteWarnsAndAddsNothing(): void {
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('getMatrix')->willReturn([]);
		$actionAuth->expects($this->once())->method('setMatrix')
			->with($this->callback(static fn (array $matrix): bool => $matrix !== [] && array_is_list($matrix) === false))
			->willThrowException(new JsonException('Malformed UTF-8'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('Failed to write matrix: Malformed UTF-8');
		$output->expects($this->never())->method('info');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');

		(new InitializeActions($actionAuth, $logger))->run($output);
	}//end testAFailingWriteWarnsAndAddsNothing()

	/**
	 * A matrix that already holds every seeded action is preserved untouched.
	 *
	 * @return void
	 */
	public function testACompleteMatrixIsPreserved(): void {
		$seed   = json_decode((string)file_get_contents(__DIR__.'/../../../lib/actions.seed.json'), true, 512, JSON_THROW_ON_ERROR);
		$matrix = array_fill_keys(array_keys($seed['actions']), ['admin']);
		$actionAuth = $this->createMock(ActionAuthService::class);
		$actionAuth->method('getMatrix')->willReturn($matrix);
		$actionAuth->expects($this->never())->method('setMatrix');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with(sprintf('Action matrix already holds every seeded action (%d) — preserving.', count($matrix)));

		(new InitializeActions($actionAuth, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testACompleteMatrixIsPreserved()
}//end class
