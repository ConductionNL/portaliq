<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * portal-cms-content-model tasks 1 and 2: the content schemas reject what the
 * spec says they reject and accept the menu shape OpenCatalogi deploys.
 *
 * @spec openspec/changes/portal-cms-content-model/tasks.md#task-2
 */
class CmsContentModelTest extends TestCase {
	/**
	 * @var array<string, object>
	 */
	private static array $schemas = [];

	public static function setUpBeforeClass(): void {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/portaliq_register.json'), false);
		foreach (['portal', 'menu', 'page', 'glossaryTerm', 'media'] as $slug) {
			self::$schemas[$slug] = $register->components->schemas->$slug;
		}
	}//end setUpBeforeClass()

	/**
	 * Whether the data satisfies the declared schema.
	 *
	 * @param string $schema The schema slug.
	 * @param array  $data   The object to write.
	 */
	private function valid(string $schema, array $data): bool {
		$validator = new Validator();
		$result    = $validator->validate(json_decode((string)json_encode($data)), self::$schemas[$schema]);

		return $result->isValid();
	}//end valid()

	/**
	 * A menu as OpenCatalogi stores it today: Dutch names, two levels, every
	 * deployed key present. Taken from the TMenu shape of a live export.
	 *
	 * @return array<string, mixed>
	 */
	private function deployedMenu(): array {
		return [
			'title'    => 'Hoofdmenu',
			'position' => 1,
			'portal'   => 'gemeente',
			'items'    => [
				[
					'order'           => 0,
					'name'            => 'Over ons',
					'link'            => '/over-ons',
					'description'     => 'Wie wij zijn',
					'icon'            => 'Information',
					'groups'          => [],
					'hideBeforeLogin' => false,
					'hideAfterLogin'  => false,
					'items'           => [
						['order' => 0, 'name' => 'Contact', 'link' => '/contact', 'icon' => 'Email', 'groups' => ['staff'], 'hideBeforeLogin' => true, 'hideAfterLogin' => false],
					],
				],
			],
		];
	}//end deployedMenu()

	public function testADeployedMenuValidatesUnchanged(): void {
		$this->assertTrue($this->valid('menu', $this->deployedMenu()));
	}//end testADeployedMenuValidatesUnchanged()

	public function testThreeLevelNestingIsRejected(): void {
		$menu = $this->deployedMenu();
		$menu['items'][0]['items'][0]['items'] = [['name' => 'Too deep']];

		$this->assertFalse($this->valid('menu', $menu));
	}//end testThreeLevelNestingIsRejected()

	public function testAContentObjectWithoutAPortalIsRejected(): void {
		$menu = $this->deployedMenu();
		unset($menu['portal']);
		$this->assertFalse($this->valid('menu', $menu));
		$this->assertFalse($this->valid('page', ['title' => 'Over ons', 'route' => '/over-ons', 'status' => 'published']));
		$this->assertFalse($this->valid('glossaryTerm', ['term' => 'Woo', 'definition' => 'x']));
		$this->assertFalse($this->valid('media', ['title' => 'Foto', 'kind' => 'image', 'status' => 'draft']));
	}//end testAContentObjectWithoutAPortalIsRejected()

	public function testAGridBodyMissingAWidgetPlacementIsRejected(): void {
		$page = ['title' => 'Home', 'route' => '/', 'status' => 'draft', 'portal' => 'gemeente'];
		$full = ['widgetKey' => 'markdown', 'gridX' => 0, 'gridY' => 0, 'gridWidth' => 12, 'gridHeight' => 2];
		$this->assertTrue($this->valid('page', $page + ['body' => ['type' => 'grid', 'widgets' => [$full]]]));

		unset($full['gridWidth']);
		$this->assertFalse($this->valid('page', $page + ['body' => ['type' => 'grid', 'widgets' => [$full]]]));
	}//end testAGridBodyMissingAWidgetPlacementIsRejected()

	public function testAnUnknownBodyTypeIsRejected(): void {
		$page = ['title' => 'Home', 'route' => '/', 'status' => 'draft', 'portal' => 'gemeente'];

		$this->assertFalse($this->valid('page', $page + ['body' => ['type' => 'html', 'markdown' => '<p>x</p>']]));
		$this->assertTrue($this->valid('page', $page + ['body' => ['type' => 'markdown', 'markdown' => "## Kop\n\n  tekst  \n"]]));
	}//end testAnUnknownBodyTypeIsRejected()

	public function testThePortalDeclaresWhatARendererNeeds(): void {
		$props = array_keys((array)self::$schemas['portal']->properties);
		foreach (['title', 'domains', 'locales', 'status', 'theme'] as $key) {
			$this->assertContains($key, $props, 'portal declares ' . $key);
		}
	}//end testThePortalDeclaresWhatARendererNeeds()
}//end class
