<?php

// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Theme;

use OCA\Portaliq\Service\PortalObjectReader;
use OCA\Portaliq\Service\PortalObjectWriter;
use OCA\Portaliq\Service\PortalThemeResolver;
use OCA\Portaliq\Service\Theme\PortalThemeChoice;
use OCA\Portaliq\Service\Theme\PortalThemeContrast;
use OCA\Thematiq\Service\ContrastService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * nldesign-theme-integration: an administrator picks a portal's house style
 * from the sets the theme app offers, each listed with its contrast verdict;
 * a set that does not resolve is refused, and one that fails AA is saved only
 * when the administrator confirms. Built over the real resolver on a fixture
 * theme app and the theme app's real ContrastService.
 *
 * @spec openspec/changes/nldesign-theme-integration/specs/nldesign-theme-integration/spec.md
 */
class PortalThemeChoiceTest extends TestCase {

	/**
	 * The fixture theme app.
	 *
	 * @var string
	 */
	private string $themeRoot = '';

	/**
	 * The writer double.
	 *
	 * @var PortalObjectWriter&MockObject
	 */
	private $writer;

	/**
	 * A theme app with a readable set, a hard-to-read one, a bare one and a
	 * catalogued set whose file is missing.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->themeRoot = sys_get_temp_dir() . '/pq-theme-choice-' . bin2hex(random_bytes(6));
		mkdir($this->themeRoot . '/css/tokens', 0o777, true);
		file_put_contents(
			$this->themeRoot . '/css/tokens/readable.css',
			":root {\n  --c-white: #ffffff;\n  --nldesign-color-background: var(--c-white);\n  --nldesign-color-text: #1a1a1a;\n}\n"
		);
		file_put_contents(
			$this->themeRoot . '/css/tokens/faint.css',
			":root {\n  --nldesign-color-background: #ffffff;\n  --nldesign-color-text: #cccccc;\n}\n"
		);
		file_put_contents($this->themeRoot . '/css/tokens/bare.css', ":root {\n  --c-brand: #01689b;\n}\n");
		file_put_contents(
			$this->themeRoot . '/token-sets.json',
			(string)json_encode([
				['id' => 'readable', 'name' => 'Readable'],
				['id' => 'faint', 'name' => 'Faint'],
				['id' => 'bare', 'name' => 'Bare'],
				['id' => 'missing', 'name' => 'Missing file'],
			])
		);

	}//end setUp()

	/**
	 * Remove only what setUp created.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach (glob($this->themeRoot . '/css/tokens/*.css') ?: [] as $file) {
			unlink($file);
		}

		@unlink($this->themeRoot . '/token-sets.json');
		@rmdir($this->themeRoot . '/css/tokens');
		@rmdir($this->themeRoot . '/css');
		@rmdir($this->themeRoot);

	}//end tearDown()

	public function testEveryAdoptableSetIsListedWithItsVerdict(): void {
		$list = $this->choice()->listFor(portal: ['slug' => 'gemeente', 'theme' => 'readable']);

		$this->assertSame('readable', $list['current']);
		$this->assertTrue($list['currentResolves']);
		$this->assertSame(['readable', 'faint', 'bare'], array_column($list['sets'], 'id'));

		$verdicts = array_column($list['sets'], 'verdict', 'id');
		$this->assertTrue($verdicts['readable']['passes']);
		$this->assertFalse($verdicts['faint']['passes']);
		$this->assertSame(0, $verdicts['bare']['measured']);
		$this->assertFalse($verdicts['bare']['passes']);
	}//end testEveryAdoptableSetIsListedWithItsVerdict()

	public function testATypedThemeTheAppDoesNotOfferIsNamedAsNotResolving(): void {
		$list = $this->choice()->listFor(portal: ['slug' => 'gemeente', 'theme' => 'rotterdam']);

		$this->assertSame('rotterdam', $list['current']);
		$this->assertFalse($list['currentResolves']);
	}//end testATypedThemeTheAppDoesNotOfferIsNamedAsNotResolving()

	public function testASetThatDoesNotResolveIsRefused(): void {
		$choice = $this->choice();
		$this->writer->expects($this->never())->method('updateObject');

		$this->assertSame(['error' => 'unknown_theme'], $choice->choose(portal: ['slug' => 'gemeente', 'id' => 'p-1'], theme: 'missing'));
		$this->assertSame(['error' => 'unknown_theme'], $choice->chooseConfirmingFindings(portal: ['slug' => 'gemeente', 'id' => 'p-1'], theme: 'missing'));
	}//end testASetThatDoesNotResolveIsRefused()

	public function testAHardToReadSetIsRefusedWithItsFindingsUntilConfirmed(): void {
		$choice = $this->choice();
		$this->writer->expects($this->once())
			->method('updateObject')
			->with(
				$this->equalTo('portaliq'),
				$this->equalTo('portal'),
				$this->anything(),
				$this->equalTo('gemeente'),
				$this->anything(),
				$this->equalTo('p-1'),
				$this->equalTo(['theme' => 'faint'])
			)
			->willReturn(['slug' => 'gemeente', 'theme' => 'faint']);

		$refused = $choice->choose(portal: ['slug' => 'gemeente', 'id' => 'p-1'], theme: 'faint');
		$this->assertSame('contrast', $refused['error']);
		$this->assertSame('--nldesign-color-text', $refused['verdict']['findings'][0]['token']);

		$saved = $choice->chooseConfirmingFindings(portal: ['slug' => 'gemeente', 'id' => 'p-1'], theme: 'faint');
		$this->assertSame('faint', $saved['portal']['theme']);
	}//end testAHardToReadSetIsRefusedWithItsFindingsUntilConfirmed()

	public function testAReadableSetIsSavedStraightAway(): void {
		$choice = $this->choice();
		$this->writer->method('updateObject')->willReturn(['slug' => 'gemeente', 'theme' => 'readable']);

		$this->assertSame('readable', $choice->choose(portal: ['slug' => 'gemeente', 'id' => 'p-1'], theme: 'readable')['portal']['theme']);
	}//end testAReadableSetIsSavedStraightAway()

	public function testAFailedWriteIsReported(): void {
		$choice = $this->choice();
		$this->writer->method('updateObject')->willReturn(null);

		$this->assertSame(['error' => 'save_failed'], $choice->choose(portal: ['slug' => 'gemeente', 'id' => 'p-1'], theme: 'readable'));
	}//end testAFailedWriteIsReported()

	/**
	 * The choice over the fixture theme app and the real contrast service.
	 *
	 * @return PortalThemeChoice
	 */
	private function choice(): PortalThemeChoice {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturn(true);
		$appManager->method('getAppPath')->willReturn($this->themeRoot);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new ContrastService());

		$reader = $this->getMockBuilder(PortalObjectReader::class)
			->disableOriginalConstructor()
			->onlyMethods(['readCollection'])
			->getMock();

		$this->writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['updateObject'])
			->getMock();

		return new PortalThemeChoice(
			$reader,
			$this->writer,
			new PortalThemeResolver(appManager: $appManager),
			new PortalThemeContrast($container, $this->createMock(LoggerInterface::class))
		);
	}//end choice()

}//end class
