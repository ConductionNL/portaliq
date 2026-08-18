<?php

/**
 * Portaliq Portal Theme Contrast Test
 *
 * That an unmeasured theme is never reported as a passing one.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Service\PortalThemeContrast;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The adoption-time contrast verdict.
 *
 * THE DEFECT THIS PINS, and it shipped for ten minutes. The first version
 * reported `passes: true` whenever it found no failures — which is also what a
 * theme declaring NONE of the tokens produces. Against the real catalogue it
 * announced **46 of 46 sets passing** while **43 of them had zero pairs
 * compared**. That reads as a clean bill of health for a check that never ran.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
class PortalThemeContrastTest extends TestCase {

	/**
	 * A contrast service standing in for the theme app's.
	 *
	 * Its arithmetic is the real thing's rule — a pass above 4.5 — so the
	 * verdicts here are about THIS class's reporting rather than about WCAG.
	 *
	 * @return object The stand-in.
	 */
	private function contrastService(): object {
		return new class {

			/**
			 * @param array<int, array<string, mixed>> $candidates The colours.
			 * @param string                           $background The backdrop.
			 *
			 * @return array<int, array<string, mixed>> The results.
			 */
			public function evaluate(array $candidates, string $background): array {
				$results = [];
				foreach ($candidates as $candidate) {
					// White on white fails, anything else passes — enough to
					// drive both branches without reimplementing WCAG here.
					$pass = strtolower((string)$candidate['value']) !== strtolower($background);
					$results[] = [
						'name' => $candidate['name'],
						'ratio' => ($pass === true ? 7.2 : 1.0),
						'threshold' => 4.5,
						'pass' => $pass,
					];
				}

				return $results;
			}

		};
	}


	/**
	 * The service under test, with or without the theme app present.
	 *
	 * @param bool $withThemeApp Whether the contrast service resolves.
	 *
	 * @return PortalThemeContrast The service.
	 */
	private function service(bool $withThemeApp = true): PortalThemeContrast {
		$container = $this->createMock(ContainerInterface::class);
		if ($withThemeApp === true) {
			$container->method('get')->willReturn($this->contrastService());
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('no theme app'));
		}

		return new PortalThemeContrast($container, $this->createMock(LoggerInterface::class));
	}


	/**
	 * A THEME DECLARING NONE OF THESE TOKENS IS NOT A PASSING THEME.
	 *
	 * 43 of the 46 shipped sets are in exactly this state, and the first
	 * version of this class called every one of them compliant.
	 *
	 * @return void
	 */
	public function testAThemeThatDeclaresNoneOfTheseTokensIsNotReportedAsPassing(): void {
		$verdict = $this->service()->evaluate(tokens: ['--something-else' => '#fff']);

		$this->assertSame(0, $verdict['measured']);
		$this->assertFalse($verdict['evaluated'], 'a set with nothing to measure was reported as evaluated');
		$this->assertFalse($verdict['passes'], 'a set with nothing to measure was reported as passing');
	}


	/**
	 * A theme whose tokens are readable passes, and says how much was measured.
	 *
	 * @return void
	 */
	public function testAReadableThemePassesAndReportsWhatWasMeasured(): void {
		$verdict = $this->service()->evaluate(
			tokens: [
				'--nldesign-color-background' => '#ffffff',
				'--nldesign-color-text' => '#1b1b23',
			]
		);

		$this->assertTrue($verdict['evaluated']);
		$this->assertSame(1, $verdict['measured']);
		$this->assertTrue($verdict['passes']);
		$this->assertSame([], $verdict['findings']);
	}


	/**
	 * A failing pair is NAMED, with the surface and the measured ratio.
	 *
	 * "This theme fails AA" sends somebody hunting; the token, the surface and
	 * the number send them to the line to change.
	 *
	 * @return void
	 */
	public function testAFailingPairIsNamedWithItsRatioAndSurface(): void {
		$verdict = $this->service()->evaluate(
			tokens: [
				'--nldesign-color-background' => '#ffffff',
				'--nldesign-color-text' => '#ffffff',
			]
		);

		$this->assertTrue($verdict['evaluated']);
		$this->assertFalse($verdict['passes']);
		$this->assertCount(1, $verdict['findings']);
		$this->assertSame('page', $verdict['findings'][0]['surface']);
		$this->assertSame('--nldesign-color-text', $verdict['findings'][0]['token']);
		$this->assertSame(1.0, $verdict['findings'][0]['ratio']);
	}


	/**
	 * WITHOUT THE THEME APP, THE VERDICT IS "NOT CHECKED", NOT "FINE".
	 *
	 * @return void
	 */
	public function testWithoutTheThemeAppTheVerdictIsUnevaluatedRatherThanPassing(): void {
		$verdict = $this->service(withThemeApp: false)->evaluate(
			tokens: [
				'--nldesign-color-background' => '#ffffff',
				'--nldesign-color-text' => '#1b1b23',
			]
		);

		$this->assertFalse($verdict['evaluated']);
		$this->assertFalse($verdict['passes']);
		$this->assertSame(0, $verdict['measured']);
	}


	/**
	 * A surface whose BACKGROUND the theme does not declare is skipped, and the
	 * skip is visible in the count rather than counted as a pass.
	 *
	 * @return void
	 */
	public function testASurfaceWithoutABackgroundTokenIsSkippedNotPassed(): void {
		$verdict = $this->service()->evaluate(
			tokens: [
				// Footer text, but no footer background.
				'--nldesign-footer-legal-color' => '#ffffff',
			]
		);

		$this->assertSame(0, $verdict['measured']);
		$this->assertFalse($verdict['passes']);
	}


}//end class
