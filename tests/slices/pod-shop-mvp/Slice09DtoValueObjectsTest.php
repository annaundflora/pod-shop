<?php
declare(strict_types=1);

namespace SpreadconnectPod\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use SpreadconnectPod\Api\Dto\Address;
use SpreadconnectPod\Api\Dto\ArticleDetail;
use SpreadconnectPod\Api\Dto\ArticleSummary;
use SpreadconnectPod\Api\Dto\AuthOk;
use SpreadconnectPod\Api\Dto\DtoMapper;
use SpreadconnectPod\Api\Dto\Money;
use SpreadconnectPod\Api\Dto\OrderCreate;
use SpreadconnectPod\Api\Dto\OrderItem;
use SpreadconnectPod\Api\Dto\Preview;
use SpreadconnectPod\Api\Dto\ShippingType;
use SpreadconnectPod\Api\Dto\StockEntry;
use SpreadconnectPod\Api\Dto\Subscription;
use SpreadconnectPod\Api\Dto\Variant;
use SpreadconnectPod\Api\Dto\WebhookEvent;

/**
 * Slice 09 — DTO Value Objects + Snake/Camel Mapper.
 *
 * Acceptance tests gegen `slice-09-dto-value-objects.md`. Pure Value-Object-
 * Validation: keine HTTP-/DB-/WP-Hook-Mocks noetig (Mocking-Strategy
 * `no_mocks`). Jeder Test ist 1:1 aus einem GIVEN/WHEN/THEN abgeleitet.
 */
final class Slice09DtoValueObjectsTest extends TestCase
{
    /**
     * 13 DTO-Value-Object-Klassen (ohne DtoMapper-Helper).
     *
     * @return list<class-string>
     */
    private static function dtoClasses(): array
    {
        return [
            OrderCreate::class,
            OrderItem::class,
            Address::class,
            Money::class,
            ArticleSummary::class,
            ArticleDetail::class,
            Variant::class,
            ShippingType::class,
            Subscription::class,
            StockEntry::class,
            Preview::class,
            WebhookEvent::class,
            AuthOk::class,
        ];
    }

    /**
     * Valid sample-input pro DTO. Alle Werte sind kanonisch korrekt
     * (snake_case wie vom Spreadconnect-API geliefert) damit AC-2 fuer
     * jede Klasse durchlaufen kann.
     *
     * @return array<class-string, array{0: string, 1: array<string, mixed>}>
     */
    private static function validSamplesPerDto(): array
    {
        $money = ['amount' => '19.99', 'currency' => 'EUR'];

        $address = [
            'first_name' => 'Anna',
            'last_name'  => 'Mustermann',
            'street'     => 'Hauptstr. 1',
            'zip_code'   => '10115',
            'city'       => 'Berlin',
            'country'    => 'DE',
        ];

        $variant = [
            'sku'      => 'V-001',
            'size_id'  => 'L',
            'color_id' => 'red',
        ];

        return [
            OrderCreate::class => [
                'fromArray',
                [
                    'external_order_reference' => 'WC-1001',
                    'order_items'              => [
                        ['sku' => 'A-1', 'quantity' => 2],
                    ],
                    'billing_address'          => $address,
                    'shipping_address'         => $address,
                ],
            ],
            OrderItem::class => [
                'fromArray',
                ['sku' => 'A-1', 'quantity' => 1],
            ],
            Address::class => [
                'fromArray',
                $address,
            ],
            Money::class => [
                'fromArray',
                $money,
            ],
            ArticleSummary::class => [
                'fromResponse',
                [
                    'id'              => 'art-1',
                    'title'           => 'Tee',
                    'product_type_id' => '12',
                    'variants'        => [$variant],
                ],
            ],
            ArticleDetail::class => [
                'fromResponse',
                [
                    'id'              => 'art-1',
                    'title'           => 'Tee',
                    'product_type_id' => '12',
                    'variants'        => [$variant],
                ],
            ],
            Variant::class => [
                'fromArray',
                $variant,
            ],
            ShippingType::class => [
                'fromResponse',
                [
                    'id'      => 'std',
                    'company' => 'DHL',
                    'name'    => 'Standard',
                    'price'   => $money,
                ],
            ],
            Subscription::class => [
                'fromResponse',
                [
                    'id'           => 'sub-1',
                    'event_type'   => 'Article.added',
                    'callback_url' => 'https://example.test/hook',
                ],
            ],
            StockEntry::class => [
                'fromResponse',
                ['sku' => 'A-1', 'quantity' => 5],
            ],
            Preview::class => [
                'fromArray',
                [
                    'view_id'   => 'v-1',
                    'image_url' => 'https://cdn.example.test/p.png',
                ],
            ],
            WebhookEvent::class => [
                'fromArray',
                [
                    'event_type' => 'Order.processed',
                    'data'       => [
                        'point_of_sale_id' => 'pos-1',
                        'entity'           => ['order_id' => 42],
                    ],
                ],
            ],
            AuthOk::class => [
                'fromResponse',
                ['point_of_sale_id' => 'pos-1', 'account_id' => 'acc-1'],
            ],
        ];
    }

