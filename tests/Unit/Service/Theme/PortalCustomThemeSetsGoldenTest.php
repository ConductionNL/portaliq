<?php
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Portaliq\Tests\Unit\Service\Theme
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/portaliq
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\Theme\PortalCustomThemeSets;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * The exact verdicts and declarations PortalCustomThemeSets hands the theme
 * app's validator, and the exact sets it lists.
 */
class PortalCustomThemeSetsGoldenTest extends TestCase {
	private const VALIDATOR = 'OCA\\Thematiq\\Service\\CustomTokenSetValidator';

	/**
	 * The service over a container that knows the given services.
	 *
	 * @param array<string, mixed> $services Class name to service.
	 *
	 * @return PortalCustomThemeSets
	 */
	private function sets(array $services): PortalCustomThemeSets {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): mixed {
				if (array_key_exists($id, $services) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $services[$id];
			}
		);

		return new PortalCustomThemeSets(container: $container);
	}//end sets()

	/**
	 * A validator that records what it judged and answers as told.
	 *
	 * @param bool       $passes   Whether validateDeclarations() passes.
	 * @param bool       $disallow What hasDisallowedSelector() answers.
	 * @param mixed      $last     What getLastError() answers.
	 *
	 * @return object
	 */
	private function validator(bool $passes, bool $disallow=false, mixed $last=null): object {
		return new class($passes, $disallow, $last) {
			/** @var array<int, array{0: array<string, string>, 1: string}> */
			public array $judged = [];

			public function __construct(private bool $passes, private bool $disallow, private mixed $last) {
			}

			public function hasDisallowedSelector(string $css): bool {
				return $this->disallow;
			}

			/** @param array<string, string> $declarations */
			public function validateDeclarations(array $declarations, string $name): ?string {
				$this->judged[] = [$declarations, $name];
				if ($this->passes === true) {
					return 'ok';
				}

				return null;
			}

			public function getLastError(): mixed {
				return $this->last;
			}
		};
	}//end validator()

	/**
	 * The declarations are read from the one block: comments dropped, a `;`
	 * inside quotes or brackets kept, non-custom names and bare words skipped.
	 *
	 * @return void
	 */
	public function testTheDeclarationsTheValidatorJudges(): void {
		$validator = $this->validator(true);
		$css = "/* a; b */ :root {\n  --a: url(\"x;y\");\n  color: red;\n  --b: 'q;r' ;\n  junk;\n  --c: calc((1px + 2px); \n  --d: f(g(h));\n}";

		$this->assertNull($this->sets([self::VALIDATOR => $validator])->refusal(id: 'custom-noord', css: $css));
		$this->assertSame(
			[[['--a' => 'url("x;y")', '--b' => "'q;r'", '--c' => 'calc((1px + 2px); 
  --d: f(g(h));'], 'noord']],
			$validator->judged
		);

		$bare = $this->validator(true);
		$this->sets([self::VALIDATOR => $bare])->refusal(id: 'custom-x', css: '--e: 1; --f :2');
		$this->assertSame([[['--e' => '1', '--f' => '2'], 'x']], $bare->judged);
	}//end testTheDeclarationsTheValidatorJudges()

	/**
	 * Each refusal in its own words; a throwing validator refuses too.
	 *
	 * @return void
	 */
	public function testEveryRefusal(): void {
		$file = ":root { --a: 1; }";
		$this->assertSame('the file holds more than one :root block of tokens', $this->sets([self::VALIDATOR => $this->validator(true, true)])->refusal(id: 'custom-x', css: $file));
		$this->assertSame('Bad colour', $this->sets([self::VALIDATOR => $this->validator(false, false, ['message' => 'Bad colour'])])->refusal(id: 'custom-x', css: $file));
		$this->assertSame('a declaration was refused', $this->sets([self::VALIDATOR => $this->validator(false, false, ['message' => 5])])->refusal(id: 'custom-x', css: $file));
		$this->assertSame('a declaration was refused', $this->sets([self::VALIDATOR => $this->validator(false, false, 'text')])->refusal(id: 'custom-x', css: $file));

		$plain = new class {
			/** @param array<string, string> $declarations */
			public function validateDeclarations(array $declarations, string $name): ?string {
				return null;
			}
		};
		$this->assertSame('a declaration was refused', $this->sets([self::VALIDATOR => $plain])->refusal(id: 'custom-x', css: $file));

		$throws = new class {
			/** @param array<string, string> $declarations */
			public function validateDeclarations(array $declarations, string $name): ?string {
				throw new RuntimeException('broken');
			}
		};
		$this->assertSame('the theme app could not check this house style', $this->sets([self::VALIDATOR => $throws])->refusal(id: 'custom-x', css: $file));
		$this->assertSame('the theme app cannot check this house style', $this->sets([self::VALIDATOR => new \stdClass()])->refusal(id: 'custom-x', css: $file));
		$this->assertNull($this->sets(['OCA\\NLDesign\\Service\\CustomTokenSetValidator' => $this->validator(true)])->refusal(id: 'custom-x', css: $file), 'the former name still checks');
	}//end testEveryRefusal()

	/**
	 * The listed sets: entries that are not arrays or not custom are left
	 * out, a missing name is the id; a failing or absent store lists none;
	 * a shipped set of the same id stays.
	 *
	 * @return void
	 */
	public function testTheListedSets(): void {
		$store = new class {
			/** @return array<int, mixed> */
			public function list(): array {
				return ['junk', ['id' => 'custom-a'], ['id' => 'custom-b', 'name' => 'B'], ['name' => 'no id']];
			}
		};
		$sets = $this->sets(['OCA\\Thematiq\\Service\\CustomTokenSetService' => null, 'OCA\\NLDesign\\Service\\CustomTokenSetService' => $store]);
		$this->assertSame([['id' => 'custom-a', 'name' => 'custom-a', 'custom' => true], ['id' => 'custom-b', 'name' => 'B', 'custom' => true]], $sets->all());
		$this->assertSame(
			[['id' => 'custom-a', 'name' => 'Shipped'], ['id' => 'custom-b', 'name' => 'B', 'custom' => true]],
			$sets->mergedInto([['id' => 'custom-a', 'name' => 'Shipped']])
		);

		$failing = new class {
			/** @return array<int, mixed> */
			public function list(): array {
				throw new RuntimeException('down');
			}
		};
		$this->assertSame([], $this->sets(['OCA\\Thematiq\\Service\\CustomTokenSetService' => $failing])->all());
		$this->assertSame([], $this->sets(['OCA\\Thematiq\\Service\\CustomTokenSetService' => new \stdClass()])->all());
	}//end testTheListedSets()
}//end class
