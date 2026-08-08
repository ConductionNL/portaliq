<?php

/**
 * SettingsController write-path unit tests.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\SettingsController;
use OCA\Portaliq\Service\SettingsService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * `PUT /api/settings` (`settings#update`) is the canonical settings write in
 * OpenRegister's AppHost dialect; `POST /api/settings` (`settings#create`) is
 * the retained legacy alias. Both must reach the SAME
 * `SettingsService::updateSettings()` call with the request's own parameters.
 *
 * These tests assert the ITEM — that the write actually reaches the service
 * with the submitted payload and that the response carries the service's
 * result. A test that only asserted "a JSONResponse came back" or "success is
 * true" would pass against a controller that silently wrote nothing.
 *
 * @covers \OCA\Portaliq\Controller\SettingsController
 */
class SettingsControllerWriteTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock SettingsService.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;

    /**
     * The controller under test.
     *
     * @var SettingsController
     */
    private SettingsController $controller;


    /**
     * Set up test fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);

        $this->controller = new SettingsController(
            request: $this->request,
            settingsService: $this->settingsService,
        );

    }//end setUp()


    /**
     * update() must persist the request parameters and return the stored config.
     *
     * @return void
     */
    public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void
    {
        $submitted = ['register' => 'new-uuid'];
        $stored    = ['register' => 'new-uuid', 'openregisters' => true, 'isAdmin' => true];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($submitted);

        // The ITEM: the write reaches the service, with the submitted params.
        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $response = $this->controller->update();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(
            [
                'success' => true,
                'config'  => $stored,
            ],
            $response->getData(),
            'update() must return the config the service actually stored, not the submission'
        );

    }//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()


    /**
     * create() must delegate to update() and still perform the same write.
     *
     * The legacy POST route stays reachable, so the alias remaining a REAL
     * write — not an empty success — is load-bearing for existing callers.
     *
     * @return void
     */
    public function testCreateDelegatesToUpdateAndStillWrites(): void
    {
        $submitted = ['register' => 'legacy-uuid'];
        $stored    = ['register' => 'legacy-uuid', 'openregisters' => true, 'isAdmin' => true];

        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn($submitted);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $response = $this->controller->create();

        self::assertInstanceOf(JSONResponse::class, $response);
        self::assertSame(
            [
                'success' => true,
                'config'  => $stored,
            ],
            $response->getData(),
            'create() must produce the same written result as update()'
        );

    }//end testCreateDelegatesToUpdateAndStillWrites()


    /**
     * Both verbs must produce byte-identical payloads for the same submission.
     *
     * This is the convergence claim of the change stated as an assertion: the
     * canonical PUT does not merely "work", it works IDENTICALLY to the legacy
     * POST, so no caller has to care which verb it used.
     *
     * @return void
     */
    public function testUpdateAndCreateProduceIdenticalPayloadsForTheSameSubmission(): void
    {
        $submitted = ['register' => 'same-uuid'];
        $stored    = ['register' => 'same-uuid', 'openregisters' => false, 'isAdmin' => true];

        $this->request->expects($this->exactly(2))
            ->method('getParams')
            ->willReturn($submitted);

        $this->settingsService->expects($this->exactly(2))
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $viaPut  = $this->controller->update()->getData();
        $viaPost = $this->controller->create()->getData();

        self::assertSame(
            $viaPut,
            $viaPost,
            'The canonical PUT and the legacy POST alias must return identical payloads'
        );

    }//end testUpdateAndCreateProduceIdenticalPayloadsForTheSameSubmission()


    /**
     * An empty submission must still reach the service.
     *
     * An early return on an empty payload would be indistinguishable from a
     * successful no-op write from the caller's side.
     *
     * @return void
     */
    public function testEmptySubmissionStillReachesTheService(): void
    {
        $this->request->expects($this->once())
            ->method('getParams')
            ->willReturn([]);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with([])
            ->willReturn(['unchanged' => true]);

        $response = $this->controller->update();

        self::assertSame(
            [
                'success' => true,
                'config'  => ['unchanged' => true],
            ],
            $response->getData()
        );

    }//end testEmptySubmissionStillReachesTheService()


}//end class
