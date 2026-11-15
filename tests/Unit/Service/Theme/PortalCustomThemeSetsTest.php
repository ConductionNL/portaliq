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
use OCA\Thematiq\Service\CustomTokenSetValidator;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * A house style made or received in the theme app, checked again by the theme
 * app's own validator before a portal may link it (nldesign-theme-integration
 * 4.1 to 4.4). The validator is thematiq's real class (a verbatim copy under
 * tests/Stubs/Thematiq when thematiq is absent).
 */
class PortalCustomThemeSetsTest extends TestCase {


	/**
	 * The service over a container that knows the given services.
	 *
	 * @param array<string, object> $services Class name to service.
	 *
	 * @return PortalCustomThemeSets
	 */
	private function sets(array $services): PortalCustomThemeSets {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($services): object {
				if (isset($services[$id]) === false) {
					throw new RuntimeException('not registered: ' . $id);
				}

				return $services[$id];
			}
		);

		return new PortalCustomThemeSets(container: $container);
	}//end sets()


	/**
	 * The custom-set store as the theme app answers `list()`: id and the
	 * manifest's metadata.
	 *
	 * @param array<int, array<string, mixed>> $listed What `list()` returns.
	 *
	 * @return object
	 */
	private function store(array $listed): object {
		return new class($listed) {
			/**
			 * @param array<int, array<string, mixed>> $listed
			 */
			public function __construct(private array $listed) {
			}

			/**
			 * @return array<int, array<string, mixed>>
			 */
			public function list(): array {
				return $this->listed;
			}
		};
	}//end store()


	/**
	 * A set the theme app wrote itself, through the validator's own serialiser.
	 *
	 * @param array<string, string> $declarations The tokens.
	 *
	 * @return string
	 */
	private function servedFile(array $declarations): string {
		return (new CustomTokenSetValidator())->serialize(declarations: $declarations);
	}//end servedFile()


	/**
	 * Task 4.1: the theme app's custom sets (uploads and shared imports) are
	 * offered, marked custom, and an entry without a custom id is left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function testTheThemeAppsCustomSetsAreListed(): void {
		$sets = $this->sets(
			[
				'OCA\\Thematiq\\Service\\CustomTokenSetService' => $this->store(
					[
						['id' => 'custom-gemeente-noord', 'name' => 'Gemeente Noord', 'uploadedAt' => '2026-09-29'],
						['id' => 'vng', 'name' => 'Not custom'],
						['id' => 'custom-../../x', 'name' => 'Traversal'],
					]
				),
			]
		);

		$this->assertSame(
			[['id' => 'custom-gemeente-noord', 'name' => 'Gemeente Noord', 'custom' => true]],
			$sets->all()
		);
	}//end testTheThemeAppsCustomSetsAreListed()


	/**
	 * Without the theme app nothing custom is offered, and nothing throws.
	 *
	 * @return void
	 */
	public function testNoThemeAppMeansNoCustomSets(): void {
		$this->assertSame([], $this->sets([])->all());
	}//end testNoThemeAppMeansNoCustomSets()


	/**
	 * The positive control: a set the theme app wrote, a data: logo with its
	 * `;` included, passes. Without this every refusal below is satisfied by
	 * a check that refuses everything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function testASetTheThemeAppWroteMayBeLinked(): void {
		$sets = $this->sets(['OCA\\Thematiq\\Service\\CustomTokenSetValidator' => new CustomTokenSetValidator()]);
		$css = $this->servedFile(
			[
				'--nldesign-color-primary' => '#01689b',
				'--utrecht-document-background-color' => '#ffffff',
				'--nldesign-logo-url' => "url('data:image/svg+xml;base64,PHN2Zy8+')",
			]
		);

		$this->assertNull($sets->refusal(id: 'custom-gemeente-noord', css: $css));
	}//end testASetTheThemeAppWroteMayBeLinked()


	/**
	 * Task 4.4: a hostile declaration is refused, and the refusal names why.
	 * Each shape is one a shared bundle from another instance could carry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/nldesign-theme-integration/tasks.md
	 */
	public function testAHostileDeclarationIsRefusedByName(): void {
		$sets = $this->sets(['OCA\\Thematiq\\Service\\CustomTokenSetValidator' => new CustomTokenSetValidator()]);
		$hostile = [
			'expression' => ":root {\n  --nldesign-color-primary: expression(alert(1));\n}",
			'javascript url' => ":root {\n  --nldesign-logo-url: url(javascript:alert(1));\n}",
			'external url' => ":root {\n  --nldesign-logo-url: url(https://evil.example/x.svg);\n}",
			'unjudged name' => ":root {\n  --utrecht-colorPrimary: expression(alert(1));\n}",
			'second block' => ":root {\n  --nldesign-color-primary: #000;\n}\nbody { background: red; }",
			'at-rule' => "@import url(https://evil.example/a.css);\n:root {\n  --nldesign-color-primary: #000;\n}",
		];

		foreach ($hostile as $shape => $css) {
			$refusal = $sets->refusal(id: 'custom-shared', css: $css);
			$this->assertIsString($refusal, $shape . ' must be refused');
			$this->assertNotSame('', $refusal, $shape . ' must say why');
		}
	}//end testAHostileDeclarationIsRefusedByName()


	/**
	 * Fails closed: with no validator nothing custom may be linked.
	 *
	 * @return void
	 */
	public function testWithoutTheValidatorNothingIsLinked(): void {
		$css = $this->servedFile(['--nldesign-color-primary' => '#01689b']);
		$this->assertSame(
			'the theme app cannot check this house style',
			$this->sets([])->refusal(id: 'custom-gemeente-noord', css: $css)
		);
	}//end testWithoutTheValidatorNothingIsLinked()


	/**
	 * A file with no tokens at all is refused rather than linked as an empty
	 * house style.
	 *
	 * @return void
	 */
	public function testAFileWithoutTokensIsRefused(): void {
		$sets = $this->sets(['OCA\\Thematiq\\Service\\CustomTokenSetValidator' => new CustomTokenSetValidator()]);
		$this->assertSame('the file declares no tokens', $sets->refusal(id: 'custom-leeg', css: ':root {}'));
	}//end testAFileWithoutTokensIsRefused()
}//end class