    // -------------------------------------------------------------------
    // AC-1: GIVEN alle 13 DTO-Klassen WHEN per Reflection inspiziert
    //       THEN final + readonly + im richtigen Namespace, kein set*()
    // -------------------------------------------------------------------
    public function test_ac1_all_dtos_are_final_readonly_value_objects(): void
    {
        foreach (self::dtoClasses() as $class) {
            $rc = new ReflectionClass($class);

            $this->assertTrue($rc->isFinal(), "{$class} must be declared `final`.");
            $this->assertTrue($rc->isReadOnly(), "{$class} must be declared `readonly`.");
            $this->assertSame(
                'SpreadconnectPod\\Api\\Dto',
                $rc->getNamespaceName(),
                "{$class} must live in namespace SpreadconnectPod\\Api\\Dto."
            );

            foreach ($rc->getMethods() as $method) {
                $name = $method->getName();
                $this->assertDoesNotMatchRegularExpression(
                    '/^set[A-Z]/',
                    $name,
                    "{$class}::{$name}() looks like a setter — DTOs must be immutable."
                );
            }
        }
    }

    // -------------------------------------------------------------------
    // AC-2: GIVEN gueltiges Input-Array WHEN Factory aufgerufen
    //       THEN Instanz mit korrekten Properties; zwei Aufrufe -> equal but not same
    // -------------------------------------------------------------------
    public function test_ac2_valid_input_constructs_instance_per_dto(): void
    {
        foreach (self::validSamplesPerDto() as $class => [$factory, $input]) {
            /** @var callable $callable */
            $callable = [$class, $factory];
            $instance = $callable($input);

            $this->assertInstanceOf($class, $instance, "{$class}::{$factory}() must return instance of {$class}.");

            // Wert-Gleichheit, separate Identitaet beim zweiten Aufruf.
            $second = $callable($input);
            $this->assertInstanceOf($class, $second);
            $this->assertNotSame($instance, $second, "{$class}::{$factory}() must return a fresh instance per call.");
            $this->assertEquals($instance, $second, "{$class}::{$factory}() must produce value-equal instances for identical input.");
        }

        // Spot-checks fuer Property-Werte:
        $order = OrderCreate::fromArray([
            'external_order_reference' => 'WC-1001',
            'order_items'              => [['sku' => 'A-1', 'quantity' => 2]],
            'billing_address'          => $this->validAddressArray(),
            'shipping_address'         => $this->validAddressArray(),
        ]);
        $this->assertSame('WC-1001', $order->externalOrderReference);
        $this->assertCount(1, $order->orderItems);
        $this->assertInstanceOf(OrderItem::class, $order->orderItems[0]);
        $this->assertSame('A-1', $order->orderItems[0]->sku);
        $this->assertSame(2, $order->orderItems[0]->quantity);
        $this->assertInstanceOf(Address::class, $order->billingAddress);
        $this->assertInstanceOf(Address::class, $order->shippingAddress);

        $money = Money::fromArray(['amount' => '19.99', 'currency' => 'EUR']);
        $this->assertSame('19.99', $money->amount);
        $this->assertSame('EUR', $money->currency);
        $this->assertNull($money->taxRate);

        $variant = Variant::fromArray([
            'sku'               => 'V-1',
            'size_id'           => 'L',
            'color_id'          => 'red',
            'price_calculation' => ['amount' => '5.00', 'currency' => 'EUR'],
        ]);
        $this->assertSame('V-1', $variant->sku);
        $this->assertSame('L', $variant->sizeId);
        $this->assertSame('red', $variant->colorId);
        $this->assertInstanceOf(Money::class, $variant->priceCalculation);
        $this->assertSame('5.00', $variant->priceCalculation->amount);

        $auth = AuthOk::fromResponse(['point_of_sale_id' => 'pos-9', 'account_id' => 'acc-9']);
        $this->assertSame('pos-9', $auth->pointOfSaleId);
        $this->assertSame('acc-9', $auth->accountId);
        $this->assertSame(['pointOfSaleId' => 'pos-9', 'accountId' => 'acc-9'], $auth->raw);
    }

