<?php

namespace Icinga\Module\Perfdatagraphsprometheus\ProvidedHook\PerfdataGraphs;

use Icinga\Module\Perfdatagraphsprometheus\Client\Prometheus;
use Icinga\Module\Perfdatagraphsprometheus\Client\Transformer;

use Icinga\Module\Perfdatagraphs\Hook\PerfdataSourceHook;
use Icinga\Module\Perfdatagraphs\Model\PerfdataRequest;
use Icinga\Module\Perfdatagraphs\Model\PerfdataResponse;

use Icinga\Application\Benchmark;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

use Exception;

class PerfdataSource extends PerfdataSourceHook
{
    public function getName(): string
    {
        return 'Prometheus';
    }

    public function fetchData(PerfdataRequest $req): PerfdataResponse
    {
        $perfdataresponse = new PerfdataResponse();

        Benchmark::measure('Fetching performance data from Prometheus');

        // Create a client and get the data from the API
        try {
            $client = Prometheus::fromConfig();
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
            return $perfdataresponse;
        }

        try {
            $response = $client->getMetrics(
                $req->getHostname(),
                $req->getServicename(),
                $req->getCheckcommand(),
                $req->getDuration(),
                $req->isHostCheck(),
                $req->getIncludeMetrics(),
                $req->getExcludeMetrics(),
                $req->getCheckInterval()
            );
        } catch (ConnectException $e) {
            $perfdataresponse->addError($e->getMessage());
        } catch (RequestException $e) {
            $perfdataresponse->addError($e->getMessage());
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
        }

        Benchmark::measure('Fetched performance data from Prometheus');

        // Why even bother when we have errors here
        if ($perfdataresponse->hasErrors()) {
            return $perfdataresponse;
        }

        try {
            // Transform into the PerfdataSourceHook format
            $perfdataresponse = Transformer::transform($response);
        } catch (Exception $e) {
            $perfdataresponse->addError($e->getMessage());
        }

        Benchmark::measure('Transformed performance data from Prometheus');

        return $perfdataresponse;
    }
}
