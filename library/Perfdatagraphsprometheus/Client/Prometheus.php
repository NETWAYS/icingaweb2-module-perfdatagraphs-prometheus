<?php

namespace Icinga\Module\Perfdatagraphsprometheus\Client;

use Icinga\Application\Config;
use Icinga\Application\Logger;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use DateInterval;
use DateTimeImmutable;
use Exception;

/**
 * Prometheus handles calling the API and returning the data.
 */
class Prometheus
{
    protected const QUERY_ENDPOINT = '/api/v1/query';
    protected const QUERYRANGE_ENDPOINT = '/api/v1/query_range';

    protected \GuzzleHttp\Client $client;
    protected string $URL;
    protected int $maxDataPoints;
    protected array $auth;

    public readonly bool $useOtelNames;

    public function __construct(
        string $baseURI,
        int $timeout = 10,
        int $maxDataPoints = 5500,
        bool $tlsVerify = true,
        bool $useOtelNames = false,
        array $auth = [],
    ) {
        $this->client = new Client([
            'timeout' => $timeout,
            'verify' => $tlsVerify
        ]);

        $this->URL = rtrim($baseURI, '/');
        $this->useOtelNames = $useOtelNames;
        $this->maxDataPoints = $maxDataPoints;
        $this->auth = $auth;
    }

    /**
     * getAuth returns the auth options to be used in the Guzzle request
     */
    protected function getAuth(): array
    {
        $method = $this->auth['method'] ?? 'none';

        $authOptions = [];

        if ($method === 'basic') {
            $authOptions['auth'] = [
                $this->auth['username'] ?? '',
                $this->auth['password'] ?? ''
            ];
        }

        if ($method === 'token') {
            $t = $this->auth['tokentype'] ?? 'Bearer';
            $v = $this->auth['tokenvalue'] ?? '';
            $authOptions['headers'] = [
                    'Authorization' =>  $t .' '. $v,
            ];
        }

        $mtls = $this->auth['mtls'] ?? false;

        if ($mtls === false) {
            return $authOptions;
        }

        if ($mtls) {
            $authOptions['cert'] = $this->auth['mtls_cert'] ?? '';
            $authOptions['ssl_key'] = $this->auth['mtls_key'] ?? '';
            if (($this->auth['mtls_ca'] ?? '') !== '') {
                $authOptions['verify'] = $this->auth['mtls_ca'] ?? '';
            }
        }

        return $authOptions;
    }

    /**
     * calculateSteps uses the start and end timestamps to calculate the step parameter.
     *
     * The step is never smaller than $checkInterval to avoid repeated identical
     * values caused by Prometheus gap-filling within the lookback window.
     */
    protected function calculateSteps(int $start, int $end, int $maxDataPoints, int $checkInterval = 0): string
    {
        $totalSeconds = $end - $start;

        // Ensure we don't divide by zero
        if ($maxDataPoints < 1) {
            Logger::warning('Perfdatagraphs Prometheus maxDataPoints is too small. Review the module configuration');
            $maxDataPoints = 1;
        }

        $stepSeconds = $totalSeconds / $maxDataPoints;
        // Use the check interval as the minimum step so we don't over-sample.
        // Fall back to 1s when no check interval is available.
        $minStep = $checkInterval > 0 ? $checkInterval : 1;
        $stepSeconds = max($stepSeconds, $minStep);

        return (int)ceil($stepSeconds) . 's';
    }

