<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * REQ-ORA-003: the seed is the catalogue of every action the code checks, each
 * with a label and a description, and it carries no template comment.
 *
 * @spec openspec/changes/operate-roles-for-content-and-actions/specs/portal-admin-roles/spec.md#requirement-the-seed-names-every-checked-action-req-ora-003
 */
class ActionSeedCensusTest extends TestCase {

	public function testEveryCheckedActionIsInTheSeed(): void {
		$seed = $this->seed();
		$missing = array_values(array_diff($this->checkedActions(), array_keys($seed)));

		$this->assertSame([], $missing, 'Actions checked in lib/ but missing from lib/actions.seed.json: ' . implode(', ', $missing));
		$this->assertNotEmpty($this->checkedActions());
	}//end testEveryCheckedActionIsInTheSeed()

	public function testEverySeedActionHasALabelADescriptionAndAdminOnlyGroups(): void {
		foreach ($this->seed() as $action => $entry) {
			$this->assertIsArray($entry, $action);
			$this->assertNotSame('', trim((string)($entry['label'] ?? '')), $action . ' has no label');
			$this->assertNotSame('', trim((string)($entry['description'] ?? '')), $action . ' has no description');
			$this->assertSame(['admin'], $entry['groups'] ?? null, $action . ' must ship admin-only');
		}
	}//end testEverySeedActionHasALabelADescriptionAndAdminOnlyGroups()

	public function testTheSeedCarriesNoTemplateComment(): void {
		$raw = json_decode((string)file_get_contents($this->seedPath()), true, 512, JSON_THROW_ON_ERROR);

		$this->assertArrayNotHasKey('$comment', $raw);
	}//end testTheSeedCarriesNoTemplateComment()

	/**
	 * The seed's actions.
	 *
	 * @return array<string, mixed>
	 */
	private function seed(): array {
		$raw = json_decode((string)file_get_contents($this->seedPath()), true, 512, JSON_THROW_ON_ERROR);

		return $raw['actions'];
	}//end seed()

	private function seedPath(): string {
		return __DIR__ . '/../../../lib/actions.seed.json';
	}//end seedPath()

	/**
	 * The action names the code passes to requireAction(), through the ACTION constants
	 * of the classes that call it.
	 *
	 * @return array<int, string>
	 */
	private function checkedActions(): array {
		$found = [];
		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../../../lib', RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($iterator as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (str_contains($source, 'requireAction(') === false || str_contains($source, 'function requireAction') === true) {
				continue;
			}

			if (preg_match_all("/const\\s+ACTION\\w*\\s*=\\s*'(portal\\.[a-z-]+)'/", $source, $matches) > 0) {
				array_push($found, ...$matches[1]);
			}
		}

		return array_values(array_unique($found));
	}//end checkedActions()
}//end class
