<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

final class PriceApiTest extends ApiTestCase
{
    public function testSetReplaceListAndDeletePrices(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $base = '/api/v1/products/'.$product['id'].'/prices';

        $price = $this->requestJson('PUT', $base.'/RUB', $merchant->apiKey, ['amount' => 199900]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['RUB', 199900], [$price['currency'], $price['amount']]);

        $this->requestJson('PUT', $base.'/USD', $merchant->apiKey, ['amount' => 2500]);
        $this->requestJson('PUT', $base.'/RUB', $merchant->apiKey, ['amount' => 149900]);

        $list = $this->requestJson('GET', $base, $merchant->apiKey);
        self::assertSame(
            ['RUB' => 149900, 'USD' => 2500],
            array_column($list['data'], 'amount', 'currency'),
        );

        $this->request('DELETE', $base.'/USD', $merchant->apiKey);
        self::assertResponseStatusCodeSame(204);

        $this->request('DELETE', $base.'/USD', $merchant->apiKey);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPricesAreEmbeddedIntoProductViews(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);

        $this->requestJson('PUT', '/api/v1/products/'.$product['id'].'/prices/EUR', $merchant->apiKey, ['amount' => 1999]);

        $fetched = $this->requestJson('GET', '/api/v1/products/'.$product['id'], $merchant->apiKey);
        self::assertSame(1999, $fetched['prices'][0]['amount']);

        $list = $this->requestJson('GET', '/api/v1/products', $merchant->apiKey);
        self::assertSame('EUR', $list['data'][0]['prices'][0]['currency']);
    }

    public function testInvalidAmountAndCurrencyAreRejected(): void
    {
        $merchant = $this->createMerchant();
        $product = $this->createProduct($merchant);
        $base = '/api/v1/products/'.$product['id'].'/prices';

        $this->requestJson('PUT', $base.'/RUB', $merchant->apiKey, ['amount' => -1]);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('PUT', $base.'/RUB', $merchant->apiKey, ['amount' => 1_000_000_001]);
        self::assertResponseStatusCodeSame(422);

        $this->requestJson('PUT', $base.'/rub', $merchant->apiKey, ['amount' => 100]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testCannotPriceSomeoneElsesProduct(): void
    {
        $owner = $this->createMerchant('Owner');
        $intruder = $this->createMerchant('Intruder');
        $product = $this->createProduct($owner);

        $this->requestJson('PUT', '/api/v1/products/'.$product['id'].'/prices/RUB', $intruder->apiKey, ['amount' => 1]);

        self::assertResponseStatusCodeSame(404);
    }
}
