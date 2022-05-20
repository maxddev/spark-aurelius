<?php

namespace Laravel\Spark;

use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Cashier;
use Mpociot\VatCalculator\VatCalculator;
use Laravel\Cashier\Billable as CashierBillable;

trait Billable
{
    use CashierBillable;

    /**
     * Determine if the user is connected to any payment provider.
     *
     * @return bool
     */
    public function hasBillingProvider()
    {
        return $this->stripe_id;
    }

    /**
     * Get all of the subscriptions for the user.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function subscriptions()
    {
        return $this->hasMany(Subscription::class)->orderBy('created_at', 'desc');
    }

    /**
     * Get the Spark plan that corresponds with the given subscription.
     *
     * If they are not subscribed and a free plan exists, that will be returned.
     *
     * @param  string  $subscription
     * @return \Laravel\Spark\Plan|null
     */
    public function sparkPlan($subscription = 'default')
    {
        $subscription = $this->subscription($subscription);

        if ($subscription && $subscription->valid()) {
            return $this->availablePlans()->first(function ($value) use ($subscription) {
                return $value->id === $subscription->provider_plan;
            });
        }

        return $this->availablePlans()->first(function ($value) {
            return $value->price === 0;
        });
    }

    /**
     * Determine if the Stripe model has subscribed before.
     *
     * @param  string  $subscription
     * @param  string|null  $plan
     * @return bool
     */
    public function hasEverSubscribedTo($subscription = 'default', $plan = null)
    {
        if (is_null($subscription = $this->subscription($subscription))) {
            return false;
        }

        return $plan ? $subscription->provider_plan === $plan : true;
    }

    /**
     * Add seats to the current subscription.
     *
     * @param  int  $count
     * @param  string  $subscription
     * @return void
     */
    public function addSeat($count = 1, $subscription = 'default')
    {
        if (! $subscription = $this->subscription($subscription)) {
            return;
        }

        if ($subscription->onGracePeriod()) {
            $subscription->update([
                'quantity' => $subscription->quantity + $count
            ]);

            return;
        }

        if (! is_null(Spark::prorationBehaviour())) {
            $subscription->setProrationBehavior(
                Spark::prorationBehaviour()
            )->incrementQuantity($count);
        } elseif (Spark::prorates()) {
            $subscription->incrementAndInvoice($count);
        } else {
            $subscription->noProrate()->incrementQuantity($count);
        }
    }

    /**
     * Remove seats from the current subscription.
     *
     * @param  int  $count
     * @param  string  $subscription
     * @return void
     */
    public function removeSeat($count = 1, $subscription = 'default')
    {
        if (! $subscription = $this->subscription($subscription)) {
            return;
        }

        if ($subscription->onGracePeriod()) {
            $subscription->update([
                'quantity' => max(1, $subscription->quantity - $count)
            ]);

            return;
        }

        if (! is_null(Spark::prorationBehaviour())) {
            $subscription->setProrationBehavior(
                Spark::prorationBehaviour()
            )->decrementQuantity($count);
        } elseif (Spark::prorates()) {
            $subscription->decrementQuantity($count);
        } else {
            $subscription->noProrate()->decrementQuantity($count);
        }
    }

    /**
     * Update the number of seats in the current subscription.
     *
     * @param  int  $count
     * @param  string  $subscription
     * @return void
     */
    public function updateSeats($count, $subscription = 'default')
    {
        if (! $subscription = $this->subscription($subscription)) {
            return;
        }

        if ($subscription->onGracePeriod()) {
            $subscription->update([
                'quantity' => $count
            ]);

            return;
        }

        if ($count > $subscription->quantity) {
            if (! is_null(Spark::prorationBehaviour())) {
                $subscription->setProrationBehavior(
                    Spark::prorationBehaviour()
                )->incrementQuantity($count - $subscription->quantity);
            } elseif (Spark::prorates()) {
                $subscription->incrementAndInvoice($count - $subscription->quantity);
            } else {
                $subscription->noProrate()->incrementQuantity($count - $subscription->quantity);
            }
        } elseif ($count < $subscription->quantity) {
            if (! is_null(Spark::prorationBehaviour())) {
                $subscription->setProrationBehavior(
                    Spark::prorationBehaviour()
                )->decrementQuantity($subscription->quantity - $count);
            } elseif (Spark::prorates()) {
                $subscription->decrementQuantity($subscription->quantity - $count);
            } else {
                $subscription->noProrate()->decrementQuantity($subscription->quantity - $count);
            }
        }
    }

    /**
     * Get the available billing plans for the given entity.
     *
     * @return \Illuminate\Support\Collection
     */
    public function availablePlans()
    {
        return Spark::plans();
    }

    /**
     * Get all of the local invoices.
     */
    public function localInvoices()
    {
        return $this->hasMany(LocalInvoice::class)->orderBy('id', 'desc');
    }

    /**
     * Get the tax percentage to apply to the subscription.
     *
     * @return int
     */
    public function taxPercentage()
    {
        if (! Spark::collectsEuropeanVat()) {
            return 0;
        }

        return Cache::remember($this->vat_id.$this->billing_country.Spark::homeCountry().$this->billing_zip, 604800, function () {
            $vatCalculator = new VatCalculator;

            $vatCalculator->setBusinessCountryCode(Spark::homeCountry());

            try {
                $isValidVAT = ! empty($this->vat_id) && $vatCalculator->isValidVATNumber($this->vat_id);
            } catch (VatCalculator\Exceptions\VATCheckUnavailableException $e) {
                $isValidVAT = false;
            }

            return $vatCalculator->getTaxRateForLocation(
                    $this->billing_country, $this->billing_zip, $isValidVAT
                ) * 100;
        });
    }

    /**
     * Get the tax rates to apply to the subscription.
     *
     * @return array
     */
    public function taxRates()
    {
        if (! $rate = $this->taxPercentage()) {
            return null;
        }

        if ($existing = TaxRate::where('percentage', $rate)->first()) {
            return [$existing->stripe_id];
        }

        $stripeTaxRate = Cashier::stripe()->taxRates->create([
            'display_name' => 'VAT',
            'inclusive' => false,
            'percentage' => $rate,
        ]);

        TaxRate::create([
            'stripe_id' => $stripeTaxRate->id,
            'percentage' => $rate,
        ]);

        return [$stripeTaxRate->id];
    }
}
