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

namespace App\Services\Quote;

use App\Factory\CloneQuoteToOrderConfirmationFactory;
use App\Utils\Ninja;
use App\Models\Quote;
use App\Models\Project;
use App\Utils\Traits\MakesHash;
use App\Exceptions\QuoteConversion;
use App\Repositories\QuoteRepository;
use App\Events\Quote\QuoteWasApproved;
use App\Events\Quote\QuoteWasRejected;
use App\Services\Invoice\LocationData;
use App\Services\Quote\UpdateReminder;
use App\Jobs\EDocument\CreateEDocument;
use Illuminate\Support\Facades\Storage;

class QuoteService
{
    use MakesHash;

    public $quote;

    public $invoice;

    public function __construct($quote)
    {
        $this->quote = $quote;
    }

    public function location(bool $set_countries = true): array
    {
        return (new LocationData($this->quote))->run($set_countries);
    }

    public function createInvitations()
    {
        $this->quote = (new CreateInvitations($this->quote))->run();

        return $this;
    }

    public function convertToProject(): Project
    {
        $project = (new ConvertQuoteToProject($this->quote))->run();

        return $project;
    }

    public function convert(): self
    {
        if ($this->quote->invoice_id) {
            throw new QuoteConversion();
        }

        $convert_quote = (new ConvertQuote($this->quote->client))->run($this->quote);

        $this->invoice = $convert_quote;

        $this->quote->fresh();

        if ($this->quote->client->getSetting('auto_archive_quote')) {
            $quote_repo = new QuoteRepository();
            $quote_repo->archive($this->quote);
        }

        return $this;
    }

    public function getQuotePdf($contact = null)
    {
        return (new GetQuotePdf($this->quote, $contact))->run();
    }

    public function getEQuote($contact = null)
    {
        return (new CreateEDocument($this->quote))->handle();
    }

    public function getEDocument($contact = null)
    {
        return $this->getEQuote($contact);
    }

    public function sendEmail($contact = null, $email_type = null): self
    {
        $email_type ??= $this->quote->isOrderConfirmation()
            ? Quote::DOCUMENT_TYPE_ORDER_CONFIRMATION
            : Quote::DOCUMENT_TYPE_QUOTE;

        (new SendEmail($this->quote, $email_type, $contact))->run();

        return $this;
    }

    /**
     * Applies the invoice number.
     * @return $this InvoiceService object
     */
    public function applyNumber(): self
    {
        $apply_number = new ApplyNumber($this->quote->client);

        $this->quote = $apply_number->run($this->quote);

        return $this;
    }

    public function markSent(): self
    {
        $this->quote = (new MarkSent($this->quote->client, $this->quote))->run();

        return $this;
    }

    public function setStatus($status): self
    {
        $this->quote->status_id = $status;

        return $this;
    }

    public function approve($contact = null): self
    {
        $this->setStatus(Quote::STATUS_APPROVED)->save();

        if (! $contact) {
            $contact = $this->quote->invitations->first()->contact;
        }

        if ($this->quote->isQuoteDocument() && $this->quote->client->getSetting('auto_create_order_confirmation')) {
            $this->createOrderConfirmation();
        } elseif ($this->quote->client->getSetting('auto_convert_quote')) {
            $this->convertToInvoice();

            $this->invoice
                 ->service()
                 ->markSent()
                 ->save();
        }

        event(new QuoteWasApproved($contact, $this->quote, $this->quote->company, Ninja::eventVars()));

        return $this;
    }

    public function createOrderConfirmation(): Quote
    {
        if ($this->quote->isOrderConfirmation()) {
            return $this->quote;
        }

        if ($existing = $this->quote->order_confirmations()->withTrashed()->first()) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $order_confirmation = CloneQuoteToOrderConfirmationFactory::create(
            $this->quote,
            auth()->user()?->id ?? $this->quote->user_id
        );

        $order_confirmation->client_id = $this->quote->client_id;
        $order_confirmation->company_id = $this->quote->company_id;
        $order_confirmation->assigned_user_id = $this->quote->assigned_user_id;

        $order_confirmation = $order_confirmation->service()
            ->fillDefaults()
            ->applyNumber()
            ->createInvitations()
            ->save();

        return $order_confirmation;
    }