    // -------------------------------------------------------------------
    // AC-3: GIVEN required-field-violation WHEN Factory aufgerufen
    //       THEN InvalidArgumentException mit Field-Name in Message
    // -------------------------------------------------------------------
    /**
     * Verletzungs-Matrix aus der Spec.
     *
     * @return array<string, array{0: callable, 1: string}>
     */
    public static function provideRequiredFieldViolations(): array
    {
        $address = [
            'first_name' => 'Anna',
            'last_name'  => 'Mustermann',
            'street'     => 'Hauptstr. 1',
            'zip_code'   => '10115',
            'city'       => 'Berlin',
            'country'    => 'DE',
        ];

        return [
            'OrderCreate empty externalOrderReference' => [
                static fn () => OrderCreate::fromArray([
                    'external_order_reference' => '',
                    'order_items'              => [['sku' => 'A', 'quantity' => 1]],
                    'billing_address'          => $address,
                    'shipping_address'         => $address,
                ]),
                'externalOrderReference',
            ],
            'OrderCreate missing billingAddress' => [
                static fn () => OrderCreate::fromArray([
                    'external_order_reference' => 'WC-1',
                    'order_items'              => [['sku' => 'A', 'quantity' => 1]],
                    'shipping_address'         => $address,
                ]),
                'billingAddress',
            ],
            'OrderCreate missing shippingAddress' => [
                static fn () => OrderCreate::fromArray([
                    'external_order_reference' => 'WC-1',
                    'order_items'              => [['sku' => 'A', 'quantity' => 1]],
                    'billing_address'          => $address,
                ]),
                'shippingAddress',
            ],
            'OrderItem empty sku' => [
                static fn () => OrderItem::fromArray(['sku' => '', 'quantity' => 1]),
                'sku',
            ],
            'OrderItem quantity zero' => [
                static fn () => OrderItem::fromArray(['sku' => 'A', 'quantity' => 0]),
                'quantity',
            ],
            'OrderItem quantity negative' => [
                static fn () => OrderItem::fromArray(['sku' => 'A', 'quantity' => -1]),
                'quantity',
            ],
            'Address empty firstName' => [
                static fn () => Address::fromArray(array_merge($address, ['first_name' => ''])),
                'firstName',
            ],
            'Address empty lastName' => [
                static fn () => Address::fromArray(array_merge($address, ['last_name' => ''])),
                'lastName',
            ],
            'Address empty street' => [
                static fn () => Address::fromArray(array_merge($address, ['street' => ''])),
                'street',
            ],
            'Address empty zipCode' => [
                static fn () => Address::fromArray(array_merge($address, ['zip_code' => ''])),
                'zipCode',
            ],
            'Address empty city' => [
                static fn () => Address::fromArray(array_merge($address, ['city' => ''])),
                'city',
            ],
            'Money invalid amount' => [
                static fn () => Money::fromArray(['amount' => '19', 'currency' => 'EUR']),
                'amount',
            ],
            'Money invalid currency' => [
                static fn () => Money::fromArray(['amount' => '19.00', 'currency' => 'eur']),
                'currency',
            ],
            'ArticleSummary empty id' => [
                static fn () => ArticleSummary::fromResponse([
                    'id' => '', 'title' => 'T', 'product_type_id' => '1', 'variants' => [],
                ]),
                'id',
            ],
            'ArticleSummary empty title' => [
                static fn () => ArticleSummary::fromResponse([
                    'id' => 'a', 'title' => '', 'product_type_id' => '1', 'variants' => [],
                ]),
                'title',
            ],
            'ArticleSummary empty productTypeId' => [
                static fn () => ArticleSummary::fromResponse([
                    'id' => 'a', 'title' => 'T', 'product_type_id' => '', 'variants' => [],
                ]),
                'productTypeId',
            ],
            'ArticleDetail empty id' => [
                static fn () => ArticleDetail::fromResponse([
                    'id' => '', 'title' => 'T', 'product_type_id' => '1', 'variants' => [],
                ]),
                'id',
            ],
            'StockEntry negative quantity' => [
                static fn () => StockEntry::fromResponse(['sku' => 'A', 'quantity' => -1]),
                'quantity',
            ],
        ];
    }

