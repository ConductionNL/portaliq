<?php

/**
 * Unit tests for TrafficSegments.
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

use OCA\Portaliq\Service\Traffic\TrafficOutcomeDefinitions;
use OCA\Portaliq\Service\Traffic\TrafficSegments;
use PHPUnit\Framework\TestCase;

/**
 * Each operator, the AND across conditions, the derived dimensions, and
 * the refusal of a condition this app cannot evaluate.
 */
class TrafficSegmentsTest extends TestCase {

	/**
	 * A session over some events.
	 *
	 * @param array<int, array<string, mixed>> $events  The events.
	 * @param string                           $visitor The visitor key.
	 *
	 * @return array<string, mixed> The session.
	 */
	private function session(array $events, string $visitor = 'h1'): array {
		return ['visitor' => $visitor, 'explicit' => false, 'events' => $events];
	}//end session()


	/**
	 * A segment with one condition.
	 *
	 * @param string $dimension The dimension.
	 * @param string $operator  The operator.
	 * @param string $value     The value.
	 *
	 * @return array<string, mixed> The resolved segment.
	 */
	private function segment(string $dimension, string $operator, string $value): array {
		$segments = (new TrafficSegments())->definitions(
			value: [['id' => 's', 'conditions' => [['dimension' => $dimension, 'operator' => $operator, 'value' => $value]]]]
		);
		$this->assertCount(1, $segments, 'the segment is usable');

		return $segments[0];
	}//end segment()


	/**
	 * @return array<string, array{0: string, 1: string, 2: bool}>
	 */
	public static function operatorProvider(): array {
		return [
			'is matches ignoring case' => ['is', 'Desktop', true],
			'is refuses another value' => ['is', 'mobile', false],
			'isNot passes another value' => ['isNot', 'mobile', true],
			'isNot refuses the same value' => ['isNot', 'desktop', false],
			'contains a part' => ['contains', 'skt', true],
			'contains refuses a missing part' => ['contains', 'tablet', false],
			'startsWith the start' => ['startsWith', 'desk', true],
			'startsWith refuses the middle' => ['startsWith', 'top', false],
		];
	}//end operatorProvider()


	/**
	 * @dataProvider operatorProvider
	 *
	 * @param string $operator The operator.
	 * @param string $value    The condition's value.
	 * @param bool   $expected Whether a desktop session matches.
	 *
	 * @return void
	 */
	public function testEachOperator(string $operator, string $value, bool $expected): void {
		$session = $this->session([['name' => 'page_view', 'deviceType' => 'desktop']]);

		$this->assertSame($expected, (new TrafficSegments())->matches(segment: $this->segment('deviceType', $operator, $value), session: $session));
	}//end testEachOperator()


	/**
	 * Every condition must hold, and the dimension is read off the first
	 * event that carries it.
	 *
	 * @return void
	 */
	public function testConditionsAreAndCombinedAcrossTheSessionsEvents(): void {
		$segments = (new TrafficSegments())->definitions(value: [[
			'id' => 'nl-desktop',
			'name' => 'Dutch desktop',
			'conditions' => [
				['dimension' => 'deviceType', 'operator' => 'is', 'value' => 'desktop'],
				['dimension' => 'language', 'operator' => 'is', 'value' => 'nl'],
			],
		]]);
		$both = $this->session([['name' => 'page_view', 'deviceType' => 'desktop'], ['name' => 'scroll', 'language' => 'nl']]);
		$one = $this->session([['name' => 'page_view', 'deviceType' => 'desktop', 'language' => 'en']], 'h2');
		$none = $this->session([['name' => 'page_view']], 'h3');

		$filtered = (new TrafficSegments())->filter(segment: $segments[0], sessions: [$both, $one, $none]);

		$this->assertSame('Dutch desktop', $segments[0]['name']);
		$this->assertCount(1, $filtered);
		$this->assertSame('h1', $filtered[0]['visitor']);
	}//end testConditionsAreAndCombinedAcrossTheSessionsEvents()


