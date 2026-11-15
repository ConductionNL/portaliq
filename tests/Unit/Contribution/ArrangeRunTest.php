<?php

/**
 * ArrangeRun: a contribution's pages in the admin's chosen order, without
 * the pages already listed elsewhere, and the best rank it holds.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ArrangeRun;
use PHPUnit\Framework\TestCase;

/**
 * The arrange run.
 */
class ArrangeRunTest extends TestCase {
	/**
	 * A contribution with four pages.
	 *
	 * @return array<string, mixed>
	 */
	private function contribution(): array {
		return ['app' => 'dossiq', 'pages' => [['id' => 'cases'], ['id' => 'documents'], ['id' => 'messages'], ['id' => 'help']]];
	}//end contribution()

	/**
	 * Pages are ordered by rank, unranked ones last in their own order.
	 *
	 * @return void
	 */
	public function testPagesFollowTheRank(): void {
		$run   = new ArrangeRun(listed: [], rank: ['dossiq:messages' => 0, 'dossiq:cases' => 2]);
		$pages = $run->arranged(contribution: $this->contribution())['pages'];
		$this->assertSame(expected: ['messages', 'cases', 'documents', 'help'], actual: array_column($pages, 'id'));
	}//end testPagesFollowTheRank()

	/**
	 * Pages already listed elsewhere are left out.
	 *
	 * @return void
	 */
	public function testListedPagesAreLeftOut(): void {
		$run   = new ArrangeRun(listed: ['dossiq:documents' => true, 'dossiq:help' => false, 'pipelinq:cases' => true], rank: []);
		$pages = $run->arranged(contribution: $this->contribution())['pages'];
		$this->assertSame(expected: ['cases', 'messages', 'help'], actual: array_column($pages, 'id'));
	}//end testListedPagesAreLeftOut()

	/**
	 * Ranks belong to one app: another app's rank for the same page id does not apply.
	 *
	 * @return void
	 */
	public function testRanksArePerApp(): void {
		$run   = new ArrangeRun(listed: [], rank: ['pipelinq:help' => 0]);
		$pages = $run->arranged(contribution: $this->contribution())['pages'];
		$this->assertSame(expected: ['cases', 'documents', 'messages', 'help'], actual: array_column($pages, 'id'));
	}//end testRanksArePerApp()

	/**
	 * A contribution without pages is returned unchanged; other keys are kept.
	 *
	 * @return void
	 */
	public function testAContributionWithoutPagesIsUnchanged(): void {
		$run = new ArrangeRun(listed: ['dossiq:cases' => true], rank: ['dossiq:cases' => 0]);
		$bare = ['app' => 'dossiq', 'collections' => []];
		$this->assertSame(expected: $bare, actual: $run->arranged(contribution: $bare));
		$arranged = $run->arranged(contribution: ['app' => 'dossiq', 'label' => 'Zaken', 'pages' => [['id' => 'cases']]]);
		$this->assertSame(expected: ['app' => 'dossiq', 'label' => 'Zaken', 'pages' => []], actual: $arranged);
	}//end testAContributionWithoutPagesIsUnchanged()

	/**
	 * The best rank is the lowest rank of the contribution's pages, or the maximum without one.
	 *
	 * @return void
	 */
	public function testTheBestRankIsTheLowest(): void {
		$run = new ArrangeRun(listed: [], rank: ['dossiq:help' => 4, 'dossiq:documents' => 1, 'pipelinq:cases' => 0]);
		$this->assertSame(expected: 1, actual: $run->bestOf(contribution: $this->contribution()));
		$this->assertSame(expected: PHP_INT_MAX, actual: $run->bestOf(contribution: ['app' => 'dossiq', 'pages' => [['id' => 'other']]]));
		$this->assertSame(expected: PHP_INT_MAX, actual: $run->bestOf(contribution: ['app' => 'dossiq']));
	}//end testTheBestRankIsTheLowest()
}//end class
