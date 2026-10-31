<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic;

use OCA\Portaliq\Service\Traffic\TrafficExperiments;
use OCA\Portaliq\Service\Traffic\TrafficOutcomeParams;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Which event params survive into the stored traffic outcome.
 */
#[CoversClass(TrafficOutcomeParams::class)]
#[UsesClass(TrafficExperiments::class)]
class TrafficOutcomeParamsTest extends TestCase {
	/**
	 * Textless events drop every param.
	 *
	 * @return void
	 */
	public function testTextlessEventKeepsNothing(): void {
		$this->assertSame([], (new TrafficOutcomeParams())->filter('assistant_asked', ['q' => 'secret'], []));
	}//end testTextlessEventKeepsNothing()

	/**
	 * Custom dimensions need a declaration; experiment tags need a running variant.
	 *
	 * @return void
	 */
	public function testCustomAndExperimentParams(): void {
		$config = [
			'customDimensions' => [['id' => 'plan'], 'junk'],
			'experiments' => [['id' => 'e1', 'status' => 'running', 'variants' => [['id' => 'v1']]]],
		];
		$filter = new TrafficOutcomeParams();

		$kept = $filter->filter('page_view', ['cd_plan' => 'x', 'cd_other' => 'y', 'experiment' => 'e1', 'variant' => 'v1', 'free' => 'z'], $config);
		$this->assertSame(['cd_plan' => 'x', 'experiment' => 'e1', 'variant' => 'v1', 'free' => 'z'], $kept);

		$dropped = $filter->filter('page_view', ['experiment' => 'e1', 'variant' => 'nope'], $config);
		$this->assertSame([], $dropped);

		$this->assertSame([], $filter->filter('page_view', ['experiment' => 'e1', 'variant' => 3], $config));
	}//end testCustomAndExperimentParams()

	/**
	 * Form events keep only their allowed params.
	 *
	 * @return void
	 */
	public function testFormEventsAreRestricted(): void {
		$kept = (new TrafficOutcomeParams())->filter('form_field', ['formId' => 'f', 'fieldId' => 'a', 'value' => 'secret', 'ms' => 40], []);

		$this->assertSame(['formId' => 'f', 'fieldId' => 'a', 'ms' => 40], $kept);
	}//end testFormEventsAreRestricted()

	/**
	 * Heat events are clamped, truncated and stripped of ids.
	 *
	 * @return void
	 */
	public function testHeatEventsAreSanitised(): void {
		$kept = (new TrafficOutcomeParams())->filter(
			'heat_click',
			[
				'x' => 0.123456,
				'y' => 1.5,
				'depth' => '0.5',
				'vw' => '1280.7',
				'tag' => 'BUTTON',
				'selector' => 'div#main > a[href="x"].link',
				'other' => 'dropped',
			],
			[]
		);

		$this->assertSame(['x' => 0.1235, 'depth' => 0.5, 'vw' => 1280, 'tag' => 'button', 'selector' => 'div > a.link'], $kept);
	}//end testHeatEventsAreSanitised()

	/**
	 * Non-numeric fractions are dropped.
	 *
	 * @return void
	 */
	public function testHeatFractionMustBeNumeric(): void {
		$this->assertSame([], (new TrafficOutcomeParams())->filter('heat_scroll', ['x' => 'abc', 'y' => -1], []));
	}//end testHeatFractionMustBeNumeric()
}//end class
