<?php
declare(strict_types=1);
/*
 * Envío de correo por SMTP (Hostinger, SSL 465).
 * Lee y valida cada respuesta del servidor: solo devuelve true si el mensaje fue
 * aceptado (250 tras el cuerpo). Cualquier otro código se registra en el log.
 */
namespace App\Services;

use App\Core\Config;

final class MailService
{
    public function __construct(private Config $config)
    {
    }

    public function enviar(string $para, string $asunto, string $html, int $timeout = 20): bool
    {
        $host = $this->config->get('smtp_host');
        $puerto = (int)$this->config->get('smtp_puerto', '465');
        $usuario = $this->config->get('smtp_usuario');
        $clave = defined('SMTP_CLAVE') ? (string)SMTP_CLAVE : $this->config->get('smtp_clave');
        $remitente = $this->config->get('smtp_remitente', $usuario);
        if ($host === '' || $usuario === '' || $clave === '' || filter_var($para, FILTER_VALIDATE_EMAIL) === false) {
            error_log('[MotoGear][correo] SMTP sin configurar o destinatario no válido');
            return false;
        }

        // Copia oculta: se añade como segundo destinatario del SOBRE SMTP (RCPT TO).
        $cco = defined('CORREO_CCO') ? trim((string)CORREO_CCO) : '';
        if ($cco !== '' && (filter_var($cco, FILTER_VALIDATE_EMAIL) === false || strcasecmp($cco, $para) === 0)) {
            if (filter_var($cco, FILTER_VALIDATE_EMAIL) === false) {
                error_log('[MotoGear][correo] CORREO_CCO no es una dirección válida; se ignora');
            }
            $cco = '';
        }

        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $fp = @stream_socket_client("ssl://$host:$puerto", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            error_log("[MotoGear][correo] conexión fallida: $errno $errstr");
            return false;
        }
        stream_set_timeout($fp, $timeout);

        $leer = function () use ($fp): string {
            $t = '';
            while (($l = fgets($fp, 1024)) !== false) {
                $t .= $l;
                if (strlen($l) < 4 || $l[3] === ' ') {
                    break;
                }
            }
            return $t;
        };
        $paso = function (string $c, array $esperados) use ($fp, $leer): bool {
            fwrite($fp, $c);
            $r = $leer();
            if (!in_array((int)substr($r, 0, 3), $esperados, true)) {
                error_log('[MotoGear][correo] SMTP rechazó "' . trim(strtok($c, " \r\n")) . '": ' . trim($r));
                return false;
            }
            return true;
        };

        $ok = false;
        $listo = $leer() !== ''
            && $paso("EHLO localhost\r\n", [250])
            && $paso("AUTH LOGIN\r\n", [334])
            && $paso(base64_encode($usuario) . "\r\n", [334])
            && $paso(base64_encode($clave) . "\r\n", [235])
            && $paso("MAIL FROM:<$usuario>\r\n", [250])
            && $paso("RCPT TO:<$para>\r\n", [250, 251]);

        if ($listo && $cco !== '' && !$paso("RCPT TO:<$cco>\r\n", [250, 251])) {
            error_log('[MotoGear][correo] CCo rechazado (' . $cco . '); el correo al cliente continúa');
        }

        if ($listo && $paso("DATA\r\n", [354])) {
            $asuntoCod = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
            $cuerpo = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $html));
            $cuerpo = str_replace("\n", "\r\n", $cuerpo);
            $mensaje = "From: $remitente\r\n"
                . "To: <$para>\r\n"
                . "Subject: $asuntoCod\r\n"
                . "Date: " . date('r') . "\r\n"
                . "Message-ID: <" . bin2hex(random_bytes(12)) . "@sebasdigital.site>\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $cuerpo . "\r\n.\r\n";
            $ok = $paso($mensaje, [250]);
        }
        if ($ok) {
            fwrite($fp, "QUIT\r\n");
        }
        fclose($fp);
        return $ok;
    }
}
