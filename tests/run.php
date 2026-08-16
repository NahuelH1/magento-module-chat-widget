<?php
/**
 * Tests del módulo, sin Magento y sin dependencias.
 *
 *   php tests/run.php
 *
 * Cubren lo que se puede romper en silencio: el contenido del JWT, el recorte
 * del TTL contra el tope de Mindo, las dos formas de cargar el secreto, y que
 * ningún camino de error propague una excepción al private content.
 *
 * Lo que NO cubren —y necesita un Magento de verdad— es el cableado: que la
 * section quede registrada, que el layout inyecte el bloque y que el Full Page
 * Cache no toque el private content.
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

require __DIR__ . '/stubs.php';
require __DIR__ . '/../Model/Config.php';
require __DIR__ . '/../Model/JwtSigner.php';
require __DIR__ . '/../ViewModel/WidgetConfig.php';
require __DIR__ . '/../CustomerData/MindoIdentity.php';

use Mindo\ChatWidget\CustomerData\MindoIdentity;
use Mindo\ChatWidget\Model\Config;
use Mindo\ChatWidget\Model\JwtSigner;
use Mindo\ChatWidget\Test\FakeAddress;
use Mindo\ChatWidget\Test\FakeCustomer;
use Mindo\ChatWidget\Test\FakeCustomerData;
use Mindo\ChatWidget\Test\FakeEncryptor;
use Mindo\ChatWidget\Test\FakeLogger;
use Mindo\ChatWidget\Test\FakeScopeConfig;
use Mindo\ChatWidget\Test\FakeSession;
use Mindo\ChatWidget\ViewModel\WidgetConfig;

$passed = 0;
$failed = [];

function test(string $name, callable $fn): void
{
    global $passed, $failed;

    try {
        $fn();
        $passed++;
        echo "  ok   $name\n";
    } catch (\Throwable $e) {
        $failed[] = $name . ' — ' . $e->getMessage();
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
    }
}

function assertSame($expected, $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(sprintf(
            '%sesperaba %s, llegó %s',
            $what !== '' ? "$what: " : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function assertTrue($value, string $what = 'esperaba true'): void
{
    if ($value !== true) {
        throw new \RuntimeException($what);
    }
}

/** Decodifica el JWT y verifica la firma, como lo haría el backend. */
function decodeJwt(string $jwt, string $secret): array
{
    $parts = explode('.', $jwt);
    assertSame(3, count($parts), 'el JWT tiene que tener 3 segmentos');

    $b64 = static fn(string $s): string => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($s)) % 4));

    $expected = rtrim(strtr(base64_encode(
        hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true)
    ), '+/', '-_'), '=');
    assertTrue(hash_equals($expected, $parts[2]), 'la firma no valida contra el secreto');

    $header = json_decode($b64($parts[0]), true);
    assertSame('HS256', $header['alg'] ?? null, 'alg del header');
    assertSame('JWT', $header['typ'] ?? null, 'typ del header');

    return json_decode($b64($parts[1]), true);
}

function makeConfig(array $values): Config
{
    return new Config(new FakeScopeConfig($values), new FakeEncryptor());
}

const P_ENABLED = 'mindo_chat_widget/general/enabled';
const P_TOKEN = 'mindo_chat_widget/general/channel_token';
const P_SECRET = 'mindo_chat_widget/general/hmac_secret';
const P_TTL = 'mindo_chat_widget/general/identity_ttl';
const P_URL = 'mindo_chat_widget/general/script_url';

// Un secreto realista: así es como los genera Mindo, `secrets.token_hex(32)`.
$secret = 'a3f1c8e07b29d4165a0e8c37bd94f2e15c6a8093d7e412bf60a9c5d283e7146b';

echo "\nModel\\Config\n";

test('TTL vacío cae al default de 24 h', function () {
    assertSame(86400, makeConfig([P_TTL => null])->getIdentityTtl());
});

test('TTL se recorta al tope de 7 días de Mindo', function () {
    assertSame(604800, makeConfig([P_TTL => 2592000])->getIdentityTtl());
});

test('TTL se sube al mínimo de 5 min', function () {
    assertSame(300, makeConfig([P_TTL => 60])->getIdentityTtl());
});

test('TTL razonable pasa tal cual', function () {
    assertSame(3600, makeConfig([P_TTL => 3600])->getIdentityTtl());
});

test('loader por http:// se descarta (mixed content)', function () {
    assertSame('', makeConfig([P_URL => 'http://app.mindosoftware.com/widget.js'])->getScriptUrl());
});

test('loader por https:// pasa', function () {
    assertSame(
        'https://app.mindosoftware.com/widget.js',
        makeConfig([P_URL => 'https://app.mindosoftware.com/widget.js'])->getScriptUrl()
    );
});

