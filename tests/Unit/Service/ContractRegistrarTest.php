<?php

/**
 * ContractRegistrar and ContributionContractRegistrar: every kernel contract
 * is aliased once, to a class that implements it.
 *
 * @category Tests
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
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service;

use OCA\Portaliq\Contribution\ContributionContractRegistrar;
use OCA\Portaliq\Service\ContractRegistrar;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The contract wiring.
 */
class ContractRegistrarTest extends TestCase {
	/**
	 * The aliases the two registrars register, as [contract, implementation] pairs.
	 *
	 * @return array<int, array{0: string, 1: string}>
	 */
	private function aliases(): array {
		$aliases = [];
		$context = $this->createMock(originalClassName: IRegistrationContext::class);
		$context->method('registerServiceAlias')->willReturnCallback(
			static function (string $alias, string $target) use (&$aliases): void {
				$aliases[] = [$alias, $target];
			}
		);
		(new ContractRegistrar())->register(context: $context);
		(new ContributionContractRegistrar())->register(context: $context);
		return $aliases;
	}//end aliases()

	/**
	 * Every alias points a kernel contract at a concrete class that implements it.
	 *
	 * @return void
	 */
	public function testEveryAliasPointsAtAnImplementation(): void {
		$aliases = $this->aliases();
		$this->assertGreaterThan(expected: 250, actual: count($aliases));
		foreach ($aliases as [$contract, $implementation]) {
			$this->assertTrue(condition: interface_exists($contract), message: $contract.' is not an interface');
			$this->assertStringStartsWith(prefix: 'OCA\\Portaliq\\Kernel\\Contracts\\', string: $contract);
			$this->assertTrue(condition: class_exists($implementation), message: $implementation.' does not exist');
			$class = new ReflectionClass(objectOrClass: $implementation);
			$this->assertFalse(condition: $class->isAbstract(), message: $implementation.' is abstract');
			$this->assertTrue(condition: $class->implementsInterface($contract), message: $implementation.' does not implement '.$contract);
		}
	}//end testEveryAliasPointsAtAnImplementation()

	/**
	 * No contract is aliased twice, so the last registration can never silently win.
	 *
	 * @return void
	 */
	public function testNoContractIsAliasedTwice(): void {
		$contracts = array_column($this->aliases(), 0);
		$counts    = array_filter(array_count_values($contracts), static fn (int $count): bool => $count > 1);
		$this->assertSame(expected: [], actual: $counts);
	}//end testNoContractIsAliasedTwice()

	/**
	 * Every contract a concrete app class implements is aliased, so the container can resolve it.
	 *
	 * @return void
	 */
	public function testEveryImplementedContractIsAliased(): void {
		$aliased = array_flip(array_column($this->aliases(), 0));
		$missing = [];
		foreach ($this->appClasses() as $class) {
			$reflection = new ReflectionClass(objectOrClass: $class);
			if ($reflection->isInterface() === true || $reflection->isAbstract() === true || $reflection->isTrait() === true) {
				continue;
			}

			foreach ($reflection->getInterfaceNames() as $contract) {
				if (str_starts_with($contract, 'OCA\\Portaliq\\Kernel\\Contracts\\') === true && isset($aliased[$contract]) === false) {
					$missing[$contract] = $class;
				}
			}
		}

		$this->assertSame(expected: [], actual: $missing);
	}//end testEveryImplementedContractIsAliased()

	/**
	 * The classes declared under lib/Service and lib/Contribution.
	 *
	 * @return array<int, class-string>
	 */
	private function appClasses(): array {
		$lib     = dirname(__DIR__, 3).'/lib';
		$classes = [];
		foreach (['Service', 'Contribution', 'Kernel/Support'] as $folder) {
			$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib.'/'.$folder, \FilesystemIterator::SKIP_DOTS));
			foreach ($files as $file) {
				if ($file->getExtension() !== 'php') {
					continue;
				}

				$class = 'OCA\\Portaliq\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($lib) + 1));
				if (class_exists($class) === true) {
					$classes[] = $class;
				}
			}
		}

		$this->assertNotEmpty(actual: $classes);
		return $classes;
	}//end appClasses()
}//end class
