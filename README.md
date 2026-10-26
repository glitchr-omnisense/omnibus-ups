# omnibus/ups

UPS for [omnisense/omnibus](https://github.com/glitchr-omnisense/omnibus): rates (Rating API, Shop),
shipments and labels (Shipping API), tracking (Tracking API) and UPS Access Points (Locator API)
- the REST APIs with OAuth2 client credentials.

```php
$gateway = (new UpsGatewayFactory($http))->create($options);   // $http: the application's HTTP client - none given, the factory makes its own; the options below
```

No framework needed: the package requires `omnisense/omnibus` and `symfony/http-client`. In a
Symfony application, the same through the bundle's configuration:

```yaml
omnibus:
    gateways:
        ups:
            factory: ups
            options:
                client_id: '%env(UPS_CLIENT_ID)%'
                client_secret: '%env(UPS_CLIENT_SECRET)%'
                account_number: '%env(UPS_ACCOUNT)%'   # the shipper account, 6 characters
                sandbox: true                           # the Customer Integration Environment
                rates: [...]                            # optional: configured prices instead of the Rating API
```

Shipment options: `label_format` (PDF, GIF or ZPL; UPS gives GIF for PDF requests), `description`.
Services by code: 11 Standard, 65 Saver, 07 Express, 08 Expedited, 03 Ground, 01 Next Day Air...

Credentials: an app in the [UPS Developer Portal](https://developer.ups.com) with the Rating,
Shipping, Tracking and Locator products, plus your shipper account number.

Built from UPS's published API documentation and tested on recorded answers; not yet run against
the Customer Integration Environment: that needs the credentials above.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by United Parcel Service of America, Inc. It reaches their service on behalf of whoever
runs it, and it does not redistribute their data beyond the short excerpts recorded as test
fixtures. Its MIT licence covers this package's code only and grants no right to the service or to
its data.

Whoever installs and runs this package is responsible for complying with UPS's terms ([UPS API
Access
Agreement](https://www.ups.com/assets/resources/webcontent/en_US/ups_api_access_agreement.pdf)), for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or a
citation, a rate limit to respect), and for any use made of what it returns. The authors publish it
free of charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.