    /**
     * getMetrics sends the PromQL query to the configured endpoint.
     */
    public function getMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics,
        int $checkInterval = 0
    ): Response {
        $endTime = new DateTimeImmutable();
        $startTime = $endTime->sub(new DateInterval($from));

        $url = $this->URL . $this::QUERYRANGE_ENDPOINT;

        if ($this->useOtelNames) {
            $q = Icinga2Fields::baseQueryWithDots($hostName, $serviceName, $checkCommand, $isHostCheck, $includeMetrics, $excludeMetrics);
        } else {
            $q = Icinga2Fields::baseQueryWithUnderscores($hostName, $serviceName, $checkCommand, $isHostCheck, $includeMetrics, $excludeMetrics);
        }

        $start = $startTime->getTimestamp();
        $end = $endTime->getTimestamp();
        $step = $this->calculateSteps($start, $end, $this->maxDataPoints, $checkInterval);

        // To avoid issues when a check interval is higher than Prometheus lookback-delta.
        // The aggregation with the reduced label set - all required by Transformer - allows merging metrics with
        // different labels, such as "service.version" after an Icinga 2 upgrade.
        $q = sprintf(
            'avg by (__name__, %s, unit, threshold_type) (last_over_time(%s[%s]))',
            Icinga2Fields::LABEL_NAME,
            $q,
            $step
        );

        $query = [
            'query' => [
                'query' => $q,
                'start' => $start,
                'end' => $end,
                'step' => $step,
            ],
        ];

        // Note, make sure this won't override entries in the $query
        // use array_merge_recursive if the need arises
        $query = array_merge($query, $this->getAuth());

        Logger::debug('Calling query API at %s with query: %s', $url, $query);

        $response = $this->client->request('POST', $url, $query);

        return $response;
    }

    /**
     * status calls the HTTP API to determine if it is reachable.
     * We use this to validate the configuration and if the API is reachable.
     *
     * @return array
     */
    public function status(): array
    {
        $l = $this->useOtelNames ? Icinga2Fields::METRIC_CHECK_DOT : Icinga2Fields::METRIC_CHECK;
        $query = [
            'query' => [
                'query' => 'count({__name__="'. $l .'"})',
            ]
        ];

        $query = array_merge($query, $this->getAuth());

        $url = $this->URL . $this::QUERY_ENDPOINT;

        Logger::debug('Calling query API at %s with query: %s', $url, $query);

        try {
            $response = $this->client->request('GET', $url, $query);

            return ['output' =>  $response->getBody()->getContents()];
        } catch (ConnectException $e) {
            return ['output' => 'Connection error: ' . $url . ' ' . $e->getMessage(), 'error' => true];
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return ['output' => 'HTTP error: ' . $url . ' ' . $e->getResponse()->getStatusCode() . ' - ' .
                                      $e->getResponse()->getReasonPhrase(), 'error' => true];
            } else {
                return ['output' => 'Request error: ' . $url . ' '. $e->getMessage(), 'error' => true];
            }
        } catch (Exception $e) {
            return ['output' => 'General error: ' . $url . ' '. $e->getMessage(), 'error' => true];
        }

        return ['output' => 'Unknown error', 'error' => true];
    }

    /**
     * fromConfig returns a new Prometheus Client from this module's configuration
     *
     * @param Config $moduleConfig configuration to load (used for testing)
     * @return $this
     */
    public static function fromConfig(Config $moduleConfig = null): Prometheus
    {
        $default = [
            'api_url' => 'http://localhost:9090',
            'api_timeout' => 10,
            'api_max_data_points' => 5500,
            'api_tls_insecure' => false,
            'api_use_otel_names' => false,
            'api_auth_method' => 'none',
            'api_auth_tokentype' => 'Bearer',
            'api_auth_tokenvalue' => '',
            'api_auth_username' => '',
            'api_auth_password' => '',
            'api_auth_mtls' => false,
            'api_auth_mtls_cert' => '',
            'api_auth_mtls_key' => '',
            'api_auth_mtls_ca' => '',
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs Prometheus module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphsprometheus');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs Prometheus module configuration: %s', $e);
                return new static(
                    baseURI: $default['api_url'],
                    timeout: $default['api_timeout'],
                    tlsVerify: true,
                    maxDataPoints: $default['api_max_data_points'],
                    auth: [],
                );
            }
        }

        $baseURI = rtrim($moduleConfig->get('prometheus', 'api_url', $default['api_url']), '/');
        $timeout = (int) $moduleConfig->get('prometheus', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('prometheus', 'api_max_data_points', $default['api_max_data_points']);
        $useOtelNames = (bool) $moduleConfig->get('prometheus', 'api_use_otel_names', $default['api_use_otel_names']);
        // Auth values
        $authMethod = $moduleConfig->get('prometheus', 'api_auth_method', $default['api_auth_method']);
        $authTokenType = $moduleConfig->get('prometheus', 'api_auth_tokentype', $default['api_auth_tokentype']);
        $authTokenValue = $moduleConfig->get('prometheus', 'api_auth_tokenvalue', $default['api_auth_tokenvalue']);
        $authUsername = $moduleConfig->get('prometheus', 'api_auth_username', $default['api_auth_username']);
        $authPassword = $moduleConfig->get('prometheus', 'api_auth_password', $default['api_auth_password']);
        // mTLS values
        $authMTLS = $moduleConfig->get('prometheus', 'api_auth_mtls', $default['api_auth_mtls']);
        $authMTLSCert = $moduleConfig->get('prometheus', 'api_auth_mtls_cert', $default['api_auth_mtls_cert']);
        $authMTLSKey = $moduleConfig->get('prometheus', 'api_auth_mtls_key', $default['api_auth_mtls_key']);
        $authMTLSCA = $moduleConfig->get('prometheus', 'api_auth_mtls_ca', $default['api_auth_mtls_ca']);
        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('prometheus', 'api_tls_insecure', $default['api_tls_insecure']);
        // Bit hacky, but fine for now
        $auth = [
            'method' => strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
            'mtls' => $authMTLS,
            'mtls_cert' => $authMTLSCert,
            'mtls_key' => $authMTLSKey,
            'mtls_ca' => $authMTLSCA,
        ];

        return new static(
            baseURI: $baseURI,
            timeout: $timeout,
            maxDataPoints: $maxDataPoints,
            tlsVerify: $tlsVerify,
            useOtelNames: $useOtelNames,
            auth: $auth,
        );
    }
}
