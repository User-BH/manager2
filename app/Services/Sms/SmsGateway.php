<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /** Send a plain-text SMS. Returns true on success. */
    public function send(string $phone, string $message): bool;
}
