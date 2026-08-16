<?php
/**
 * Datos PÚBLICOS del widget para el template.
 *
 * Todo lo que sale de acá viaja en HTML cacheado por el Full Page Cache, así
 * que es idéntico para todos los visitantes. El secreto y la identidad del
 * cliente no pasan por acá: van por la section de private content.
 *
 * @copyright Mindo Software
 */
declare(strict_types=1);

namespace Mindo\ChatWidget\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Mindo\ChatWidget\Model\Config;

class WidgetConfig implements ArgumentInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled()
            && $this->config->getChannelToken() !== ''
            && $this->config->getScriptUrl() !== '';
    }

    public function getChannelToken(): string
    {
        return $this->config->getChannelToken();
    }

    public function getScriptUrl(): string
    {
        return $this->config->getScriptUrl();
    }
}
