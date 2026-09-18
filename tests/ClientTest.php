<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost\Tests;

use CodeConjure\FoxPost\Client;
use CodeConjure\FoxPost\Environment;
use CodeConjure\FoxPost\Exception\FoxPostApiException;
use CodeConjure\FoxPost\Exception\FoxPostAuthException;
use Http\Client\Exception\NetworkException;
use Http\Mock\Client as MockHttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * A FoxPost HTTP kliens — hálózat nélkül, PSR-18 teszt-duplával.
 *
 * NEM a FoxPost API-t teszteljük, hanem a SAJÁT hibakezelésünket: mit csinálunk
 * 401-gyel, 500-zal, szemét JSON-nal és megszakadt kapcsolattal.
 *
 * A bolt FoxPostApiClientTest-jének átvétele (#170). Egyetlen viselkedés
 * változott: a 3xx — lásd testARedirectIsAnExplicitError.
 */
final class ClientTest extends TestCase
{
    private const string PRODUCTION_BASE_URL = 'https://webapi.foxpost.hu';

    private const string SANDBOX_BASE_URL = 'https://webapi-test.foxpost.hu';

    private MockHttpClient $httpClient;

    // -------------------------------------------------------------------------
    // Hitelesítési hibák
    // -------------------------------------------------------------------------

    #[DataProvider('authErrorStatusProvider')]
    public function testAnAuthenticationErrorBecomesAFoxPostAuthException(int $status): void
    {
        $client = $this->client(new Response($status, [], '{"error":"nope"}'));

        $this->expectException(FoxPostAuthException::class);
        $this->expectExceptionMessage(sprintf('FoxPost API hitelesítési hiba (HTTP %d)', $status));

        $client->getTracking('CSOMAG1');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authErrorStatusProvider(): iterable
    {
        yield '401 Unauthorized' => [401];
        yield '403 Forbidden' => [403];
    }

    /**
     * A hitelesítési hiba a FoxPostApiException leszármazottja, tehát a hívók
     * egyetlen `catch (FoxPostApiException)` ággal is elkapják.
     */
    public function testTheAuthExceptionIsAFoxPostApiException(): void
    {
        $client = $this->client(new Response(401, [], ''));

        try {
            $client->getTracking('CSOMAG1');
            self::fail('Kivételt vártunk.');
        } catch (FoxPostApiException $e) {
            self::assertInstanceOf(FoxPostAuthException::class, $e);
        }
    }

    /**
     * A hibaüzenet megnevezi a beállítandó env változókat — ez a mező, amiből
     * az üzemeltető megtudja, mit kell javítania.
     */
    public function testTheAuthErrorMessageNamesTheCredentialEnvVars(): void
    {
        $client = $this->client(new Response(401, [], ''));

        $this->expectExceptionMessage('FOXPOST_USERNAME');

        $client->getTracking('CSOMAG1');
    }

    // -------------------------------------------------------------------------
    // Egyéb HTTP hibák
    // -------------------------------------------------------------------------

    #[DataProvider('httpErrorStatusProvider')]
    public function testAnyOtherHttpErrorBecomesAFoxPostApiExceptionWithTheResponseBody(int $status): void
    {
        $client = $this->client(new Response($status, [], '{"message":"Hibás vonalkód"}'));

        try {
            $client->getTracking('CSOMAG1');
            self::fail('Kivételt vártunk.');
        } catch (FoxPostApiException $e) {
            self::assertStringContainsString(sprintf('FoxPost API hiba (HTTP %d)', $status), $e->getMessage());
            self::assertStringContainsString('Hibás vonalkód', $e->getMessage());
            self::assertSame($status, $e->getCode());
            self::assertNotInstanceOf(FoxPostAuthException::class, $e);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function httpErrorStatusProvider(): iterable
    {
        yield '400 Bad Request' => [400];
        yield '404 Not Found' => [404];
        yield '422 Unprocessable Entity' => [422];
        yield '500 Internal Server Error' => [500];
        yield '503 Service Unavailable' => [503];
    }

    /**
     * PSR-18 alatt a 3xx nem dob — a mai „olvasási hiba" (#172) tehát nem
     * reprodukálható anélkül, hogy szándékosan félrevezető üzenetet írnánk.
     * A kliens ezért külön ágon kezeli, és megmondja, mi történt.
     */
    public function testARedirectIsAnExplicitError(): void
    {
        $client = $this->client(new Response(302, ['Location' => 'https://example.test/uj-hely'], '{"status":"DELIVERED"}'));

        try {
            $client->getTracking('CSOMAG1');
            self::fail('Kivételt vártunk.');
        } catch (FoxPostApiException $e) {
            self::assertStringContainsString('FoxPost API váratlan átirányítás (HTTP 302)', $e->getMessage());
            self::assertStringContainsString('https://example.test/uj-hely', $e->getMessage());
            self::assertSame(302, $e->getCode());
            self::assertNotInstanceOf(FoxPostAuthException::class, $e);
        }
    }

    public function testARedirectWithoutLocationStillReports(): void
    {
        $client = $this->client(new Response(301, [], ''));

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('FoxPost API váratlan átirányítás (HTTP 301): nincs Location');

        $client->getTracking('CSOMAG1');
    }

    // -------------------------------------------------------------------------
    // Érvénytelen válasz
    // -------------------------------------------------------------------------

    #[DataProvider('invalidJsonProvider')]
    public function testAnInvalidJsonResponseBecomesAFoxPostApiException(string $body): void
    {
        $client = $this->client(new Response(200, [], $body));

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('FoxPost API érvénytelen JSON választ adott vissza.');

        $client->getTracking('CSOMAG1');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidJsonProvider(): iterable
    {
        yield 'üres válasz' => [''];
        yield 'HTML hibaoldal' => ['<html><body>502 Bad Gateway</body></html>'];
        yield 'csonka JSON' => ['{"status":'];
        yield 'JSON string' => ['"DELIVERED"'];
        yield 'JSON szám' => ['42'];
        yield 'JSON null' => ['null'];
    }

    /**
     * A bolt eredeti tesztje egy Symfony `MockResponse`-nak adott generátorral
     * szimulálta a megszakadt törzsolvasást. PSR-7 alatt erre nincs beépített
     * eszköz — a Nyholm `Stream` viszont `RuntimeException`-t dob, ha a
     * `__toString()` egy lezárt (detached) streamet próbál olvasni, tehát a
     * válasz törzsét előre lezárva ugyanaz a hiba reprodukálható.
     */
    public function testAnUnreadableResponseBodyBecomesAFoxPostApiException(): void
    {
        $response = new Response(200, [], 'valami');
        $response->getBody()->close();

        $client = $this->client($response);

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('FoxPost API válasz olvasási hiba');

        $client->getTracking('CSOMAG1');
    }

    public function testAConnectionErrorBecomesAFoxPostApiException(): void
    {
        $client = $this->clientThatThrows(new NetworkException(
            'Name or service not known',
            new Request('GET', 'https://webapi.foxpost.hu/'),
        ));

        try {
            $client->getTracking('CSOMAG1');
            self::fail('Kivételt vártunk.');
        } catch (FoxPostApiException $e) {
            self::assertStringContainsString('FoxPost API kapcsolódási hiba', $e->getMessage());
            self::assertStringContainsString('Name or service not known', $e->getMessage());
            self::assertInstanceOf(NetworkException::class, $e->getPrevious());
        }
    }

    /**
     * A régi kód (`FoxPostApiClient.php:189-196`) üres törzzsel, de
     * `FoxPostApiException`-nel bukott, ha a hibaválasz törzse nem volt
     * olvasható. A törzs-olvasásnak ezért védőhálóban kell lennie: a hiba
     * NEM szökhet ki nyers `RuntimeException`-ként.
     */
    public function testAnUnreadableErrorBodyStillBecomesAFoxPostApiException(): void
    {
        $response = new Response(500, [], 'hiba');
        $response->getBody()->close();

        $client = $this->client($response);

        try {
            $client->getTracking('CSOMAG1');
            self::fail('Kivételt vártunk.');
        } catch (FoxPostApiException $e) {
            self::assertStringContainsString('FoxPost API hiba (HTTP 500)', $e->getMessage());
            self::assertSame(500, $e->getCode());
        }
    }

    // -------------------------------------------------------------------------
    // Base URL választás
    // -------------------------------------------------------------------------

    public function testTheProductionBaseUrlIsUsedByDefault(): void
    {
        $this->client(new Response(200, [], '{"status":"DELIVERED"}'), sandbox: false)->getTracking('CSOMAG1');

        self::assertSame(self::PRODUCTION_BASE_URL . '/api/tracking/CSOMAG1', (string) $this->lastRequest()->getUri());
    }

    public function testTheSandboxBaseUrlIsUsedInSandboxMode(): void
    {
        $this->client(new Response(200, [], '{"status":"DELIVERED"}'), sandbox: true)->getTracking('CSOMAG1');

        self::assertSame(self::SANDBOX_BASE_URL . '/api/tracking/CSOMAG1', (string) $this->lastRequest()->getUri());
    }

    public function testIsSandboxReportsTheConfiguredMode(): void
    {
        self::assertSame(Environment::Production, $this->client(new Response(200, [], ''), sandbox: false)->environment());
        self::assertSame(Environment::Sandbox, $this->client(new Response(200, [], ''), sandbox: true)->environment());
    }

    // -------------------------------------------------------------------------
    // Hitelesítés és fejlécek
    // -------------------------------------------------------------------------

    public function testEveryRequestCarriesBasicAuthAndTheApiKey(): void
    {
        $this->client(new Response(200, [], '{"status":"DELIVERED"}'))->getTracking('CSOMAG1');

        $request = $this->lastRequest();

        self::assertSame('Basic ' . base64_encode('felhasznalo:jelszo'), $request->getHeaderLine('Authorization'));
        self::assertSame('kulcs', $request->getHeaderLine('Api-key'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
    }

    /**
     * A hívásonkénti fejléc felülírja az alapértelmezettet — a címkekérés
     * emiatt kap PDF-et JSON helyett.
     */
    public function testAPerCallHeaderOverridesTheDefaultAccept(): void
    {
        $this->client(new Response(200, [], '%PDF-1.4'))->getLabel(['CSOMAG1']);

        $request = $this->lastRequest();

        self::assertSame('application/pdf', $request->getHeaderLine('Accept'));
        self::assertSame('kulcs', $request->getHeaderLine('Api-key'));
    }

    // -------------------------------------------------------------------------
    // getLabel
    // -------------------------------------------------------------------------

    public function testGetLabelWithoutBarcodesIsARefusedProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Legalább egy vonalkódot meg kell adni.');

        $this->client(new Response(200, [], ''))->getLabel([]);
    }

    public function testGetLabelDoesNotTouchTheNetworkWithoutBarcodes(): void
    {
        $client = $this->client(new Response(200, [], ''));

        try {
            $client->getLabel([]);
            self::fail('Kivételt vártunk.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(\InvalidArgumentException::class, $e);
        }

        self::assertCount(0, $this->httpClient->getRequests());
    }

    public function testGetLabelReturnsTheResponseBodyUntouched(): void
    {
        $pdf = "%PDF-1.4\n\x00\x01 bináris \xff";
        $client = $this->client(new Response(200, [], $pdf));

        self::assertSame($pdf, $client->getLabel(['CSOMAG1']));
    }

    #[DataProvider('pageSizeProvider')]
    public function testGetLabelPutsThePageSizeInTheUrl(string $pageSize, string $expectedPath): void
    {
        $this->client(new Response(200, [], '%PDF-1.4'))->getLabel(['CSOMAG1'], $pageSize);

        self::assertSame(self::PRODUCTION_BASE_URL . $expectedPath, (string) $this->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pageSizeProvider(): iterable
    {
        yield 'alapértelmezett A6' => ['A6', '/api/label/A6'];
        yield 'A5' => ['A5', '/api/label/A5'];
        yield 'A7' => ['A7', '/api/label/A7'];
        yield 'matrica' => ['_85X85', '/api/label/_85X85'];
    }

    public function testGetLabelSendsTheBarcodesAsAJsonArray(): void
    {
        $this->client(new Response(200, [], '%PDF-1.4'))->getLabel(['CSOMAG1', 'CSOMAG2']);

        $request = $this->lastRequest();

        self::assertSame('POST', $request->getMethod());
        self::assertSame('["CSOMAG1","CSOMAG2"]', (string) $request->getBody());
    }

    public function testAFailingLabelRequestBecomesAFoxPostApiException(): void
    {
        $client = $this->client(new Response(404, [], 'nincs ilyen csomag'));

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('FoxPost API hiba (HTTP 404)');

        $client->getLabel(['CSOMAG1']);
    }

    public function testAnUnreadableLabelBodyBecomesAFoxPostApiException(): void
    {
        $response = new Response(200, [], 'valami');
        $response->getBody()->close();

        $client = $this->client($response);

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('Címke lekérés sikertelen');

        $client->getLabel(['CSOMAG1']);
    }

    // -------------------------------------------------------------------------
    // createParcel / deleteParcel / getTracking
    // -------------------------------------------------------------------------

    public function testCreateParcelPostsTheParcelDataAndReturnsTheDecodedResponse(): void
    {
        $result = $this->client(new Response(200, [], '{"barcode":"CSOMAG1"}'))
            ->createParcel(['recipientName' => 'Teszt Elek']);

        $request = $this->lastRequest();

        self::assertSame(['barcode' => 'CSOMAG1'], $result);
        self::assertSame('POST', $request->getMethod());
        self::assertSame(self::PRODUCTION_BASE_URL . '/api/parcel', (string) $request->getUri());
        self::assertSame('{"recipientName":"Teszt Elek"}', (string) $request->getBody());
    }

    /**
     * A régi Symfony HttpClient a `json` opcióra a
     * `HttpClientTrait::jsonEncode()` alapértelmezésével kódolt:
     * `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_PRESERVE_ZERO_FRACTION`
     * (lásd `vendor/symfony/http-client/HttpClientTrait.php:559` a boltban) —
     * `JSON_UNESCAPED_UNICODE` NINCS köztük, tehát az ékezetes karakterek is
     * `\uXXXX` alakban mennek ki, a `<`/`>`/`/` pedig escape-elve. Ez rögzíti,
     * hogy a drótformátum a kiszervezés után is ugyanaz marad.
     */
    public function testTheRequestBodyUsesTheSameJsonEscapingAsBefore(): void
    {
        $this->client(new Response(200, [], '{"barcode":"CSOMAG1"}'))
            ->createParcel(['recipientName' => 'Kovács Béla', 'comment' => 'a/b <x>']);

        self::assertSame(
            '{"recipientName":"Kov\u00e1cs B\u00e9la","comment":"a\/b \u003Cx\u003E"}',
            (string) $this->lastRequest()->getBody(),
        );
    }

    public function testDeleteParcelCallsTheParcelEndpointWithTheBarcode(): void
    {
        $this->client(new Response(200, [], ''))->deleteParcel('CSOMAG1');

        $request = $this->lastRequest();

        self::assertSame('DELETE', $request->getMethod());
        self::assertSame(self::PRODUCTION_BASE_URL . '/api/parcel/CSOMAG1', (string) $request->getUri());
    }

    /**
     * A vonalkód URL-kódolva kerül az útvonalba — különben egy `/` jel kitörne
     * a végpontból.
     */
    public function testTheBarcodeIsUrlEncodedInThePath(): void
    {
        $this->client(new Response(200, [], '{}'))->getTracking('CSOMAG/1 2');

        self::assertSame(self::PRODUCTION_BASE_URL . '/api/tracking/CSOMAG%2F1+2', (string) $this->lastRequest()->getUri());
    }

    public function testGetTrackingReturnsTheDecodedResponse(): void
    {
        $client = $this->client(new Response(200, [], '{"status":"IN_TRANSIT","barcode":"CSOMAG1"}'));

        self::assertSame(['status' => 'IN_TRANSIT', 'barcode' => 'CSOMAG1'], $client->getTracking('CSOMAG1'));
    }

    // -------------------------------------------------------------------------
    // getParcelLockers
    // -------------------------------------------------------------------------

    /**
     * A csomagautomata-lista NEM a webapi-ról jön, hanem a CDN-ről, és
     * sandbox módban is ugyanonnan.
     */
    #[DataProvider('sandboxFlagProvider')]
    public function testTheParcelLockerListAlwaysComesFromTheCdn(bool $sandbox): void
    {
        $this->client(new Response(200, [], '[]'), sandbox: $sandbox)->getParcelLockers();

        self::assertSame('https://cdn.foxpost.hu/foxplus.json', (string) $this->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function sandboxFlagProvider(): iterable
    {
        yield 'éles' => [false];
        yield 'sandbox' => [true];
    }

    public function testTheParcelLockerListIsReturnedAsAList(): void
    {
        $client = $this->client(new Response(200, [], '[{"place_id":"HU1234","name":"Teszt automata"}]'));

        self::assertSame([['place_id' => 'HU1234', 'name' => 'Teszt automata']], $client->getParcelLockers());
    }

    /**
     * A nem tömb elemeket (pl. `null`) kihagyjuk, és a kulcsokat stringgé
     * normalizáljuk — a CDN JSON-ja nem szerződés, bármit tartalmazhat.
     */
    public function testNonArrayEntriesAreSkippedAndKeysAreNormalized(): void
    {
        $client = $this->client(new Response(200, [], '[null,"szemét",{"0":"nulladik","name":"Teszt"},[]]'));

        $lockers = $client->getParcelLockers();

        self::assertCount(2, $lockers);
        self::assertSame(['0' => 'nulladik', 'name' => 'Teszt'], $lockers[0]);
        self::assertSame([], $lockers[1]);
    }

    public function testAnInvalidParcelLockerListBecomesAFoxPostApiException(): void
    {
        $client = $this->client(new Response(200, [], '<html>hupsz</html>'));

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('FoxPost API érvénytelen JSON választ adott vissza.');

        $client->getParcelLockers();
    }

    public function testAnUnreachableCdnBecomesAFoxPostApiException(): void
    {
        $client = $this->clientThatThrows(new NetworkException(
            'cdn.foxpost.hu nem elérhető',
            new Request('GET', 'https://cdn.foxpost.hu/foxplus.json'),
        ));

        $this->expectException(FoxPostApiException::class);
        $this->expectExceptionMessage('Csomagautomata lista lekérés sikertelen');

        $client->getParcelLockers();
    }

    /**
     * A CDN-kérésnek NEM szabad hitelesítő adatot vinnie — a CDN-nek nem kell,
     * és felesleges naplózódna. A régi kód is csak `Accept: * / *`-ot küldött.
     */
    public function testTheCdnRequestCarriesNoCredentials(): void
    {
        $this->client(new Response(200, [], '[]'))->getParcelLockers();

        $request = $this->lastRequest();

        self::assertSame('', $request->getHeaderLine('Authorization'));
        self::assertSame('', $request->getHeaderLine('Api-key'));
    }

    /**
     * A régi kód a `getContent()`-en keresztül nem-2xx válaszra dobott — a
     * `getParcelLockers()`-nek is így kell tennie, különben egy hibás CDN
     * válasz (pl. 401, üres törzs) némán üres listaként jelenik meg hiba
     * helyett.
     */
    #[DataProvider('cdnErrorStatusProvider')]
    public function testANonSuccessfulCdnResponseBecomesAFoxPostApiException(int $status, string $body): void
    {
        $client = $this->client(new Response($status, [], $body));

        try {
            $client->getParcelLockers();
            self::fail('Kivételt vártunk.');
        } catch (FoxPostApiException $e) {
            self::assertStringContainsString(sprintf('Csomagautomata lista lekérés sikertelen: HTTP %d', $status), $e->getMessage());
            self::assertSame($status, $e->getCode());
        }
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function cdnErrorStatusProvider(): iterable
    {
        yield '404, érvényes JSON törzzsel' => [404, '{"error":"nincs ilyen"}'];
        yield '401, üres lista törzzsel' => [401, '[]'];
    }

    // -------------------------------------------------------------------------

    private function client(Response $response, bool $sandbox = false): Client
    {
        $this->httpClient = new MockHttpClient();
        $this->httpClient->addResponse($response);

        return $this->buildClient($sandbox);
    }

    private function clientThatThrows(\Exception $exception, bool $sandbox = false): Client
    {
        $this->httpClient = new MockHttpClient();
        $this->httpClient->addException($exception);

        return $this->buildClient($sandbox);
    }

    private function buildClient(bool $sandbox): Client
    {
        $psr17 = new Psr17Factory();

        return new Client(
            $this->httpClient,
            $psr17,
            $psr17,
            'felhasznalo',
            'jelszo',
            'kulcs',
            Environment::fromSandboxFlag($sandbox),
        );
    }

    private function lastRequest(): RequestInterface
    {
        $requests = $this->httpClient->getRequests();

        if ($requests === []) {
            self::fail('Nem ment ki kérés.');
        }

        return $requests[count($requests) - 1];
    }
}
