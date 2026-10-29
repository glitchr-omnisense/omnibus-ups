<?php

namespace Omnibus\Ups;

use Omnibus\Config;
use Omnibus\GatewayFactory;
use Omnibus\Ups\Action\PickupAction;
use Omnibus\Ups\Action\RatingAction;
use Omnibus\Ups\Action\ShippingAction;
use Omnibus\Ups\Action\TrackingAction;
use Symfony\Component\HttpClient\HttpClient;

/**
 *   options:
 *     client_id: '%env(UPS_CLIENT_ID)%'          # an app in the UPS Developer Portal
 *     client_secret: '%env(UPS_CLIENT_SECRET)%'
 *     account_number: '%env(UPS_ACCOUNT)%'       # the shipper account (6 characters)
 *     sandbox: true                               # the Customer Integration Environment
 *     rates: [...]                                # optional: prices from configuration instead of the Rating API
 */
final class UpsGatewayFactory extends GatewayFactory
{
    protected function populateConfig(Config $config): void
    {
        $config->defaults([
            'omnibus.factory_name' => 'ups',
            'omnibus.factory_title' => 'UPS',
            'omnibus.required_options' => ['client_id', 'client_secret', 'account_number'],
            'sandbox' => false,
            'omnibus.api' => function (Config $c) {
                $http = $this->http ?? HttpClient::create();

                return new Api($http, (string) $c['client_id'], (string) $c['client_secret'], (string) $c['account_number'], (bool) $c['sandbox']);
            },
            'omnibus.action.rating' => static fn (Config $c) => $c->get('rates') ? null : new RatingAction(),
            'omnibus.action.shipping' => new ShippingAction(),
            'omnibus.action.tracking' => new TrackingAction(),
            'omnibus.action.pickup' => new PickupAction(),
        ]);
    }
}
