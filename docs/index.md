# omnibus/ups

UPS for [omniscient/omnibus](https://github.com/glitchr-studio/omnibus): rates, shipments and
labels, tracking and UPS Access Points, through UPS's REST APIs on an OAuth 2 client-credentials
token. The gateway calls:

| Operation | UPS API | Path |
|---|---|---|
| token | OAuth | `POST /security/v1/oauth/token` |
| rate | Rating API, `Shop` | `POST /api/rating/v2409/Shop` |
| ship | Shipping API | `POST /api/shipments/v2409/ship` |
| track | Tracking API | `GET /api/track/v1/details/{trackingNumber}` |
| pickup points | Locator API, Access Point search | `POST /api/locations/v2/search/availabilities/64` |

on `https://onlinetools.ups.com` (live) or `https://wwwcie.ups.com` (the Customer Integration
Environment, `sandbox: true`). The API version is the constant `Omnibus\Ups\Api::VERSION`
(`v2409`).

## Installation

```sh
composer require omnibus/ups
```

Requires PHP 8.2, `omniscient/omnibus` (2.x) and `symfony/http-client` (6.4, 7 or 8). In a Symfony
application with `Omnibus\Bridge\Symfony\OmnibusBundle`, the factory is registered as soon as the
package is installed.

## Credentials

- An application in the [UPS Developer Portal](https://developer.ups.com) with the Rating,
  Shipping, Tracking and Locator products: its **client id** and **client secret**.
- The shipper's **UPS account number** (6 characters): it pays for the shipments
  (`BillShipper`) and is sent as `x-merchant-id` when the token is asked for.

The same credentials work on the Customer Integration Environment; UPS publishes no shared test
account.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `client_id` | string | - | yes | the app's client id |
| `client_secret` | string | - | yes | the app's client secret |
| `account_number` | string | - | yes | the shipper account |
| `sandbox` | bool | `false` | no | `true`: the Customer Integration Environment |
| `rates` | list | - | no | prices from configuration instead of the Rating API ([the rates option](https://github.com/glitchr-studio/omnibus/blob/2.x/docs/configuration.md#the-rates-option)) |

Shipment options read when booking (`Shipment::$options`):

| Option | Default | |
|---|---|---|
| `label_format` | `PDF` | `PDF`, `GIF` or `ZPL`. UPS has no PDF label: `PDF` asks for `GIF`, and the label comes back as `image/gif` |
| `description` | `Parcel` | the shipment's description |

The factory also declares a `shipper` option (default `null`), which no code reads: the sender is
always the shipment's `sender`.

> [!WARNING]
> Known defect: `shipper` is declared and never read, where the factory's docblock calls it the default sender.

## Capabilities

| Request | Supported |
|---|---|
| `Rating` | yes (the Rating API), or configured rates with `rates` |
| `Shipping` | yes |
| `Tracking` | yes |
| `Pickup` | yes (UPS Access Points) |
| `GetSlip` | no |
| `Cancel` | no |
| `Paperless` | no |

## Behaviour

**Token.** Asked for on the first call, not when the gateway is built: `POST` with HTTP basic
authentication (client id and secret) and `grant_type=client_credentials`. It is kept in the API
client until 60 seconds before it expires (`expires_in`, 14400 seconds when UPS does not say). Every
call sends `Authorization: Bearer`, a random `transId` and `transactionSrc: omnibus`, with a
20-second timeout.

**Addresses and parcels.** `Name` is the address's company, else its name; `AttentionName` its
name; both cut to 35 characters. The phone keeps its digits only. A parcel is UPS packaging code
`02` (customer packaging), its weight in kilograms with one decimal (at least 0.1 kg), its
dimensions in centimetres when all three are given.

**Rate.** The `Shop` request prices every service UPS offers for the shipment; `Shipment::$service`
is not sent. The amount is the negotiated total when UPS returns one (`NegotiatedRateCharges`),
else the published total, in the currency UPS gives. `days` is
`GuaranteedDelivery.BusinessDaysInTransit` when present. Labels come from
`Omnibus\Ups\Mapping::SERVICES`:

| Code | Service |
|---|---|
| `01` | Next Day Air |
| `02` | 2nd Day Air |
| `03` | Ground |
| `07` | Worldwide Express |
| `08` | Worldwide Expedited |
| `11` | Standard |
| `12` | 3 Day Select |
| `54` | Worldwide Express Plus |
| `65` | Worldwide Saver |

Another code is labelled `UPS <code>`. Rates come cheapest first; `toPickupPoint` is always `false`.

**Ship.** One `ShipmentRequest` with `RequestOption: nonvalidate` (UPS does not validate the
address), billed to the shipper account, service `Shipment::$service` or `11` (Standard), the
reference (35 characters) as `ReferenceNumber`, a 4 x 6 inch label. The tracking number is
`ShipmentIdentificationNumber`, else the first package's; the label is the first package's image,
base64-decoded into `content`; `trackingUrl` is `https://www.ups.com/track?tracknum=<number>`. A
response without a number is a `CarrierException` (`UPS booked no shipment.`). `pickupPoint` is
not sent: delivery to an Access Point is not booked by this package.

**Track.** `GET` with `locale` built from the request's locale: `fr` becomes `fr_FR`, `en-GB`
becomes `en_GB`. UPS lists activities newest first; they are reversed, so events come oldest first,
each with UPS's status code kept as `code` and `city countryCode` as `location`. The status is the
latest event's, mapped from UPS's status type:

| UPS type | `TrackingStatus` |
|---|---|
| `D`, `DD`, `DO` | `DELIVERED` |
| `O` | `OUT_FOR_DELIVERY` |
| `I`, `P`, `W` | `IN_TRANSIT` |
| `M` | `PENDING` |
| `X` | `EXCEPTION` |
| `RS` | `RETURNED` |
| anything else | `UNKNOWN` |

A shipment of several packages has the events of each package in turn.

**Pickup points.** Active UPS Access Points (`AccessPointStatus 01`) within 25 km of the address
(its first street line, city, postcode and country), at most `min(50, limit)`; the parcel is not
sent. The id is the Access Point's public id (else the location id), the distance UPS's kilometres
converted to metres, opening hours read from UPS's standard hours by its `Day` number and its
`OpenHours` / `CloseHours` as UPS writes them.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnibus\Model\Address;
use Omnibus\Model\Parcel;
use Omnibus\Model\Shipment;
use Omnibus\Ups\UpsGatewayFactory;
use Symfony\Component\HttpClient\HttpClient;

$ups = (new UpsGatewayFactory(HttpClient::create()))->create([
    'client_id' => getenv('UPS_CLIENT_ID'),
    'client_secret' => getenv('UPS_CLIENT_SECRET'),
    'account_number' => getenv('UPS_ACCOUNT'),
    'sandbox' => true,
]);

$shipment = new Shipment(
    new Address('Glitch Art', ['12 rue de la Paix'], '75002', 'Paris', 'FR', phone: '+33102030405'),
    new Address('Ada Lovelace', ['10 Downing Street'], 'SW1A 2AA', 'London', 'GB', email: 'ada@example.org'),
    [new Parcel(1200, 30, 20, 10)],
    service: '11',
    reference: 'CMD-1042',
    options: ['label_format' => 'ZPL', 'description' => 'Prints'],
);

foreach ($ups->rate($shipment) as $rate) {
    printf("%s %-20s %6.2f %s\n", $rate->service, $rate->label, $rate->amount / 100, $rate->currency);
}

$label = $ups->ship($shipment);
file_put_contents($label->trackingNumber.'.zpl', $label->content);
echo $label->trackingNumber, ' ', $label->trackingUrl, "\n";

$tracking = $ups->track($label->trackingNumber, 'en');
echo $tracking->status->value, "\n";
```

In Symfony:

```yaml
omnibus:
    gateways:
        ups:
            factory: ups
            options:
                client_id: '%env(UPS_CLIENT_ID)%'
                client_secret: '%env(UPS_CLIENT_SECRET)%'
                account_number: '%env(UPS_ACCOUNT)%'
                sandbox: '%env(bool:UPS_SANDBOX)%'
```

The core's Docker harness calls the same gateway from its console with the keys in
`docker/.env`: `docker compose run --rm omnibus rate ups --to-country GB --weight 1200`.

## Errors

Every failure is an `Omnibus\Exception\CarrierException` whose `carrier` is `ups`:

- an HTTP status of 400 or more: the message is UPS's first error (`response.errors[0].message`),
  its code in `carrierCode`;
- a body that is not JSON (an error page from a proxy included), a network error: `UPS request
  failed: ...` or `UPS answered HTTP <status> with a body that is not JSON.`;
- no token: `UPS gave no token: ...`, or UPS's error message.

A missing `client_id`, `client_secret` or `account_number` is an `InvalidConfigException` when the
gateway is built; `GetSlip` and `Cancel` raise `RequestNotSupportedException` before any call.

## Verified, and not

Tested offline: `Tests/UpsGatewayTest.php` (rates cheapest first with their service names and
transit days, the shipper account in the request, weights in kilograms, the GIF label, tracking
events oldest first, Access Points with their distance) and `Tests/UpsContractTest.php`, the
family's conformance suite on recorded answers (every failure a `CarrierException`, unsupported
requests refused before any call). The recorded answers follow UPS's published API documentation.

Not verified: no call was made to UPS, on the Customer Integration Environment or live; that
needs the credentials above. Two readings of the Locator's answer are unchecked against a real
one: whether its `Day` numbers are ISO weekdays (1 = Monday), and whether its hours are written
`HH:MM` (they are passed on as UPS writes them).

## Responsibility

An independent, unofficial client, not affiliated with United Parcel Service of America, Inc.
Whoever runs it complies with UPS's terms ([UPS API Access
Agreement](https://www.ups.com/assets/resources/webcontent/en_US/ups_api_access_agreement.pdf)):
see the README's Responsibility section.
