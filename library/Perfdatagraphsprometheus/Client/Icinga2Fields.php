<?php

namespace Icinga\Module\Perfdatagraphsprometheus\Client;

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

    // Don't use this class any other way
    private function __construct()
    {
    }

    public static function baseQueryWithUnderscore(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): string {
        $q = '{';
        $q .= '__name__=~"' . self::METRIC_CHECK .'|' . self::METRIC_THRESHOLD .'"';
        $q .= ', '. self::COMMAND_NAME . '="' . $checkCommand . '"';
        $q .= ', '. self::HOST_NAME . '="' . $hostName . '"';

        if (count($includeMetrics) > 0) {
            $includes = array_map(fn($label) => str_replace('*', '.*', $label), $includeMetrics);
            $q .= ', '. self::LABEL_NAME .'=~"' . implode('|', $includes) . '"';
        }

        if (count($excludeMetrics) > 0) {
            $excludes = array_map(fn($label) => str_replace('*', '.*', $label), $excludeMetrics);
            $q .= ', '. self::LABEL_NAME .'!~"' . implode('|', $excludes) . '"';
        }

        if (!$isHostCheck) {
            $q .= ', '. self::SERVICE_NAME .'="' . $serviceName . '"';
        }

        $q .= '}';

        return $q;
    }

    public static function baseQueryWithDots(
        string $hostName,
        string $serviceName,
        string $checkCommand,
        bool $isHostCheck,
        array $includeMetrics,
        array $excludeMetrics
    ): string {
        $q = '{';
        $q .= '__name__=~"' . self::METRIC_CHECK_DOT .'|' . self::METRIC_THRESHOLD_DOT .'"';
        $q .= ', "'. self::COMMAND_NAME_DOT . '"="' . $checkCommand . '"';
        $q .= ', "'. self::HOST_NAME_DOT . '"="' . $hostName . '"';

        if (count($includeMetrics) > 0) {
            $includes = array_map(fn($label) => str_replace('*', '.*', $label), $includeMetrics);
            $q .= ', "'. self::LABEL_NAME .'"=~"' . implode('|', $includes) . '"';
        }

        if (count($excludeMetrics) > 0) {
            $excludes = array_map(fn($label) => str_replace('*', '.*', $label), $excludeMetrics);
            $q .= ', "'. self::LABEL_NAME .'"!~"' . implode('|', $excludes) . '"';
        }

        if (!$isHostCheck) {
            $q .= ', "'. self::SERVICE_NAME_DOT .'"="' . $serviceName . '"';
        }

        $q .= '}';

        return $q;
    }
}