    #[DataProvider('provideRequiredFieldViolations')]
    public function test_ac3_invalid_input_throws_with_field_name(callable $invoke, string $expectedField): void
    {
        try {
            $invoke();
            $this->fail("Expected InvalidArgumentException mentioning '{$expectedField}'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString(
                $expectedField,
                $e->getMessage(),
                "Exception message must mention violated field '{$expectedField}', got: {$e->getMessage()}"
            );
        }
    }

    // -------------------------------------------------------------------
    // AC-4: GIVEN OrderCreate WHEN orderItems leer
    //       THEN InvalidArgumentException; mit >=1 Element OK
    // -------------------------------------------------------------------
    public function test_ac4_order_create_rejects_empty_order_items(): void
    {
        $address = $this->validAddressArray();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/orderItems/');

        OrderCreate::fromArray([
            'external_order_reference' => 'WC-1',
            'order_items'              => [],
            'billing_address'          => $address,
            'shipping_address'         => $address,
        ]);
    }

    public function test_ac4_order_create_accepts_single_item(): void
    {
        $address = $this->validAddressArray();

        $order = OrderCreate::fromArray([
            'external_order_reference' => 'WC-1',
            'order_items'              => [['sku' => 'A', 'quantity' => 1]],
            'billing_address'          => $address,
            'shipping_address'         => $address,
        ]);

        $this->assertCount(1, $order->orderItems);
        $this->assertSame('A', $order->orderItems[0]->sku);
    }

    // -------------------------------------------------------------------
    // AC-5: Address country == ISO 3166-1 alpha-2
    // -------------------------------------------------------------------
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideInvalidCountryCodes(): array
    {
        return [
            'three letters'   => ['DEU'],
            'lower case'      => ['de'],
            'empty string'    => [''],
            'single letter'   => ['D'],
            'mixed case'      => ['De'],
            'four letters'    => ['DEUT'],
            'digits'          => ['12'],
        ];
    }

