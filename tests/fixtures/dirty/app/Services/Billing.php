<?php

class Billing
{
    public function secret(): string
    {
        return env('STRIPE_SECRET');
    }
}