    public function reject($contact = null, ?string $notes = null): self
    {

        if ($this->quote->status_id != Quote::STATUS_SENT) {
            return $this;
        }

        $this->setStatus(Quote::STATUS_REJECTED)->save();

        if (! $contact) {
            $contact = $this->quote->invitations->first()->contact;
        }

        event(new QuoteWasRejected($contact, $this->quote, $this->quote->company, $notes ?? '', Ninja::eventVars()));

        return $this;
    }


    public function approveWithNoCoversion($contact = null): self
    {
        $this->setStatus(Quote::STATUS_APPROVED)->save();

        if (! $contact) {
            $contact = $this->quote->invitations->first()->contact;
        }

        if ($this->quote->isQuoteDocument() && $this->quote->client->getSetting('auto_create_order_confirmation')) {
            $this->createOrderConfirmation();
        }

        event(new QuoteWasApproved($contact, $this->quote, $this->quote->company, Ninja::eventVars()));

        return $this;
    }

    /**
     * convertToInvoice
     *
     * @NOTE - this method will force the quote to include all invitations for the
     * client where ADD TO INVOICE = true
     *
     */
    public function convertToInvoice()
    {
        $this->convert();

        // $this->invoice->service()->createInvitations();

        return $this->invoice;
    }

    public function isConvertable(): bool
    {
        if ($this->quote->invoice_id) {
            return false;
        }

        if ($this->quote->status_id == Quote::STATUS_EXPIRED) {
            return false;
        }

        return true;
    }

    public function fillDefaults()
    {
        $settings = $this->quote->client->getMergedSettings();

        $design_setting = $this->quote->isOrderConfirmation()
            ? 'order_confirmation_design_id'
            : 'quote_design_id';
        $footer_setting = $this->quote->isOrderConfirmation()
            ? 'order_confirmation_footer'
            : 'quote_footer';
        $terms_setting = $this->quote->isOrderConfirmation()
            ? 'order_confirmation_terms'
            : 'quote_terms';
        $public_notes_setting = $this->quote->isOrderConfirmation()
            ? 'order_confirmation_public_notes'
            : null;

        if (! $this->quote->design_id) {
            $this->quote->design_id = $this->decodePrimaryKey($settings->{$design_setting});
        }

        if (! isset($this->quote->footer)) {
            $this->quote->footer = $settings->{$footer_setting};
        }

        if (! isset($this->quote->terms)) {
            $this->quote->terms = $settings->{$terms_setting};
        }

        /* If client currency differs from the company default currency, then insert the client exchange rate on the model.*/
        if (! isset($this->quote->exchange_rate) && $this->quote->client->currency()->id != (int) $this->quote->company->settings->currency_id) {
            $this->quote->exchange_rate = $this->quote->client->currency()->exchange_rate;
        }

        if (! isset($this->quote->public_notes)) {
            if ($this->quote->isOrderConfirmation()) {
                $public_notes = trim((string) ($settings->{$public_notes_setting} ?? ''));

                if ($public_notes === '') {
                    $raw_date = $this->quote->source_quote?->date ?? $this->quote->date ?? null;
                    $quote_date = $raw_date
                        ? \Carbon\Carbon::parse($raw_date)->format($this->quote->company->date_format())
                        : '';

                    $reference = trim((string) ($this->quote->source_quote?->number ?? ''));
                    $from_reference = $reference !== '' ? " {$reference}" : '';
                    $from_date = $quote_date !== '' ? " vom {$quote_date}" : '';

                    $public_notes = trim("Hiermit bestaetigen wir Ihnen den Auftrag zu Angebot{$from_reference}{$from_date}.");
                }

                $this->quote->public_notes = $public_notes;
            } else {
                $this->quote->public_notes = $this->quote->client->public_notes;
            }
        }

        return $this;
    }

