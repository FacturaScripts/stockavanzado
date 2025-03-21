<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2023 Carlos Garcia Gomez <carlos@facturascripts.com>
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

use Closure;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockRebuild;
use FacturaScripts\Dinamic\Model\MovimientoStock;

/**
 * Description of ListProducto
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListProducto
{
    protected function createViews(): Closure
    {
        return function () {
            if ($this->user->admin) {
                $this->addButton('ListStock', [
                    'action' => 'rebuild-movements',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-repeat',
                    'label' => 'rebuild-movements'
                ]);

                $this->addButton('ListStock', [
                    'action' => 'rebuild-stock',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-wand-magic-sparkles',
                    'label' => 'rebuild-stock'
                ]);
            }
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action === 'rebuild-stock') {
                $this->rebuildStockAction();
            } elseif ($action === 'rebuild-movements') {
                $this->rebuildMovementsAction();
            }
        };
    }

    protected function rebuildMovementsAction(): Closure
    {
        return function () {
            // si no hay movimientos, no hacemos nada
            $movimiento = new MovimientoStock();
            if ($movimiento->count() === 0) {
                Tools::log()->warning('no-movements-to-rebuild-stock');
                return;
            }

            StockMovementManager::rebuild();
        };
    }

    protected function rebuildStockAction(): Closure
    {
        return function () {
            // si no hay movimientos, no hacemos nada
            $movimiento = new MovimientoStock();
            if ($movimiento->count() === 0) {
                Tools::log()->warning('no-movements-to-rebuild-stock');
                return;
            }

            StockRebuild::rebuild();
        };
    }
}
