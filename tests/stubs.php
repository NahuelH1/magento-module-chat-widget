<?php
/**
 * Stubs mínimos de Magento para correr los tests sin Magento instalado.
 *
 * Solo declara los símbolos que el módulo realmente toca, con las firmas que el
 * módulo realmente usa. No pretende imitar a Magento: pretende que la lógica del
 * módulo —el payload del JWT, el recorte del TTL, la degradación a anónimo— se
 * pueda ejercitar en una máquina sin 15 GB de stack.
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

namespace Magento\Framework\App\Config {
    interface ScopeConfigInterface
    {
        public function getValue($path, $scope = 'default', $scopeCode = null);
        public function isSetFlag($path, $scope = 'default', $scopeCode = null);
    }
}

namespace Magento\Framework\Encryption {
    interface EncryptorInterface
    {
        public function decrypt($data);
    }
}

namespace Magento\Store\Model {
    interface ScopeInterface
    {
        public const SCOPE_STORE = 'store';
        public const SCOPE_WEBSITE = 'website';
    }
}

namespace Magento\Framework\View\Element\Block {
    interface ArgumentInterface
    {
    }
}

namespace Magento\Customer\CustomerData {
    interface SectionSourceInterface
    {
        public function getSectionData();
    }
}

namespace Magento\Customer\Model {
    class Session
    {
        public function isLoggedIn()
        {
            return false;
        }

        public function getCustomerId()
        {
            return null;
        }

        public function getCustomerData()
        {
            return null;
        }

        public function getCustomer()
        {
            return null;
        }
    }
}

namespace Psr\Log {
    interface LoggerInterface
    {
        public function warning($message, array $context = []);
    }
}

namespace Mindo\ChatWidget\Test {

    use Magento\Framework\App\Config\ScopeConfigInterface;
    use Magento\Framework\Encryption\EncryptorInterface;

    /** Config en memoria: `path => valor`. */
    class FakeScopeConfig implements ScopeConfigInterface
    {
        public function __construct(private array $values = [])
        {
        }

        public function getValue($path, $scope = 'default', $scopeCode = null)
        {
            return $this->values[$path] ?? null;
        }

        public function isSetFlag($path, $scope = 'default', $scopeCode = null)
        {
            return (bool)($this->values[$path] ?? false);
        }
    }

    /**
     * Encryptor de mentira: solo sabe deshacer el prefijo `enc:`. Todo lo demás
     * devuelve '', que es como se comporta el real ante un valor que no está
     * encriptado — el caso de `config:sensitive:set`, que escribe en claro.
     */
    class FakeEncryptor implements EncryptorInterface
    {
        public function decrypt($data)
        {
            return str_starts_with((string)$data, 'enc:') ? substr((string)$data, 4) : '';
        }
    }

    class FakeAddress
    {
        public function __construct(private ?string $telephone)
        {
        }

        public function getTelephone()
        {
            return $this->telephone;
        }
    }

    class FakeCustomerData
    {
        public function __construct(
            private ?string $firstname,
            private ?string $lastname,
            private ?string $email
        ) {
        }

        public function getFirstname()
        {
            return $this->firstname;
        }

        public function getLastname()
        {
            return $this->lastname;
        }

        public function getEmail()
        {
            return $this->email;
        }
    }

    class FakeCustomer
    {
        public function __construct(private $address)
        {
        }

        public function getDefaultBillingAddress()
        {
            return $this->address;
        }
    }

    class FakeSession extends \Magento\Customer\Model\Session
    {
        public function __construct(
            private bool $loggedIn = false,
            private $customerId = null,
            private $customerData = null,
            private $customer = null,
            private bool $explodeOnGetCustomer = false
        ) {
        }

        public function isLoggedIn()
        {
            return $this->loggedIn;
        }

        public function getCustomerId()
        {
            return $this->customerId;
        }

        public function getCustomerData()
        {
            return $this->customerData;
        }

        public function getCustomer()
        {
            if ($this->explodeOnGetCustomer) {
                throw new \RuntimeException('sesión rota');
            }

            return $this->customer;
        }
    }

    class FakeLogger implements \Psr\Log\LoggerInterface
    {
        public array $warnings = [];

        public function warning($message, array $context = [])
        {
            $this->warnings[] = (string)$message;
        }
    }
}
