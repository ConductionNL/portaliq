<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\Theme\PortalThemeContrast;
use OCA\Thematiq\Service\ContrastService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * nldesign-theme-integration: a theme's contrast verdict is computed by the
 * theme app's own ContrastService (the real class, a verbatim copy under
 * tests/Stubs/Thematiq), names each failing token with its ratio, and never
 * reads as a pass when nothing was measured or the theme app is absent.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */
class PortalThemeContrastTest extends TestCase {

	public function testReadableTextOnEverySurfacePasses(): void {
		$verdict = $this->contrast(service: new ContrastService())->evaluate(tokens: [
			'--nldesign-color-background' => '#ffffff',
			'--nldesign-color-text' => '#1a1a1a',
			'--nldesign-color-footer-background' => '#154273',
			'--nldesign-footer-legal-color' => '#ffffff',
		]);

		$this->assertTrue($verdict['evaluated']);
		$this->assertSame(2, $verdict['measured']);
		$this->assertTrue($verdict['passes']);
		$this->assertSame([], $verdict['findings']);
	}//end testReadableTextOnEverySurfacePasses()

	public function testALowContrastTokenIsNamedWithItsRatio(): void {
		$verdict = $this->contrast(service: new ContrastService())->evaluate(tokens: [
			'--nldesign-color-background' => '#ffffff',
			'--nldesign-color-text' => '#1a1a1a',
			'--nldesign-color-footer-background' => '#e6e6e6',
			'--nldesign-footer-heading-color' => '#ffffff',
		]);

		$this->assertTrue($verdict['evaluated']);
		$this->assertFalse($verdict['passes']);
		$this->assertCount(1, $verdict['findings']);
		$this->assertSame('footer', $verdict['findings'][0]['surface']);
		$this->assertSame('--nldesign-footer-heading-color', $verdict['findings'][0]['token']);
		$this->assertLessThan(4.5, $verdict['findings'][0]['ratio']);
		$this->assertSame(4.5, $verdict['findings'][0]['threshold']);
	}//end testALowContrastTokenIsNamedWithItsRatio()

	public function testASetDeclaringNoSurfaceTokenIsNotChecked(): void {
		$verdict = $this->contrast(service: new ContrastService())->evaluate(tokens: ['--c-brand' => '#01689b']);

		$this->assertFalse($verdict['evaluated']);
		$this->assertSame(0, $verdict['measured']);
		$this->assertFalse($verdict['passes']);
	}//end testASetDeclaringNoSurfaceTokenIsNotChecked()

	public function testAValueTheServiceCannotReadIsNotCountedAsMeasured(): void {
		$verdict = $this->contrast(service: new ContrastService())->evaluate(tokens: [
			'--nldesign-color-background' => '#ffffff',
			'--nldesign-color-text' => 'var(--unresolved)',
		]);

		$this->assertSame(0, $verdict['measured']);
		$this->assertFalse($verdict['passes']);
	}//end testAValueTheServiceCannotReadIsNotCountedAsMeasured()

	public function testWithoutTheThemeAppNothingIsEvaluated(): void {
		$verdict = $this->contrast(service: null)->evaluate(tokens: [
			'--nldesign-color-background' => '#ffffff',
			'--nldesign-color-text' => '#1a1a1a',
		]);

		$this->assertFalse($verdict['evaluated']);
		$this->assertFalse($verdict['passes']);
	}//end testWithoutTheThemeAppNothingIsEvaluated()

	/**
	 * The contrast check with the theme app's service, or without one.
	 *
	 * @param object|null $service The service the container answers.
	 *
	 * @return PortalThemeContrast
	 */
	private function contrast(?object $service): PortalThemeContrast {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($service !== null && $id === ContrastService::class) {
					return $service;
				}

				throw new RuntimeException('not installed: ' . $id);
			}
		);

		return new PortalThemeContrast($container, $this->createMock(LoggerInterface::class));
	}//end contrast()

}//end class
