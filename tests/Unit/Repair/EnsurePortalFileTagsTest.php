<?php

/**
 * Unit tests for EnsurePortalFileTags.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Repair;

use OCA\Portaliq\Repair\EnsurePortalFileTags;
use OCP\Migration\IOutput;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The file tags a portal upload needs exist before the first upload.
 */
class EnsurePortalFileTagsTest extends TestCase {

	/**
	 * A tag with a name.
	 *
	 * @param string $name The name.
	 *
	 * @return ISystemTag
	 */
	private function tag(string $name): ISystemTag {
		$tag = $this->createMock(ISystemTag::class);
		$tag->method('getName')->willReturn($name);
		return $tag;
	}//end tag()

	/**
	 * A missing tag is created, user visible and assignable.
	 *
	 * @return void
	 */
	public function testAMissingTagIsCreated(): void {
		$tags = $this->createMock(ISystemTagManager::class);
		// A tag whose name only contains the searched one does not count.
		$tags->method('getAllTags')->willReturn([$this->tag(name: 'portal:from-applicant-old')]);
		$tags->expects($this->once())->method('createTag')->with('portal:from-applicant', true, true)->willReturn($this->tag(name: 'portal:from-applicant'));

		(new EnsurePortalFileTags(tags: $tags))->run(output: $this->createMock(IOutput::class));
	}//end testAMissingTagIsCreated()

	/**
	 * A tag that exists is left alone.
	 *
	 * @return void
	 */
	public function testAnExistingTagIsLeftAlone(): void {
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getAllTags')->willReturn([$this->tag(name: 'portal:from-applicant')]);
		$tags->expects($this->never())->method('createTag');

		(new EnsurePortalFileTags(tags: $tags))->run(output: $this->createMock(IOutput::class));
	}//end testAnExistingTagIsLeftAlone()

	/**
	 * A refused creation is a warning, never a failed upgrade.
	 *
	 * @return void
	 */
	public function testARefusedCreationIsAWarning(): void {
		$tags = $this->createMock(ISystemTagManager::class);
		$tags->method('getAllTags')->willReturn([]);
		$tags->method('createTag')->willThrowException(new RuntimeException('forbidden'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('portal:from-applicant'));

		(new EnsurePortalFileTags(tags: $tags))->run(output: $output);
	}//end testARefusedCreationIsAWarning()
}//end class
