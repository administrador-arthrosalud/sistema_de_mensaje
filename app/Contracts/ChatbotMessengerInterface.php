<?php
declare(strict_types=1);

interface ChatbotMessengerInterface
{
    public function normalizeDestination(string $destination): string;

    public function sendText(string $destination, string $message): array;

    public function sendMessage(string $destination, string $message, ?MessageAttachment $attachment = null): array;

    public function sendOperationalAlert(string $destination, string $message): array;
}
