<?php
/**
 * This file is part of StockAvanzado plugin for FacturaScripts
 * Copyright (C) 2022 Carlos Garcia Gomez <carlos@facturascripts.com>
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

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class ReportProducto
{
    protected function createViews(): Closure
    {
        return function () {
            $this->createViewsMovements();
        };
    }

    protected function createViewsMovements(): Closure
    {
        return function ($viewName = 'ListMovimientoProducto') {
            $this->addView($viewName, 'Join\MovimientoProducto', 'movements', 'fas fa-truck-loading');
            $this->addOrderBy($viewName, ['cantidad'], 'quantity');
            $this->addSearchFields($viewName, ['sm.referencia', 'p.descripcion']);

            // Filters
            $this->addFilterPeriod($viewName, 'fecha', 'date', 'sm.fecha');
            $this->addFilterNumber($viewName, 'cantidadgt', 'quantity', 'cantidad', '>=');
            $this->addFilterNumber($viewName, 'cantidadlt', 'quantity', 'cantidad', '<=');

            $warehouses = $this->codeModel->all('almacenes', 'codalmacen', 'nombre');
            $this->addFilterSelect($viewName, 'codalmacen', 'warehouse', 'sm.codalmacen', $warehouses);

            // disable buttons
            $this->setSettings($viewName, 'btnDelete', false);
            $this->setSettings($viewName, 'btnNew', false);
            $this->setSettings($viewName, 'checkBoxes', false);
        };
    }
}