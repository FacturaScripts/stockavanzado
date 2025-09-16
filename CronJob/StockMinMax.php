<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Plugins\StockAvanzado\CronJob;

use FacturaScripts\Core\Template\CronJobClass;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockMinMaxManager;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
final class StockMinMax extends CronJobClass
{
    public const JOB_NAME = 'stock-min-max';

    public static function run(): void
    {
        $messages = [];
        self::echo("\n\n* JOB: " . self::JOB_NAME . ' ...');
        StockMinMaxManager::notify($messages, true);

        foreach ($messages as $message) {
            self::echo("\n- " . Tools::lang()->trans($message));
        }

        self::saveEcho();
    }
}