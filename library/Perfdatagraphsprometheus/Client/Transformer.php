<?php

namespace Icinga\Module\Perfdatagraphsprometheus\Client;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Logger;
use Icinga\Util\Json;

use GuzzleHttp\Psr7\Response;

/**
 * Transformer handles all data transformation.
 */
class Transformer
{
    /**
     * preparePerfdataResponse adds the values PerfdataSeries and timestamps
     */
    protected static function preparePerfdataResponse(array $results, PerfdataResponse $pfr): PerfdataResponse
    {
        $metricname = 'state_check_perfdata';

        foreach ($results as $result) {
            // We first check for the state_check_perfdata metric
            // This is where we get the values and timestamps
            if ($result['metric']['__name__'] !== $metricname) {
                continue;
            }

            // The label (name) of the performance data series
            $label = $result['metric']['perfdata_label'];

            // Do we have a dataset already?
            $dataset = $pfr->getDataset($label);
            // if not we create a new one
            if (empty($dataset)) {
                $unit = $result['metric']['unit'] ?? '';
                $dataset = new PerfdataSet($label, $unit);
                $pfr->addDataset($dataset);
            }

            $values = [];
            $timestamps = [];

            foreach ($result['values'] as $point) {
                $timestamps[] = $point[0];
                $values[] = $point[1];
            }

            $valuesSeries = new PerfdataSeries('value', $values);

            // If the series is empty we can stop here
            if ($valuesSeries->isEmpty()) {
                continue;
            }

            $dataset->addSeries($valuesSeries);
            $dataset->setTimestamps($timestamps);
        }

        return $pfr;
    }

    /**
     * preparePerfdataResponse adds the values PerfdataSeries and timestamps
     */
    protected static function appendThresholds(array $results, PerfdataResponse $pfr): PerfdataResponse
    {
        $metricname = 'state_check_threshold';

        foreach ($results as $result) {
            // If the __name__ is state_check_threshold then we have 'thresholds'
            // We create a new PerfdataSeries for 'critical' and add values
            if ($result['metric']['__name__'] !== $metricname) {
                continue;
            }

            // The label (name) of the performance data series
            $label = $result['metric']['perfdata_label'];
            // The the of threshold (warning, critical, etc.)
            $thresholdType = $result['metric']['threshold_type'] ?? '';

            // Skip everything that is not critical/warning
            if ($thresholdType !== 'warning' && $thresholdType !== 'critical') {
                continue;
            }

            $dataset = $pfr->getDataset($label);
            // Probably not gonna happen, but just in case
            if ($dataset === null) {
                continue;
            }

            $ts = $dataset->getTimestamps();
            $valueMap = array_column($result['values'], 1, 0);
            // Get the matching threshold value for the given timestamp otherwise use null
            $thresholds = [];
            foreach ($ts as $timestamp) {
                $thresholds[] = $valueMap[$timestamp] ?? null;
            }

            $thresholdSeries = new PerfdataSeries($thresholdType, $thresholds);

            // If the series is empty we can stop here
            if ($thresholdSeries->isEmpty()) {
                continue;
            }

            $dataset->addSeries($thresholdSeries);
        }

        return $pfr;
    }

    /**
     * transform takes the Prometheus response and transforms it into the
     * output format we need.
     *
     * @param GuzzleHttp\Psr7\Response $response the data to transform
     * @return PerfdataResponse
     */
    public static function transform(Response $response): PerfdataResponse
    {
        $pfr = new PerfdataResponse();

        if (empty($response)) {
            Logger::warning('Did not receive data in response');
            return $pfr;
        }

        $body = Json::decode($response->getBody()->getContents(), true);

        if ($body['status'] !== 'success') {
            $pfr->addError($body['error'] ?? 'unknown error');
            return $pfr;
        }

        $results = $body['data']['result'];

        $pfr = self::preparePerfdataResponse($results, $pfr);
        // Since the thresholds might be enabled/disabled we need to use the length of the values for the Series
        // and pad missing thresholds with null. I don't like having a second loop, maybe there's a better way
        $pfr = self::appendThresholds($results, $pfr);

        return $pfr;
    }
}
