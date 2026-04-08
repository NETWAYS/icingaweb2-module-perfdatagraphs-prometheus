<?php

namespace Icinga\Module\Perfdatagraphsprometheus\Client;

use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSet;
use Icinga\Module\Perfdatagraphs\Model\PerfdataSeries;

use Icinga\Application\Logger;

use GuzzleHttp\Psr7\Response;

use SplFixedArray;

/**
 * Transformer handles all data transformation.
 */
class Transformer
{
    /**
     * transform takes the Prometheus response and transforms it into the
     * output format we need.
     *
     * @param GuzzleHttp\Psr7\Response $response the data to transform
     * @param array $includeMetrics metrics to include in the response
     * @param array $excludeMetrics metrics to exlude from the response
     * @return PerfdataResponse
     */
    public static function transform(
        Response $response,
        array $excludeMetrics = [],
    ): PerfdataResponse {
        $pfr = new PerfdataResponse();

        if (empty($response)) {
            Logger::warning('Did not receive data in response');
            return $pfr;
        }

        $body = json_decode($response->getBody()->getContents(), true);

        if ($body['status'] !== 'success') {
            $perfdataresponse->addError($body['error'] ?? 'unknown error');
            return $pfr;
        }

        foreach ($body['data']['result'] as $result) {
            $metriclabel = $result['metric']['perfdata_label'];
            // Probably not gonna happen, but just in case
            if ($metriclabel === null || $metriclabel === '') {
                continue;
            }

            // Since we query the values and thresholds in one query
            $metricname = $result['metric']['__name__'];

            // If the __name__ is state_check_perfdata then we have 'values'
            // We create a new PerfdataSeries for 'value' and add values
            if ($metricname == 'state_check_perfdata') {
                // Do we have a dataset already?
                $dataset = $pfr->getDataset($metriclabel);

                // No, then create a new one
                if (empty($dataset)) {
                    $unit = $result['metric']['unit'] ?? '';
                    $dataset = new PerfdataSet($metriclabel, $unit);
                    $pfr->addDataset($dataset);
                }

                // We're using an SplFixedArray since we don't need fancy array features
                // and want to use less memory
                $values = new SplFixedArray(count($result['values']));
                $timestamps = new SplFixedArray(count($result['values']));

                foreach ($result['values'] as $i => $point) {
                    $timestamps[$i] = $point[0];
                    $values[$i] = $point[1];
                }

                $valuesSeries = new PerfdataSeries('value', $values);
                if ($valuesSeries->isEmpty()) {
                    continue;
                }
                $dataset->addSeries($valuesSeries);
                $dataset->setTimestamps($timestamps);
            }

            // If the __name__ is state_check_threshold then we have 'thresholds'
            // We create a new PerfdataSeries for 'critical' and add values
            if ($metricname === 'state_check_threshold') {
                $thresholdType = $result['metric']['threshold_type'] ?? '';
                // Skip everything that is not critical/warning
                if ($thresholdType === null || $thresholdType === '' || $thresholdType === 'min' || $thresholdType === 'max') {
                    continue;
                }

                $dataset = $pfr->getDataset($metriclabel);

                if (empty($dataset)) {
                    // TODO: What now? Could the state_check_threshold is returned before the state_check_perfdata
                    continue;
                }

                $values = new SplFixedArray(count($result['values']));

                foreach ($result['values'] as $i => $point) {
                    $values[$i] = $point[1];
                }

                $thresholdSeries = new PerfdataSeries($thresholdType, $values);

                if ($thresholdSeries->isEmpty()) {
                    continue;
                }
                $dataset->addSeries($thresholdSeries);
            }
        }

        return $pfr;
    }
}
