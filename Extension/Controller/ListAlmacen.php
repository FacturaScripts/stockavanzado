<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2020-2021 Carlos Garcia Gomez <carlos@facturascripts.com>
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

/**
 * Description of ListAlmacen
 *
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class ListAlmacen
{

    protected function createViews()
    {
        return function () {
            $this->createViewsMovements();
            $this->createViewsTransfers();
            $this->createViewsCountings();
        };
    }

    protected function createViewsCountings()
    {
        return function ($viewName = 'ListConteoStock') {
            $this->addView($viewName, 'ConteoStock', 'stock-counts', 'fas fa-scroll');
            $this->addOrderBy($viewName, ['fechainicio'], 'date', 2);
            $this->addSearchFields($viewName, ['idconteo', 'observaciones']);

            /// Filters
            $this->addFilterPeriod($viewName, 'fechainicio', 'date', 'fechainicio');
            $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
            $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);
            $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');
        };
    }

    protected function createViewsMovements()
    {
        return function ($viewName = 'ListMovimientoStock') {
            $this->addView($viewName, 'MovimientoStock', 'movements', 'fas fa-truck-loading');
            $this->addOrderBy($viewName, ['fecha', 'hora', 'id'], 'date', 2);
            $this->addOrderBy($viewName, ['cantidad'], 'quantity');
            $this->addSearchFields($viewName, ['documento', 'referencia']);

            /// Filters
            $this->addFilterPeriod($viewName, 'fecha', 'date', 'fecha');

            $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
            $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'codalmacen', $warehouses);

            /// disable buttons
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'checkBoxes', false);
        };
    }

    protected function createViewsTransfers()
    {
        return function ($viewName = 'ListTransferenciaStock') {
            $this->addView($viewName, 'TransferenciaStock', 'transfers', 'fas fa-exchange-alt');
            $this->addOrderBy($viewName, ['fecha'], 'date', 2);
            $this->addSearchFields($viewName, ['idtrans', 'observaciones']);

            /// Filters
            $this->addFilterPeriod($viewName, 'fecha', 'date', 'fecha');
            $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
            $this->addFilterSelect($viewName, 'codalmacenorigen', 'origin-warehouse', 'codalmacenorigen', $warehouses);
            $this->addFilterSelect($viewName, 'codalmacendestino', 'destination-warehouse', 'codalmacendestino', $warehouses);
            $this->addFilterAutocomplete($viewName, 'nick', 'user', 'nick', 'users', 'nick', 'nick');
        };
    }
}
