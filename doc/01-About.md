# Icinga Web Performance Data Graphs Prometheus Backend

A Prometheus backend for the Icinga Web Performance Data Graphs Module.

This backend uses `PromQL` to fetch performance data from a Prometheus API.

It is meant to be used with the Icinga2 OTLPMetricsWriter.

## Prometheus High Availability

Note that we do not intend to support multiple configurable Prometheus targets.
High availability for Prometheus needs to be implemented outside of this module and expose a single URL endpoint for this module.

We recommend a load balancer or proxy layer for redundancy. A [Thanos](https://thanos.io) query-frontend with multiple sidecars can also be used.
