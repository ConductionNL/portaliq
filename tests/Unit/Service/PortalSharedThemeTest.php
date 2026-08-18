<?php

/**
 * Portaliq Portal Shared Theme Test
 *
 * That a hostile shared bundle is refused, and that the refusal is visible.
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

use OCA\Portaliq\Service\PortalSharedTheme;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Adopting a token set from another instance.
 *
 * SHARED CONFIGURATION IS INPUT FROM SOMEBODY ELSE'S SERVER, and these tests
 * are about the two ways that goes wrong quietly: a hostile declaration being
 * stored, and a refused bundle being indistinguishable from an adopted one
 * that happened to contain nothing.
 *
 * @spec openspec/changes/nldesign-theme-integration/tasks.md
 */
class PortalSharedThemeTest extends TestCase {

	/**
	 * A validator standing in for the theme app's.
	 *
	 * Its rule mirrors the real one's contract: `--nldesign-*` and
	 * `--{slug}-*` are accepted, anything else is skipped, and a forbidden
	 * construct in an accepted declaration is a HARD FAILURE returning null.
	 *
	 * @return object The stand-in.
	 */
	private function validator(): object {
		return new class {

			/** @var array<string, string>|null The last hard failure. */
			private ?array $lastError = null;

			/**
			 * @param array<string, string> $declarations The declarations.
			 * @param string                $slug         The set slug.
			 *
			 * @return array{accepted: array<string, string>, skipped: array<int, string>}|null The split.
			 */
			public function validateDeclarations(array $declarations, string $slug): ?array {
				$accepted = [];
				$skipped = [];

				foreach ($declarations as $name => $value) {
					if (str_starts_with($name, '--nldesign-') === false
						&& str_starts_with($name, '--' . $slug . '-') === false
					) {
						$skipped[] = $name;
						continue;
					}

					if (preg_match('/url\(|expression\(|javascript:|@import|<\/?[a-z]/i', $value) === 1) {
						$this->lastError = ['message' => 'forbidden construct in ' . $name];
						return null;
					}

					$accepted[$name] = $value;
				}

				return ['accepted' => $accepted, 'skipped' => $skipped];
			}

			/**
			 * @return array<string, string>|null The last hard failure.
			 */
			public function getLastError(): ?array {
				return $this->lastError;
			}

		};
	}


	/**
	 * The service, with the theme app present or absent.
	 *
	 * @param bool $withThemeApp Whether the validator resolves.
	 *
	 * @return PortalSharedTheme The service.
	 */
	private function service(bool $withThemeApp = true): PortalSharedTheme {
		$container = $this->createMock(ContainerInterface::class);
		if ($withThemeApp === true) {
			// The config type is deliberately NOT provided: a bundle is data,
			// and adoption must not depend on which optional helper happens to
			// be registered.
			$validator = $this->validator();
			$container->method('get')->willReturnCallback(
				static function (string $id) use ($validator): object {
					if (str_contains($id, 'CustomTokenSetValidator') === true) {
						return $validator;
					}

					throw new \RuntimeException('not registered');
				}
			);
		} else {
			$container->method('get')->willThrowException(new \RuntimeException('no theme app'));
		}

		return new PortalSharedTheme($container, $this->createMock(LoggerInterface::class));
	}


	/**
	 * A well-formed bundle is adopted, and non-theme declarations are reported.
	 *
	 * @return void
	 */
	public function testAWellFormedBundleIsAdoptedAndSkippedKeysAreReported(): void {
		$result = $this->service()->adopt(
			bundle: [
				'declarations' => [
					'--nldesign-color-primary' => '#21468b',
					'--color-primary' => '#ff0000',
				],
			],
			slug: 'lafranken'
		);

		$this->assertTrue($result['adopted']);
		$this->assertSame(['--nldesign-color-primary' => '#21468b'], $result['tokens']);
		$this->assertSame(['--color-primary'], $result['skipped']);
		$this->assertSame('', $result['refusal']);
	}


	/**
	 * A HOSTILE DECLARATION IS REFUSED, AND THE REFUSAL SAYS WHY (task 4.4).
	 *
	 * @dataProvider hostileProvider
	 *
	 * @param string $value The hostile value.
	 *
	 * @return void
	 */
	public function testAHostileDeclarationIsRefusedVisibly(string $value): void {
		$result = $this->service()->adopt(
			bundle: ['declarations' => ['--nldesign-color-primary' => $value]],
			slug: 'lafranken'
		);

		$this->assertFalse($result['adopted'], "adopted a bundle containing: {$value}");
		$this->assertSame([], $result['tokens']);
		// VISIBLE means it NAMES something. An empty refusal string is a
		// refusal an operator cannot act on and cannot tell from a no-op.
		$this->assertNotSame('', $result['refusal']);
		$this->assertStringContainsString('--nldesign-color-primary', $result['refusal']);
	}


	/**
	 * The shapes a shared set could try to inject CSS with.
	 *
	 * @return array<string, array{0: string}> The cases.
	 */
	public static function hostileProvider(): array {
		return [
			'remote url' => ['url(https://evil.test/x.png)'],
			'legacy expression' => ['expression(alert(1))'],
			'javascript scheme' => ['javascript:alert(1)'],
			'nested import' => ['@import url(https://evil.test/x.css)'],
			'markup' => ['</style><script>alert(1)</script>'],
		];
	}


	/**
	 * A bundle with nothing adoptable is REFUSED, not silently adopted empty.
	 *
	 * A portal that adopted nothing renders unstyled, and "the theme did not
	 * apply" is the one outcome an operator cannot distinguish from "the theme
	 * is subtle".
	 *
	 * @return void
	 */
	public function testABundleWithNothingAdoptableIsRefusedRatherThanEmpty(): void {
		$result = $this->service()->adopt(
			bundle: ['declarations' => ['--color-primary' => '#ff0000']],
			slug: 'lafranken'
		);

		$this->assertFalse($result['adopted']);
		$this->assertNotSame('', $result['refusal']);
	}


	/**
	 * An empty bundle is refused with its own reason.
	 *
	 * @return void
	 */
	public function testAnEmptyBundleIsRefused(): void {
		$result = $this->service()->adopt(bundle: [], slug: 'lafranken');

		$this->assertFalse($result['adopted']);
		$this->assertStringContainsString('no token declarations', $result['refusal']);
	}


	/**
	 * WITHOUT THE THEME APP, NOTHING IS ADOPTED — validation is not optional.
	 *
	 * Failing open here would mean storing another instance's declarations
	 * unvalidated whenever the validator happened to be unavailable, which is
	 * the one circumstance in which it matters most.
	 *
	 * @return void
	 */
	public function testWithoutTheValidatorNothingIsAdopted(): void {
		$result = $this->service(withThemeApp: false)->adopt(
			bundle: ['declarations' => ['--nldesign-color-primary' => '#21468b']],
			slug: 'lafranken'
		);

		$this->assertFalse($result['adopted']);
		$this->assertStringContainsString('validate', $result['refusal']);
	}


}//end class
