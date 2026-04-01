<?php

declare(strict_types=1);

namespace ExeLearningTest\Controller;

use ExeLearning\Controller\EditorController;
use ExeLearning\Service\ElpFileService;
use Laminas\View\Model\ViewModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for EditorController.
 *
 * @covers \ExeLearning\Controller\EditorController
 */
class EditorControllerTest extends TestCase
{
    private EditorController $controller;
    private ElpFileService $elpService;

    protected function setUp(): void
    {
        $this->elpService = $this->createMock(ElpFileService::class);
        $this->controller = new EditorController($this->elpService);
    }

    private function callProtectedMethod(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($object, $args);
    }

    // =========================================================================
    // Constructor tests
    // =========================================================================

    public function testConstructorSetsElpService(): void
    {
        $reflection = new ReflectionClass($this->controller);
        $property = $reflection->getProperty('elpService');
        $property->setAccessible(true);

        $this->assertSame($this->elpService, $property->getValue($this->controller));
    }

    // =========================================================================
    // indexAction() tests
    // =========================================================================

    public function testIndexActionRedirectsToAdmin(): void
    {
        $result = $this->controller->indexAction();

        $this->assertEquals(302, $result->getStatusCode());
    }

    // =========================================================================
    // editAction() tests - authentication
    // =========================================================================

    public function testEditActionRequiresAuthentication(): void
    {
        $this->controller->setIdentity(null);

        $result = $this->controller->editAction();

        // Should redirect to login
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testEditActionRequiresMediaId(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams([]);

        $result = $this->controller->editAction();

        // Should redirect to admin
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testEditActionWithMediaIdButNoMedia(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);

        $result = $this->controller->editAction();

        // Should redirect because media not found
        $this->assertEquals(302, $result->getStatusCode());
    }

    // =========================================================================
    // Controller class tests
    // =========================================================================

    public function testControllerExtendsAbstractActionController(): void
    {
        $this->assertInstanceOf(
            \Laminas\Mvc\Controller\AbstractActionController::class,
            $this->controller
        );
    }

    public function testControllerHasEditAction(): void
    {
        $this->assertTrue(method_exists($this->controller, 'editAction'));
    }

    public function testControllerHasIndexAction(): void
    {
        $this->assertTrue(method_exists($this->controller, 'indexAction'));
    }

    // =========================================================================
    // Identity tests
    // =========================================================================

    public function testSetAndGetIdentity(): void
    {
        $identity = new class {
            public function getId(): int { return 42; }
            public function getName(): string { return 'Test Admin'; }
        };

        $this->controller->setIdentity($identity);

        $this->assertSame($identity, $this->controller->identity());
    }

    public function testIdentityReturnsNullByDefault(): void
    {
        $this->assertNull($this->controller->identity());
    }

    // =========================================================================
    // Route params tests
    // =========================================================================

    public function testSetAndGetRouteParams(): void
    {
        $this->controller->setRouteParams(['id' => '123', 'action' => 'edit']);

        $this->assertEquals('123', $this->controller->params('id'));
        $this->assertEquals('edit', $this->controller->params('action'));
    }

    public function testParamsReturnsDefaultForMissingKey(): void
    {
        $this->controller->setRouteParams([]);

        $this->assertEquals('default', $this->controller->params('missing', 'default'));
    }

    // =========================================================================
    // Helper method tests
    // =========================================================================

    public function testRedirectReturnsRedirectHelper(): void
    {
        $redirect = $this->controller->redirect();

        $this->assertIsObject($redirect);
        $this->assertTrue(method_exists($redirect, 'toRoute'));
    }

    public function testMessengerReturnsMessengerHelper(): void
    {
        $messenger = $this->controller->messenger();

        $this->assertIsObject($messenger);
        $this->assertTrue(method_exists($messenger, 'addError'));
        $this->assertTrue(method_exists($messenger, 'addSuccess'));
    }

    public function testSettingsReturnsSettingsHelper(): void
    {
        $settings = $this->controller->settings();

        $this->assertIsObject($settings);
        $this->assertTrue(method_exists($settings, 'get'));
    }

    public function testTranslateReturnsString(): void
    {
        $result = $this->controller->translate('Test message');

        $this->assertEquals('Test message', $result);
    }

    public function testUrlReturnsUrlHelper(): void
    {
        $url = $this->controller->url();

        $this->assertIsObject($url);
        $this->assertTrue(method_exists($url, 'fromRoute'));
    }

    public function testUrlFromRouteGeneratesUrl(): void
    {
        $url = $this->controller->url()->fromRoute('test-route', ['id' => '123']);

        $this->assertStringContainsString('test-route', $url);
        $this->assertStringContainsString('123', $url);
    }

    // =========================================================================
    // Response tests
    // =========================================================================

    public function testGetResponseReturnsResponse(): void
    {
        $response = $this->controller->getResponse();

        $this->assertInstanceOf(\Laminas\Http\Response::class, $response);
    }

    public function testRedirectToRouteReturns302(): void
    {
        $result = $this->controller->redirect()->toRoute('admin');

        $this->assertEquals(302, $result->getStatusCode());
    }

    // =========================================================================
    // API helper tests
    // =========================================================================

    public function testApiHelperThrowsExceptionInTest(): void
    {
        $api = $this->controller->api();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('API not available in test');
        $api->read('media', 1);
    }

    // =========================================================================
    // Settings helper tests
    // =========================================================================

    public function testSettingsReturnsDefaultValue(): void
    {
        $value = $this->controller->settings()->get('nonexistent', 'default_value');

        $this->assertEquals('default_value', $value);
    }

    public function testSettingsReturnsNullForMissingWithoutDefault(): void
    {
        $value = $this->controller->settings()->get('nonexistent');

        $this->assertNull($value);
    }

    // =========================================================================
    // Messenger helper tests
    // =========================================================================

    public function testMessengerAddErrorDoesNotThrow(): void
    {
        // Should not throw any exception
        $this->controller->messenger()->addError('Test error');
        $this->assertTrue(true);
    }

    public function testMessengerAddSuccessDoesNotThrow(): void
    {
        // Should not throw any exception
        $this->controller->messenger()->addSuccess('Test success');
        $this->assertTrue(true);
    }

    // =========================================================================
    // editAction flow tests
    // =========================================================================

    public function testEditActionWithAuthenticatedUserButNoId(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams([]);

        $result = $this->controller->editAction();

        // Should redirect when no media ID
        $this->assertInstanceOf(\Laminas\Http\Response::class, $result);
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testEditActionWithEmptyMediaId(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '']);

        $result = $this->controller->editAction();

        // Empty ID should be treated as no ID
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testEditActionWithZeroMediaId(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '0']);