    #[DataProvider('provideInvalidCountryCodes')]
    public function test_ac5_address_rejects_non_iso_alpha2_country(mixed $country): void
    {
        $payload = array_merge($this->validAddressArray(), ['country' => $country]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/country/');

        Address::fromArray($payload);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideValidCountryCodes(): array
    {
        return [
            'DE' => ['DE'],
            'AT' => ['AT'],
            'FR' => ['FR'],
            'US' => ['US'],
        ];
    }

    #[DataProvider('provideValidCountryCodes')]
    public function test_ac5_address_accepts_iso_alpha2_country(string $country): void
    {
        $payload = array_merge($this->validAddressArray(), ['country' => $country]);

        $address = Address::fromArray($payload);

        $this->assertSame($country, $address->country);
    }

    // -------------------------------------------------------------------
    // AC-6: Money amount + currency Format
    // -------------------------------------------------------------------
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideInvalidMoneyAmounts(): array
    {
        return [
            'no decimals'       => ['19'],
            'one decimal'       => ['19.5'],
            'three decimals'    => ['19.999'],
            'negative'          => ['-1.00'],
            'int instead string'=> [1990],
            'empty'             => [''],
            'letters'           => ['ten.00'],
        ];
    }

    #[DataProvider('provideInvalidMoneyAmounts')]
    public function test_ac6_money_rejects_invalid_amount(mixed $amount): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/amount/');

        Money::fromArray(['amount' => $amount, 'currency' => 'EUR']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideInvalidMoneyCurrencies(): array
    {
        return [
            'lower case'    => ['eur'],
            'two letters'   => ['EU'],
            'four letters'  => ['EURO'],
            'empty'         => [''],
            'digits'        => ['123'],
        ];
    }

    #[DataProvider('provideInvalidMoneyCurrencies')]
    public function test_ac6_money_rejects_invalid_currency(mixed $currency): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/currency/');

        Money::fromArray(['amount' => '19.99', 'currency' => $currency]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideValidMoneyAmounts(): array
    {
        return [
            'simple'     => ['19.99'],
            'zero'       => ['0.00'],
            'large'      => ['1234567.89'],
        ];
    }

    #[DataProvider('provideValidMoneyAmounts')]
    public function test_ac6_money_accepts_two_decimal_amount(string $amount): void
    {
        $money = Money::fromArray(['amount' => $amount, 'currency' => 'EUR']);

        $this->assertSame($amount, $money->amount);
        $this->assertSame('EUR', $money->currency);
    }

    // -------------------------------------------------------------------
    // AC-7: Preview imageUrl muss HTTPS sein
    // -------------------------------------------------------------------
    /**
     * @return array<string, array{0: mixed}>
     */
    public static function provideNonHttpsUrls(): array
    {
        return [
            'http'   => ['http://example.test/p.png'],
            'ftp'    => ['ftp://example.test/p.png'],
            'empty'  => [''],
            'no scheme' => ['example.test/p.png'],
        ];
    }

    #[DataProvider('provideNonHttpsUrls')]
    public function test_ac7_preview_rejects_non_https_image_url(mixed $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/imageUrl/');

        Preview::fromArray(['view_id' => 'v-1', 'image_url' => $url]);
    }

    public function test_ac7_preview_accepts_https_image_url(): void
    {
        $preview = Preview::fromArray([
            'view_id'   => 'v-1',
            'image_url' => 'https://cdn.example.test/p.png',
        ]);

        $this->assertSame('v-1', $preview->viewId);
        $this->assertSame('https://cdn.example.test/p.png', $preview->imageUrl);
    }

    // -------------------------------------------------------------------
    // AC-8: WebhookEvent eventType in 7-Event-Enum, entity bleibt array
    // -------------------------------------------------------------------
    /**
     * @return array<string, array{0: string}>
     */
    public static function provideAllowedEventTypes(): array
    {
        return [
            'Article.added'     => ['Article.added'],
            'Article.updated'   => ['Article.updated'],
            'Article.removed'   => ['Article.removed'],
            'Order.cancelled'   => ['Order.cancelled'],
            'Order.processed'   => ['Order.processed'],
            'Order.needs-action'=> ['Order.needs-action'],
            'Shipment.sent'     => ['Shipment.sent'],
        ];
    }

    #[DataProvider('provideAllowedEventTypes')]
    public function test_ac8_webhook_event_accepts_allowed_event_types(string $eventType): void
    {
        $event = WebhookEvent::fromArray([
            'event_type' => $eventType,
            'data'       => [
                'point_of_sale_id' => 'pos-1',
                'entity'           => ['anything' => 'goes'],
            ],
        ]);

        $this->assertSame($eventType, $event->eventType);
        $this->assertSame('pos-1', $event->pointOfSaleId);
    }

    public function test_ac8_webhook_event_rejects_unknown_event_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/eventType/');

        WebhookEvent::fromArray([
            'event_type' => 'Order.exploded',
            'data'       => [
                'point_of_sale_id' => 'pos-1',
                'entity'           => [],
            ],
        ]);
    }

    public function test_ac8_webhook_event_keeps_entity_untyped_array(): void
    {
        $rawEntity = [
            'order_id' => 42,
            'nested'   => ['weird_field' => true, 'list' => [1, 2, 3]],
        ];

        $event = WebhookEvent::fromArray([
            'event_type' => 'Order.processed',
            'data'       => [
                'point_of_sale_id' => 'pos-1',
                'entity'           => $rawEntity,
            ],
        ]);

        // entity ist Array (per Architecture-Note "Raw entity kept untyped").
        $this->assertIsArray($event->entity);
        // Keys werden vom DtoMapper normalisiert (snake -> camel rekursiv).
        $this->assertArrayHasKey('orderId', $event->entity);
        $this->assertSame(42, $event->entity['orderId']);
    }

    // -------------------------------------------------------------------
    // AC-9: Subscription eventType im gleichen 7-Event-Enum
    // -------------------------------------------------------------------
    #[DataProvider('provideAllowedEventTypes')]
    public function test_ac9_subscription_accepts_allowed_event_types(string $eventType): void
    {
        $sub = Subscription::fromResponse([
            'id'           => 'sub-1',
            'event_type'   => $eventType,
            'callback_url' => 'https://example.test/hook',
        ]);

        $this->assertSame($eventType, $sub->eventType);
    }

    public function test_ac9_subscription_rejects_unknown_event_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/eventType/');

        Subscription::fromResponse([
            'id'           => 'sub-1',
            'event_type'   => 'NotAnEvent',
            'callback_url' => 'https://example.test/hook',
        ]);
    }

    public function test_ac9_subscription_uses_same_enum_as_webhook_event(): void
    {
        $this->assertSame(
            Subscription::ALLOWED_EVENT_TYPES,
            WebhookEvent::ALLOWED_EVENT_TYPES,
            'Subscription and WebhookEvent must share the same 7-event enum.'
        );
        $this->assertCount(7, Subscription::ALLOWED_EVENT_TYPES);
    }

    // -------------------------------------------------------------------
    // AC-10: OrderItem sku non-empty + quantity >= 1
    // -------------------------------------------------------------------
    public function test_ac10_order_item_rejects_empty_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sku/');

        OrderItem::fromArray(['sku' => '', 'quantity' => 1]);
    }

    public function test_ac10_order_item_rejects_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/quantity/');

        OrderItem::fromArray(['sku' => 'ABC-123', 'quantity' => 0]);
    }

