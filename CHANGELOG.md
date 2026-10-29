# Changelog

## [Unreleased]

### Fixed
- Access Points' opening hours are ISO weekdays in `HH:MM`: UPS's Locator numbers the days 1
  Sunday to 7 Saturday and writes military `HHMM` (`930`, `0` at midnight), and both were passed on
  as written (Sunday became Monday, `0900` stayed `0900`). The regular hours (`HoursType` 10) are
  read from `StandardHours` as the v2 list it is; a day with `ClosedIndicator` is left out, one with
  `Open24HoursIndicator` is `00:00`-`24:00`.
- Each label image format UPS answers is labelled with its own media type (`image/gif`,
  `image/png`, `application/zpl`, `application/epl`, `application/spl`, else
  `application/octet-stream`); any code other than GIF and ZPL was labelled `application/pdf`.

### Removed
- The `shipper` option ("the default sender" in the factory's docblock): declared, never read, and
  without a role a shipment's mandatory `sender` leaves it. A provider's option list is public API:
  a configuration that still sets `shipper` keeps working, the value is ignored as it always was.
