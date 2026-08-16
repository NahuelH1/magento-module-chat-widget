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
     * @param array<string, mixed> $payload
     * @throws \JsonException si el payload no es serializable (ej. un nombre con
     *                        bytes que no son UTF-8 válido).
     */
    public function signHs256(array $payload, string $secret): string
    {
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
