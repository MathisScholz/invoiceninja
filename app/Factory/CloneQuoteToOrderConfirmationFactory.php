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

namespace App\Factory;

use App\Models\Quote;

class CloneQuoteToOrderConfirmationFactory
{
    public static function create(Quote $quote, int $user_id): Quote
    {
        $order_confirmation = new Quote();

        $quote_array = $quote->toArray();

        unset($quote_array['client']);
        unset($quote_array['company']);
        unset($quote_array['hashed_id']);
        unset($quote_array['id']);
        unset($quote_array['invitations']);
        unset($quote_array['invoice']);
        unset($quote_array['invoice_id']);
        unset($quote_array['order_confirmations']);
        unset($quote_array['source_quote']);
        unset($quote_array['source_quote_id']);
        unset($quote_array['user']);

        foreach ($quote_array as $key => $value) {
            $order_confirmation->{$key} = $value;
        }

        $order_confirmation->document_type = Quote::DOCUMENT_TYPE_ORDER_CONFIRMATION;
        $order_confirmation->source_quote_id = $quote->id;
        $order_confirmation->invoice_id = null;
        $order_confirmation->design_id = null;
        $order_confirmation->number = null;
        $order_confirmation->status_id = Quote::STATUS_DRAFT;
        $order_confirmation->footer = null;
        $order_confirmation->terms = null;
        $order_confirmation->public_notes = null;
        $order_confirmation->last_sent_date = null;
        $order_confirmation->last_viewed = null;
        $order_confirmation->next_send_date = null;
        $order_confirmation->reminder1_sent = null;
        $order_confirmation->reminder2_sent = null;
        $order_confirmation->reminder3_sent = null;
        $order_confirmation->reminder_last_sent = null;
        $order_confirmation->deleted_at = null;
        $order_confirmation->is_deleted = false;
        $order_confirmation->user_id = $user_id;

        return $order_confirmation;
    }
}
