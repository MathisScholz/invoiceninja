<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace Tests\Unit;

use App\Factory\CloneQuoteToOrderConfirmationFactory;
use App\Models\Quote;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\MockAccountData;
use Tests\TestCase;

class CloneQuoteToOrderConfirmationFactoryTest extends TestCase
{
    use MockAccountData;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        Model::reguard();
    }

    public function testCloneProperties()
    {
        $order_confirmation = CloneQuoteToOrderConfirmationFactory::create($this->quote, $this->quote->user_id);

        $this->assertSame(Quote::DOCUMENT_TYPE_ORDER_CONFIRMATION, $order_confirmation->document_type);
        $this->assertSame($this->quote->id, $order_confirmation->source_quote_id);
        $this->assertNull($order_confirmation->invoice_id);
        $this->assertNull($order_confirmation->number);
        $this->assertSame(Quote::STATUS_DRAFT, $order_confirmation->status_id);
        $this->assertNull($order_confirmation->design_id);
        $this->assertNull($order_confirmation->footer);
        $this->assertNull($order_confirmation->terms);
        $this->assertNull($order_confirmation->public_notes);
        $this->assertTrue($order_confirmation->isOrderConfirmation());
        $this->assertCount(count($this->quote->line_items), $order_confirmation->line_items);
    }
}
