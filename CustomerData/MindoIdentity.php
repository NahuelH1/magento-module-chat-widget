<?php
/**
 * Identidad firmada del cliente logueado, como private content de Magento.
 *
 * ## Por qué una section y no un `.phtml`
 *
 * El JWT identifica a UNA persona. Renderizarlo en el HTML lo mete dentro del
 * Full Page Cache, y el FPC le sirve a todos los visitantes la página que
 * generó el primero: el segundo cliente entraría al chat del primero. Las
 * sections de private content se sirven aparte, por `/customer/section/load`,
 * y nunca se cachean — es el único lugar correcto para esto en Magento.
 *
 * ## Contrato con Mindo
 *
 * JWT HS256 firmado con el `hmac_secret` del canal Web.
 *   - `sub` (obligatorio): el ID del cliente en Magento. Estable: el email
 *     cambia, el ID no.
 *   - `exp` (obligatorio): tope de 7 días del lado de Mindo.
 *   - `name`, `email`, `phone` (opcionales): Mindo los usa para completar y
 *     unificar el contacto en el CRM.
 *
 * ## Ante cualquier problema, anónimo
 *
 * Este método nunca propaga una excepción. Un secreto mal cargado tiene que
 * degradar a "el visitante chatea sin identificar", no a una excepción en el
 * `/customer/section/load` que rompe el private content de toda la tienda
 * (minicart incluido).
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

namespace Mindo\ChatWidget\CustomerData;

use Firebase\JWT\JWT;
use Magento\Customer\CustomerData\SectionSourceInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Mindo\ChatWidget\Model\Config;
use Psr\Log\LoggerInterface;

class MindoIdentity implements SectionSourceInterface
{
    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{identity: string|null}
     */
    public function getSectionData(): array
    {
        try {
            return ['identity' => $this->buildToken()];
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Mindo_ChatWidget: no se pudo firmar la identidad del cliente: ' . $e->getMessage()
            );

            return ['identity' => null];
        }
    }

    private function buildToken(): ?string
    {
        if (!$this->config->isEnabled() || !$this->customerSession->isLoggedIn()) {
            return null;
        }

        $secret = $this->config->getHmacSecret();
        if ($secret === '') {
            // Config a medias: el widget anda, pero sin identificar a nadie.
            return null;
        }

        $customerId = (int)$this->customerSession->getCustomerId();
        if ($customerId <= 0) {
            return null;
        }

        $payload = ['sub' => (string)$customerId, 'exp' => time() + $this->config->getIdentityTtl()];

        $customer = $this->customerSession->getCustomerData();
        if ($customer !== null) {
            $name = trim(($customer->getFirstname() ?? '') . ' ' . ($customer->getLastname() ?? ''));
            if ($name !== '') {
                $payload['name'] = $name;
            }

            $email = trim((string)$customer->getEmail());
            if ($email !== '') {
                $payload['email'] = $email;
            }
        }

        $phone = $this->resolvePhone();
        if ($phone !== '') {
            $payload['phone'] = $phone;
        }

        return JWT::encode($payload, $secret, 'HS256');
    }

    /**
     * Teléfono de la dirección de facturación por defecto.
     *
     * Es el único dato que no está ya en la sesión, así que va aparte y con su
     * propio guard: no vale romper la identidad entera —ni pagarla con un error
     * en cada carga de private content— por un cliente sin dirección cargada.
     */
    private function resolvePhone(): string
    {
        try {
            $address = $this->customerSession->getCustomer()->getDefaultBillingAddress();
        } catch (\Throwable $e) {
            return '';
        }

        return $address ? trim((string)$address->getTelephone()) : '';
    }
}
