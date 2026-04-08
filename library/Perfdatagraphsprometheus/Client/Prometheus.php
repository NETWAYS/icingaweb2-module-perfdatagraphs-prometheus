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

    /** @var $this \Icinga\Application\Modules\Module */
    protected $client = null;

    protected string $URL;
    protected int $maxDataPoints;
    protected array $auth;

    public function __construct(
        string $baseURI,
        int $timeout = 10,
        int $maxDataPoints = 10000,
        bool $tlsVerify = true,
        array $auth = [],
    ) {
        $this->client = new Client([
            'timeout' => $timeout,
            'verify' => $tlsVerify
        ]);

        $this->URL = rtrim($baseURI, '/');

        $this->maxDataPoints = $maxDataPoints;
        $this->auth = $auth;
    }

    protected function generateBaseQuery(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): string {
        $q = '{';
        // TODO: Should we support OTel style names for metrics and labels?
        // e.g. state_check.perfdata and icinga2.host.name
        // Would require a config option to toggle.
        $q .= '__name__=~"state_check_perfdata|state_check_threshold"';
        $q .= ', icinga2_command_name="' . $checkCommand . '"';
        $q .= ', icinga2_host_name="' . $hostName . '"';

        if (count($includeMetrics) > 0) {
            $includes = array_map(function ($label) {
                return str_replace('*', '.*', $label);
            }, $includeMetrics);

            $q .= ', perfdata_label=~"' . implode('|', $includes) . '"';
        }

        if (count($excludeMetrics) > 0) {
            $excludes = array_map(function ($label) {
                return str_replace('*', '.*', $label);
            }, $excludeMetrics);

            $q .= ', perfdata_label!~"' . implode('|', $excludes) . '"';
        }

        if (!$isHostCheck) {
            $q .= ', icinga2_service_name="' . $serviceName . '"';
        }

        $q .= '}';

        return $q;
    }

    protected function getAuth(): array
    {
        $authOptions = [];

        if ($this->auth['method'] == 'none') {
            return $authOptions;
        }

        if ($this->auth['method'] == 'basic') {
            $authOptions['auth'] = [
                $this->auth['username'] ?? '',
                $this->auth['password'] ?? ''
            ];
        }

        if ($this->auth['method'] == 'token') {
            $t = $this->auth['tokentype'] ?? 'Bearer';
            $v = $this->auth['tokenvalue'] ?? '';
            $authOptions['headers'] = [
                    'Authorization' =>  $t .' '. $v,
            ];
        }

        return $authOptions;
    }

    /**
     * calculateSteps uses the start and end timestamps to calculate the step parameter
     */
    protected function calculateSteps(int $start, int $end, int $maxDataPoints): string
    {
        $totalSeconds = $end - $start;
        $stepSeconds = $totalSeconds / $maxDataPoints;
        $stepSeconds = max($stepSeconds, 60);

        return (int)round($stepSeconds) . 's';
    }

    public function getMetrics(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        string $from,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): Response {
        $endTime = new DateTimeImmutable();
        $startTime = $endTime->sub(new DateInterval($from));

        $url = $this->URL . $this::QUERYRANGE_ENDPOINT;

        $q = $this->generateBaseQuery($hostName, $serviceName, $checkCommand, $isHostCheck, $includeMetrics, $excludeMetrics);

        $start = $startTime->getTimestamp();
        $end = $endTime->getTimestamp();
        $step = $this->calculateSteps($start, $end, $this->maxDataPoints);

        $query = [
            'query' => [
                'query' => $q,
                'start' => $start,
                'end' => $end,
                'step' => $step,
            ],
        ];

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
        $query = [
            'query' => [
                'query' => 'count({__name__="state_check_perfdata"})',
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
            'api_max_data_points' => 10000,
            'api_tls_insecure' => false,
            'api_auth_method' => 'none',
            'api_auth_tokentype' => 'Bearer',
            'api_auth_tokenvalue' => '',
            'api_auth_username' => '',
            'api_auth_password' => '',
        ];

        // Try to load the configuration
        if ($moduleConfig === null) {
            try {
                Logger::debug('Loaded Perfdata Graphs Prometheus module configuration to get Config');
                $moduleConfig = Config::module('perfdatagraphsprometheus');
            } catch (Exception $e) {
                Logger::error('Failed to load Perfdata Graphs Prometheus module configuration: %s', $e);
                return $default;
            }
        }

        $baseURI = rtrim($moduleConfig->get('prometheus', 'api_url', $default['api_url']), '/');
        $timeout = (int) $moduleConfig->get('prometheus', 'api_timeout', $default['api_timeout']);
        $maxDataPoints = (int) $moduleConfig->get('prometheus', 'api_max_data_points', $default['api_max_data_points']);
        // Auth values
        $authMethod = $moduleConfig->get('prometheus', 'api_auth_method', $default['api_auth_method']);
        $authTokenType = $moduleConfig->get('prometheus', 'api_auth_tokentype', $default['api_auth_tokentype']);
        $authTokenValue = $moduleConfig->get('prometheus', 'api_auth_tokenvalue', $default['api_auth_tokenvalue']);
        $authUsername = $moduleConfig->get('prometheus', 'api_auth_username', $default['api_auth_username']);
        $authPassword = $moduleConfig->get('prometheus', 'api_auth_password', $default['api_auth_password']);

        // Hint: We use a "skip TLS" logic in the UI, but Guzzle uses "verify TLS"
        $tlsVerify = !(bool) $moduleConfig->get('prometheus', 'api_tls_insecure', $default['api_tls_insecure']);
        // Bit hacky, but fine for now
        $auth = [
            'method' => mb_strtolower($authMethod),
            'tokentype' => $authTokenType,
            'tokenvalue' => $authTokenValue,
            'username' => $authUsername,
            'password' => $authPassword,
        ];

        return new static(
            baseURI: $baseURI,
            timeout: $timeout,
            maxDataPoints: $maxDataPoints,
            tlsVerify: $tlsVerify,
            auth: $auth,
        );
    }
}
