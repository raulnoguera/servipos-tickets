<?php

namespace App\Services\Inbound;

use Closure;
use Throwable;

/**
 * Comprueba la firma DKIM de un correo (RFC 6376): la prueba criptográfica de
 * que el mensaje salió del dominio que dice y de que no fue alterado en el
 * camino. No se fía de ninguna cabecera "de verificación": esas las puede
 * escribir cualquiera; la firma, solo quien tiene la clave privada del dominio.
 */
class DkimVerifier
{
    private const CRLF = "\r\n";

    /** @param  (Closure(string $selector, string $domain): ?string)|null  $keyLookup  clave pública en base64; por defecto, la del DNS */
    public function __construct(private ?Closure $keyLookup = null) {}

    /**
     * Dominios con una firma válida que cubre el remitente (From) y el cuerpo completo.
     *
     * @return list<string>
     */
    public function verifiedDomains(string $raw): array
    {
        $message = str_replace(["\r\n", "\r", "\n"], ["\n", "\n", self::CRLF], $raw);
        [$head, $body] = array_pad(explode(self::CRLF.self::CRLF, $message, 2), 2, '');
        $fields = $this->fields($head);

        $domains = [];
        foreach ($fields as $index => $field) {
            if ($field['name'] !== 'dkim-signature') {
                continue;
            }
            try {
                if ($domain = $this->verify($field['raw'], $index, $fields, $body)) {
                    $domains[] = $domain;
                }
            } catch (Throwable) {
                // Una firma ilegible es una firma que no vale.
            }
        }

        return array_values(array_unique($domains));
    }

    /** @param  list<array{name: string, raw: string}>  $fields */
    private function verify(string $signature, int $at, array $fields, string $body): ?string
    {
        $tags = [];
        foreach (explode(';', substr($signature, strpos($signature, ':') + 1)) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $tags[trim($key)] = in_array(trim($key), ['b', 'bh', 'h'], true) ? preg_replace('/\s+/', '', $value) : trim(preg_replace('/\s+/', ' ', $value));
            }
        }
        foreach (['v', 'a', 'b', 'bh', 'd', 'h', 's'] as $required) {
            if (($tags[$required] ?? '') === '') {
                return null;
            }
        }
        // Solo RSA-SHA256, y nunca firmas de "los primeros N bytes" (l=): dejarían agregar texto sin romperlas.
        if ($tags['v'] !== '1' || strtolower($tags['a']) !== 'rsa-sha256' || isset($tags['l'])) {
            return null;
        }
        $signed = array_map('strtolower', explode(':', $tags['h']));
        if (! in_array('from', $signed, true)) {
            return null;
        }
        [$headerMode, $bodyMode] = array_pad(explode('/', strtolower($tags['c'] ?? 'simple/simple'), 2), 2, 'simple');

        if (! hash_equals(base64_encode(hash('sha256', $this->canonicalBody($body, $bodyMode), true)), $tags['bh'])) {
            return null;
        }

        // Cada nombre de h= toma la siguiente aparición de esa cabecera, de abajo hacia arriba;
        // si ya no quedan, no aporta nada (así el firmante impide que se agreguen duplicados).
        $data = '';
        $cursor = [];
        foreach ($signed as $name) {
            for ($i = ($cursor[$name] ?? count($fields)) - 1; $i >= 0; $i--) {
                if ($fields[$i]['name'] === $name && $i !== $at) {
                    $data .= $this->canonicalHeader($fields[$i]['raw'], $headerMode).self::CRLF;
                    break;
                }
            }
            $cursor[$name] = max($i, 0);
        }
        $data .= $this->canonicalHeader(preg_replace('/(^[^:]*:|;)(\s*b\s*=)[^;]*/', '$1$2', $signature, 1), $headerMode);

        $key = $this->publicKey($tags['s'], strtolower($tags['d']));
        if ($key === null) {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n").'-----END PUBLIC KEY-----';

        return openssl_verify($data, (string) base64_decode($tags['b'], true), $pem, OPENSSL_ALGO_SHA256) === 1 ? strtolower($tags['d']) : null;
    }

    /**
     * Cabeceras en su orden, con el texto original (incluidos los saltos de las que ocupan varias líneas).
     *
     * @return list<array{name: string, raw: string}>
     */
    private function fields(string $head): array
    {
        $fields = [];
        foreach (explode(self::CRLF, $head) as $line) {
            if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $fields) {
                $fields[count($fields) - 1]['raw'] .= self::CRLF.$line;
            } elseif (str_contains($line, ':')) {
                $fields[] = ['name' => strtolower(trim(strstr($line, ':', true))), 'raw' => $line];
            }
        }

        return $fields;
    }

    private function canonicalHeader(string $raw, string $mode): string
    {
        if ($mode !== 'relaxed') {
            return $raw;
        }
        [$name, $value] = explode(':', $raw, 2);
        $value = preg_replace('/[ \t]+/', ' ', str_replace(self::CRLF, '', $value));

        return strtolower(trim($name)).':'.trim($value, " \t");
    }

    private function canonicalBody(string $body, string $mode): string
    {
        if ($mode === 'relaxed') {
            $lines = array_map(fn (string $line) => rtrim(preg_replace('/[ \t]+/', ' ', $line), ' '), explode(self::CRLF, $body));
            $body = implode(self::CRLF, $lines);
        }
        $body = preg_replace('/(\r\n)+\z/', '', $body);

        return $body === '' && $mode === 'relaxed' ? '' : $body.self::CRLF;
    }

    private function publicKey(string $selector, string $domain): ?string
    {
        if ($this->keyLookup) {
            return ($this->keyLookup)($selector, $domain);
        }
        if (! preg_match('/^[a-z0-9._-]+$/i', $selector.'.'.$domain)) {
            return null;
        }
        foreach (@dns_get_record("{$selector}._domainkey.{$domain}", DNS_TXT) ?: [] as $record) {
            $text = implode('', $record['entries'] ?? [$record['txt'] ?? '']);
            if (preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=\s]+)/', $text, $m)) {
                return preg_replace('/\s+/', '', $m[1]) ?: null;
            }
        }

        return null;
    }
}
