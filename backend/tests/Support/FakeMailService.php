<?php

declare(strict_types=1);

namespace Siiis\Tests\Support;

use Siiis\Services\MailException;
use Siiis\Services\MailService;

/**
 * Doble de MailService: guarda los correos en memoria o simula una falla SMTP.
 */
final class FakeMailService extends MailService
{
    /** @var list<array{to: string, subject: string, html: string, text: string}> */
    public array $enviados = [];

    public function __construct(private readonly bool $fallar = false)
    {
    }

    public function send(string $to, string $subject, string $html, string $text = ''): void
    {
        if ($this->fallar) {
            throw new MailException('SMTP caído (simulado)');
        }
        $this->enviados[] = ['to' => $to, 'subject' => $subject, 'html' => $html, 'text' => $text];
    }

    public function ultimoToken(): string
    {
        $ultimo = end($this->enviados);
        if ($ultimo === false || preg_match('/token=([a-f0-9]{64})/', $ultimo['text'], $m) !== 1) {
            throw new \RuntimeException('No se envió ningún token');
        }

        return $m[1];
    }
}
