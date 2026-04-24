# Installation

## Packages

NETWAYS provides this module via [https://packages.netways.de](https://packages.netways.de/).

To install this module, follow the setup instructions for the **extras** repository.

**RHEL or compatible:**

`dnf install icingaweb2-module-perfdatagraphs-prometheus`

**Ubuntu/Debian:**

`apt install icingaweb2-module-perfdatagraphs-prometheus`

## From source

1. Clone a Icinga Web Performance Data Graphs Backend repository into `/usr/share/icingaweb2/modules/perfdatagraphsprometheus/`

2. Enable the module using the `Configuration → Modules` menu or the `icingacli`

3. Configure the Prometheus URL, organization, bucket and authentication using the `Configuration → Modules` menu

# Configuration

`config.ini` - section `prometheus`

| Option                      | Description                                                                                              | Default value            |
|-----------------------------|----------------------------------------------------------------------------------------------------------|--------------------------|
| api_url              | The URL for Prometheus including the scheme                                                                | http://localhost:9090  |
| api_timeout          | HTTP timeout for the API in seconds. Should be higher than 0                                             | `10` (seconds)           |
| api_max_data_points  | The maximum numbers of datapoints each series returns. Aggregation can be disabled by setting this to 0. | `10000`                  |
| api_tls_insecure     | Skip the TLS verification                                                                                | `false` (unchecked)      |
| api_auth_method     | Authentication method to use for the API                                                                  | none (none,basic,token) |
| api_auth_username    | HTTP basic auth username                                                                                 |   |
| api_auth_password    | HTTP basic auth password                                                                                 |   |
| api_auth_tokentype   | Token type for the Authorization header                                                                  | `Bearer` |
| api_auth_tokenvalue  | Token for the Authorization header                                                                       |   |

`max_data_points` is used for downsampling data. It uses the `step` parameter of the `/api/v1/query_range` endpoint.

## Prometheus

Prometheus needs to promote the Icinga2 resource attributes. Also, out-of-order ingestion is recommended.

```yaml
storage:
  tsdb:
    out_of_order_time_window: 30m
otlp:
  promote_resource_attributes:
    - service.namespace
    - service.instance.id
    - service.name
    - icinga2.service.name
    - icinga2.command.name
    - icinga2.host.name
```

Example Icinga2 configuration:

```
object OTLPMetricsWriter "prometheus" {
  host = "prometheus"
  port = 9090
  metrics_endpoint = "/api/v1/otlp/v1/metrics"
  enable_send_thresholds = true
}
```

## Prometheus-compatible databases

The module works with Prometheus-compatible databases.

**VictoriaMetrics**

To use VictoriaMetrics enable the `-usePromCompatibleNaming` flag.
This replaces characters unsupported by Prometheus with underscores in the ingested metric names and label names (e.g. foo.bar{a.b='c'} is transformed into foo_bar{a_b='c'}).

Example Icinga2 configuration:

```
object OTLPMetricsWriter "victoriametrics" {
  host = "victoriametrics"
  port = 8428
  metrics_endpoint = "/opentelemetry/v1/metrics"
  enable_send_thresholds = true
}
```

**Grafana Mimir**

With Grafana Mimir make sure the Icinga2 resource attributes are promoted to labels:

```yaml
limits:
  otel_keep_identifying_resource_attributes: true
  promote_otel_resource_attributes: "icinga2.command.name,icinga2.service.name,icinga2.host.name"
```

Example Icinga2 configuration:

```
object OTLPMetricsWriter "mimir" {
  host = "mimir"
  port = 8080
  metrics_endpoint = "/otlp/v1/metrics"
  enable_send_thresholds = true
}
```
