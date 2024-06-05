<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Description of EditAlmacen
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class EditAlmacen
{
    protected function createViews(): Closure
    {
        return function () {
            $this->createViewsMovements();
            $this->createViewsCounts();
        };
    }

    protected function createViewsCounts(): Closure
    {
        return function ($viewName = 'ListConteoStock') {
            $this->addListView($viewName, 'ConteoStock', 'stock-counts', 'fas fa-scroll')
                ->addOrderBy(['fechainicio'], 'date', 2)
                ->addSearchFields(['observaciones'])
                ->disableColumn('warehouse')
                ->setSettings('btnDelete', false)
                ->setSettings('checkBoxes', false);
        };
    }

    protected function createViewsMovements(): Closure
    {
        return function ($viewName = 'ListMovimientoStock') {
            $this->addListView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading')
                ->addOrderBy(['fecha', 'hora', 'id'], 'date', 2)
                ->addOrderBy(['cantidad'], 'quantity')
                ->addSearchFields(['documento', 'referencia'])
                ->disableColumn('warehouse')
                ->setSettings('btnDelete', false)
                ->setSettings('btnNew', false)
                ->setSettings('checkBoxes', false);

            // filters
            $this->listView($viewName)->addFilterPeriod('fecha', 'date', 'fecha')
                ->addFilterNumber('cantidadgt', 'quantity', 'cantidad', '>=')
                ->addFilterNumber('cantidadlt', 'quantity', 'cantidad', '<=');
        };
    }

    protected function loadData(): Closure
    {
        return function ($viewName, $view) {
            switch ($viewName) {
                case 'ListConteoStock':
                case 'ListMovimientoStock':
                    $codalmacen = $this->getViewModelValue('EditAlmacen', 'codalmacen');
                    $where = [new DataBaseWhere('codalmacen', $codalmacen)];
                    $view->loadData('', $where);
                    break;
            }
        };
    }
}
