<?php

namespace App\Services;

use App\Exceptions\AgentClientException;
use App\Exceptions\AgentConnectionException;
use App\Exceptions\AgentSecurityException;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AgentClient
{
    /**
     * Compute SHA-256 hex string of the raw JSON payload.
     */
    public function computePayloadHash(string $payloadJson): string
    {
        return hash('sha256', $payloadJson);
    }

    /**
     * Canonical string format: action.requestId.timestamp.nonce.dryRun.payloadHash
     */
    public function buildCanonicalString(
        string $action,
        string $requestId,
        int $timestamp,
        string $nonce,
        bool $dryRun,
        string $payloadJson
    ): string {
        $dryRunStr = $dryRun ? '1' : '0';
        $payloadHash = $this->computePayloadHash($payloadJson);

        return sprintf(
            '%s.%s.%d.%s.%s.%s',
            $action,
            $requestId,
            $timestamp,
            $nonce,
            $dryRunStr,
            $payloadHash
        );
    }

    /**
     * Sign the request using HMAC-SHA256 with the shared secret key.
     */
    public function sign(
        string $action,
        string $requestId,
        int $timestamp,
        string $nonce,
        bool $dryRun,
        string $payloadJson,
        string $secret
    ): string {
        $canonical = $this->buildCanonicalString(
            $action,
            $requestId,
            $timestamp,
            $nonce,
            $dryRun,
            $payloadJson
        );

        return hash_hmac('sha256', $canonical, $secret);
    }

    /**
     * Execute a typed action on a server agent.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, data: array<string, mixed>, error: string|null, logs: array<int, string>, request_id: string, action: string}
     *
     * @throws AgentSecurityException
     * @throws AgentConnectionException
     * @throws AgentClientException
     */
    public function execute(
        Server $server,
        string $action,
        array $payload = [],
        bool $dryRun = false,
        ?string $requestId = null,
        int $maxRetries = 3
    ): array {
        $requestId ??= (string) Str::uuid();
        $timestamp = time();
        $nonce = bin2hex(random_bytes(16));
        $secret = (string) config('services.agent.secret', '');
        $defaultSecret = 'test-agent-secret-key-change-me';

        if (app()->environment('production') && ($secret === '' || hash_equals($defaultSecret, $secret))) {
            throw new \RuntimeException(
                'PANEL_AGENT_HMAC_SECRET must be configured to a non-default value in production.'
            );
        }

        $payloadJson = empty($payload)
            ? '{}'
            : (json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        $signature = $this->sign($action, $requestId, $timestamp, $nonce, $dryRun, $payloadJson, $secret);

        $url = rtrim($server->agent_url, '/').'/action';
        $bodyJson = $this->buildRequestBody($action, $requestId, $timestamp, $nonce, $dryRun, $payloadJson, $signature);

        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                $response = $this->sendHttpRequest($url, $bodyJson);

                return $this->handleResponse($response, $action, $requestId);
            } catch (AgentSecurityException|AgentClientException $e) {
                // Do not retry 4xx auth or validation client errors
                throw $e;
            } catch (ConnectionException|\Exception $e) {
                $lastException = $e;

                if ($attempt >= $maxRetries) {
                    break;
                }

                // Exponential backoff: 200ms, 400ms, 800ms...
                usleep((int) (200000 * (2 ** ($attempt - 1))));
            }
        }

        throw new AgentConnectionException(
            sprintf(
                'Agent at %s failed to respond to action %s after %d attempts: %s',
                $server->hostname,
                $action,
                $maxRetries,
                $lastException?->getMessage() ?? 'Unknown error'
            ),
            500,
            $lastException,
            ['action' => $action, 'request_id' => $requestId, 'server_id' => $server->id]
        );
    }

    /**
     * Send ping action to the server agent.
     *
     * @return array{success: bool, data: array<string, mixed>, error: string|null, logs: array<int, string>, request_id: string, action: string}
     */
    public function ping(Server $server, bool $dryRun = false): array
    {
        return $this->execute($server, 'system.ping', [], $dryRun);
    }

    /**
     * Send health check action to the server agent.
     *
     * @return array{success: bool, data: array<string, mixed>, error: string|null, logs: array<int, string>, request_id: string, action: string}
     */
    public function health(Server $server, bool $dryRun = false): array
    {
        return $this->execute($server, 'system.health', [], $dryRun);
    }

    /**
     * Build the raw JSON request body embedding the exact signed payloadJson bytes.
     */
    public function buildRequestBody(
        string $action,
        string $requestId,
        int $timestamp,
        string $nonce,
        bool $dryRun,
        string $payloadJson,
        string $signature
    ): string {
        return sprintf(
            '{"action":%s,"request_id":%s,"timestamp":%d,"nonce":%s,"dry_run":%s,"payload":%s,"signature":%s}',
            json_encode($action, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($requestId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $timestamp,
            json_encode($nonce, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $dryRun ? 'true' : 'false',
            $payloadJson,
            json_encode($signature, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Prepare HTTP client with mTLS options and timeouts.
     *
     * @param  string|array<string, mixed>  $body
     */
    protected function sendHttpRequest(string $url, string|array $body): Response
    {
        $timeout = (int) config('services.agent.timeout', 30);
        $connectTimeout = (int) config('services.agent.connect_timeout', 5);

        $options = [];

        // Configure client certificate and private key for mTLS if provided
        $clientCert = config('services.agent.client_cert_file');
        $clientKey = config('services.agent.client_key_file');
        if ($clientCert && $clientKey && file_exists($clientCert) && file_exists($clientKey)) {
            $options['ssl_key'] = $clientKey;
            $options['cert'] = $clientCert;
        }

        // Configure custom CA certificate if provided
        $caFile = config('services.agent.tls_ca_file');
        if ($caFile && file_exists($caFile)) {
            $options['verify'] = $caFile;
        }

        $request = Http::timeout($timeout)
            ->connectTimeout($connectTimeout)
            ->withOptions($options)
            ->acceptJson();

        if (is_string($body)) {
            return $request->withBody($body, 'application/json')->post($url);
        }

        return $request->asJson()->post($url, $body);
    }

    /**
     * Parse and validate HTTP response from agent.
     *
     * @return array{success: bool, data: array<string, mixed>, error: string|null, logs: array<int, string>, request_id: string, action: string}
     */
    protected function handleResponse(Response $response, string $action, string $requestId): array
    {
        $status = $response->status();
        $json = $response->json();

        if ($status === 401) {
            $code = $json['error']['code'] ?? 'UNAUTHORIZED';
            $message = $json['error']['message'] ?? 'Authentication or signature verification failed';

            throw new AgentSecurityException(
                sprintf('Agent security rejection [%s]: %s', $code, $message),
                401,
                null,
                ['code' => $code, 'action' => $action, 'request_id' => $requestId]
            );
        }

        if ($status === 404) {
            $message = $json['error']['message'] ?? "Unknown action '$action'";

            throw new AgentClientException(
                sprintf('Unknown action requested: %s', $message),
                404,
                null,
                ['action' => $action, 'request_id' => $requestId]
            );
        }

        if (in_array($status, [400, 422, 500], true)) {
            $message = $this->extractErrorMessage($json, 'Invalid payload or request format');

            throw new AgentClientException(
                sprintf('Agent payload rejection (%d): %s', $status, $message),
                $status,
                null,
                ['action' => $action, 'request_id' => $requestId, 'response' => $json]
            );
        }

        if ($response->failed()) {
            throw new AgentConnectionException(
                sprintf('Agent returned HTTP error %d: %s', $status, $response->body()),
                $status,
                null,
                ['action' => $action, 'request_id' => $requestId]
            );
        }

        return [
            'success' => (bool) ($json['success'] ?? false),
            'data' => (array) ($json['data'] ?? []),
            'error' => $json['error'] ?? null,
            'logs' => (array) ($json['logs'] ?? []),
            'request_id' => (string) ($json['request_id'] ?? $requestId),
            'action' => (string) ($json['action'] ?? $action),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $json
     */
    protected function extractErrorMessage(?array $json, string $default): string
    {
        $error = $json['error'] ?? null;
        $message = $default;

        if (is_string($error) && $error !== '') {
            $message = $error;
        } elseif (is_array($error)) {
            $parts = [];
            $errorMessage = $error['message'] ?? null;
            $errorDetails = $error['details'] ?? null;

            if (is_string($errorMessage) && $errorMessage !== '') {
                $parts[] = $errorMessage;
            }
            if (is_string($errorDetails) && $errorDetails !== '') {
                $parts[] = $errorDetails;
            }

            if ($parts !== []) {
                $message = implode(' | ', $parts);
            }
        }

        return Str::limit($message, 500, '');
    }
}
