<?php
/**
 * Firma JWT HS256, sin dependencias.
 *
 * ## Por qué no `firebase/php-jwt`
 *
 * El módulo sólo **firma**; nunca verifica. Los riesgos clásicos de implementar
 * JWT a mano —confusión de algoritmo, `alg: none`, comparación de firmas no
 * constante— viven todos del lado de la verificación, que acá la hace el
 * backend de Mindo. Firmar HS256 es un `hash_hmac` y dos base64url.
 *
 * A cambio, el módulo no le suma ninguna dependencia al `composer.json` del
 * cliente. En un proyecto Magento eso no es cosmético: `firebase/php-jwt`
 * arrastra la advisory CVE-2025-45769 en todo el rango `<7.0.0`, y Composer
 * ≥2.10 bloquea por defecto la instalación de paquetes con advisories — el
 * `composer require` del cliente fallaría de entrada.
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

namespace Mindo\ChatWidget\Model;

class JwtSigner
{
    /**
     * Largo mínimo del secreto, en bytes. HS256 firma con SHA-256, así que una
     * clave más corta que los 256 bits del digest le baja la fuerza al esquema
     * (CWE-326). Es la validación que `firebase/php-jwt` recién agregó en 7.0
     * para cerrar CVE-2025-45769.
     *
     * Los secretos que genera Mindo son `token_hex(32)` — 64 caracteres, 512
     * bits — así que esto nunca debería saltar. Está para el caso en que
     * alguien cargue a mano un secreto corto en el admin: mejor no identificar
     * a nadie y dejarlo dicho en el log que firmar con crypto débil.
     */
    private const MIN_SECRET_BYTES = 32;

    /**
     * @param array<string, mixed> $payload
     * @throws \JsonException si el payload no es serializable (ej. un nombre con
     *                        bytes que no son UTF-8 válido).
     * @throws \DomainException si el secreto es más corto que el mínimo.
     */
    public function signHs256(array $payload, string $secret): string
    {
        if (strlen($secret) < self::MIN_SECRET_BYTES) {
            throw new \DomainException(sprintf(
                'El secreto HMAC tiene %d bytes; HS256 necesita al menos %d. '
                . 'Copiá el secreto del canal Web tal cual lo da Mindo.',
                strlen($secret),
                self::MIN_SECRET_BYTES
            ));
        }

        $header = $this->encodeSegment(['alg' => 'HS256', 'typ' => 'JWT']);
        $body = $this->encodeSegment($payload);
        $signature = hash_hmac('sha256', $header . '.' . $body, $secret, true);

        return $header . '.' . $body . '.' . $this->base64Url($signature);
    }

    /**
     * @param array<string, mixed> $data
     * @throws \JsonException
     */
    private function encodeSegment(array $data): string
    {
        return $this->base64Url(json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ));
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