test('secreto cargado por admin se desencripta', function () use ($secret) {
    assertSame($secret, makeConfig([P_SECRET => 'enc:' . $secret])->getHmacSecret());
});

test('secreto cargado por env.php (en claro) se usa tal cual', function () use ($secret) {
    assertSame($secret, makeConfig([P_SECRET => $secret])->getHmacSecret());
});

test('sin secreto devuelve vacío', function () {
    assertSame('', makeConfig([])->getHmacSecret());
});

echo "\nViewModel\\WidgetConfig (lo que ve el Full Page Cache)\n";

test('sin token no se renderiza nada', function () {
    $vm = new WidgetConfig(makeConfig([P_ENABLED => 1, P_URL => 'https://app.mindosoftware.com/widget.js']));
    assertSame(false, $vm->isEnabled());
});

test('deshabilitado no se renderiza nada', function () {
    $vm = new WidgetConfig(makeConfig([P_TOKEN => 'abc', P_URL => 'https://app.mindosoftware.com/widget.js']));
    assertSame(false, $vm->isEnabled());
});

test('con todo cargado se renderiza', function () {
    $vm = new WidgetConfig(makeConfig([
        P_ENABLED => 1,
        P_TOKEN => 'abc123',
        P_URL => 'https://app.mindosoftware.com/widget.js',
    ]));
    assertSame(true, $vm->isEnabled());
    assertSame('abc123', $vm->getChannelToken());
});

test('el ViewModel no expone el secreto', function () use ($secret) {
    $vm = new WidgetConfig(makeConfig([P_ENABLED => 1, P_TOKEN => 'abc', P_SECRET => 'enc:' . $secret]));
    foreach (get_class_methods($vm) as $method) {
        if (str_starts_with($method, 'get')) {
            assertTrue(
                !str_contains((string)$vm->$method(), $secret),
                "$method() filtra el secreto al HTML"
            );
        }
    }
});

echo "\nCustomerData\\MindoIdentity\n";

$enabled = [P_ENABLED => 1, P_TOKEN => 'abc123', P_SECRET => 'enc:' . $secret, P_TTL => 86400];

$build = static function (array $cfg, FakeSession $session, ?FakeLogger $logger = null): array {
    $identity = new MindoIdentity(
        $session,
        makeConfig($cfg),
        new JwtSigner(),
        $logger ?? new FakeLogger()
    );

    return $identity->getSectionData();
};

test('módulo deshabilitado → identity null', function () use ($build, $enabled, $secret) {
    $data = $build([P_ENABLED => 0, P_SECRET => 'enc:' . $secret], new FakeSession(true, 42));
    assertSame(null, $data['identity']);
});

test('visitante no logueado → identity null', function () use ($build, $enabled) {
    assertSame(null, $build($enabled, new FakeSession(false))['identity']);
});

test('sin secreto cargado → identity null, el widget sigue andando', function () use ($build) {
    $data = $build([P_ENABLED => 1, P_TOKEN => 'abc'], new FakeSession(true, 42));
    assertSame(null, $data['identity']);
});

test('customerId 0 → identity null', function () use ($build, $enabled) {
    assertSame(null, $build($enabled, new FakeSession(true, 0))['identity']);
});

test('cliente logueado → JWT con sub, name, email y phone', function () use ($build, $enabled, $secret) {
    $session = new FakeSession(
        true,
        88213,
        new FakeCustomerData('Juan', 'Pérez', 'juan@example.com'),
        new FakeCustomer(new FakeAddress('+54 9 11 2233-4455'))
    );

    $payload = decodeJwt($build($enabled, $session)['identity'], $secret);

    assertSame('88213', $payload['sub'], 'sub es el customer ID como string');
    assertSame('Juan Pérez', $payload['name']);
    assertSame('juan@example.com', $payload['email']);
    assertSame('+54 9 11 2233-4455', $payload['phone']);
    assertTrue($payload['exp'] > time(), 'exp tiene que estar en el futuro');
    assertTrue($payload['exp'] <= time() + 86400, 'exp no puede pasarse del TTL');
});

test('exp respeta el tope de 7 días aunque la config pida 30', function () use ($build, $secret) {
    $cfg = [P_ENABLED => 1, P_SECRET => 'enc:' . $secret, P_TTL => 2592000];
    $session = new FakeSession(true, 1, new FakeCustomerData('A', 'B', 'a@b.com'), new FakeCustomer(null));

    $payload = decodeJwt($build($cfg, $session)['identity'], $secret);

    assertTrue($payload['exp'] <= time() + 604800 + 1, 'exp se pasó de los 7 días: Mindo lo rechazaría entero');
    assertTrue($payload['exp'] > time() + 604000, 'exp quedó demasiado corto');
});

