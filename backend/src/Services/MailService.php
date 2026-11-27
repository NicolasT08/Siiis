<?php

declare(strict_types=1);

namespace Siiis\Services;

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;
use Siiis\Config\Settings;

/**
 * Envío centralizado de correo vía SMTP con PHPMailer (spec 10.6 y 14).
 */
class MailService
{
    private const TIMEOUT_SECONDS = 10;

    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * `$replyTo` (correo y nombre) es la dirección de "Responder a", p. ej. el remitente del formulario de contacto.
     *
     * @param array{string, string}|null $replyTo
     *
     * @throws MailException si falta configuración o el SMTP falla
     */
    public function send(string $to, string $subject, string $html, string $text = '', ?array $replyTo = null): void
    {
        $s = $this->settings;
        if ($s->mailUsername === '' || $s->mailPassword === '') {
            throw new MailException('SMTP no configurado (MAIL_USERNAME / MAIL_PASSWORD).');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $s->mailHost;
            $mail->Port = $s->mailPort;
            $mail->SMTPAuth = true;
            $mail->Username = $s->mailUsername;
            $mail->Password = $s->mailPassword;
            $mail->SMTPSecure = $s->mailPort === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Timeout = self::TIMEOUT_SECONDS;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $mail->setFrom($s->mailFromAddress !== '' ? $s->mailFromAddress : $s->mailUsername, $s->mailFromName);
            $mail->addAddress($to);
            if ($replyTo !== null) {
                $mail->addReplyTo($replyTo[0], $replyTo[1]);
            }
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text !== '' ? $text : trim(strip_tags($html));

            $mail->send();
        } catch (PHPMailerException $e) {
            throw new MailException('Error SMTP: ' . $mail->ErrorInfo, 0, $e);
        }
    }
}
