<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Traffic\Geo;

use OCA\Portaliq\Service\Traffic\Geo\GeoDatabaseStore;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Files\Storage\IStorage;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Where the geography database lives on disk (the app data folder's local
 * path, else the data directory), reading its metadata and installing a
 * verified download.
 */
#[CoversClass(GeoDatabaseStore::class)]
class GeoDatabaseStoreTest extends TestCase {

	private string $root = '';

	private IAppData&MockObject $appData;
	private IRootFolder&MockObject $rootFolder;
	private IConfig&MockObject $config;
	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/geo-store-' . bin2hex(random_bytes(6));
		mkdir($this->root);
		$this->appData = $this->createMock(IAppData::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	protected function tearDown(): void {
		$this->remove($this->root);
	}//end tearDown()

	/**
	 * Remove a directory tree.
	 *
	 * @param string $path The path.
	 *
	 * @return void
	 */
	private function remove(string $path): void {
		if (is_dir($path) === false) {
			if (is_file($path) === true) {
				unlink($path);
			}
			return;
		}
		foreach ((array)scandir($path) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->remove($path . '/' . $entry);
			}
		}
		rmdir($path);
	}//end remove()

	/**
	 * The store, with the root folder resolving to $local (or failing).
	 *
	 * @param string|null $local   The local path of the app data folder, or null when it cannot be resolved.
	 * @param string      $dataDir The configured data directory.
	 *
	 * @return GeoDatabaseStore
	 */
	private function store(?string $local, string $dataDir = ''): GeoDatabaseStore {
		$this->config->method('getSystemValueString')->willReturnCallback(
			fn (string $key, string $default = ''): string => match ($key) {
				'instanceid' => 'oc123',
				'datadirectory' => $dataDir,
				default => $default,
			}
		);
		if ($local === null) {
			$this->rootFolder->method('get')->willThrowException(new NotFoundException('no node'));
		} else {
			$storage = $this->createMock(IStorage::class);
			$storage->method('getLocalFile')->with('appdata_oc123/portaliq/geo')->willReturn($local);
			$node = $this->createMock(Node::class);
			$node->method('getStorage')->willReturn($storage);
			$node->method('getInternalPath')->willReturn('appdata_oc123/portaliq/geo');
			$this->rootFolder->method('get')->with('appdata_oc123/portaliq/geo')->willReturn($node);
		}

		return new GeoDatabaseStore($this->appData, $this->rootFolder, $this->config, $this->logger);
	}//end store()

	public function testTheDirectoryIsTheAppDataFoldersLocalPathAndIsRemembered(): void {
		$this->appData->expects($this->once())->method('getFolder')->with('geo')->willReturn($this->createMock(ISimpleFolder::class));
		$store = $this->store($this->root . '/geo');

		$this->assertSame($this->root . '/geo', $store->directory());
		$this->assertDirectoryExists($this->root . '/geo');
		$this->assertSame($this->root . '/geo', $store->directory());
	}//end testTheDirectoryIsTheAppDataFoldersLocalPathAndIsRemembered()