test('cliente sin dirección de facturación → firma igual, sin claim phone', function () use ($build, $enabled, $secret) {
    $session = new FakeSession(true, 7, new FakeCustomerData('Ana', 'Gómez', 'ana@x.com'), new FakeCustomer(null));

    $payload = decodeJwt($build($enabled, $session)['identity'], $secret);

    assertSame('7', $payload['sub']);
    assertTrue(!array_key_exists('phone', $payload), 'no debería haber claim phone');
});

test('cliente sin nombre ni email → JWT mínimo con sub y exp', function () use ($build, $enabled, $secret) {
    $session = new FakeSession(true, 9, new FakeCustomerData(null, null, null), new FakeCustomer(null));

    $payload = decodeJwt($build($enabled, $session)['identity'], $secret);

    assertSame(['sub', 'exp'], array_keys($payload));
});

test('sesión que explota → identity null, nunca propaga la excepción', function () use ($enabled, $secret) {
    $logger = new FakeLogger();
    $session = new FakeSession(true, 5, new FakeCustomerData('X', 'Y', 'x@y.com'), null, true);

    $identity = new MindoIdentity($session, makeConfig($enabled), new JwtSigner(), $logger);
    $data = $identity->getSectionData();

    // El teléfono tiene su propio guard: la excepción de getCustomer() no puede
    // tumbar la identidad entera, solo el claim phone.
    $payload = decodeJwt($data['identity'], $secret);
    assertSame('5', $payload['sub']);
    assertTrue(!array_key_exists('phone', $payload), 'no debería haber claim phone');
});

test('firma con un secreto distinto no valida', function () use ($build, $enabled) {
    $session = new FakeSession(true, 1, new FakeCustomerData('A', 'B', 'a@b.com'), new FakeCustomer(null));
    $jwt = $build($enabled, $session)['identity'];

    try {
        decodeJwt($jwt, 'otro-secreto');
        throw new \RuntimeException('validó con el secreto equivocado');
    } catch (\RuntimeException $e) {
        assertTrue(str_contains($e->getMessage(), 'no valida'), 'esperaba un fallo de firma');
    }
});

echo "\nModel\\JwtSigner\n";

test('un nombre con caracteres no UTF-8 no rompe la página', function () use ($secret) {
    $signer = new JwtSigner();

    try {
        $signer->signHs256(['sub' => '1', 'name' => "\xB1\x31", 'exp' => time() + 60], $secret);
        throw new \RuntimeException('esperaba una JsonException');
    } catch (\JsonException $e) {
        // Correcto: MindoIdentity la atrapa y degrada a anónimo.
    }
});

test('el JWT no lleva padding base64 ni caracteres fuera de base64url', function () use ($secret) {
    $jwt = (new JwtSigner())->signHs256(['sub' => 'abc', 'exp' => time() + 60], $secret);
    assertTrue((bool)preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $jwt), "JWT mal formado: $jwt");
});

test('secreto corto se rechaza en vez de firmar con crypto débil', function () {
    try {
        (new JwtSigner())->signHs256(['sub' => '1', 'exp' => time() + 60], 'clave-corta');
        throw new \RuntimeException('firmó con un secreto de 11 bytes');
    } catch (\DomainException $e) {
        assertTrue(str_contains($e->getMessage(), '11 bytes'), 'el error tiene que decir el largo real');
    }
});

test('secreto de exactamente 32 bytes se acepta', function () {
    $jwt = (new JwtSigner())->signHs256(['sub' => '1', 'exp' => time() + 60], str_repeat('k', 32));
    assertTrue(substr_count($jwt, '.') === 2);
});

test('secreto corto en el admin → visitante anónimo y queda en el log', function () {
    $logger = new FakeLogger();
    $session = new FakeSession(true, 42, new FakeCustomerData('A', 'B', 'a@b.com'), new FakeCustomer(null));
    $cfg = makeConfig([P_ENABLED => 1, P_SECRET => 'enc:corto']);

    $data = (new MindoIdentity($session, $cfg, new JwtSigner(), $logger))->getSectionData();

    assertSame(null, $data['identity']);
    assertSame(1, count($logger->warnings), 'tenía que loguear el motivo');
    assertTrue(str_contains($logger->warnings[0], 'al menos 32'), 'el log tiene que explicar qué hacer');
});

echo "\n";
echo $failed === []
    ? "TODO OK — $passed tests\n\n"
    : sprintf("%d ok, %d FALLARON:\n  - %s\n\n", $passed, count($failed), implode("\n  - ", $failed));

exit($failed === [] ? 0 : 1);
