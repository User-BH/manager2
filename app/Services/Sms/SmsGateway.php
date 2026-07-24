<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /** Send a plain-text SMS. Returns true on success. */
    public function send(string $phone, string $message): bool;

    /**
     * Send a one-time login code. Drivers that support patterns (e.g. IPPanel)
     * use them for faster, regulation-friendly delivery; others fall back to a
     * formatted text message. This is the ONLY SMS the app ever sends.
     */
    public function sendOtp(string $phone, string $code): bool;
}