	/**
	 * The derived dimensions: an account reference present, the visitor
	 * type from the session start, and a goal met.
	 *
	 * @return void
	 */
	public function testDerivedDimensionsEvaluateToTrueOrFalse(): void {
		$goals = (new TrafficOutcomeDefinitions())->goals(value: [['id' => 'contact', 'type' => 'page_reached', 'match' => ['pathEquals' => '/contact']]]);
		$segments = new TrafficSegments();
		$linked = $segments->definitions(value: [['id' => 'l', 'conditions' => [['dimension' => 'userRef-present', 'operator' => 'is', 'value' => 'true']]]])[0];
		$returning = $segments->definitions(value: [['id' => 'r', 'conditions' => [['dimension' => 'visitorType', 'operator' => 'is', 'value' => 'returning']]]])[0];
		$converted = $segments->definitions(
			value: [['id' => 'c', 'conditions' => [['dimension' => 'goal:contact', 'operator' => 'is', 'value' => 'true']]]],
			goals: $goals
		)[0];

		$account = $this->session([['name' => 'page_view', 'userRef' => 'subj-1']]);
		$anonymous = $this->session([['name' => 'page_view']]);
		$this->assertTrue($segments->matches(segment: $linked, session: $account));
		$this->assertFalse($segments->matches(segment: $linked, session: $anonymous));

		$comeBack = ['visitor' => 'c:abc', 'explicit' => true, 'events' => [['name' => 'session_start', 'params' => ['visitorType' => 'returning']]]];
		$cookieless = ['visitor' => 'h:abc', 'explicit' => false, 'events' => [['name' => 'session_start', 'params' => ['visitorType' => 'returning']]]];
		$this->assertTrue($segments->matches(segment: $returning, session: $comeBack));
		$this->assertFalse($segments->matches(segment: $returning, session: $cookieless), 'a daily hash cannot say it returned');

		$met = $this->session([['name' => 'page_view', 'pagePath' => '/contact', 'params' => []]]);
		$missed = $this->session([['name' => 'page_view', 'pagePath' => '/', 'params' => []]]);
		$this->assertTrue($segments->matches(segment: $converted, session: $met, goals: $goals));
		$this->assertFalse($segments->matches(segment: $converted, session: $missed, goals: $goals));
	}//end testDerivedDimensionsEvaluateToTrueOrFalse()


	/**
	 * A segment with a condition on an unknown dimension, an unknown
	 * operator, an undeclared goal, no conditions or a bad id is left out
	 * at configuration time; the good ones stay.
	 *
	 * @return void
	 */
	public function testAnUnusableSegmentIsRefusedAtConfigurationTime(): void {
		$segments = (new TrafficSegments())->definitions(value: [
			['id' => 'ok', 'conditions' => [['dimension' => 'channel', 'operator' => 'is', 'value' => 'organic']]],
			['id' => 'unknown-dimension', 'conditions' => [['dimension' => 'shoeSize', 'operator' => 'is', 'value' => '42']]],
			['id' => 'unknown-operator', 'conditions' => [['dimension' => 'channel', 'operator' => 'matches', 'value' => 'x']]],
			['id' => 'undeclared-goal', 'conditions' => [['dimension' => 'goal:nope', 'operator' => 'is', 'value' => 'true']]],
			['id' => 'empty', 'conditions' => []],
			['id' => 'bad id!', 'conditions' => [['dimension' => 'channel', 'operator' => 'is', 'value' => 'x']]],
			['id' => 'ok', 'conditions' => [['dimension' => 'channel', 'operator' => 'is', 'value' => 'twice']]],
			'not a row',
		]);

		$this->assertSame(['ok'], array_column($segments, 'id'));
		$this->assertSame('ok', $segments[0]['name'], 'the id is the fallback name');
		$this->assertSame('organic', $segments[0]['conditions'][0]['value'], 'the first declaration of an id wins');
	}//end testAnUnusableSegmentIsRefusedAtConfigurationTime()
}//end class
