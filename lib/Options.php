<?php
declare(strict_types=1);

namespace Beeralex\Catalog;

use Beeralex\Core\Config\AbstractOptions;

final class Options extends AbstractOptions
{
    public readonly string $dadataApiKey;
    public readonly string $dadataSecretKey;

    protected function mapOptions(array $options): void
    {
        $this->dadataApiKey = (string)($options['dadata_api_key'] ?? '');
        $this->dadataSecretKey = (string)($options['dadata_secret_key'] ?? '');
    }

    public function getModuleId(): string
    {
        return 'beeralex.catalog';
    }
}