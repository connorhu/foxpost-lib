<?php

declare(strict_types=1);

namespace CodeConjure\FoxPost;

use CodeConjure\FoxPost\Exception\FoxPostApiException;
use CodeConjure\FoxPost\Exception\FoxPostAuthException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @see https://foxpost.hu/uzleti-partnereknek/integracios-segedlet/webapi-integracio
 * @see https://webapi.foxpost.hu/swagger-ui/index.html
 * @see https://webapi.foxpost.hu/v3/api-docs
 */
final class Client
{
    private const string PARCEL_LOCKERS_URL = 'https://cdn.foxpost.hu/foxplus.json';

    private const string ENDPOINT_PARCEL = '/api/parcel';

    private const string ENDPOINT_LABEL = '/api/label/{pageSize}';

    private const string ENDPOINT_TRACKING = '/api/tracking/{barcode}';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        #[\SensitiveParameter]
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly Environment $environment,
    ) {
    }

    /**
     * POST /api/parcel — csomag feladás.
     *
     * @param array<string, mixed> $parcelData
     *
     * @return array<string, mixed>
     *
     * @throws FoxPostApiException
     */
    public function createParcel(array $parcelData): array
    {
        $response = $this->send('POST', self::ENDPOINT_PARCEL, json: $parcelData);

        return $this->decodeJson($response);
    }

    /**
     * POST /api/label/{pageSize} — csomag címke lekérés.
     *
     * @param string[] $barcodes
     * @param string $pageSize A5|A6|A7|_85X85
     *
     * @return string PDF bináris tartalom
     *
     * @throws FoxPostApiException
     */
    public function getLabel(array $barcodes, string $pageSize = 'A6'): string
    {
        if ($barcodes === []) {
            throw new \InvalidArgumentException('Legalább egy vonalkódot meg kell adni.');
        }

        $endpoint = str_replace('{pageSize}', urlencode($pageSize), self::ENDPOINT_LABEL);

        $response = $this->send('POST', $endpoint, json: $barcodes, headers: ['Accept' => 'application/pdf']);

        try {
            return (string) $response->getBody();
        } catch (\Throwable $e) {
            throw new FoxPostApiException('Címke lekérés sikertelen: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * DELETE /api/parcel/{barcode} — csomag törlés.
     *
     * @throws FoxPostApiException
     */
    public function deleteParcel(string $barcode): void
    {
        $this->send('DELETE', self::ENDPOINT_PARCEL . '/' . urlencode($barcode));
    }

    /**
     * GET /api/tracking/{barcode} — csomag nyomkövetés.
     *
     * @return array<string, mixed>
     *
     * @throws FoxPostApiException
     */
    public function getTracking(string $barcode): array
    {
        $endpoint = str_replace('{barcode}', urlencode($barcode), self::ENDPOINT_TRACKING);

        return $this->decodeJson($this->send('GET', $endpoint));
    }

    /**
     * GET https://cdn.foxpost.hu/foxplus.json — csomagautomata lista.
     *
     * A lista MINDIG a CDN-ről jön, sandbox módban is.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws FoxPostApiException
     */
    public function getParcelLockers(): array
    {
        $request = $this->requestFactory->createRequest('GET', self::PARCEL_LOCKERS_URL)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FoxPostApiException('Csomagautomata lista lekérés sikertelen: ' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            throw new FoxPostApiException(
                sprintf('Csomagautomata lista lekérés sikertelen: HTTP %d', $statusCode),
                $statusCode,
            );
        }

        $lockers = [];

        foreach ($this->decodeJson($response) as $locker) {
            if (!is_array($locker)) {
                continue;
            }

            $normalized = [];

            foreach ($locker as $key => $value) {
                $normalized[(string) $key] = $value;
            }

            $lockers[] = $normalized;
        }

        return $lockers;
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    // -------------------------------------------------------------------------

    /**
     * @param array<mixed>|null $json
     * @param array<string, string> $headers
     *
     * @throws FoxPostApiException
     */
    private function send(string $method, string $endpoint, ?array $json = null, array $headers = []): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $this->environment->baseUrl() . $endpoint);
        $request = $this->withDefaultHeaders($request);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($json !== null) {
            $request = $request->withBody(
                $this->streamFactory->createStream(json_encode(
                    $json,
                    \JSON_HEX_TAG | \JSON_HEX_APOS | \JSON_HEX_AMP | \JSON_HEX_QUOT | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR,
                )),
            );
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FoxPostApiException('FoxPost API kapcsolódási hiba: ' . $e->getMessage(), 0, $e);
        }

        $statusCode = $response->getStatusCode();

        if ($statusCode === 401 || $statusCode === 403) {
            throw new FoxPostAuthException(
                sprintf('FoxPost API hitelesítési hiba (HTTP %d). Ellenőrizd a FOXPOST_USERNAME és FOXPOST_PASSWORD értékeket.', $statusCode),
            );
        }

        if ($statusCode >= 400) {
            $body = '';

            try {
                $body = (string) $response->getBody();
            } catch (\Throwable) {
            }

            throw new FoxPostApiException(
                sprintf('FoxPost API hiba (HTTP %d): %s', $statusCode, $body),
                $statusCode,
            );
        }

        // PSR-18 alatt a 3xx nem dob — külön ág kell, különben a kliens sikert
        // adna vissza egy átirányításra. Lásd a spec 3.9 szakaszát (#172).
        if ($statusCode >= 300) {
            throw new FoxPostApiException(
                sprintf(
                    'FoxPost API váratlan átirányítás (HTTP %d): %s',
                    $statusCode,
                    $response->getHeaderLine('Location') !== '' ? $response->getHeaderLine('Location') : 'nincs Location',
                ),
                $statusCode,
            );
        }

        return $response;
    }

    private function withDefaultHeaders(RequestInterface $request): RequestInterface
    {
        return $request
            ->withHeader('Authorization', 'Basic ' . base64_encode($this->username . ':' . $this->password))
            ->withHeader('Accept', 'application/json')
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Api-key', $this->apiKey);
    }

    /**
     * @return array<mixed>
     *
     * @throws FoxPostApiException
     */
    private function decodeJson(ResponseInterface $response): array
    {
        try {
            $content = (string) $response->getBody();
        } catch (\Throwable $e) {
            throw new FoxPostApiException('FoxPost API válasz olvasási hiba: ' . $e->getMessage(), 0, $e);
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new FoxPostApiException('FoxPost API érvénytelen JSON választ adott vissza.');
        }

        return $decoded;
    }
}
