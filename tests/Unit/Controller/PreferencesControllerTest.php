<?php

/**
 * PreferencesController contract tests.
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

use OCA\Portaliq\Controller\PreferencesController;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Wire contract for `GET /api/preferences/{key}` (`preferences#getPreference`)
 * and `PUT /api/preferences/{key}` (`preferences#setPreference`).
 *
 * Both are `@NoAdminRequired` + `@NoCSRFRequired`, so any authenticated user
 * reaches them with a key THEY choose. The contract that matters is therefore
 * not "a JSONResponse came back" — it is:
 *
 *   1. no user  -> 401, and NOTHING is read or written;
 *   2. a key that sanitises to empty -> 400, and NOTHING is read or written;
 *   3. every key is namespaced `pref_<sanitised>`, so a caller cannot reach an
 *      arbitrary IConfig user value belonging to another app;
 *   4. an empty value DELETES rather than storing "".
 *
 * Each test asserts the ITEM — the exact key handed to IConfig, the exact
 * status code, the exact body — because a test that only asserted the response
 * type would pass against a controller that read the wrong key entirely.
 *
 * @covers \OCA\Portaliq\Controller\PreferencesController
 */
class PreferencesControllerTest extends TestCase
{

    /**
     * Mock IRequest.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * Mock IConfig.
     *
     * @var IConfig&MockObject
     */
    private IConfig&MockObject $config;

    /**
     * Mock IUserSession.
     *
     * @var IUserSession&MockObject
     */
    private IUserSession&MockObject $userSession;

    /**
     * The controller under test.
     *
     * @var PreferencesController
     */
    private PreferencesController $controller;

    /**
     * Build the controller with mocked collaborators.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request     = $this->createMock(IRequest::class);
        $this->config      = $this->createMock(IConfig::class);
        $this->userSession = $this->createMock(IUserSession::class);

        $this->controller = new PreferencesController(
            $this->request,
            $this->config,
            $this->userSession
        );
    }//end setUp()

    /**
     * Log a user in for the tests that need one.
     *
     * @param string $uid The user id to report.
     *
     * @return void
     */
    private function loginAs(string $uid): void
    {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }//end loginAs()

    /**
     * Decode a JSONResponse body to an array.
     *
     * @param JSONResponse $response The response to read.
     *
     * @return array<string, mixed>
     */
    private function body(JSONResponse $response): array
    {
        return (array) $response->getData();
    }//end body()

    /**
     * An anonymous caller gets 401 and no config read happens.
     *
     * @return void
     */
    public function testGetPreferenceRefusesAnonymousWithoutReadingConfig(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->config->expects($this->never())->method('getUserValue');

        $response = $this->controller->getPreference(key: 'seen-support-dialog');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testGetPreferenceRefusesAnonymousWithoutReadingConfig()

    /**
     * A stored value comes back under the `pref_` namespace, not the raw key.
     *
     * @return void
     */
    public function testGetPreferenceReadsTheNamespacedKeyAndReturnsTheValue(): void
    {
        $this->loginAs('alice');

        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'portaliq', 'pref_seen-support-dialog', '')
            ->willReturn('1');

        $response = $this->controller->getPreference(key: 'seen-support-dialog');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['value' => '1'], $this->body($response));
    }//end testGetPreferenceReadsTheNamespacedKeyAndReturnsTheValue()

    /**
     * An unset preference reports null, not the empty-string sentinel.
     *
     * @return void
     */
    public function testGetPreferenceReportsNullWhenUnset(): void
    {
        $this->loginAs('alice');
        $this->config->method('getUserValue')->willReturn('');

        $response = $this->controller->getPreference(key: 'never-set');

        $this->assertSame(['value' => null], $this->body($response));
    }//end testGetPreferenceReportsNullWhenUnset()

    /**
     * A key is sanitised to `[a-z0-9-]` BEFORE it reaches IConfig, so a caller
     * cannot escape the `pref_` namespace into another app's user values.
     *
     * This is the security half of the contract: the assertion is on the key
     * IConfig actually receives, which is the only place the escape could
     * happen.
     *
     * @return void
     */
    public function testGetPreferenceSanitisesTheKeyBeforeReachingConfig(): void
    {
        $this->loginAs('alice');

        $this->config->expects($this->once())
            ->method('getUserValue')
            ->with('alice', 'portaliq', 'pref_passwordsalt', '')
            ->willReturn('');

        $this->controller->getPreference(key: '../../Password_Salt!');
    }//end testGetPreferenceSanitisesTheKeyBeforeReachingConfig()

    /**
     * A key with nothing safe left in it is refused with 400, and no read.
     *
     * @return void
     */
    public function testGetPreferenceRejectsAKeyThatSanitisesToEmpty(): void
    {
        $this->loginAs('alice');
        $this->config->expects($this->never())->method('getUserValue');

        $response = $this->controller->getPreference(key: '/../!@#$');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testGetPreferenceRejectsAKeyThatSanitisesToEmpty()

    /**
     * An anonymous caller cannot write, and nothing is stored.
     *
     * @return void
     */
    public function testSetPreferenceRefusesAnonymousWithoutWriting(): void
    {
        $this->userSession->method('getUser')->willReturn(null);
        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->never())->method('deleteUserValue');

        $response = $this->controller->setPreference(key: 'seen', value: '1');

        $this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
    }//end testSetPreferenceRefusesAnonymousWithoutWriting()

    /**
     * A write stores the value under the namespaced key and echoes it back.
     *
     * @return void
     */
    public function testSetPreferenceStoresTheNamespacedKey(): void
    {
        $this->loginAs('bob');

        $this->config->expects($this->once())
            ->method('setUserValue')
            ->with('bob', 'portaliq', 'pref_seen-support-dialog', '1');
        $this->config->expects($this->never())->method('deleteUserValue');

        $response = $this->controller->setPreference(key: 'seen-support-dialog', value: '1');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame(['value' => '1'], $this->body($response));
    }//end testSetPreferenceStoresTheNamespacedKey()

    /**
     * An empty value CLEARS the preference — it must not store "".
     *
     * @return void
     */
    public function testSetPreferenceWithAnEmptyValueDeletesInsteadOfStoring(): void
    {
        $this->loginAs('bob');

        $this->config->expects($this->once())
            ->method('deleteUserValue')
            ->with('bob', 'portaliq', 'pref_seen-support-dialog');
        $this->config->expects($this->never())->method('setUserValue');

        $response = $this->controller->setPreference(key: 'seen-support-dialog', value: '');

        $this->assertSame(['value' => null], $this->body($response));
    }//end testSetPreferenceWithAnEmptyValueDeletesInsteadOfStoring()

    /**
     * A write with an unusable key is refused before any store happens.
     *
     * @return void
     */
    public function testSetPreferenceRejectsAKeyThatSanitisesToEmpty(): void
    {
        $this->loginAs('bob');
        $this->config->expects($this->never())->method('setUserValue');
        $this->config->expects($this->never())->method('deleteUserValue');

        $response = $this->controller->setPreference(key: '!!!', value: '1');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }//end testSetPreferenceRejectsAKeyThatSanitisesToEmpty()
}//end class
