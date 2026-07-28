<?php

namespace Icinga\Module\Perfdatagraphsprometheus\Client;

/**
 * Icinga2Fields holds information for the PromQL queries
 */
final class Icinga2Fields
{
    public const LABEL_NAME = 'perfdata_label';

    public const HOST_NAME = 'icinga2_host_name';
    public const SERVICE_NAME = 'icinga2_service_name';
    public const COMMAND_NAME = 'icinga2_command_name';
    public const METRIC_CHECK = 'state_check_perfdata';
    public const METRIC_THRESHOLD = 'state_check_threshold';

    public const HOST_NAME_DOT = 'icinga2.host.name';
    public const SERVICE_NAME_DOT = 'icinga2.service.name';
    public const COMMAND_NAME_DOT = 'icinga2.command.name';
    public const METRIC_CHECK_DOT = 'state_check.perfdata';
    public const METRIC_THRESHOLD_DOT = 'state_check.threshold';

    /**
     * Don't use this class any other way
     */
    private function __construct()
    {
    }

    /**
     * escapeLabel handles double quotes and backslashes to prevent breaking the query
     */
    private static function escapeLabel(string $s): string
    {
        return addcslashes($s, '"\\');
    }

    /**
     * buildQuery generates the PromQL query for either format
     */
    private static function buildQuery(
        string $metricCheck,
        string $metricThreshold,
        string $commandKey,
        string $hostKey,
        string $labelKey,
        string $serviceKey,
        string $hostValue,
        string $serviceValue,
        string $commandValue,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): string {
        $q = '{__name__=~"' . $metricCheck . '|' . $metricThreshold . '", ';
        $q .= '"' . $commandKey . '"="' . self::escapeLabel($commandValue) . '", ';
        $q .= '"' . $hostKey . '"="' . self::escapeLabel($hostValue) . '"';

        if (!empty($includeMetrics)) {
            $includes = array_map(fn($label) => str_replace('*', '.*', $label), $includeMetrics);
            $q .= ', "' . $labelKey . '"=~"' . implode('|', $includes) . '"';
        }

        if (!empty($excludeMetrics)) {
            $excludes = array_map(fn($label) => str_replace('*', '.*', $label), $excludeMetrics);
            $q .= ', "' . $labelKey . '"!~"' . implode('|', $excludes) . '"';
        }

        if (!$isHostCheck) {
            $q .= ', "' . $serviceKey . '"="' . self::escapeLabel($serviceValue) . '"';
        }

        return $q . '}';
    }

    /**
     * baseQueryWithUnderscores generates a query with the Prometheus style underscores
     * in the names.
     *
     * {__name__=~"state_check_perfdata|state_check_threshold",
     * icinga2_command_name="procs", icinga2_host_name="example", icinga2_service_name="procs"}
     */
    public static function baseQueryWithUnderscores(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): string {
        return self::buildQuery(
            self::METRIC_CHECK,
            self::METRIC_THRESHOLD,
            self::COMMAND_NAME,
            self::HOST_NAME,
            self::LABEL_NAME,
            self::SERVICE_NAME,
            $hostName,
            $serviceName,
            $checkCommand,
            $isHostCheck,
            $includeMetrics,
            $excludeMetrics
        );
    }

    /**
     * baseQueryWithDots generates a query with the OTel style dots
     * in the names.
     *
     * {__name__=~"state_check.perfdata|state_check.threshold",
     * "icinga2.command.name"="procs", "icinga2.host.name"="example", "icinga2.service.name"="procs"}
     */
    public static function baseQueryWithDots(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): string {
        return self::buildQuery(
            self::METRIC_CHECK_DOT,
            self::METRIC_THRESHOLD_DOT,
            self::COMMAND_NAME_DOT,
            self::HOST_NAME_DOT,
            self::LABEL_NAME,
            self::SERVICE_NAME_DOT,
            $hostName,
            $serviceName,
            $checkCommand,
            $isHostCheck,
            $includeMetrics,
            $excludeMetrics
        );
    }
}
