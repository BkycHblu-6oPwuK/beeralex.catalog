<?php
declare(strict_types=1);

use Beeralex\Core\Config\Module\Schema\Schema;
use Beeralex\Core\Config\Module\Schema\SchemaTab;

return Schema::make()
    ->tab(
        'edit2',
        'Локации',
        'Настройки Dadata',
        function (SchemaTab $tab) {
            $tab->input(
                'DADATA_API_KEY',
                'Ключ API Dadata (токен)',
                ''
            );

            $tab->input(
                'DADATA_SECRET_KEY',
                'Секретный ключ API Dadata',
            );
        }
    );