    public function test_ac10_order_item_rejects_negative_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/quantity/');

        OrderItem::fromArray(['sku' => 'ABC-123', 'quantity' => -1]);
    }

    public function test_ac10_order_item_rejects_missing_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/quantity/');

        OrderItem::fromArray(['sku' => 'ABC-123']);
    }

    public function test_ac10_order_item_accepts_valid_input(): void
    {
        $item = OrderItem::fromArray(['sku' => 'ABC-123', 'quantity' => 1]);

        $this->assertSame('ABC-123', $item->sku);
        $this->assertSame(1, $item->quantity);
    }

    // -------------------------------------------------------------------
    // AC-11: DtoMapper::snakeToCamel konvertiert alle gelisteten Keys
    // -------------------------------------------------------------------
    public function test_ac11_mapper_converts_all_listed_snake_keys_to_camel(): void
    {
        $input = [
            'external_order_reference' => 1,
            'zip_code'                 => 1,
            'customer_email'           => 1,
            'tax_type'                 => 1,
            'street_annex'             => 1,
            'first_name'               => 1,
            'last_name'                => 1,
            'tax_rate'                 => 1,
            'tax_amount'               => 1,
            'product_type_id'          => 1,
            'design_id'                => 1,
            'color_id'                 => 1,
            'size_id'                  => 1,
            'price_calculation'        => 1,
            'callback_url'             => 1,
            'event_type'               => 1,
            'view_id'                  => 1,
            'image_url'                => 1,
            'expires_at'               => 1,
            'point_of_sale_id'         => 1,
            'error_reason'             => 1,
        ];

        $output = DtoMapper::snakeToCamel($input);

        $expected = [
            'externalOrderReference', 'zipCode', 'customerEmail', 'taxType',
            'streetAnnex', 'firstName', 'lastName', 'taxRate', 'taxAmount',
            'productTypeId', 'designId', 'colorId', 'sizeId', 'priceCalculation',
            'callbackUrl', 'eventType', 'viewId', 'imageUrl', 'expiresAt',
            'pointOfSaleId', 'errorReason',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey(
                $key,
                $output,
                "snakeToCamel must produce camelCase key '{$key}' for the corresponding snake_case input."
            );
        }
        // Keine snake-Keys bleiben uebrig.
        foreach (array_keys($input) as $snakeKey) {
            $this->assertArrayNotHasKey($snakeKey, $output);
        }
    }

    public function test_ac11_mapper_recurses_into_nested_arrays(): void
    {
        $input = [
            'order_items' => [
                ['product_type_id' => 'pt-1', 'price_calculation' => ['tax_amount' => '1.00']],
                ['product_type_id' => 'pt-2'],
            ],
            'data' => [
                'point_of_sale_id' => 'pos-1',
                'entity'           => ['order_id' => 99],
            ],
        ];

        $output = DtoMapper::snakeToCamel($input);

        $this->assertArrayHasKey('orderItems', $output);
        $this->assertArrayHasKey('productTypeId', $output['orderItems'][0]);
        $this->assertArrayHasKey('priceCalculation', $output['orderItems'][0]);
        $this->assertArrayHasKey('taxAmount', $output['orderItems'][0]['priceCalculation']);
        $this->assertArrayHasKey('data', $output);
        $this->assertArrayHasKey('pointOfSaleId', $output['data']);
        $this->assertArrayHasKey('orderId', $output['data']['entity']);
    }

    public function test_ac11_mapper_preserves_numeric_list_keys(): void
    {
        $input = [
            'order_items' => [
                ['sku' => 'A'],
                ['sku' => 'B'],
                ['sku' => 'C'],
            ],
        ];

        $output = DtoMapper::snakeToCamel($input);

        $this->assertSame([0, 1, 2], array_keys($output['orderItems']));
    }

    // -------------------------------------------------------------------
    // AC-12: DtoMapper::camelToSnake + Roundtrip-Identitaet
    // -------------------------------------------------------------------
    public function test_ac12_mapper_camel_to_snake_converts_all_listed_keys(): void
    {
        $camelKeys = [
            'externalOrderReference', 'zipCode', 'customerEmail', 'taxType',
            'streetAnnex', 'firstName', 'lastName', 'taxRate', 'taxAmount',
            'productTypeId', 'designId', 'colorId', 'sizeId', 'priceCalculation',
            'callbackUrl', 'eventType', 'viewId', 'imageUrl', 'expiresAt',
            'pointOfSaleId', 'errorReason',
        ];

        $input = array_fill_keys($camelKeys, 1);

        $output = DtoMapper::camelToSnake($input);

        $expectedSnake = [
            'external_order_reference', 'zip_code', 'customer_email', 'tax_type',
            'street_annex', 'first_name', 'last_name', 'tax_rate', 'tax_amount',
            'product_type_id', 'design_id', 'color_id', 'size_id', 'price_calculation',
            'callback_url', 'event_type', 'view_id', 'image_url', 'expires_at',
            'point_of_sale_id', 'error_reason',
        ];

        foreach ($expectedSnake as $snake) {
            $this->assertArrayHasKey(
                $snake,
                $output,
                "camelToSnake must produce snake_case key '{$snake}'."
            );
        }
    }

    public function test_ac12_mapper_roundtrip_is_identity(): void
    {
        $camelInput = [
            'externalOrderReference' => 'WC-1',
            'orderItems'             => [
                [
                    'sku'              => 'A',
                    'quantity'         => 1,
                    'priceCalculation' => ['taxAmount' => '1.00', 'taxRate' => '19.00'],
                ],
            ],
            'billingAddress' => [
                'firstName' => 'Anna',
                'lastName'  => 'Mustermann',
                'zipCode'   => '10115',
                'streetAnnex' => null,
            ],
            'data' => [
                'pointOfSaleId' => 'pos-1',
                'entity'        => ['orderId' => 42],
                'errorReason'   => 'NONE',
            ],
        ];

        $roundtrip = DtoMapper::snakeToCamel(DtoMapper::camelToSnake($camelInput));

        $this->assertSame($camelInput, $roundtrip);
    }

    public function test_ac12_mapper_camel_to_snake_recurses(): void
    {
        $input = [
            'orderItems' => [['productTypeId' => 'pt-1']],
            'data'       => ['pointOfSaleId' => 'pos-1', 'entity' => ['orderId' => 1]],
        ];

        $output = DtoMapper::camelToSnake($input);

        $this->assertArrayHasKey('order_items', $output);
        $this->assertArrayHasKey('product_type_id', $output['order_items'][0]);
        $this->assertArrayHasKey('point_of_sale_id', $output['data']);
        $this->assertArrayHasKey('order_id', $output['data']['entity']);
    }

    // -------------------------------------------------------------------
    // AC-13: Alle Klassen via Root-Autoloader resolvable
    // -------------------------------------------------------------------
    public function test_ac13_all_dto_classes_are_autoloadable(): void
    {
        $expected = array_merge(self::dtoClasses(), [DtoMapper::class]);

        $this->assertCount(14, $expected, 'Slice 09 must ship 13 DTOs + 1 DtoMapper helper.');

        foreach ($expected as $class) {
            $this->assertTrue(
                class_exists($class),
                "Class '{$class}' must be resolvable via the root composer autoloader."
            );
        }
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------
    /**
     * @return array<string, mixed>
     */
    private function validAddressArray(): array
    {
        return [
            'first_name' => 'Anna',
            'last_name'  => 'Mustermann',
            'street'     => 'Hauptstr. 1',
            'zip_code'   => '10115',
            'city'       => 'Berlin',
            'country'    => 'DE',
        ];
    }
}