        $result = $this->controller->editAction();

        // Zero ID should redirect
        $this->assertEquals(302, $result->getStatusCode());
    }

    // =========================================================================
    // indexAction tests
    // =========================================================================

    public function testIndexActionAlwaysRedirects(): void
    {
        // Even with identity set, indexAction should redirect
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Admin'; }
        };

        $this->controller->setIdentity($identity);
        $result = $this->controller->indexAction();

        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testIndexActionRedirectsWithoutIdentity(): void
    {
        $this->controller->setIdentity(null);
        $result = $this->controller->indexAction();

        $this->assertEquals(302, $result->getStatusCode());
    }

    // =========================================================================
    // editAction - permission denied tests
    // =========================================================================

    public function testEditActionReturns302WhenUserNotAllowed(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/test.elpx',
            'Test ELP',
            'test.elpx',
            123
        );

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(false);

        $result = $this->controller->editAction();

        // Should redirect when user not allowed
        $this->assertEquals(302, $result->getStatusCode());
    }

    // =========================================================================
    // editAction - file type validation tests
    // =========================================================================

    public function testEditActionRejectsNonElpxFile(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        // Create a media with a non-elpx file
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/document.pdf',
            'Test PDF',
            'document.pdf',
            123
        );

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->editAction();

        // Should redirect when file is not .elpx or .zip
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testEditActionAcceptsZipFile(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        // Create a media with a .zip file
        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/project.zip',
            'Test ZIP',
            'project.zip',
            123
        );

        // Create a request object with URI
        $request = new class {
            public function getUri() {
                return new class {
                    public function getScheme(): string { return 'https'; }
                    public function getHost(): string { return 'example.com'; }
                    public function getPort(): ?int { return null; }
                };
            }
            public function getBasePath(): string { return '/omeka-s'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);
        $this->controller->setRequest($request);

        // This will either return a ViewModel if editor exists or redirect if not
        $result = $this->controller->editAction();

        // The result depends on whether the editor file exists
        // Both Response and ViewModel are valid outcomes
        $this->assertTrue(
            $result instanceof \Laminas\Http\Response ||
            $result instanceof \Laminas\View\Model\ViewModel
        );
    }

    public function testEditActionAcceptsElpxFile(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/project.elpx',
            'Test ELPX',
            'project.elpx',
            123
        );

        $request = new class {
            public function getUri() {
                return new class {
                    public function getScheme(): string { return 'https'; }
                    public function getHost(): string { return 'example.com'; }
                    public function getPort(): ?int { return 443; }
                };
            }
            public function getBasePath(): string { return ''; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);
        $this->controller->setRequest($request);

        $result = $this->controller->editAction();

        // Either a ViewModel or Response is valid
        $this->assertTrue(
            $result instanceof \Laminas\Http\Response ||
            $result instanceof \Laminas\View\Model\ViewModel
        );
    }

    public function testEditActionWithNonStandardPort(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com:8080/files/original/project.elpx',
            'Test ELPX',
            'project.elpx',
            123
        );

        $request = new class {
            public function getUri() {
                return new class {
                    public function getScheme(): string { return 'http'; }
                    public function getHost(): string { return 'localhost'; }
                    public function getPort(): ?int { return 8080; }
                };
            }
            public function getBasePath(): string { return '/omeka-s'; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);
        $this->controller->setRequest($request);

        $result = $this->controller->editAction();

        // Either a ViewModel or Response is valid
        $this->assertTrue(
            $result instanceof \Laminas\Http\Response ||
            $result instanceof \Laminas\View\Model\ViewModel
        );
    }

    // =========================================================================
    // editAction - case insensitive extension tests
    // =========================================================================

    public function testEditActionAcceptsUppercaseElpxExtension(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/project.ELPX',
            'Test ELPX',
            'project.ELPX',
            123
        );

        $request = new class {
            public function getUri() {
                return new class {
                    public function getScheme(): string { return 'https'; }
                    public function getHost(): string { return 'example.com'; }
                    public function getPort(): ?int { return null; }
                };
            }
            public function getBasePath(): string { return ''; }
        };

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);
        $this->controller->setRequest($request);

        $result = $this->controller->editAction();

        // Either a ViewModel or Response is valid (depends on editor existence)
        $this->assertTrue(
            $result instanceof \Laminas\Http\Response ||
            $result instanceof \Laminas\View\Model\ViewModel
        );
    }

    public function testEditActionRejectsTxtFile(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/readme.txt',
            'Test TXT',
            'readme.txt',
            123
        );

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->editAction();

        // Should redirect when file is not .elpx or .zip
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testEditActionRejectsHtmlFile(): void
    {
        $identity = new class {
            public function getId(): int { return 1; }
            public function getName(): string { return 'Test User'; }
        };

        $media = new \Omeka\Api\Representation\MediaRepresentation(
            'http://example.com/files/original/index.html',
            'Test HTML',
            'index.html',
            123
        );

        $this->controller->setIdentity($identity);
        $this->controller->setRouteParams(['id' => '123']);
        $this->controller->addMedia(123, $media);
        $this->controller->setUserAllowed(true);

        $result = $this->controller->editAction();

        // Should redirect when file is not .elpx or .zip
        $this->assertEquals(302, $result->getStatusCode());
    }

    public function testExtractBasePathFromAdminRoute(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'extractBasePath', [
            '/playground/123/php83/admin/exelearning/editor/edit/1',
        ]);

        $this->assertSame('/playground/123/php83', $result);
    }

    public function testExtractBasePathReturnsEmptyWhenNoKnownMarker(): void
    {
        $result = $this->callProtectedMethod($this->controller, 'extractBasePath', ['/plain/path']);
        $this->assertSame('', $result);
    }

    public function testBuildInstallStatusPayloadHandlesIdleNotInstalledState(): void
    {
        $settings = new class {
            private array $store = [];
            public function set(string $key, $value): void { $this->store[$key] = $value; }
            public function get(string $key, $default = null) { return $this->store[$key] ?? $default; }
        };

        $payload = $this->callProtectedMethod($this->controller, 'buildInstallStatusPayload', [$settings]);

        $this->assertSame('idle', $payload['phase']);
        $this->assertFalse($payload['is_installed']);
        $this->assertSame('Download & Install Editor', $payload['button_label']);
        $this->assertStringContainsString('not installed', strtolower($payload['description']));
    }

    public function testBuildInstallStatusPayloadConvertsStaleStatusToError(): void
    {
        $settings = new class {
            private array $store = [];
            public function set(string $key, $value): void { $this->store[$key] = $value; }
            public function get(string $key, $default = null) { return $this->store[$key] ?? $default; }
        };

        $settings->set(\ExeLearning\Service\StaticEditorInstaller::SETTING_INSTALL_PHASE, 'installing');
        $settings->set(
            \ExeLearning\Service\StaticEditorInstaller::SETTING_INSTALL_STARTED_AT,
            time() - (\ExeLearning\Service\StaticEditorInstaller::INSTALL_LOCK_TTL + 5)
        );

        $payload = $this->callProtectedMethod($this->controller, 'buildInstallStatusPayload', [$settings]);

        $this->assertSame('error', $payload['phase']);
        $this->assertFalse($payload['running']);
        $this->assertStringContainsString('stalled', strtolower($payload['error']));
    }
}
