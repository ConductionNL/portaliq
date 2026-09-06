<?php

/**
 * Unit tests for TrafficGoals and TrafficMatch.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficGoals;
use OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * One goal type per test, plus the two numbers a goal carries.
 */
class TrafficGoalsTest extends TestCase {

	/**
	 * One stored event.
	 *
	 * @param string               $name  The event name.
	 * @param string               $path  The page path.
	 * @param array<string, mixed> $extra More fields.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function event(string $name, string $path = '/', array $extra = []): array {
		return $extra + ['name' => $name, 'pagePath' => $path, 'params' => []];
	}//end event()


	/**
	 * Resolve goal definitions and evaluate them.
	 *
	 * @param array<int, array<string, mixed>>              $goals    The configured goals.
	 * @param array<int, array<int, array<string, mixed>>> $sessions Events per session.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(array $goals, array $sessions): array {
		$definitions = (new TrafficOutcomeDefinitions())->goals(value: $goals);
		$wrapped = array_map(static fn (array $events): array => ['visitor' => 'h1', 'explicit' => false, 'events' => $events], $sessions);

		return (new TrafficGoals())->rows(goals: $definitions, sessions: $wrapped);
	}//end rows()


	/**
	 * A page reached twice in one session is one conversion and two
	 * completions; a session that never reached it is neither.
	 *
	 * @return void
	 */
	public function testAPageReachedGoalConvertsOncePerSession(): void {
		$rows = $this->rows(
			goals: [['id' => 'contact', 'name' => 'Contact opened', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact'], 'value' => 5]],
			sessions: [
				[$this->event('page_view', '/'), $this->event('page_view', '/contact'), $this->event('page_view', '/contact/')],
				[$this->event('page_view', '/'), $this->event('scroll', '/contact')],
			]
		);

		$this->assertSame(
			[['id' => 'contact', 'name' => 'Contact opened', 'conversions' => 1, 'completions' => 2, 'value' => 5.0]],
			$rows
		);
	}//end testAPageReachedGoalConvertsOncePerSession()


	/**
	 * A prefix matches every path under it, on page views only.
	 *
	 * @return void
	 */
	public function testAPathPrefixMatchesPageViewsUnderIt(): void {
		$rows = $this->rows(
			goals: [['id' => 'woo', 'type' => 'page_reached', 'match' => ['pathPrefix' => '/woo']]],
			sessions: [[$this->event('page_view', '/woo/2026/1'), $this->event('scroll', '/woo/2026/1')], [$this->event('page_view', '/wonen')]]
		);

		$this->assertSame(1, $rows[0]['conversions']);
		$this->assertSame(1, $rows[0]['completions'], 'the scroll on the page is not a page reached');
		$this->assertSame('woo', $rows[0]['name'], 'a goal without a name is called by its id');
	}//end testAPathPrefixMatchesPageViewsUnderIt()


	/**
	 * An event goal matches by name, whatever page it happened on.
	 *
	 * @return void
	 */
	public function testAnEventGoalMatchesByName(): void {
		$rows = $this->rows(
			goals: [['id' => 'scrolled', 'type' => 'event', 'match' => ['eventName' => 'scroll']]],
			sessions: [[$this->event('page_view'), $this->event('scroll')], [$this->event('page_view')]]
		);

		$this->assertSame(1, $rows[0]['conversions']);
	}//end testAnEventGoalMatchesByName()


	/**
	 * A download goal reads the file's extension from the file name, or from
	 * the link when the name is not kept, case-insensitively.
	 *
	 * @return void
	 */
	public function testADownloadGoalMatchesTheFileExtension(): void {
		$rows = $this->rows(
			goals: [['id' => 'pdf', 'type' => 'download', 'match' => ['fileExtension' => '.PDF']]],
			sessions: [
				[$this->event('file_download', '/', ['fileName' => 'folder.pdf'])],
				[$this->event('file_download', '/', ['linkUrl' => 'https://open-tilburg.nl/docs/beleid.pdf?v=2'])],
				[$this->event('file_download', '/', ['fileName' => 'cijfers.xlsx'])],
				[$this->event('page_view', '/folder.pdf')],
			]
		);

		$this->assertSame(2, $rows[0]['conversions']);
	}//end testADownloadGoalMatchesTheFileExtension()


	/**
	 * A form goal matches the submitted form's id, and only a submit.
	 *
	 * @return void
	 */
	public function testAFormSubmittedGoalMatchesTheFormId(): void {
		$rows = $this->rows(
			goals: [['id' => 'signup', 'type' => 'form_submitted', 'match' => ['formId' => 'aanmelden']]],
			sessions: [
				[$this->event('form_start', '/', ['params' => ['formId' => 'aanmelden']]), $this->event('form_submit', '/', ['params' => ['formId' => 'aanmelden']])],
				[$this->event('form_start', '/', ['params' => ['formId' => 'aanmelden']])],
				[$this->event('form_submit', '/', ['params' => ['formId' => 'contact']])],
			]
		);

		$this->assertSame(1, $rows[0]['conversions']);
		$this->assertSame(1, $rows[0]['completions']);
	}//end testAFormSubmittedGoalMatchesTheFormId()


	/**
	 * A search goal matches a term as a case-insensitive substring.
	 *
	 * @return void
	 */
	public function testASearchGoalMatchesTheTerm(): void {
		$rows = $this->rows(
			goals: [['id' => 'woo-search', 'type' => 'search', 'match' => ['term' => 'woo']]],
			sessions: [
				[$this->event('search', '/', ['searchTerm' => 'Woo-verzoek'])],
				[$this->event('search', '/', ['params' => ['search_term' => 'parkeren']])],
			]
		);

		$this->assertSame(1, $rows[0]['conversions']);
	}//end testASearchGoalMatchesTheTerm()


	/**
	 * The conversion rate is the share of sessions that met ANY goal, and a
	 * portal without goals or a day without sessions has none.
	 *
	 * @return void
	 */
	public function testTheConversionRateCountsSessionsThatMetAnyGoal(): void {
		$definitions = (new TrafficOutcomeDefinitions())->goals(value: [
			['id' => 'a', 'type' => 'page_reached', 'match' => ['pathEquals' => '/a']],
			['id' => 'b', 'type' => 'page_reached', 'match' => ['pathEquals' => '/b']],
		]);
		$sessions = [
			['visitor' => 'h1', 'explicit' => false, 'events' => [$this->event('page_view', '/a'), $this->event('page_view', '/b')]],
			['visitor' => 'h2', 'explicit' => false, 'events' => [$this->event('page_view', '/b')]],
			['visitor' => 'h3', 'explicit' => false, 'events' => [$this->event('page_view', '/c')]],
			['visitor' => 'h4', 'explicit' => false, 'events' => [$this->event('page_view', '/c')]],
		];

		$goals = new TrafficGoals();
		$this->assertSame(0.5, $goals->conversionRate(goals: $definitions, sessions: $sessions));
		$this->assertSame(0.0, $goals->conversionRate(goals: [], sessions: $sessions));
		$this->assertSame(0.0, $goals->conversionRate(goals: $definitions, sessions: []));
	}//end testTheConversionRateCountsSessionsThatMetAnyGoal()


	/**
	 * A goal that cannot be acted on is dropped: no id, an unknown type, an
	 * empty match, a duplicate id, a non-token id.
	 *
	 * @return void
	 */
	public function testUnusableGoalsAreDropped(): void {
		$definitions = (new TrafficOutcomeDefinitions())->goals(value: [
			['type' => 'page_reached', 'match' => ['pathEquals' => '/a']],
			['id' => 'x', 'type' => 'teleport', 'match' => ['pathEquals' => '/a']],
			['id' => 'y', 'type' => 'page_reached', 'match' => []],
			['id' => 'z', 'type' => 'page_reached', 'match' => ['pathEquals' => '/a']],
			['id' => 'z', 'type' => 'page_reached', 'match' => ['pathEquals' => '/b']],
			['id' => 'has space', 'type' => 'page_reached', 'match' => ['pathEquals' => '/a']],
			'not a row',
		]);

		$this->assertCount(1, $definitions);
		$this->assertSame('z', $definitions[0]['id']);
		$this->assertSame(['pathEquals' => '/a'], $definitions[0]['match'], 'the first of two duplicates wins');
		$this->assertSame(0.0, $definitions[0]['value']);
	}//end testUnusableGoalsAreDropped()
}
