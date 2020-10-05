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

use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Description of EditAlmacen
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class EditAlmacen
{

    protected function createViews()
    {
        return function() {
            $this->createViewsMovements();
            $this->createViewsCounts();
        };
    }

    protected function createViewsCounts()
    {
        return function($viewName = 'ListConteoStock') {
            $this->addListView($viewName, 'ConteoStock', 'stock-counts', 'fas fa-scroll');
            $this->views[$viewName]->addOrderBy(['fechainicio'], 'date', 2);
            $this->views[$viewName]->searchFields = ['observaiones'];

            /// disable column
            $this->views[$viewName]->disableColumn('warehouse');

            /// disable buttons
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'checkBoxes', false);
        };
    }

    protected function createViewsMovements()
    {
        return function($viewName = 'ListMovimientoStock') {
            $this->addListView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading');
            $this->views[$viewName]->addOrderBy(['fecha', 'hora', 'id'], 'date', 2);
            $this->views[$viewName]->addOrderBy(['cantidad'], 'quantity');
            $this->views[$viewName]->searchFields = ['documento', 'referencia'];

            /// disable column
            $this->views[$viewName]->disableColumn('warehouse');

            /// disable buttons
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'checkBoxes', false);
        };
    }

    protected function loadData()
    {
        return function($viewName, $view) {
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
