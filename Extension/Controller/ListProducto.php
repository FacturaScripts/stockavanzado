<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020 Carlos Garcia Gomez <carlos@facturascripts.com>
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
namespace FacturaScripts\Plugins\StockAvanzado\Extension\Controller;

use FacturaScripts\Plugins\StockAvanzado\Lib\StockRebuild;

/**
 * Description of ListProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListProducto
{

    protected function createViews()
    {
        return function() {
            if ($this->user->admin) {
                $this->addButton('ListStock', [
                    'action' => 'rebuild-stock',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fas fa-magic',
                    'label' => 'rebuild-stock'
                ]);
            }
        };
    }

    protected function execPreviousAction()
    {
        return function($action) {
            if ($action === 'rebuild-stock') {
                StockRebuild::rebuild();
                $this->toolBox()->i18nLog()->notice('rebuilt-stock');
            }
        };
    }
}
