<?php

declare(strict_types=1);

namespace carlcs\assetmetadata\tests\integration;

use carlcs\assetmetadata\tests\support\CraftFixtures;
use carlcs\assetmetadata\tests\support\Fixtures;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/**
 * The control panel action behind the field’s “Refresh” button, exercised over HTTP against the
 * harness’ web server: authentication, CSRF, request type and asset permissions.
 */
final class RefreshActionTest extends IntegrationTestCase
{
    private static string $baseUrl = '';
    private static ?Asset $asset = null;
    private static ?User $viewer = null;
    private static mixed $originalEdition = null;

    public static function setUpBeforeClass(): void
    {
        self::$baseUrl = rtrim(getenv('ASSET_METADATA_TEST_BASE_URL') ?: 'http://127.0.0.1', '/') . '/';

        try {
            $probe = (new Client(['base_uri' => self::$baseUrl, 'timeout' => 5, 'http_errors' => false, 'allow_redirects' => false]))
                ->get('admin/actions/users/session-info', ['headers' => ['Accept' => 'application/json', 'X-Asset-Metadata-Test' => '1']]);
        } catch (\Throwable $e) {
            self::markTestSkipped('No web server reachable at ' . self::$baseUrl . ': ' . $e->getMessage());
        }

        if ($probe->getStatusCode() !== 200 || !is_array(json_decode((string)$probe->getBody(), true))) {
            self::markTestSkipped(sprintf(
                'The web server at %s didn’t return session info (HTTP %d%s); is Craft installed and its database up?',
                self::$baseUrl,
                $probe->getStatusCode(),
                $probe->hasHeader('X-Redirect') ? ', redirect to ' . $probe->getHeaderLine('X-Redirect') : ''
            ));
        }

        parent::setUpBeforeClass();

        // An MP3 rather than an image: Craft re-encodes uploaded images (dropping their EXIF data unless
        // `preserveExifData` is enabled), and a refresh reads the stored file.
        self::$asset = (new self('noop'))->upload('refresh.mp3', Fixtures::mp3(static::$fixtureDir . '/refresh.mp3', ['TIT2' => 'Refresh title']));

        // A user with control panel access but without permissions for the test volume needs Craft Pro
        self::$originalEdition = property_exists(Craft::$app, 'edition') ? Craft::$app->edition : Craft::$app->getEdition();
        Craft::$app->setEdition(Craft::Pro);
        // The edition lives in the project config; persist it so the web server process sees it
        CraftFixtures::saveProjectConfig();

        $viewer = new User([
            'username' => 'amviewer' . static::$suffix,
            'email' => 'amviewer' . static::$suffix . '@example.com',
            'newPassword' => 'viewer-password',
            'active' => true,
        ]);
        self::assertTrue(Craft::$app->getElements()->saveElement($viewer), implode('; ', $viewer->getFirstErrors()));
        Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accessCp']);
        self::$viewer = $viewer;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$viewer !== null) {
            Craft::$app->getElements()->deleteElement(self::$viewer, true);
            self::$viewer = null;
        }

        if (self::$originalEdition !== null) {
            Craft::$app->setEdition(self::$originalEdition);
            CraftFixtures::saveProjectConfig();
        }

        parent::tearDownAfterClass();
    }

    public function testAnAdminGetsTheFreshlyExtractedValues(): void
    {
        $client = $this->login(getenv('DEV_ADMIN_USERNAME') ?: 'admin', getenv('DEV_ADMIN_PASSWORD') ?: 'password');
        $before = self::$extractions;

        $response = $this->refresh($client);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $data = json_decode((string)$response->getBody(), true);
        self::assertSame('Refresh title', $data['value']['col6']);
        self::assertSame('audio/mpeg', $data['value']['col8']);
        self::assertArrayNotHasKey('title', $data['value'], 'Only subfield IDs are returned to the form');
        // The extraction ran in the web process, not in this one
        self::assertSame($before, self::$extractions);
    }

    public function testRequiresAPostRequestWithACsrfToken(): void
    {
        $client = $this->login('admin', 'password');

        $response = $client->post('admin/actions/asset-metadata/metadata/get-field-value', [
            'form_params' => ['elementId' => self::$asset->id, 'fieldId' => static::$field->id],
        ]);
        self::assertSame(400, $response->getStatusCode(), 'Missing CSRF token');

        $response = $client->get('admin/actions/asset-metadata/metadata/get-field-value', [
            'query' => ['elementId' => self::$asset->id, 'fieldId' => static::$field->id],
        ]);
        // Craft 4 answers a Bad Request, Craft 5 a Method Not Allowed
        self::assertContains($response->getStatusCode(), [400, 405], 'GET is not allowed');
    }

    public function testRejectsUnknownAssetsAndFields(): void
    {
        $client = $this->login('admin', 'password');

        self::assertSame(400, $this->refresh($client, ['elementId' => 999999999])->getStatusCode());
        self::assertSame(400, $this->refresh($client, ['fieldId' => 999999999])->getStatusCode());
    }

    public function testGuestsAreRejected(): void
    {
        $response = $this->refresh($this->client());

        self::assertContains($response->getStatusCode(), [401, 403]);
        self::assertStringNotContainsString('Refresh title', (string)$response->getBody());
    }

    public function testUsersWithoutAccessToTheVolumeAreRejected(): void
    {
        $client = $this->login(self::$viewer->username, 'viewer-password');

        $response = $this->refresh($client);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringNotContainsString('Refresh title', (string)$response->getBody());

        // Once the user may view assets in the volume (uploaded by someone else), the metadata is available
        Craft::$app->getUserPermissions()->saveUserPermissions(self::$viewer->id, [
            'accessCp',
            'viewAssets:' . static::$volume->uid,
            'viewPeerAssets:' . static::$volume->uid,
        ]);
        $response = $this->refresh($client);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('Refresh title', json_decode((string)$response->getBody(), true)['value']['col6']);
    }

    // Helpers
    // =========================================================================

    private function client(): Client
    {
        return new Client([
            'base_uri' => self::$baseUrl,
            'cookies' => new CookieJar(),
            'http_errors' => false,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'X-Requested-With' => 'XMLHttpRequest',
                // Tells the dev harness not to auto-login these requests (see dev/docker/scripts/app.web.php)
                'X-Asset-Metadata-Test' => '1',
            ],
        ]);
    }

    private function csrfToken(Client $client): string
    {
        $response = $client->get('admin/actions/users/session-info');
        $data = json_decode((string)$response->getBody(), true);
        self::assertIsArray($data, sprintf('HTTP %d: %s', $response->getStatusCode(), (string)$response->getBody()));

        return (string)$data['csrfTokenValue'];
    }

    private function login(string $username, string $password): Client
    {
        $client = $this->client();
        $response = $client->post('admin/actions/users/login', [
            'form_params' => [
                'loginName' => $username,
                'password' => $password,
                'CRAFT_CSRF_TOKEN' => $this->csrfToken($client),
            ],
        ]);
        self::assertSame(200, $response->getStatusCode(), "Login failed: " . (string)$response->getBody());

        return $client;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function refresh(Client $client, array $params = []): ResponseInterface
    {
        return $client->post('admin/actions/asset-metadata/metadata/get-field-value', [
            'form_params' => array_merge([
                'elementId' => self::$asset->id,
                'fieldId' => static::$field->id,
                'CRAFT_CSRF_TOKEN' => $this->csrfToken($client),
            ], $params),
        ]);
    }
}
