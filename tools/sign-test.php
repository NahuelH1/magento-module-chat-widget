<?php
/**
 * Verifica el contrato de firma contra el backend de Mindo, sin Magento.
 *
 * Firma un JWT igual que lo hace el módulo y lo manda a `/web-widget/session/`,
 * que responde 401 si la firma no valida y 200 si valida. Sirve para cerrar la
 * mitad criptográfica antes de tocar nada de Magento.
 *
 *   php tools/sign-test.php <hmac_secret> <channel_token> [origin] [api_base]
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

$secret  = $argv[1] ?? '';
$token   = $argv[2] ?? '';
$origin  = $argv[3] ?? '';
$apiBase = rtrim($argv[4] ?? 'https://api.mindosoftware.com', '/');

if ($secret === '' || $token === '') {
    fwrite(STDERR, "uso: php tools/sign-test.php <hmac_secret> <channel_token> [origin] [api_base]\n");
    exit(2);
}

$b64 = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

$header  = $b64((string)json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
$payload = $b64((string)json_encode([
    'sub'   => 'test_customer_1',
    'name'  => 'Prueba Mindo',
    'email' => 'prueba@example.com',
    'exp'   => time() + 86400,
]));
$jwt = $header . '.' . $payload . '.' . $b64(hash_hmac('sha256', "$header.$payload", $secret, true));

$headers = ['Content-Type: application/json'];
if ($origin !== '') {
    $headers[] = 'Origin: ' . $origin;
}

$ch = curl_init($apiBase . '/web-widget/session/');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => json_encode(['publicId' => $token, 'identity' => $jwt]),
]);

$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "JWT:    $jwt\n";
echo "HTTP:   $status\n";
echo "Body:   $body\n\n";

echo match (true) {
    $status === 200 => "OK: Mindo aceptó la firma. El contrato quedó cerrado.\n",
    $status === 401 => "FALLA: firma rechazada. Revisá que el secreto sea el del MISMO canal que el token.\n",
    $status === 403 => "FALLA: origen no permitido. Agregá el dominio a allowed_origins del canal.\n",
    $status === 404 => "FALLA: canal no encontrado o inactivo. Revisá el channel token.\n",
    default         => "Respuesta inesperada; mirá el body.\n",
};
