<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\StockMovementManager;

/**
 * Description of ListAlmacen
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListAlmacen
{
    protected function createViews(): Closure
    {
        return function () {
            $this->createViewsMovements();
            $this->createViewsTransfers();
            $this->createViewsCounting();
        };
    }

    protected function createViewsCounting(): Closure
    {
        return function ($viewName = 'ListConteoStock') {
            $this->addView($viewName, 'ConteoStock', 'stock-counts', 'fa-solid fa-scroll')
                ->addOrderBy(['fechainicio', 'idconteo'], 'date', 2)
                ->addSearchFields(['idconteo', 'observaciones'])
                ->addFilterPeriod('fechainicio', 'date', 'fechainicio')
                ->addFilterCheckbox('completed', 'completed', 'completed');

            $warehouses = Almacenes::codeModel();
            if (count($warehouses) > 2) {
                $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);
            }

            $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');
        };
    }

    protected function createViewsMovements(): Closure
    {
        return function ($viewName = 'ListMovimientoStock') {
            $this->addView($viewName, 'MovimientoStock', 'movements', 'fa-solid fa-truck-loading')
                ->addOrderBy(['fecha', 'hora', 'id'], 'date', 2)
                ->addOrderBy(['cantidad'], 'quantity')
                ->addSearchFields(['documento', 'referencia'])
                ->setSettings('btnDelete', false)
                ->setSettings('btnNew', false)
                ->setSettings('checkBoxes', false)
                ->addFilterPeriod('fecha', 'date', 'fecha')
                ->addFilterNumber('cantidadgt', 'quantity', 'cantidad', '>=')
                ->addFilterNumber('cantidadlt', 'quantity', 'cantidad', '<=');

            $warehouses = Almacenes::codeModel();
            if (count($warehouses) > 2) {
                $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);
            }

            if ($this->user->admin) {
                $this->addButton($viewName, [
                    'action' => 'rebuild-movements',
                    'color' => 'warning',
                    'confirm' => true,
                    'icon' => 'fa-solid fa-magic',
                    'label' => 'rebuild-movements'
                ]);
            }
        };
    }

    protected function createViewsTransfers(): Closure
    {
        return function ($viewName = 'ListTransferenciaStock') {
            $this->addView($viewName, 'TransferenciaStock', 'transfers', 'fa-solid fa-exchange-alt')
                ->addOrderBy(['fecha', 'idtrans'], 'date', 2)
                ->addSearchFields(['idtrans', 'observaciones'])
                ->addFilterPeriod('fecha', 'date', 'fecha')
                ->addFilterCheckbox('completed', 'completed', 'completed')
                ->addFilterAutocomplete('nick', 'user', 'nick', 'users', 'nick', 'nick');

            $warehouses = Almacenes::codeModel();
            if (count($warehouses) > 2) {
                $this->addFilterSelect($viewName, 'codalmacenorigen', 'origin-warehouse', 'codalmacenorigen', $warehouses);
                $this->addFilterSelect($viewName, 'codalmacendestino', 'destination-warehouse', 'codalmacendestino', $warehouses);
            }
        };
    }

    protected function execPreviousAction(): Closure
    {
        return function ($action) {
            if ($action === 'rebuild-movements') {
                $this->rebuildMovementsAction();
            }
        };
    }

    protected function rebuildMovementsAction(): Closure
    {
        return function () {
            if (false === $this->user->admin) {
                Tools::log()->warning('not-allowed-modify');
                return;
            } elseif (false === $this->validateFormToken()) {
                return;
            }

            StockMovementManager::rebuild();
        };
    }
}
