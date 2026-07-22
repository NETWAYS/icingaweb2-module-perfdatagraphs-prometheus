# Changelog

## v0.2.1

- Fix issues with different service.version labels
- Fix default max_data_points not handling large amounts of data

## v0.2.0

- Raise minimum requirements to PHP 8.2
- Add mTLS authentication option
- Add option to use OTel style queries
- Switch to SplFixedArray and improved error handling

## v0.1.2

- Fix issues when a check interval is higher than the Prometheus lookback-delta
- Validate maxdatapoints input in config form

## v0.1.1

- Fix missing form element for maxdatapoints
- Use getCheckInterval and proper types in Transformer to fix rendering issues

## v0.1.0

- Initial Release
