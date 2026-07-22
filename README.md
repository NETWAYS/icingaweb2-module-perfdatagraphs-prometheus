# Icinga Web Performance Data Graphs Prometheus Backend

A Prometheus backend for the Icinga Web Performance Data Graphs Module.

This module requires the frontend module:

- https://github.com/NETWAYS/icingaweb2-module-perfdatagraphs

Other Icinga and Prometheus integrations we provide:

* https://github.com/NETWAYS/check_prometheus
* https://github.com/NETWAYS/icinga2-exporter
* https://github.com/NETWAYS/notify-alertmanager
* https://github.com/NETWAYS/alertmanager-icinga-bridge

## Installation Requirements

* PHP version ≥ 8.2
* Icinga2 OTLPMetricsWriter
* A Prometheus compatible API to fetch the data from (Prometheus, Mimir, VictoriaMetrics, etc.)

The module supports both `metrics.with.dots` and `metrics_with_underscores`.