    public function triggeredActions($request)
    {
        $this->quote = (new TriggeredActions($this->quote->load('invitations'), $request))->run();

        return $this;
    }

    public function deletePdf()
    {
        $this->quote->invitations->each(function ($invitation) {
            // (new UnlinkFile(config('filesystems.default'), $this->quote->client->quote_filepath($invitation).$this->quote->numberFormatter().'.pdf'))->handle();

            //30-06-2023
            try {
                // if (Storage::disk(config('filesystems.default'))->exists($this->invoice->client->invoice_filepath($invitation).$this->invoice->numberFormatter().'.pdf')) {
                Storage::disk(config('filesystems.default'))->delete($this->quote->client->quote_filepath($invitation) . $this->quote->numberFormatter() . '.pdf');
                // }

                // if (Ninja::isHosted() && Storage::disk('public')->exists($this->invoice->client->invoice_filepath($invitation).$this->invoice->numberFormatter().'.pdf')) {
                if (Ninja::isHosted()) {
                    Storage::disk('public')->delete($this->quote->client->quote_filepath($invitation) . $this->quote->numberFormatter() . '.pdf');
                }
            } catch (\Exception $e) {
                nlog($e->getMessage());
            }

        });

        return $this;
    }
    public function deleteEQuote()
    {
        $this->quote->load('invitations');

        $this->quote->invitations->each(function ($invitation) {
            try {
                // if (Storage::disk(config('filesystems.default'))->exists($this->invoice->client->e_invoice_filepath($invitation).$this->invoice->getFileName("xml"))) {
                Storage::disk(config('filesystems.default'))->delete($this->quote->client->e_document_filepath($invitation) . $this->quote->getFileName("xml"));
                // }

                // if (Ninja::isHosted() && Storage::disk('public')->exists($this->invoice->client->e_invoice_filepath($invitation).$this->invoice->getFileName("xml"))) {
                if (Ninja::isHosted()) {
                    Storage::disk('public')->delete($this->quote->client->e_document_filepath($invitation) . $this->quote->getFileName("xml"));
                }
            } catch (\Exception $e) {
                nlog($e->getMessage());
            }
        });

        return $this;
    }

    public function setReminder($settings = null)
    {
        $this->quote = (new UpdateReminder($this->quote, $settings))->run();

        return $this;
    }


    /*When a reminder is sent we want to touch the dates they were sent*/
    public function touchReminder(string $reminder_template)
    {
        nrlog(now()->format('Y-m-d h:i:s') . " INV #{$this->quote->number} : Touching Reminder => {$reminder_template}");
        switch ($reminder_template) {
            case 'reminder1':
                $this->quote->reminder1_sent = now();
                $this->quote->reminder_last_sent = now();
                $this->quote->last_sent_date = now();
                break;
            case 'reminder2':
                $this->quote->reminder2_sent = now();
                $this->quote->reminder_last_sent = now();
                $this->quote->last_sent_date = now();
                break;
            case 'reminder3':
                $this->quote->reminder3_sent = now();
                $this->quote->reminder_last_sent = now();
                $this->quote->last_sent_date = now();
                break;
            case 'endless_reminder':
                $this->quote->reminder_last_sent = now();
                $this->invoice->last_sent_date = now();
                break;
            default:
                $this->quote->reminder1_sent = now();
                $this->quote->reminder_last_sent = now();
                $this->quote->last_sent_date = now();
                break;
        }

        return $this;
    }

    public function getDocuNinjaSignable(?\App\Models\QuoteInvitation $invite = null)
    {

        if (class_exists(\InvoiceNinja\AdminApi\Services\DocuNinja\DocuNinja::class))
        {
            $invite = $invite ?: $this->quote->invitations->first();
            return (new \InvoiceNinja\AdminApi\Services\DocuNinja\DocuNinja())->signable->get($invite);
        }
        
    }

    /**
     * Saves the quote.
     * @return Quote|null
     */
    public function save(): ?Quote
    {
        $this->quote->saveQuietly();

        return $this->quote;
    }
}
