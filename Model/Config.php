<?php
/**
 * Acceso tipado a la configuración del módulo.
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

namespace Mindo\ChatWidget\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    private const XML_PATH_ENABLED = 'mindo_chat_widget/general/enabled';
    private const XML_PATH_CHANNEL_TOKEN = 'mindo_chat_widget/general/channel_token';
    private const XML_PATH_HMAC_SECRET = 'mindo_chat_widget/general/hmac_secret';
    private const XML_PATH_IDENTITY_TTL = 'mindo_chat_widget/general/identity_ttl';
    private const XML_PATH_SCRIPT_URL = 'mindo_chat_widget/general/script_url';

    /**
     * Tope duro del backend de Mindo: un JWT con `exp` a más de 7 días se
     * rechaza entero y el visitante queda anónimo. Recortamos acá para que una
     * config generosa degrade a "24 h" en vez de a "nunca identifica a nadie".
     */
    private const MAX_IDENTITY_TTL = 604800;
    private const MIN_IDENTITY_TTL = 300;
    private const DEFAULT_IDENTITY_TTL = 86400;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getChannelToken(?int $storeId = null): string
    {
        return trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_CHANNEL_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    public function getScriptUrl(?int $storeId = null): string
    {
        $url = trim((string)$this->scopeConfig->getValue(
            self::XML_PATH_SCRIPT_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));

        // Un loader por http:// lo bloquea el navegador por mixed content y el
        // síntoma es "el widget no aparece", sin error visible. Mejor no
        // renderizar nada que renderizar algo que el browser va a descartar.
        return str_starts_with(strtolower($url), 'https://') ? $url : '';
    }

    /**
     * El secreto, desencriptado.
     *
     * Soporta las dos formas de cargarlo: por admin (queda encriptado en
     * `core_config_data`) y por entorno (`config:sensitive:set`, que lo escribe
     * en claro en `env.php`). Si el descifrado no devuelve nada, el valor ya
     * venía en claro.
     */
    public function getHmacSecret(?int $storeId = null): string
    {
        $raw = (string)$this->scopeConfig->getValue(
            self::XML_PATH_HMAC_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($raw === '') {
            return '';
        }

        try {
            $decrypted = (string)$this->encryptor->decrypt($raw);
        } catch (\Throwable $e) {
            $decrypted = '';
        }

        return $decrypted !== '' ? $decrypted : $raw;
    }

    public function getIdentityTtl(?int $storeId = null): int
    {
        $ttl = (int)$this->scopeConfig->getValue(
            self::XML_PATH_IDENTITY_TTL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($ttl <= 0) {
            $ttl = self::DEFAULT_IDENTITY_TTL;
        }

        return max(self::MIN_IDENTITY_TTL, min(self::MAX_IDENTITY_TTL, $ttl));
    }
}