	public function testWithoutALocalNodeTheDataDirectoryIsUsedAndCreated(): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException('none'));
		$this->appData->expects($this->once())->method('newFolder')->with('geo');

		$directory = $this->store(null, $this->root . '/data/')->directory();

		$this->assertSame($this->root . '/data/appdata_oc123/portaliq/geo', $directory);
		$this->assertDirectoryExists($directory);
	}//end testWithoutALocalNodeTheDataDirectoryIsUsedAndCreated()

	public function testAnEmptyLocalPathFallsBackToTheDataDirectory(): void {
		$this->assertSame($this->root . '/appdata_oc123/portaliq/geo', $this->store('', $this->root)->directory());
	}//end testAnEmptyLocalPathFallsBackToTheDataDirectory()

	public function testWithNeitherThereIsNoDirectoryNoDatabaseAndNoMetadata(): void {
		$store = $this->store(null, '');

		$this->assertNull($store->directory());
		$this->assertNull($store->databasePath());
		$this->assertSame([], $store->metadata());
		$this->assertFalse($store->install($this->root . '/x.mmdb', []));
	}//end testWithNeitherThereIsNoDirectoryNoDatabaseAndNoMetadata()

	public function testAnUncreatableDirectoryIsLoggedAndAbsent(): void {
		file_put_contents($this->root . '/a-file', 'x');
		$this->logger->expects($this->once())->method('error')
			->with('Portaliq: the geography directory cannot be created', ['path' => $this->root . '/a-file/geo']);

		// mkdir() over an existing file warns; the store reads its false result.
		set_error_handler(static fn (): bool => true, E_WARNING);
		try {
			$directory = $this->store($this->root . '/a-file/geo')->directory();
		} finally {
			restore_error_handler();
		}

		$this->assertNull($directory);
	}//end testAnUncreatableDirectoryIsLoggedAndAbsent()

	public function testAnInstalledDatabaseIsMovedInPlaceWithItsMetadata(): void {
		$download = $this->root . '/download.mmdb';
		file_put_contents($download, 'MMDB');
		$store = $this->store($this->root . '/geo');

		$this->assertNull($store->databasePath());
		$this->assertTrue($store->install($download, ['provider' => 'dbip', 'url' => 'https://example.org/a/b']));

		$this->assertSame($this->root . '/geo/traffic-geo.mmdb', $store->databasePath());
		$this->assertFileDoesNotExist($download);
		$this->assertSame('MMDB', file_get_contents($this->root . '/geo/traffic-geo.mmdb'));
		$this->assertSame('0640', substr(sprintf('%o', fileperms($this->root . '/geo/traffic-geo.mmdb')), -4));
		$this->assertSame(['provider' => 'dbip', 'url' => 'https://example.org/a/b'], $store->metadata());
		$this->assertStringContainsString('"https://example.org/a/b"', (string)file_get_contents($this->root . '/geo/traffic-geo.json'));
	}//end testAnInstalledDatabaseIsMovedInPlaceWithItsMetadata()

	public function testAMissingDownloadIsNotInstalled(): void {
		$this->logger->expects($this->once())->method('error')
			->with('Portaliq: the geography database cannot be moved into place', ['target' => $this->root . '/geo/traffic-geo.mmdb']);
		$store = $this->store($this->root . '/geo');

		$this->assertFalse($store->install($this->root . '/missing.mmdb', []));
		$this->assertFileDoesNotExist($this->root . '/geo/traffic-geo.json');
	}//end testAMissingDownloadIsNotInstalled()

	public function testUnencodableMetadataIsStoredAsAnEmptyObject(): void {
		$download = $this->root . '/download.mmdb';
		file_put_contents($download, 'MMDB');
		$store = $this->store($this->root . '/geo');

		$this->assertTrue($store->install($download, ['bad' => "\xB1\x31"]));
		$this->assertSame('{}', file_get_contents($this->root . '/geo/traffic-geo.json'));
		$this->assertSame([], $store->metadata());
	}//end testUnencodableMetadataIsStoredAsAnEmptyObject()

	public function testUnreadableMetadataIsEmpty(): void {
		mkdir($this->root . '/geo');
		file_put_contents($this->root . '/geo/traffic-geo.json', '"just a string"');

		$this->assertSame([], $this->store($this->root . '/geo')->metadata());
	}//end testUnreadableMetadataIsEmpty()

	public function testAnAppDataFolderThatCannotBeMadeOrReadIsOnlyLogged(): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException('none'));
		$this->appData->method('newFolder')->willThrowException(new RuntimeException('read-only'));
		$this->logger->expects($this->once())->method('debug')
			->with('Portaliq: app data folder for geography not created', ['reason' => 'read-only']);

		$this->assertSame($this->root . '/geo', $this->store($this->root . '/geo')->directory());
	}//end testAnAppDataFolderThatCannotBeMadeOrReadIsOnlyLogged()

	public function testAnUnreadableAppDataFolderIsOnlyLogged(): void {
		$this->appData->method('getFolder')->willThrowException(new RuntimeException('broken'));
		$this->appData->expects($this->never())->method('newFolder');
		$this->logger->expects($this->once())->method('debug')
			->with('Portaliq: app data folder for geography not readable', ['reason' => 'broken']);

		$this->assertSame($this->root . '/geo', $this->store($this->root . '/geo')->directory());
	}//end testAnUnreadableAppDataFolderIsOnlyLogged()
}//end class
